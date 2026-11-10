<?php
defined( 'ABSPATH' ) || exit;

/**
 * Meta WhatsApp Cloud API: approved templates, status-triggered and manual sends.
 *
 * Variables available for template parameters (comma-separated, in {{1}},{{2}}… order):
 *   name, order_id, items, total, amount, address, phone, tracking
 */
class PG_WhatsApp {

	const VARS = array( 'name', 'order_id', 'items', 'total', 'amount', 'cod', 'shipping', 'address', 'phone', 'tracking', 'courier', 'brand', 'pay_number' );

	/** Triggers that are not order statuses. */
	const EVENTS = array(
		'new_order'      => 'New order (right after it is placed)',
		'reminder'       => 'No reply to the new-order message (reminder, once)',
		'call_missed'    => 'Call not received (staff note)',
		'resched_due'    => 'Rescheduled date arrived',
		'courier_failed' => 'Courier could not deliver (parcel returning)',
	);

	/** Automatic messages wait outside these hours (Bangladesh time) except the new-order message. */
	const QUIET_FROM = 21;
	const QUIET_TO   = 9;

	/** True while an automatic (not staff-clicked) message is being sent. */
	private static $auto = false;

	public static function init() {
		add_action( 'wp_ajax_pg_wa_templates', array( __CLASS__, 'ajax_templates' ) );
		add_action( 'wp_ajax_pg_wa_save_map', array( __CLASS__, 'ajax_save_map' ) );
		add_action( 'wp_ajax_pg_wa_send', array( __CLASS__, 'ajax_send' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 20, 4 );
		// New order → message right away (classic + block checkout, CartFlows, Product Glide's own forms).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_new_order' ), 50, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_new_order' ), 50, 1 );
		add_action( 'pg_order_created', array( __CLASS__, 'on_new_order' ), 50, 1 );
		add_action( 'pg_wa_new_order', array( __CLASS__, 'send_new_order' ), 10, 1 );
		add_action( 'pg_staff_note', array( __CLASS__, 'on_staff_note' ), 10, 2 );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'pg_wa_cron', array( __CLASS__, 'cron' ) );
		if ( ! wp_next_scheduled( 'pg_wa_cron' ) ) {
			wp_schedule_event( time() + 120, 'pg_quarter_hour', 'pg_wa_cron' );
		}
	}

	public static function schedules( $s ) {
		$s['pg_quarter_hour'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Product Glide)' );
		return $s;
	}

	public static function quiet_now() {
		$h = (int) PG_Helpers::bd_time( time(), 'G' );
		return $h >= self::QUIET_FROM || $h < self::QUIET_TO;
	}

	public static function map_for( $trigger ) {
		foreach ( self::mappings() as $m ) {
			if ( $m->enabled && $trigger === $m->trigger_status && $m->template_name ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Send the template mapped to a trigger, once per order (or once per $once_key).
	 * Skips numbers known to have no WhatsApp (except the first, new-order message).
	 */
	public static function send_trigger( WC_Order $order, $trigger, $once_key = '' ) {
		if ( ! PG_Settings::get( 'wa_auto', 0 ) || ! self::configured() ) {
			return false;
		}
		$m = self::map_for( $trigger );
		if ( ! $m ) {
			return false;
		}
		$key = '_pg_wa_sent_' . $trigger;
		$val = '' !== $once_key ? (string) $once_key : '1';
		if ( (string) $order->get_meta( $key ) === $val || ( '' === $once_key && $order->get_meta( $key ) ) ) {
			return false;
		}
		if ( 'new_order' !== $trigger && 'none' === $order->get_meta( '_pg_wa_state' ) ) {
			return false;
		}
		$order->update_meta_data( $key, '' !== $once_key ? $val : time() ); // claim first: never twice
		$order->save_meta_data();
		self::$auto = true;
		$res        = self::send_template( $order, $m->template_name, $m->language, $m->variables, (bool) $m->has_header_text );
		self::$auto = false;
		return ! is_wp_error( $res );
	}

	/** Staff wrote "Call not received" / "Phone switched off" → message the customer on WhatsApp. */
	public static function on_staff_note( $order, $text ) {
		if ( ! $order || ! preg_match( '/(call\s*not\s*received|not\s*received|switched\s*off|phone\s*off|not\s*reachable|unreachable|ফোন\s*ধর(?:ে|ছে)\s*না|কল\s*ধর(?:ে|ছে)\s*না|ফোন\s*বন্ধ|রিসিভ\s*কর(?:ে|ছে)\s*না)/iu', (string) $text ) ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'processing', 'on-hold', 'pending', 'rescheduled' ), true ) || $order->get_meta( '_pg_confirmed' ) ) {
			return;
		}
		self::send_trigger( $order, 'call_missed' );
	}

	/** Every 15 minutes: confirmation reminders and rescheduled-date messages (not at night). */
	public static function cron() {
		if ( ! PG_Settings::get( 'wa_auto', 0 ) || ! self::configured() || self::quiet_now() ) {
			return;
		}
		if ( self::map_for( 'reminder' ) ) {
			$hours = max( 1, (float) PG_Settings::get( 'wa_reminder_hours', 3 ) );
			$ids   = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => array( 'processing', 'on-hold', 'pending' ),
					'limit'        => 100,
					'return'       => 'ids',
					'date_created' => ( time() - 2 * DAY_IN_SECONDS ) . '...' . ( time() - (int) ( $hours * HOUR_IN_SECONDS ) ),
				)
			);
			foreach ( $ids as $id ) {
				$o = wc_get_order( $id );
				if ( ! $o || ! $o->get_meta( '_pg_wa_sent_new_order' ) || $o->get_meta( '_pg_confirmed' ) || $o->get_meta( '_pg_cancel_req' ) || $o->get_meta( '_pg_wa_replied' ) || PG_Steadfast::active( $id ) ) {
					continue;
				}
				if ( in_array( $o->get_meta( '_pg_wa_state' ), array( 'none', 'failed' ), true ) ) {
					continue;
				}
				self::send_trigger( $o, 'reminder' );
			}
		}
		if ( self::map_for( 'resched_due' ) ) {
			foreach ( PG_Reschedule::ids( true ) as $id ) {
				$o  = wc_get_order( $id );
				$at = $o ? (int) $o->get_meta( PG_Reschedule::AT ) : 0;
				if ( $at && $at <= time() && $at > time() - DAY_IN_SECONDS ) {
					self::send_trigger( $o, 'resched_due', (string) $at ); // once per follow-up date
				}
			}
		}
	}

	/** Public wrapper for other classes (inbox, media). */
	public static function api( $method, $path, $body = null ) {
		return self::call( $method, $path, $body );
	}

	public static function new_order_map() {
		foreach ( self::mappings() as $m ) {
			if ( $m->enabled && 'new_order' === $m->trigger_status && $m->template_name ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Queue the "new order" message. It is sent after the checkout response has gone back to the
	 * customer (so checkout is never slowed down), with a cron fallback a few minutes later.
	 */
	public static function on_new_order( $order ) {
		$id = is_object( $order ) ? $order->get_id() : (int) $order;
		if ( ! $id || ! PG_Settings::get( 'wa_auto', 0 ) || ! self::configured() || ! self::new_order_map() ) {
			return;
		}
		static $queued = array();
		if ( isset( $queued[ $id ] ) ) {
			return;
		}
		$queued[ $id ] = 1;
		if ( ! wp_next_scheduled( 'pg_wa_new_order', array( $id ) ) ) {
			wp_schedule_single_event( time() + 180, 'pg_wa_new_order', array( $id ) );
		}
		add_action(
			'shutdown',
			function () use ( $id ) {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				} elseif ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				}
				self::send_new_order( $id );
			},
			1
		);
	}

	public static function send_new_order( $id ) {
		$order = wc_get_order( $id );
		$m     = self::new_order_map();
		if ( ! $order || ! $m || $order->get_meta( '_pg_wa_sent_new_order' ) ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'failed', 'cancelled', 'trash', 'checkout-draft' ), true ) ) {
			return;
		}
		if ( 'pending' === $order->get_status() && 'cod' !== $order->get_payment_method() ) {
			return; // online payment not finished yet
		}
		if ( '' === (string) $order->get_billing_phone() ) {
			return;
		}
		$order->update_meta_data( '_pg_wa_sent_new_order', time() ); // claim first so the cron fallback never sends twice
		$order->save_meta_data();
		self::$auto = true;
		$res        = self::send_template( $order, $m->template_name, $m->language, $m->variables, (bool) $m->has_header_text );
		self::$auto = false;
		if ( is_wp_error( $res ) ) {
			$order->update_meta_data( '_pg_wa_state', 'failed' );
			$order->save_meta_data();
		}
		wp_clear_scheduled_hook( 'pg_wa_new_order', array( $id ) );
	}

	/** Template body with {{1}}… filled in, for showing in the inbox. */
	public static function render_body( $template, $lang, $params ) {
		$list = self::templates();
		$body = '';
		if ( is_array( $list ) ) {
			foreach ( $list as $t ) {
				if ( $t['name'] === $template && ( ! $lang || $t['language'] === $lang ) ) {
					$body = $t['body'];
					break;
				}
			}
		}
		if ( '' === $body ) {
			return '[Template: ' . $template . '] ' . implode( ' · ', $params );
		}
		return preg_replace_callback(
			'/\{\{\s*(\d+)\s*\}\}/',
			function ( $mm ) use ( $params ) {
				$i = (int) $mm[1] - 1;
				return isset( $params[ $i ] ) ? $params[ $i ] : $mm[0];
			},
			$body
		);
	}

	public static function configured() {
		return PG_Settings::get( 'wa_phone_id', '' ) && PG_Settings::get( 'wa_token', '' );
	}

	private static function graph( $path ) {
		return 'https://graph.facebook.com/' . rawurlencode( PG_Settings::get( 'wa_api_version', 'v21.0' ) ) . '/' . ltrim( $path, '/' );
	}

	private static function call( $method, $path, $body = null ) {
		$res = PG_Helpers::remote_json( $method, self::graph( $path ), array( 'Authorization' => 'Bearer ' . PG_Settings::get( 'wa_token', '' ) ), $body, 25 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] >= 400 || isset( $res['data']['error'] ) ) {
			$m = isset( $res['data']['error']['message'] ) ? $res['data']['error']['message'] : 'HTTP ' . $res['code'];
			if ( isset( $res['data']['error']['error_data']['details'] ) ) {
				$m .= ' — ' . $res['data']['error']['error_data']['details'];
			}
			return new WP_Error( 'pg_wa', $m );
		}
		return $res['data'];
	}

	/** Fetch APPROVED templates from the WABA (cached 10 minutes). */
	public static function templates( $force = false ) {
		$cached = get_transient( 'pg_wa_templates' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$waba = PG_Settings::get( 'wa_waba_id', '' );
		if ( ! $waba || ! PG_Settings::get( 'wa_token', '' ) ) {
			return new WP_Error( 'pg_wa', __( 'Set the WABA ID and access token first.', 'product-glide' ) );
		}
		$out  = array();
		$path = rawurlencode( $waba ) . '/message_templates?status=APPROVED&limit=100&fields=name,language,status,category,components';
		for ( $i = 0; $i < 5 && $path; $i++ ) {
			$data = self::call( 'GET', $path );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( isset( $data['data'] ) ? $data['data'] : array() as $t ) {
				$body_text = '';
				$header    = false;
				foreach ( isset( $t['components'] ) ? $t['components'] : array() as $c ) {
					if ( 'BODY' === $c['type'] ) {
						$body_text = isset( $c['text'] ) ? $c['text'] : '';
					}
					if ( 'HEADER' === $c['type'] && isset( $c['format'] ) && 'TEXT' === $c['format'] && isset( $c['text'] ) && false !== strpos( $c['text'], '{{' ) ) {
						$header = true;
					}
				}
				preg_match_all( '/\{\{\s*(\d+)\s*\}\}/', $body_text, $m );
				$out[] = array(
					'name'        => $t['name'],
					'language'    => $t['language'],
					'category'    => isset( $t['category'] ) ? $t['category'] : '',
					'body'        => $body_text,
					'params'      => $m[1] ? max( array_map( 'intval', $m[1] ) ) : 0,
					'header_text' => $header,
				);
			}
			$path = '';
			if ( ! empty( $data['paging']['next'] ) ) {
				$next = wp_parse_url( $data['paging']['next'] );
				$path = isset( $next['path'], $next['query'] ) ? preg_replace( '#^/v[\d.]+/#', '', $next['path'] ) . '?' . $next['query'] : '';
			}
		}
		set_transient( 'pg_wa_templates', $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function mappings() {
		global $wpdb;
		$t = PG_Install::table( 'wa_templates' );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC" ); // phpcs:ignore
	}

	public static function brand() {
		$b = trim( (string) PG_Settings::get( 'brand_name', '' ) );
		return '' !== $b ? $b : get_bloginfo( 'name' );
	}

	public static function vars_for( WC_Order $order ) {
		$c = PG_Steadfast::active( $order->get_id() );
		return array(
			'name'     => PG_Helpers::order_name( $order ),
			'order_id' => (string) $order->get_order_number(),
			'items'    => mb_substr( PG_Helpers::items_text( $order ), 0, 900 ),
			'total'    => wc_format_decimal( $order->get_total(), 0 ) . ' টাকা',
			'amount'   => wc_format_decimal( $order->get_total(), 0 ), // number only, for templates that already say "টাকা"
			'address'  => mb_substr( PG_Helpers::order_address( $order ), 0, 300 ),
			'phone'    => (string) PG_Helpers::bd_phone( $order->get_billing_phone() ),
			'cod'      => wc_format_decimal( $c && null !== $c->cod_amount ? $c->cod_amount : $order->get_total(), 0 ) . ' টাকা',
			'shipping' => wc_format_decimal( $order->get_shipping_total(), 0 ) . ' টাকা',
			'courier'  => $c ? PG_Courier::label( isset( $c->provider ) ? $c->provider : 'steadfast' ) : '-',
			'brand'    => self::brand(),
			'pay_number' => (string) ( PG_Settings::get( 'wa_pay_number', '' ) ? PG_Settings::get( 'wa_pay_number', '' ) : PG_Settings::get( 'hotline', '-' ) ),
			'tracking' => $c && $c->tracking_code ? $c->tracking_code : '-',
		);
	}

	public static function send_template( WC_Order $order, $template, $lang, $var_keys, $header_text = false, $to_key = '' ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'pg_wa', __( 'WhatsApp Cloud API is not configured.', 'product-glide' ) );
		}
		$to = $to_key ? PG_WA_Inbox::key( $to_key ) : PG_Helpers::bd_phone( $order->get_billing_phone() );
		if ( ! $to ) {
			return new WP_Error( 'pg_wa', __( 'Customer phone is not a valid BD mobile.', 'product-glide' ) );
		}
		$vars   = self::vars_for( $order );
		$keys   = array_filter( array_map( 'trim', is_array( $var_keys ) ? $var_keys : explode( ',', (string) $var_keys ) ) );
		$params = array();
		foreach ( $keys as $k ) {
			$text     = isset( $vars[ $k ] ) ? $vars[ $k ] : $k;
			$params[] = array( 'type' => 'text', 'text' => '' === trim( $text ) ? '-' : $text );
		}
		$components = array();
		if ( $header_text && $params ) {
			$components[] = array( 'type' => 'header', 'parameters' => array( $params[0] ) );
		}
		if ( $params ) {
			$components[] = array( 'type' => 'body', 'parameters' => $params );
		}
		$body = array(
			'messaging_product' => 'whatsapp',
			'to'                => '88' . $to,
			'type'              => 'template',
			'template'          => array(
				'name'     => $template,
				'language' => array( 'code' => $lang ? $lang : PG_Settings::get( 'wa_lang', 'bn' ) ),
			),
		);
		if ( $components ) {
			$body['template']['components'] = $components;
		}
		$body['to'] = PG_WA_Inbox::wa_to( $to );
		$res        = self::call( 'POST', rawurlencode( PG_Settings::get( 'wa_phone_id' ) ) . '/messages', $body );
		$texts      = wp_list_pluck( $params, 'text' );
		$lang_code  = $body['template']['language']['code'];
		if ( is_wp_error( $res ) ) {
			$order->add_order_note( 'WhatsApp send failed (' . $template . '): ' . $res->get_error_message() );
			PG_WA_Inbox::log( array( 'phone' => $to, 'type' => 'template', 'template' => $template, 'body' => self::render_body( $template, $lang_code, $texts ), 'status' => 'failed', 'error' => $res->get_error_message(), 'order_id' => $order->get_id(), 'user_id' => self::$auto ? 0 : get_current_user_id() ) );
			return $res;
		}
		$mid = isset( $res['messages'][0]['id'] ) ? $res['messages'][0]['id'] : '';
		$order->add_order_note( sprintf( 'WhatsApp template "%s" sent to %s. %s', $template, $to, $mid ) );
		PG_WA_Inbox::log( array( 'wa_mid' => $mid, 'phone' => $to, 'type' => 'template', 'template' => $template, 'body' => self::render_body( $template, $lang_code, $texts ), 'order_id' => $order->get_id(), 'user_id' => self::$auto ? 0 : get_current_user_id() ) );
		if ( '' === (string) $order->get_meta( '_pg_wa_state' ) || 'failed' === $order->get_meta( '_pg_wa_state' ) ) {
			$order->update_meta_data( '_pg_wa_state', 'sent' );
			$order->save_meta_data();
		}
		return $res;
	}

	public static function on_status( $order_id, $from, $to, $order ) {
		if ( ! PG_Settings::get( 'wa_auto', 0 ) || ! self::configured() || ! $order ) {
			return;
		}
		$trigger = $to;
		// The courier cancelled the parcel = delivery failed, not "your order is cancelled".
		if ( 'cancelled' === $to && self::courier_failed( $order_id ) ) {
			$trigger = 'courier_failed';
		}
		self::send_trigger( $order, $trigger );
	}

	/** Was the latest courier parcel of this order cancelled by the courier? */
	public static function courier_failed( $order_id ) {
		global $wpdb;
		$t = PG_Install::table( 'consignments' );
		$s = $wpdb->get_var( $wpdb->prepare( "SELECT courier_status FROM {$t} WHERE order_id = %d AND consignment_id <> '' ORDER BY id DESC LIMIT 1", $order_id ) ); // phpcs:ignore
		return 'cancelled' === $s;
	}

	/* AJAX */

	public static function ajax_templates() {
		PG_Helpers::guard_ajax();
		$t = self::templates( (bool) PG_Helpers::post_int( 'force' ) );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( array( 'message' => $t->get_error_message() ) );
		}
		wp_send_json_success( array( 'templates' => $t ) );
	}

	public static function ajax_save_map() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$rows  = PG_Helpers::post_json( 'rows' );
		$t     = PG_Install::table( 'wa_templates' );
		$valid = array_map(
			function ( $s ) {
				return substr( $s, 3 );
			},
			array_keys( wc_get_order_statuses() )
		);
		$valid = array_merge( $valid, array_keys( self::EVENTS ) );
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$status = sanitize_key( isset( $r['trigger_status'] ) ? $r['trigger_status'] : '' );
			$name   = sanitize_text_field( isset( $r['template_name'] ) ? $r['template_name'] : '' );
			if ( ! $name ) {
				continue;
			}
			$vars = array_filter(
				array_map( 'sanitize_key', explode( ',', isset( $r['variables'] ) ? $r['variables'] : '' ) ),
				function ( $v ) {
					return in_array( $v, self::VARS, true );
				}
			);
			$wpdb->insert(
				$t,
				array(
					'trigger_status'  => in_array( $status, $valid, true ) ? $status : 'manual',
					'template_name'   => $name,
					'language'        => sanitize_text_field( isset( $r['language'] ) ? $r['language'] : 'bn' ),
					'variables'       => implode( ',', $vars ),
					'has_header_text' => empty( $r['has_header_text'] ) ? 0 : 1,
					'enabled'         => empty( $r['enabled'] ) ? 0 : 1,
				)
			);
		}
		wp_send_json_success( array( 'message' => __( 'Mappings saved.', 'product-glide' ), 'rows' => self::mappings() ) );
	}

	public static function ajax_send() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		$map_id = PG_Helpers::post_int( 'map_id' );
		$m      = null;
		foreach ( self::mappings() as $row ) {
			if ( ( $map_id && (int) $row->id === $map_id ) || ( ! $map_id && 'manual' === $row->trigger_status ) ) {
				$m = $row;
				break;
			}
		}
		if ( ! $m ) {
			$all = self::mappings();
			$m   = $all ? $all[0] : null;
		}
		if ( ! $m ) {
			wp_send_json_error( array( 'message' => __( 'Add a template mapping in Settings → WhatsApp first.', 'product-glide' ) ) );
		}
		$res = self::send_template( $order, $m->template_name, $m->language, $m->variables, (bool) $m->has_header_text );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'WhatsApp message sent.', 'product-glide' ) ) );
	}

	/** Mapping editor shown under Settings → WhatsApp. */
	public static function render_mapping_ui() {
		$rows     = self::mappings();
		$statuses = array( 'manual' => __( 'Manual button only', 'product-glide' ) ) + self::EVENTS;
		foreach ( wc_get_order_statuses() as $k => $l ) {
			$statuses[ substr( $k, 3 ) ] = $l;
		}
		?>
		<div class="pg-card" id="pg-wa-map" data-rows="<?php echo esc_attr( wp_json_encode( $rows ) ); ?>" data-statuses="<?php echo esc_attr( wp_json_encode( $statuses ) ); ?>">
			<div class="pg-card-head">
				<h2><?php esc_html_e( 'Template mappings', 'product-glide' ); ?></h2>
				<button type="button" class="pg-btn pg-btn-ghost" id="pg-wa-load"><?php esc_html_e( 'Load Approved Templates', 'product-glide' ); ?></button>
			</div>
			<p class="pg-muted"><?php esc_html_e( 'Variables (comma-separated, in {{1}}, {{2}}… order): name, order_id, items, total (with টাকা), amount (number only), address, phone, tracking. Use exactly as many as the template has {{…}}.', 'product-glide' ); ?></p>
			<div id="pg-wa-rows"></div>
			<div class="pg-form-actions">
				<button type="button" class="pg-btn pg-btn-ghost" id="pg-wa-add">+ <?php esc_html_e( 'Add mapping', 'product-glide' ); ?></button>
				<button type="button" class="pg-btn pg-btn-primary" id="pg-wa-save"><?php esc_html_e( 'Save mappings', 'product-glide' ); ?></button>
			</div>
		</div>
		<?php
	}
}
