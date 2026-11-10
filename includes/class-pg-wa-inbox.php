<?php
defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp inbox: every message sent from the Cloud API number (automatic templates,
 * manual replies) and every customer reply, in one conversation per customer.
 *
 * A number registered on the Cloud API cannot be used in the WhatsApp app, so this is
 * where the team reads and answers. Meta sends incoming messages and delivery statuses
 * to the webhook below; a failed delivery with error 131026 means the number has no
 * WhatsApp, which is shown on the order card.
 */
class PG_WA_Inbox {

	const NS          = 'product-glide/v1';
	const ROUTE       = '/wa-webhook';
	const WINDOW      = DAY_IN_SECONDS; // free-form replies allowed for 24 h after the customer's last message
	const NO_WA_CODES = array( 131026 );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array( 'inbox', 'thread', 'reply', 'send_tpl', 'unread', 'mark', 'quick' ) as $a ) {
			add_action( 'wp_ajax_pg_wa_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		add_action( 'wp_ajax_pg_wa_media', array( __CLASS__, 'ajax_media' ) );
	}

	public static function t_msg() {
		return PG_Install::table( 'wa_messages' );
	}

	public static function t_contact() {
		return PG_Install::table( 'wa_contacts' );
	}

	public static function webhook_url() {
		return rest_url( self::NS . self::ROUTE );
	}

	/** Verify token Meta asks for when the webhook is connected (created once). */
	public static function verify_token() {
		$t = (string) PG_Settings::get( 'wa_verify_token', '' );
		if ( '' === $t ) {
			$t = 'pg-' . wp_generate_password( 24, false, false );
			$s = get_option( 'pg_settings', array() );
			$s['wa_verify_token'] = $t;
			update_option( 'pg_settings', $s, false );
		}
		return $t;
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	/** 8801XXXXXXXXX / +880… / 01… → 01XXXXXXXXX (or the raw digits for foreign numbers). */
	public static function key( $phone ) {
		$bd = PG_Helpers::bd_phone( $phone );
		return $bd ? $bd : substr( preg_replace( '/\D+/', '', (string) $phone ), 0, 20 );
	}

	public static function wa_to( $key ) {
		return preg_match( '/^01\d{9}$/', $key ) ? '88' . $key : $key;
	}

	/** Latest order id for a phone (0 when none). */
	public static function latest_order( $key ) {
		$k   = PG_Helpers::phone_key( $key );
		$map = PG_Helpers::orders_by_phone_keys( array( $k ) );
		return ! empty( $map[ $k ][0]['id'] ) ? (int) $map[ $k ][0]['id'] : 0;
	}

	public static function log( $args ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'wa_mid'     => '',
				'phone'      => '',
				'direction'  => 'out',
				'type'       => 'text',
				'body'       => '',
				'media_id'   => '',
				'mime'       => '',
				'filename'   => '',
				'template'   => '',
				'status'     => 'sent',
				'error'      => '',
				'order_id'   => 0,
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
				'name'       => '',
			)
		);
		if ( '' === $a['phone'] ) {
			return 0;
		}
		if ( $a['wa_mid'] && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t_msg() . ' WHERE wa_mid = %s', $a['wa_mid'] ) ) ) { // phpcs:ignore
			return 0; // Meta retries webhooks: store each message once.
		}
		$wpdb->insert( // phpcs:ignore
			self::t_msg(),
			array(
				'wa_mid'     => substr( $a['wa_mid'], 0, 128 ),
				'phone'      => $a['phone'],
				'direction'  => 'in' === $a['direction'] ? 'in' : 'out',
				'type'       => substr( $a['type'], 0, 20 ),
				'body'       => (string) $a['body'],
				'media_id'   => substr( $a['media_id'], 0, 80 ),
				'mime'       => substr( $a['mime'], 0, 80 ),
				'filename'   => substr( $a['filename'], 0, 190 ),
				'template'   => substr( $a['template'], 0, 120 ),
				'status'     => substr( $a['status'], 0, 16 ),
				'error'      => substr( $a['error'], 0, 255 ),
				'order_id'   => (int) $a['order_id'],
				'user_id'    => 'in' === $a['direction'] ? 0 : (int) $a['user_id'],
				'created_at' => $a['created_at'],
			)
		);
		$id = (int) $wpdb->insert_id;
		self::touch_contact( $a );
		return $id;
	}

	private static function preview( $a ) {
		$labels = array(
			'image'    => '📷 Photo',
			'video'    => '🎬 Video',
			'audio'    => '🎤 Voice message',
			'document' => '📄 Document',
			'sticker'  => 'Sticker',
			'location' => '📍 Location',
			'contacts' => '👤 Contact',
		);
		$t = '' !== trim( (string) $a['body'] ) ? $a['body'] : ( isset( $labels[ $a['type'] ] ) ? $labels[ $a['type'] ] : $a['type'] );
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', $t ) ), 0, 180 );
	}

	private static function touch_contact( $a ) {
		global $wpdb;
		$t   = self::t_contact();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone = %s", $a['phone'] ) ); // phpcs:ignore
		$in  = 'in' === $a['direction'];
		$oid = (int) $a['order_id'] ? (int) $a['order_id'] : ( $row && $row->order_id ? (int) $row->order_id : self::latest_order( $a['phone'] ) );
		$data = array(
			'last_at'      => $a['created_at'],
			'last_preview' => self::preview( $a ),
			'last_dir'     => $in ? 'in' : 'out',
			'order_id'     => $oid,
		);
		if ( $in ) {
			$data['last_in_at'] = $a['created_at'];
			$data['has_wa']     = 1;
		}
		if ( '' !== $a['name'] && ( ! $row || '' === (string) $row->name ) ) {
			$data['name'] = mb_substr( $a['name'], 0, 120 ); // keep the order name once we have one
		}
		if ( $row ) {
			$wpdb->update( $t, $data, array( 'phone' => $a['phone'] ) ); // phpcs:ignore
			if ( $in ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET unread = unread + 1 WHERE phone = %s", $a['phone'] ) ); // phpcs:ignore
			}
		} else {
			if ( ! isset( $data['name'] ) && $oid && ( $o = wc_get_order( $oid ) ) ) {
				$data['name'] = mb_substr( PG_Helpers::order_name( $o ), 0, 120 );
			}
			$data['phone']  = $a['phone'];
			$data['unread'] = $in ? 1 : 0;
			$wpdb->insert( $t, $data ); // phpcs:ignore
		}
		delete_transient( 'pg_wa_unread' );
	}

	public static function unread_total() {
		$n = get_transient( 'pg_wa_unread' );
		if ( false === $n ) {
			global $wpdb;
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_contact() . ' WHERE unread > 0' ); // phpcs:ignore
			set_transient( 'pg_wa_unread', $n, 60 );
		}
		return (int) $n;
	}

	/** Save the delivery state on the order so the card can show it. */
	private static function order_state( $order_id, $state, $note = '' ) {
		$o = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $o ) {
			return;
		}
		$rank = array( '' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3 );
		$cur  = (string) $o->get_meta( '_pg_wa_state' );
		if ( in_array( $state, array( 'none', 'failed' ), true ) || ( isset( $rank[ $state ], $rank[ $cur ] ) && $rank[ $state ] > $rank[ $cur ] ) || ! isset( $rank[ $cur ] ) ) {
			$o->update_meta_data( '_pg_wa_state', $state );
			$o->save_meta_data();
		}
		if ( $note ) {
			$o->add_order_note( $note );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Webhook                                                             */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Verified inside: verify token (GET) and app-secret signature (POST).
			)
		);
	}

	public static function webhook( WP_REST_Request $req ) {
		if ( 'GET' === $req->get_method() ) {
			$mode  = (string) $req->get_param( 'hub_mode' );
			$token = (string) $req->get_param( 'hub_verify_token' );
			if ( '' === $mode ) { // PHP turns hub.mode into hub_mode; fall back to the raw query string.
				parse_str( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_QUERY ), $q ); // phpcs:ignore
				$mode  = isset( $q['hub_mode'] ) ? $q['hub_mode'] : '';
				$token = isset( $q['hub_verify_token'] ) ? $q['hub_verify_token'] : '';
			}
			if ( 'subscribe' === $mode && hash_equals( self::verify_token(), $token ) ) {
				update_option( 'pg_wa_webhook_ok', time(), false );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo esc_html( (string) $req->get_param( 'hub_challenge' ) );
				exit;
			}
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$raw    = $req->get_body();
		$secret = (string) PG_Settings::get( 'wa_app_secret', '' );
		if ( '' !== $secret ) {
			$sig = (string) $req->get_header( 'x_hub_signature_256' );
			if ( ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $raw, $secret ), $sig ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad signature' ), 401 );
			}
		}
		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			update_option( 'pg_wa_last_event', time(), false );
			self::handle( $data );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function handle( array $data ) {
		$mine = (string) PG_Settings::get( 'wa_phone_id', '' );
		foreach ( isset( $data['entry'] ) ? (array) $data['entry'] : array() as $entry ) {
			foreach ( isset( $entry['changes'] ) ? (array) $entry['changes'] : array() as $ch ) {
				$v = isset( $ch['value'] ) ? $ch['value'] : array();
				if ( $mine && isset( $v['metadata']['phone_number_id'] ) && (string) $v['metadata']['phone_number_id'] !== $mine ) {
					continue; // another number on the same app
				}
				$names = array();
				foreach ( isset( $v['contacts'] ) ? (array) $v['contacts'] : array() as $c ) {
					if ( isset( $c['wa_id'], $c['profile']['name'] ) ) {
						$names[ (string) $c['wa_id'] ] = $c['profile']['name'];
					}
				}
				foreach ( isset( $v['messages'] ) ? (array) $v['messages'] : array() as $m ) {
					self::incoming( $m, $names );
				}
				foreach ( isset( $v['statuses'] ) ? (array) $v['statuses'] : array() as $s ) {
					self::status( $s );
				}
			}
		}
	}

	private static function incoming( array $m, array $names ) {
		$from = isset( $m['from'] ) ? (string) $m['from'] : '';
		if ( '' === $from ) {
			return;
		}
		$type  = isset( $m['type'] ) ? (string) $m['type'] : 'text';
		$body  = '';
		$media = '';
		$mime  = '';
		$file  = '';
		switch ( $type ) {
			case 'text':
				$body = isset( $m['text']['body'] ) ? $m['text']['body'] : '';
				break;
			case 'image':
			case 'video':
			case 'audio':
			case 'document':
			case 'sticker':
				$x     = isset( $m[ $type ] ) ? $m[ $type ] : array();
				$media = isset( $x['id'] ) ? $x['id'] : '';
				$mime  = isset( $x['mime_type'] ) ? $x['mime_type'] : '';
				$body  = isset( $x['caption'] ) ? $x['caption'] : '';
				$file  = isset( $x['filename'] ) ? $x['filename'] : '';
				break;
			case 'button':
				$body = isset( $m['button']['text'] ) ? $m['button']['text'] : '';
				break;
			case 'interactive':
				$i    = isset( $m['interactive'] ) ? $m['interactive'] : array();
				$body = isset( $i['button_reply']['title'] ) ? $i['button_reply']['title'] : ( isset( $i['list_reply']['title'] ) ? $i['list_reply']['title'] : '' );
				break;
			case 'location':
				$l    = isset( $m['location'] ) ? $m['location'] : array();
				$body = trim( ( isset( $l['name'] ) ? $l['name'] . ' ' : '' ) . ( isset( $l['address'] ) ? $l['address'] . ' ' : '' ) ) . ( isset( $l['latitude'], $l['longitude'] ) ? ' https://maps.google.com/?q=' . $l['latitude'] . ',' . $l['longitude'] : '' );
				break;
			case 'contacts':
				$c    = isset( $m['contacts'][0] ) ? $m['contacts'][0] : array();
				$body = ( isset( $c['name']['formatted_name'] ) ? $c['name']['formatted_name'] : '' ) . ( isset( $c['phones'][0]['phone'] ) ? ' ' . $c['phones'][0]['phone'] : '' );
				break;
			case 'reaction':
				$body = ( isset( $m['reaction']['emoji'] ) ? $m['reaction']['emoji'] : '' ) . ' (reaction)';
				break;
			default:
				$body = '[' . $type . ' message — open WhatsApp Manager to view]';
		}
		$key = self::key( $from );
		$new = self::log(
			array(
				'wa_mid'     => isset( $m['id'] ) ? $m['id'] : '',
				'phone'      => $key,
				'direction'  => 'in',
				'type'       => $type,
				'body'       => $body,
				'media_id'   => $media,
				'mime'       => $mime,
				'filename'   => $file,
				'status'     => 'received',
				'created_at' => isset( $m['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', (int) $m['timestamp'] ) : current_time( 'mysql', true ),
				'name'       => isset( $names[ $from ] ) ? $names[ $from ] : '',
			)
		);
		if ( $new && 'reaction' !== $type ) {
			do_action( 'pg_wa_incoming', $key, $type, $body );
		}
	}

	private static function status( array $s ) {
		global $wpdb;
		$mid = isset( $s['id'] ) ? (string) $s['id'] : '';
		$st  = isset( $s['status'] ) ? (string) $s['status'] : '';
		if ( '' === $mid || '' === $st ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_msg() . ' WHERE wa_mid = %s', $mid ) ); // phpcs:ignore
		if ( ! $row ) {
			return;
		}
		$rank = array( 'sent' => 1, 'delivered' => 2, 'read' => 3 );
		if ( 'failed' === $st ) {
			$code  = isset( $s['errors'][0]['code'] ) ? (int) $s['errors'][0]['code'] : 0;
			$no_wa = in_array( $code, self::NO_WA_CODES, true );
			$err   = $no_wa ? 'এই নাম্বারে WhatsApp নেই (বা মেসেজ নিতে পারছে না)' : trim( ( isset( $s['errors'][0]['title'] ) ? $s['errors'][0]['title'] : 'Failed' ) . ( isset( $s['errors'][0]['error_data']['details'] ) ? ' — ' . $s['errors'][0]['error_data']['details'] : '' ) );
			$wpdb->update( self::t_msg(), array( 'status' => $no_wa ? 'nowa' : 'failed', 'error' => mb_substr( $err . ( $code ? ' (' . $code . ')' : '' ), 0, 255 ) ), array( 'id' => $row->id ) ); // phpcs:ignore
			if ( $no_wa ) {
				$wpdb->update( self::t_contact(), array( 'has_wa' => 0 ), array( 'phone' => $row->phone ) ); // phpcs:ignore
			}
			if ( $row->order_id ) {
				self::order_state( (int) $row->order_id, $no_wa ? 'none' : 'failed', $no_wa ? 'WhatsApp: customer number ' . $row->phone . ' has no WhatsApp — message not delivered. Call or SMS instead.' : 'WhatsApp message failed: ' . $err );
			}
			return;
		}
		if ( isset( $rank[ $st ] ) && ( ! isset( $rank[ $row->status ] ) || $rank[ $st ] > $rank[ $row->status ] ) && ! in_array( $row->status, array( 'failed', 'nowa' ), true ) ) {
			$wpdb->update( self::t_msg(), array( 'status' => $st ), array( 'id' => $row->id ) ); // phpcs:ignore
		}
		if ( in_array( $st, array( 'delivered', 'read' ), true ) ) {
			$wpdb->update( self::t_contact(), array( 'has_wa' => 1 ), array( 'phone' => $row->phone ) ); // phpcs:ignore
			if ( $row->order_id ) {
				self::order_state( (int) $row->order_id, $st );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                             */
	/* ------------------------------------------------------------------ */

	public static function window_left( $key ) {
		global $wpdb;
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT last_in_at FROM ' . self::t_contact() . ' WHERE phone = %s', $key ) ); // phpcs:ignore
		if ( ! $last ) {
			return 0;
		}
		return max( 0, strtotime( $last . ' UTC' ) + self::WINDOW - time() );
	}

	/** Free-form text reply (only inside the 24-hour window). */
	public static function send_text( $key, $text ) {
		if ( ! PG_WhatsApp::configured() ) {
			return new WP_Error( 'pg_wa', __( 'WhatsApp Cloud API is not configured.', 'product-glide' ) );
		}
		$res = PG_WhatsApp::api(
			'POST',
			rawurlencode( PG_Settings::get( 'wa_phone_id' ) ) . '/messages',
			array(
				'messaging_product' => 'whatsapp',
				'recipient_type'    => 'individual',
				'to'                => self::wa_to( $key ),
				'type'              => 'text',
				'text'              => array(
					'preview_url' => false,
					'body'        => $text,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::log(
			array(
				'wa_mid'   => isset( $res['messages'][0]['id'] ) ? $res['messages'][0]['id'] : '',
				'phone'    => $key,
				'type'     => 'text',
				'body'     => $text,
				'order_id' => self::latest_order( $key ),
			)
		);
		return $res;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	private static function contact_row( $c ) {
		$ts = $c->last_at ? strtotime( $c->last_at . ' UTC' ) : 0;
		$o = ! empty( $c->order_id ) ? wc_get_order( (int) $c->order_id ) : null;
		return array(
			'onum'    => $o ? $o->get_order_number() : '',
			'oslug'   => $o ? $o->get_status() : '',
			'ostatus' => $o ? PG_Helpers::status_label( $o->get_status() ) : '',
			'phone'   => $c->phone,
			'name'    => (string) $c->name,
			'preview' => (string) $c->last_preview,
			'dir'     => (string) $c->last_dir,
			'unread'  => (int) $c->unread,
			'has_wa'  => null === $c->has_wa ? null : (int) $c->has_wa,
			'order'   => (int) $c->order_id,
			'ts'      => $ts,
			'when'    => $ts ? self::when( $ts ) : '',
			'open'    => $c->last_in_at && strtotime( $c->last_in_at . ' UTC' ) + self::WINDOW > time(),
		);
	}

	private static function when( $ts ) {
		$today = PG_Helpers::bd_time( time(), 'Y-m-d' );
		$d     = PG_Helpers::bd_time( $ts, 'Y-m-d' );
		if ( $d === $today ) {
			return PG_Helpers::bd_time( $ts, 'h:i A' );
		}
		if ( PG_Helpers::bd_time( $ts + DAY_IN_SECONDS, 'Y-m-d' ) === $today ) {
			return 'Yesterday';
		}
		return PG_Helpers::bd_time( $ts, 'd M' );
	}

	public static function ajax_inbox() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$t      = self::t_contact();
		$q      = trim( PG_Helpers::post( 'q', '' ) );
		$filter = PG_Helpers::post( 'filter', 'all' );
		$where  = array( '1=1' );
		$args   = array();
		if ( '' !== $q ) {
			$k       = preg_replace( '/\D+/', '', PG_Helpers::bd_phone( $q ) ? PG_Helpers::bd_phone( $q ) : $q );
			$where[] = '(name LIKE %s' . ( strlen( $k ) >= 4 ? ' OR phone LIKE %s' : '' ) . ')';
			$args[]  = '%' . $wpdb->esc_like( $q ) . '%';
			if ( strlen( $k ) >= 4 ) {
				$args[] = '%' . $wpdb->esc_like( $k ) . '%';
			}
		}
		if ( 'unread' === $filter ) {
			$where[] = 'unread > 0';
		} elseif ( 'nowa' === $filter ) {
			$where[] = 'has_wa = 0';
		} elseif ( 'open' === $filter ) {
			$where[] = $wpdb->prepare( 'last_in_at >= %s', gmdate( 'Y-m-d H:i:s', time() - self::WINDOW ) );
		}
		$per    = 40;
		$offset = max( 0, PG_Helpers::post_int( 'offset' ) );
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY last_at DESC LIMIT ' . ( $per + 1 ) . ' OFFSET ' . $offset;
		$rows   = (array) ( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ) ); // phpcs:ignore
		$more   = count( $rows ) > $per;
		$rows   = array_slice( $rows, 0, $per );
		$since  = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW );
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS `all`, SUM(unread > 0) AS unread, SUM(last_in_at >= %s) AS `open`, SUM(has_wa = 0) AS nowa FROM {$t}", $since ), ARRAY_A ); // phpcs:ignore
		wp_send_json_success(
			array(
				'more'      => $more,
				'counts'    => array_map( 'intval', (array) $counts ),
				'contacts'  => array_map( array( __CLASS__, 'contact_row' ), $rows ),
				'unread'    => self::unread_total(),
				'ready'     => PG_WhatsApp::configured(),
				'hook'      => (bool) get_option( 'pg_wa_webhook_ok' ),
			)
		);
	}

	private static function msg_row( $m ) {
		$ts = strtotime( $m->created_at . ' UTC' );
		$by = '';
		if ( 'out' === $m->direction ) {
			$u  = $m->user_id ? get_userdata( (int) $m->user_id ) : null;
			$by = $u ? $u->display_name : ( $m->template ? 'Auto' : '' );
		}
		return array(
			'id'       => (int) $m->id,
			'dir'      => $m->direction,
			'type'     => $m->type,
			'body'     => (string) $m->body,
			'media'    => $m->media_id ? 1 : 0,
			'mime'     => (string) $m->mime,
			'filename' => (string) $m->filename,
			'template' => (string) $m->template,
			'status'   => (string) $m->status,
			'error'    => (string) $m->error,
			'by'       => $by,
			'order'    => (int) $m->order_id,
			'ts'       => $ts,
			'time'     => PG_Helpers::bd_time( $ts, 'h:i A' ),
			'day'      => PG_Helpers::bd_time( $ts, 'D, d M Y' ),
		);
	}

	public static function ajax_thread() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$key   = self::key( PG_Helpers::post( 'phone' ) );
		$after = PG_Helpers::post_int( 'after' );
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => 'No phone' ) );
		}
		$t = self::t_msg();
		if ( $after ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone = %s AND id > %d ORDER BY id ASC LIMIT 200", $key, $after ) ); // phpcs:ignore
		} else {
			$rows = array_reverse( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone = %s ORDER BY id DESC LIMIT 300", $key ) ) ); // phpcs:ignore
		}
		// Statuses of recent outgoing messages change after they are shown (sent → delivered → read).
		$since  = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$states = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, error FROM {$t} WHERE phone = %s AND direction = 'out' AND created_at >= %s", $key, $since ) ); // phpcs:ignore
		$c      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_contact() . ' WHERE phone = %s', $key ) ); // phpcs:ignore
		if ( $c && $c->unread ) {
			$wpdb->update( self::t_contact(), array( 'unread' => 0 ), array( 'phone' => $key ) ); // phpcs:ignore
			delete_transient( 'pg_wa_unread' );
		}
		$orders = array();
		$k      = PG_Helpers::phone_key( $key );
		$map    = PG_Helpers::orders_by_phone_keys( array( $k ) );
		foreach ( array_slice( isset( $map[ $k ] ) ? $map[ $k ] : array(), 0, 6 ) as $r ) {
			$o = wc_get_order( $r['id'] );
			if ( $o ) {
				$orders[] = array(
					'id'     => $o->get_id(),
					'number' => $o->get_order_number(),
					'status' => PG_Helpers::status_label( $o->get_status() ),
					'slug'   => $o->get_status(),
					'total'  => (float) $o->get_total(),
					'date'   => PG_Helpers::bd_time( $o->get_date_created(), 'd M' ),
					'items'  => mb_substr( PG_Helpers::items_text( $o ), 0, 120 ),
				);
			}
		}
		if ( ! $c && $orders ) {
			$o = wc_get_order( $orders[0]['id'] );
			$c = (object) array( 'phone' => $key, 'name' => $o ? PG_Helpers::order_name( $o ) : '', 'last_preview' => '', 'last_dir' => '', 'unread' => 0, 'has_wa' => null, 'order_id' => $orders[0]['id'], 'last_at' => null, 'last_in_at' => null );
		}
		// Customer summary: all orders with this number + delivery score.
		$all_rows  = isset( $map[ $k ] ) ? $map[ $k ] : array();
		$profile   = array( 'orders' => count( $all_rows ), 'delivered' => 0, 'cancelled' => 0, 'spent' => 0.0, 'score' => null );
		foreach ( $all_rows as $r ) {
			$o = wc_get_order( $r['id'] );
			if ( ! $o ) {
				continue;
			}
			if ( 'completed' === $o->get_status() ) {
				++$profile['delivered'];
				$profile['spent'] += (float) $o->get_total();
			} elseif ( 'cancelled' === $o->get_status() ) {
				++$profile['cancelled'];
			}
		}
		$fr = PG_Fraud::cached_many( array( $key ) );
		if ( isset( $fr[ $key ]['delivery_ratio'] ) && null !== $fr[ $key ]['delivery_ratio'] ) {
			$profile['score'] = (int) $fr[ $key ]['delivery_ratio'];
		}
		$quick = array_values( array_filter( array_map( 'trim', explode( "\n", (string) PG_Settings::get( 'wa_quick_replies', '' ) ) ) ) );
		$maps = array();
		foreach ( PG_WhatsApp::mappings() as $m ) {
			$maps[] = array( 'id' => (int) $m->id, 'name' => $m->template_name, 'trigger' => $m->trigger_status );
		}
		wp_send_json_success(
			array(
				'contact'  => $c ? self::contact_row( $c ) : array( 'phone' => $key, 'name' => '', 'has_wa' => null, 'open' => false ),
				'messages' => array_map( array( __CLASS__, 'msg_row' ), (array) $rows ),
				'states'   => $states,
				'window'   => self::window_left( $key ),
				'orders'   => $orders,
				'profile'  => $profile,
				'quick'    => $quick,
				'maps'     => $maps,
				'unread'   => self::unread_total(),
			)
		);
	}

	public static function ajax_reply() {
		PG_Helpers::guard_ajax();
		$key  = self::key( PG_Helpers::post( 'phone' ) );
		$text = trim( PG_Helpers::post_textarea( 'text' ) );
		if ( '' === $key || '' === $text ) {
			wp_send_json_error( array( 'message' => __( 'Write a message first.', 'product-glide' ) ) );
		}
		if ( ! self::window_left( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'The 24-hour reply window is closed (the customer has not messaged in the last 24 hours). WhatsApp only allows an approved template now.', 'product-glide' ), 'closed' => 1 ) );
		}
		$res = self::send_text( $key, mb_substr( $text, 0, 4000 ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'ok' => 1 ) );
	}

	public static function ajax_send_tpl() {
		PG_Helpers::guard_ajax();
		$key    = self::key( PG_Helpers::post( 'phone' ) );
		$map_id = PG_Helpers::post_int( 'map_id' );
		$m      = null;
		foreach ( PG_WhatsApp::mappings() as $row ) {
			if ( (int) $row->id === $map_id ) {
				$m = $row;
			}
		}
		$oid   = PG_Helpers::post_int( 'order_id' );
		$oid   = $oid ? $oid : self::latest_order( $key );
		$order = $oid ? wc_get_order( $oid ) : null;
		if ( ! $m || ! $order ) {
			wp_send_json_error( array( 'message' => $m ? __( 'This customer has no order to fill the template from.', 'product-glide' ) : __( 'Choose a template.', 'product-glide' ) ) );
		}
		$res = PG_WhatsApp::send_template( $order, $m->template_name, $m->language, $m->variables, (bool) $m->has_header_text, $key );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'ok' => 1 ) );
	}

	public static function quick_list() {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) PG_Settings::get( 'wa_quick_replies', '' ) ) ) ) );
	}

	/** Add / remove a quick reply from the inbox (shared by the whole team). */
	public static function ajax_quick() {
		PG_Helpers::guard_ajax();
		$list = self::quick_list();
		$op   = PG_Helpers::post( 'op', '' );
		if ( 'add' === $op ) {
			$text = trim( preg_replace( '/\s*\n\s*/u', ' ', PG_Helpers::post_textarea( 'text' ) ) );
			if ( '' === $text ) {
				wp_send_json_error( array( 'message' => __( 'Write the reply first.', 'product-glide' ) ) );
			}
			if ( ! in_array( $text, $list, true ) ) {
				$list[] = mb_substr( $text, 0, 1000 );
			}
		} elseif ( 'delete' === $op ) {
			$i = PG_Helpers::post_int( 'index' );
			if ( isset( $list[ $i ] ) && $list[ $i ] === trim( PG_Helpers::post_textarea( 'text' ) ) ) {
				array_splice( $list, $i, 1 );
			}
		}
		$s                     = get_option( 'pg_settings', array() );
		$s['wa_quick_replies'] = implode( "\n", $list );
		update_option( 'pg_settings', $s, false );
		PG_Settings::flush();
		wp_send_json_success( array( 'quick' => self::quick_list() ) );
	}

	public static function ajax_unread() {
		PG_Helpers::guard_ajax();
		wp_send_json_success( array( 'unread' => self::unread_total() ) );
	}

	public static function ajax_mark() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$key = self::key( PG_Helpers::post( 'phone' ) );
		$wpdb->update( self::t_contact(), array( 'unread' => PG_Helpers::post_int( 'unread' ) ? 1 : 0 ), array( 'phone' => $key ) ); // phpcs:ignore
		delete_transient( 'pg_wa_unread' );
		wp_send_json_success( array( 'unread' => self::unread_total() ) );
	}

	/** Stream a customer's photo / voice / document through the site (Meta media URLs need the token). */
	public static function ajax_media() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
		$row = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT media_id, mime, filename FROM ' . self::t_msg() . ' WHERE id = %d', $id ) ) : null; // phpcs:ignore
		if ( ! $row || ! $row->media_id ) {
			status_header( 404 );
			exit;
		}
		$info = PG_WhatsApp::api( 'GET', rawurlencode( $row->media_id ) );
		if ( is_wp_error( $info ) || empty( $info['url'] ) ) {
			status_header( 410 );
			echo esc_html( is_wp_error( $info ) ? $info->get_error_message() : 'Media expired' );
			exit;
		}
		$res = wp_remote_get( $info['url'], array( 'timeout' => 40, 'headers' => array( 'Authorization' => 'Bearer ' . PG_Settings::get( 'wa_token', '' ) ) ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			status_header( 502 );
			exit;
		}
		$mime = $row->mime ? $row->mime : wp_remote_retrieve_header( $res, 'content-type' );
		header( 'Content-Type: ' . preg_replace( '/[^\w\/\.\-\+;= ]/', '', $mime ) );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $row->filename ) {
			header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $row->filename ) . '"' );
		}
		echo wp_remote_retrieve_body( $res ); // phpcs:ignore -- binary media
		exit;
	}
}
