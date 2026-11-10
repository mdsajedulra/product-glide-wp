<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-click WhatsApp setup: paste the permanent token + App Secret, and Product Glide
 * finds the WABA and phone number, connects the webhook, creates the order-confirmation
 * template and switches the new-order message on once Meta approves it.
 */
class PG_WA_Setup {


	public static function init() {
		foreach ( array( 'connect', 'status', 'make_tpl', 'test' ) as $a ) {
			add_action( 'wp_ajax_pg_wa_setup_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		// While the button version waits for Meta, check every hour and switch over when approved.
		add_action( 'pg_wa_tpl_check', array( __CLASS__, 'cron_check' ) );
		if ( ( get_option( 'pg_wa_tpl_pending' ) || get_option( 'pg_wa_v2_pending' ) ) && ! wp_next_scheduled( 'pg_wa_tpl_check' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'pg_wa_tpl_check' );
		}
	}

	private static function g( $method, $path, $token, $body = null ) {
		$url = 'https://graph.facebook.com/' . rawurlencode( PG_Settings::get( 'wa_api_version', 'v21.0' ) ) . '/' . ltrim( $path, '/' );
		$res = PG_Helpers::remote_json( $method, $url, array( 'Authorization' => 'Bearer ' . $token ), $body, 25 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] >= 400 || isset( $res['data']['error'] ) ) {
			$e = isset( $res['data']['error'] ) ? $res['data']['error'] : array();
			$m = isset( $e['error_user_msg'] ) ? $e['error_user_msg'] : ( isset( $e['message'] ) ? $e['message'] : 'HTTP ' . $res['code'] );
			return new WP_Error( 'pg_wa', $m );
		}
		return $res['data'];
	}

	private static function save( array $pairs ) {
		$s = get_option( 'pg_settings', array() );
		foreach ( $pairs as $k => $v ) {
			$s[ $k ] = $v;
		}
		update_option( 'pg_settings', $s, false );
		PG_Settings::flush();
	}

	/** Look inside the token: app, expiry, permissions and the WhatsApp accounts it can use. */
	private static function inspect( $token ) {
		$d = self::g( 'GET', 'debug_token?input_token=' . rawurlencode( $token ), $token );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$d = isset( $d['data'] ) ? $d['data'] : array();
		if ( empty( $d['is_valid'] ) ) {
			return new WP_Error( 'pg_wa', __( 'This token is not valid (expired or deleted). Generate a new one.', 'product-glide' ) );
		}
		$scopes = isset( $d['scopes'] ) ? (array) $d['scopes'] : array();
		$miss   = array_diff( array( 'whatsapp_business_messaging', 'whatsapp_business_management' ), $scopes );
		$wabas  = array();
		foreach ( isset( $d['granular_scopes'] ) ? (array) $d['granular_scopes'] : array() as $gs ) {
			if ( 'whatsapp_business_management' === $gs['scope'] && ! empty( $gs['target_ids'] ) ) {
				$wabas = array_merge( $wabas, (array) $gs['target_ids'] );
			}
		}
		return array(
			'app_id'  => isset( $d['app_id'] ) ? (string) $d['app_id'] : '',
			'expires' => isset( $d['expires_at'] ) ? (int) $d['expires_at'] : 0,
			'missing' => array_values( $miss ),
			'wabas'   => array_values( array_unique( $wabas ) ),
		);
	}

	/* ------------------------------------------------------------------ */

	public static function ajax_connect() {
		PG_Helpers::guard_ajax( true );
		$token  = trim( (string) PG_Helpers::post( 'token', '' ) );
		$secret = trim( (string) PG_Helpers::post( 'secret', '' ) );
		$token  = '' === $token ? (string) PG_Settings::get( 'wa_token', '' ) : $token;
		$secret = '' === $secret ? (string) PG_Settings::get( 'wa_app_secret', '' ) : $secret;
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Paste the access token first.', 'product-glide' ) ) );
		}
		$steps = array();
		$info  = self::inspect( $token );
		if ( is_wp_error( $info ) ) {
			wp_send_json_error( array( 'message' => $info->get_error_message() ) );
		}
		if ( $info['missing'] ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'The token is missing these permissions: %s. Generate it again with both WhatsApp permissions ticked.', 'product-glide' ), implode( ', ', $info['missing'] ) ) ) );
		}
		$steps[] = array( 'ok', $info['expires'] ? sprintf( 'Token works — but it expires on %s. Use a System User token with "Never" expiry.', PG_Helpers::bd_time( $info['expires'], 'd M Y' ) ) : 'Token works and never expires' );

		// WhatsApp account + phone number.
		$waba  = (string) PG_Helpers::post( 'waba', '' );
		$wabas = $info['wabas'];
		if ( ! $wabas && PG_Settings::get( 'wa_waba_id', '' ) ) {
			$wabas = array( (string) PG_Settings::get( 'wa_waba_id' ) );
		}
		if ( ! $wabas ) {
			wp_send_json_error( array( 'message' => __( 'The token has no WhatsApp account attached. In Business Settings → System users → Assign assets, add your WhatsApp account with Full control, then generate the token again.', 'product-glide' ) ) );
		}
		$numbers = array();
		foreach ( $wabas as $w ) {
			$r = self::g( 'GET', rawurlencode( $w ) . '/phone_numbers?fields=id,display_phone_number,verified_name,quality_rating,code_verification_status,name_status', $token );
			if ( is_wp_error( $r ) ) {
				continue;
			}
			foreach ( isset( $r['data'] ) ? $r['data'] : array() as $n ) {
				$numbers[] = array(
					'waba'  => $w,
					'id'    => (string) $n['id'],
					'phone' => isset( $n['display_phone_number'] ) ? $n['display_phone_number'] : '',
					'name'  => isset( $n['verified_name'] ) ? $n['verified_name'] : '',
				);
			}
		}
		if ( ! $numbers ) {
			wp_send_json_error( array( 'message' => __( 'No phone number found in this WhatsApp account.', 'product-glide' ) ) );
		}
		$pick = (string) PG_Helpers::post( 'phone_id', '' );
		$num  = null;
		foreach ( $numbers as $n ) {
			if ( $pick === $n['id'] || ( '' === $pick && 1 === count( $numbers ) ) ) {
				$num = $n;
			}
		}
		if ( ! $num ) {
			self::save( array( 'wa_token' => $token, 'wa_app_secret' => $secret ) );
			wp_send_json_success( array( 'choose' => $numbers, 'steps' => $steps ) );
		}
		self::save(
			array(
				'wa_token'      => $token,
				'wa_app_secret' => $secret,
				'wa_phone_id'   => $num['id'],
				'wa_waba_id'    => $num['waba'],
				'wa_app_id'     => $info['app_id'],
			)
		);
		$steps[] = array( 'ok', sprintf( 'Number found: %s (%s)', $num['phone'], $num['name'] ) );

		// Let this app receive the account's messages.
		$sub     = self::g( 'POST', rawurlencode( $num['waba'] ) . '/subscribed_apps', $token );
		$steps[] = is_wp_error( $sub ) ? array( 'bad', 'Could not subscribe the app to the WhatsApp account: ' . $sub->get_error_message() ) : array( 'ok', 'App subscribed to the WhatsApp account' );

		// Point the app webhook at Product Glide (needs the App Secret for an app token).
		if ( '' !== $secret && $info['app_id'] ) {
			delete_option( 'pg_wa_webhook_ok' );
			$app_token = $info['app_id'] . '|' . $secret;
			$q         = http_build_query(
				array(
					'object'       => 'whatsapp_business_account',
					'callback_url' => PG_WA_Inbox::webhook_url(),
					'verify_token' => PG_WA_Inbox::verify_token(),
					'fields'       => 'messages',
				)
			);
			$hook = self::g( 'POST', rawurlencode( $info['app_id'] ) . '/subscriptions?' . $q, $app_token );
			if ( is_wp_error( $hook ) ) {
				$steps[] = array( 'bad', 'Webhook not connected: ' . $hook->get_error_message() );
			} else {
				update_option( 'pg_wa_webhook_ok', time(), false );
				$steps[] = array( 'ok', 'Webhook connected — replies and delivery status will come here' );
			}
		} else {
			$steps[] = array( 'bad', 'Add the App Secret to connect the webhook automatically' );
		}
		delete_transient( 'pg_wa_templates' );
		wp_send_json_success( array( 'steps' => $steps, 'status' => self::status() ) );
	}

	/* ------------------------------------------------------------------ */
	/* The template set                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Every message Product Glide sends, as Meta templates. Generic wording ("প্রোডাক্ট") and the
	 * shop name as a variable, so the same set works for every shop that uses this number.
	 */
	public static function defs() {
		$yes_no = array( 'কনফার্ম করছি', 'বাতিল করতে চাই' );
		return array(
			'new'       => array(
				'name'    => 'pg_order_new',
				'label'   => 'New order',
				'trigger' => 'new_order',
				'vars'    => array( 'name', 'brand', 'order_id', 'items', 'total' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\n{{2}} থেকে অর্ডার করার জন্য ধন্যবাদ।\n\nঅর্ডার নম্বর: #{{3}}\nপ্রোডাক্ট: {{4}}\nমোট: {{5}}\n\nঅর্ডারটি কনফার্ম করতে নিচের বাটনে চাপুন। ঠিকানা বা অর্ডারে কিছু বদলাতে চাইলে এই চ্যাটেই লিখুন।",
				'buttons' => $yes_no,
			),
			'reminder'  => array(
				'name'    => 'pg_order_reminder',
				'label'   => 'Confirmation reminder',
				'trigger' => 'reminder',
				'vars'    => array( 'name', 'order_id', 'items' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nআপনার অর্ডার #{{2}} ({{3}}) এখনো কনফার্ম হয়নি। কনফার্ম না হলে আমরা অর্ডারটি পাঠাতে পারছি না। নিচের বাটনে চাপুন অথবা এই চ্যাটে জানান।",
				'buttons' => $yes_no,
			),
			'call'      => array(
				'name'    => 'pg_call_missed',
				'label'   => 'Call not received',
				'trigger' => 'call_missed',
				'vars'    => array( 'name', 'order_id' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nআপনার অর্ডার #{{2}} কনফার্ম করার জন্য আমরা আপনাকে কল করেছিলাম, কিন্তু পাইনি। অর্ডারটি পেতে চাইলে নিচের বাটনে চাপুন, অথবা কখন কল করলে সুবিধা হয় এই চ্যাটে জানান।",
				'buttons' => $yes_no,
			),
			'shipped'   => array(
				'name'    => 'pg_order_shipped',
				'label'   => 'Sent to courier',
				'trigger' => 'courier_sent',
				'vars'    => array( 'name', 'order_id', 'courier', 'tracking', 'cod' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nআপনার অর্ডার #{{2}} কুরিয়ারে পাঠানো হয়েছে।\n\nকুরিয়ার: {{3}}\nট্র্যাকিং কোড: {{4}}\nডেলিভারির সময় পরিশোধ করবেন: {{5}}\n\nডেলিভারিম্যান কল করলে অনুগ্রহ করে ফোনটি রিসিভ করবেন।",
				'buttons' => array(),
			),
			'resched'   => array(
				'name'    => 'pg_resched_due',
				'label'   => 'Rescheduled date arrived',
				'trigger' => 'resched_due',
				'vars'    => array( 'name', 'order_id', 'items' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nআপনি অর্ডার #{{2}} ({{3}}) এই সময়ে পাঠাতে বলেছিলেন। এখন পাঠিয়ে দেব? নিচের বাটনে জানান।",
				'buttons' => array( 'এখন পাঠান', 'আরও পরে' ),
			),
			'failed'    => array(
				'name'    => 'pg_delivery_failed',
				'label'   => 'Delivery failed',
				'trigger' => 'courier_failed',
				'vars'    => array( 'name', 'order_id' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nকুরিয়ার জানিয়েছে আপনার অর্ডার #{{2}} ডেলিভারি করা যায়নি। আপনি কি আবার পাঠাতে বলবেন? নিচের বাটনে জানান অথবা সুবিধামতো সময় এই চ্যাটে লিখুন।",
				'buttons' => array( 'আবার পাঠান', 'দরকার নেই' ),
			),
			'delivered' => array(
				'name'    => 'pg_order_delivered',
				'label'   => 'Delivered',
				'trigger' => 'completed',
				'vars'    => array( 'name', 'order_id', 'brand' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nআপনার অর্ডার #{{2}} ডেলিভারি সম্পন্ন হয়েছে। {{3}} থেকে কেনাকাটার জন্য ধন্যবাদ। প্রোডাক্ট নিয়ে কোনো সমস্যা বা প্রশ্ন থাকলে এই চ্যাটেই জানাবেন।",
				'buttons' => array(),
			),
			'cancelled' => array(
				'name'    => 'pg_order_cancelled',
				'label'   => 'Order cancelled',
				'trigger' => 'cancelled',
				'vars'    => array( 'name', 'order_id' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nআপনার অর্ডার #{{2}} বাতিল করা হয়েছে। ভুল করে বাতিল হয়ে থাকলে বা আবার অর্ডার করতে চাইলে এই চ্যাটে জানান।",
				'buttons' => array(),
			),
			'advance'   => array(
				'name'    => 'pg_advance_payment',
				'label'   => 'Advance payment request (manual)',
				'trigger' => 'manual',
				'vars'    => array( 'name', 'order_id', 'shipping', 'pay_number' ),
				'text'    => "আসসালামু আলাইকুম {{1}},\nঅর্ডার #{{2}} কনফার্ম করতে ডেলিভারি চার্জ {{3}} অগ্রিম পাঠাতে হবে।\nবিকাশ/নগদ (পার্সোনাল): {{4}}\nপাঠানোর পর ট্রানজেকশন আইডি এই চ্যাটে লিখুন।",
				'buttons' => array(),
			),
		);
	}

	/** Realistic sample values Meta needs for review. */
	public static function sample( $var ) {
		$brand = PG_WhatsApp::brand();
		$map   = array(
			'name'       => 'রহিম',
			'brand'      => $brand,
			'order_id'   => '1234',
			'items'      => 'প্রোডাক্ট এ x1, প্রোডাক্ট বি x2',
			'total'      => '৯৫০ টাকা',
			'amount'     => '৯৫০',
			'cod'        => '৯৫০ টাকা',
			'shipping'   => '১২০ টাকা',
			'courier'    => 'Steadfast',
			'tracking'   => 'SFR123456',
			'address'    => 'বাসা ১২, রোড ৫, মিরপুর, ঢাকা',
			'phone'      => '01711000000',
			'pay_number' => '01711000000',
		);
		return isset( $map[ $var ] ) ? $map[ $var ] : '-';
	}

	private static function components( $d ) {
		$c = array(
			array(
				'type'    => 'BODY',
				'text'    => $d['text'],
				'example' => array( 'body_text' => array( array_map( array( __CLASS__, 'sample' ), $d['vars'] ) ) ),
			),
		);
		if ( $d['buttons'] ) {
			$c[] = array(
				'type'    => 'BUTTONS',
				'buttons' => array_map(
					function ( $t ) {
						return array( 'type' => 'QUICK_REPLY', 'text' => $t );
					},
					$d['buttons']
				),
			);
		}
		return $c;
	}

	/** name => { status, reason } for every template in the WhatsApp account. */
	private static function remote_templates() {
		$out  = array();
		$path = rawurlencode( PG_Settings::get( 'wa_waba_id' ) ) . '/message_templates?limit=100&fields=name,status,language,rejected_reason';
		$r    = self::g( 'GET', $path, PG_Settings::get( 'wa_token' ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		foreach ( isset( $r['data'] ) ? $r['data'] : array() as $t ) {
			$out[ $t['name'] ] = array(
				'status' => strtolower( $t['status'] ),
				'reason' => isset( $t['rejected_reason'] ) && 'NONE' !== $t['rejected_reason'] ? $t['rejected_reason'] : '',
				'lang'   => isset( $t['language'] ) ? $t['language'] : 'bn',
			);
		}
		return $out;
	}

	/** Send every template that is not in the account yet. */
	public static function create_all() {
		if ( ! PG_WhatsApp::configured() ) {
			return new WP_Error( 'pg_wa', __( 'Connect WhatsApp first.', 'product-glide' ) );
		}
		$have = self::remote_templates();
		if ( is_wp_error( $have ) ) {
			return $have;
		}
		$res = array();
		foreach ( self::defs() as $key => $d ) {
			if ( isset( $have[ $d['name'] ] ) ) {
				$res[ $key ] = 'exists';
				continue;
			}
			$r = self::g(
				'POST',
				rawurlencode( PG_Settings::get( 'wa_waba_id' ) ) . '/message_templates',
				PG_Settings::get( 'wa_token' ),
				array(
					'name'       => $d['name'],
					'language'   => 'bn',
					'category'   => 'UTILITY',
					'components' => self::components( $d ),
				)
			);
			$res[ $key ] = is_wp_error( $r ) ? 'error: ' . $r->get_error_message() : strtolower( isset( $r['status'] ) ? $r['status'] : 'pending' );
		}
		update_option( 'pg_wa_tpl_pending', time(), false );
		if ( ! wp_next_scheduled( 'pg_wa_tpl_check' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'pg_wa_tpl_check' );
		}
		return $res;
	}

	/** Approved template → it becomes the message for its trigger (replacing whatever was there). */
	private static function use_def( $d, $lang ) {
		global $wpdb;
		$t    = PG_Install::table( 'wa_templates' );
		$vars = implode( ',', $d['vars'] );
		$mine = null;
		foreach ( PG_WhatsApp::mappings() as $row ) {
			if ( $row->template_name === $d['name'] ) {
				$mine = $row;
			} elseif ( 'manual' !== $d['trigger'] && $row->trigger_status === $d['trigger'] ) {
				$wpdb->delete( $t, array( 'id' => $row->id ) ); // phpcs:ignore -- replaced by the new template
			}
		}
		$data = array( 'trigger_status' => $d['trigger'], 'template_name' => $d['name'], 'language' => $lang, 'variables' => $vars, 'has_header_text' => 0, 'enabled' => 1 );
		if ( $mine ) {
			if ( $mine->variables !== $vars || $mine->trigger_status !== $d['trigger'] ) {
				$wpdb->update( $t, $data, array( 'id' => $mine->id ) ); // phpcs:ignore
			}
		} else {
			$wpdb->insert( $t, $data ); // phpcs:ignore
			delete_transient( 'pg_wa_templates' );
		}
	}

	/** Current state for the setup card (also applies newly approved templates). */
	public static function status() {
		$out = array(
			'connected' => PG_WhatsApp::configured(),
			'hook'      => (bool) get_option( 'pg_wa_webhook_ok' ),
			'auto'      => (bool) PG_Settings::get( 'wa_auto', 0 ),
			'phone'     => '',
			'templates' => array(),
		);
		if ( ! $out['connected'] ) {
			return $out;
		}
		$n = self::g( 'GET', rawurlencode( PG_Settings::get( 'wa_phone_id' ) ) . '?fields=display_phone_number,verified_name', PG_Settings::get( 'wa_token' ) );
		if ( ! is_wp_error( $n ) ) {
			$out['phone'] = trim( ( isset( $n['display_phone_number'] ) ? $n['display_phone_number'] : '' ) . ' · ' . ( isset( $n['verified_name'] ) ? $n['verified_name'] : '' ), ' ·' );
		}
		$have    = self::remote_templates();
		$pending = 0;
		$labels  = array( 'manual' => 'Manual (Send WA button)' ) + PG_WhatsApp::EVENTS;
		foreach ( wc_get_order_statuses() as $k => $l ) {
			$labels[ substr( $k, 3 ) ] = 'Status: ' . $l;
		}
		foreach ( self::defs() as $key => $d ) {
			$st = is_wp_error( $have ) ? 'unknown' : ( isset( $have[ $d['name'] ] ) ? $have[ $d['name'] ]['status'] : 'missing' );
			if ( 'approved' === $st ) {
				self::use_def( $d, $have[ $d['name'] ]['lang'] );
			}
			if ( in_array( $st, array( 'pending', 'in_review' ), true ) ) {
				++$pending;
			}
			$out['templates'][] = array(
				'key'     => $key,
				'label'   => $d['label'],
				'name'    => $d['name'],
				'when'    => isset( $labels[ $d['trigger'] ] ) ? $labels[ $d['trigger'] ] : $d['trigger'],
				'status'  => $st,
				'reason'  => ! is_wp_error( $have ) && isset( $have[ $d['name'] ] ) ? $have[ $d['name'] ]['reason'] : '',
				'preview' => str_replace( array_map( function ( $i ) { return '{{' . $i . '}}'; }, range( 1, count( $d['vars'] ) ) ), array_map( array( __CLASS__, 'sample' ), $d['vars'] ), $d['text'] ),
				'buttons' => $d['buttons'],
			);
		}
		$out['pending'] = $pending;
		$out['missing'] = count( array_filter( $out['templates'], function ( $t ) { return 'missing' === $t['status']; } ) );
		if ( ! $pending ) {
			delete_option( 'pg_wa_tpl_pending' );
			delete_option( 'pg_wa_v2_pending' );
			wp_clear_scheduled_hook( 'pg_wa_tpl_check' );
		}
		return $out;
	}

	public static function cron_check() {
		self::status();
	}

	public static function ajax_status() {
		PG_Helpers::guard_ajax( true );
		wp_send_json_success( array( 'status' => self::status() ) );
	}

	public static function ajax_make_tpl() {
		PG_Helpers::guard_ajax( true );
		$res = self::create_all();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$errors = array_filter( $res, function ( $r ) { return 0 === strpos( $r, 'error' ); } );
		wp_send_json_success(
			array(
				'message' => $errors ? implode( ' | ', $errors ) : __( 'Templates sent to Meta for approval (minutes to a few hours). Each one switches on by itself when approved; until then the current messages keep going out.', 'product-glide' ),
				'status'  => self::status(),
			)
		);
	}

	/** Send the new-order template with sample values to a phone, to see it arrive and test the buttons. */
	public static function ajax_test() {
		PG_Helpers::guard_ajax( true );
		$to = PG_Helpers::bd_phone( PG_Helpers::post( 'phone', '' ) );
		$m  = PG_WhatsApp::new_order_map();
		if ( ! $to || ! $m ) {
			wp_send_json_error( array( 'message' => $to ? __( 'No approved new-order template yet.', 'product-glide' ) : __( 'Enter a valid BD mobile number.', 'product-glide' ) ) );
		}
		$params = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $m->variables ) ) ) as $v ) {
			$params[] = 'name' === $v ? 'টেস্ট কাস্টমার' : ( 'order_id' === $v ? 'TEST' : self::sample( $v ) );
		}
		$body = array(
			'messaging_product' => 'whatsapp',
			'to'                => '88' . $to,
			'type'              => 'template',
			'template'          => array(
				'name'     => $m->template_name,
				'language' => array( 'code' => $m->language ),
			),
		);
		if ( $params ) {
			$body['template']['components'] = array( array( 'type' => 'body', 'parameters' => array_map( function ( $p ) { return array( 'type' => 'text', 'text' => $p ); }, $params ) ) );
		}
		$res = PG_WhatsApp::api( 'POST', rawurlencode( PG_Settings::get( 'wa_phone_id' ) ) . '/messages', $body );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		PG_WA_Inbox::log(
			array(
				'wa_mid'   => isset( $res['messages'][0]['id'] ) ? $res['messages'][0]['id'] : '',
				'phone'    => $to,
				'type'     => 'template',
				'template' => $m->template_name,
				'body'     => PG_WhatsApp::render_body( $m->template_name, $m->language, $params ),
			)
		);
		wp_send_json_success( array( 'message' => __( 'Test message sent. Reply to it from that phone — the reply will appear in the WhatsApp inbox.', 'product-glide' ) ) );
	}

	/** The setup card on Settings → WhatsApp. */
	public static function render() {
		$st = PG_WhatsApp::configured();
		?>
		<div class="pg-card pg-wa-quick" id="pg-wa-quick">
			<div class="pg-card-head"><h2><?php esc_html_e( 'Quick connect', 'product-glide' ); ?></h2><span class="pg-muted"><?php esc_html_e( 'Paste two things — Product Glide does the rest', 'product-glide' ); ?></span></div>
			<ol class="pg-wa-q-steps">
				<li><b><?php esc_html_e( 'Permanent access token', 'product-glide' ); ?></b> <span class="pg-muted">Business Settings → Users → System users → Generate new token (expiry: Never, both WhatsApp permissions)</span>
					<input type="password" id="pg-wa-q-token" autocomplete="off" placeholder="<?php echo esc_attr( $st ? __( 'Saved — paste a new one only to replace it', 'product-glide' ) : 'EAAG…' ); ?>"></li>
				<li><b><?php esc_html_e( 'App Secret', 'product-glide' ); ?></b> <span class="pg-muted">developers.facebook.com → your app → App settings → Basic → App secret → Show</span>
					<input type="password" id="pg-wa-q-secret" autocomplete="off" placeholder="<?php echo esc_attr( PG_Settings::get( 'wa_app_secret', '' ) ? __( 'Saved', 'product-glide' ) : '' ); ?>"></li>
			</ol>
			<div id="pg-wa-q-choose"></div>
			<div class="pg-form-actions"><button type="button" class="pg-btn pg-btn-primary" id="pg-wa-q-go"><?php echo esc_html( $st ? __( 'Reconnect & check', 'product-glide' ) : __( 'Connect WhatsApp', 'product-glide' ) ); ?></button></div>
			<ul class="pg-wa-q-list" id="pg-wa-q-out"></ul>
			<div id="pg-wa-q-status"><?php if ( $st ) : ?><p class="pg-muted"><?php esc_html_e( 'Checking…', 'product-glide' ); ?></p><?php endif; ?></div>
		</div>
		<?php
	}
}
