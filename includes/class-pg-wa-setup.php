<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-click WhatsApp setup: paste the permanent token + App Secret, and Product Glide
 * finds the WABA and phone number, connects the webhook, creates the order-confirmation
 * template and switches the new-order message on once Meta approves it.
 */
class PG_WA_Setup {

	const TPL = 'pg_order_confirmation';

	public static function init() {
		foreach ( array( 'connect', 'status', 'make_tpl', 'test' ) as $a ) {
			add_action( 'wp_ajax_pg_wa_setup_' . $a, array( __CLASS__, 'ajax_' . $a ) );
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

	/** Current state for the setup card. */
	public static function status() {
		$out = array(
			'connected' => PG_WhatsApp::configured(),
			'hook'      => (bool) get_option( 'pg_wa_webhook_ok' ),
			'auto'      => (bool) PG_Settings::get( 'wa_auto', 0 ),
			'map'       => (bool) PG_WhatsApp::new_order_map(),
			'tpl'       => '',
			'tpl_name'  => '',
			'phone'     => '',
		);
		if ( ! $out['connected'] ) {
			return $out;
		}
		$token = PG_Settings::get( 'wa_token' );
		$n     = self::g( 'GET', rawurlencode( PG_Settings::get( 'wa_phone_id' ) ) . '?fields=display_phone_number,verified_name', $token );
		if ( ! is_wp_error( $n ) ) {
			$out['phone'] = trim( ( isset( $n['display_phone_number'] ) ? $n['display_phone_number'] : '' ) . ' · ' . ( isset( $n['verified_name'] ) ? $n['verified_name'] : '' ), ' ·' );
		}
		// Our template (any status) — or any approved one already mapped.
		$t = self::g( 'GET', rawurlencode( PG_Settings::get( 'wa_waba_id' ) ) . '/message_templates?name=' . self::TPL . '&fields=name,status,language,rejected_reason', $token );
		if ( ! is_wp_error( $t ) && ! empty( $t['data'][0] ) ) {
			$out['tpl']      = strtolower( $t['data'][0]['status'] );
			$out['tpl_name'] = self::TPL;
			if ( isset( $t['data'][0]['rejected_reason'] ) && 'NONE' !== $t['data'][0]['rejected_reason'] ) {
				$out['tpl_reason'] = $t['data'][0]['rejected_reason'];
			}
			// Approved → switch the new-order message on, once.
			if ( 'approved' === $out['tpl'] && ! $out['map'] ) {
				global $wpdb;
				$wpdb->insert( // phpcs:ignore
					PG_Install::table( 'wa_templates' ),
					array(
						'trigger_status'  => 'new_order',
						'template_name'   => self::TPL,
						'language'        => isset( $t['data'][0]['language'] ) ? $t['data'][0]['language'] : 'bn',
						'variables'       => 'name,order_id,total',
						'has_header_text' => 0,
						'enabled'         => 1,
					)
				);
				$wpdb->insert( // phpcs:ignore
					PG_Install::table( 'wa_templates' ),
					array(
						'trigger_status'  => 'manual',
						'template_name'   => self::TPL,
						'language'        => isset( $t['data'][0]['language'] ) ? $t['data'][0]['language'] : 'bn',
						'variables'       => 'name,order_id,total',
						'has_header_text' => 0,
						'enabled'         => 1,
					)
				);
				self::save( array( 'wa_auto' => 1 ) );
				delete_transient( 'pg_wa_templates' );
				$out['map']  = true;
				$out['auto'] = true;
			}
		} elseif ( $out['map'] ) {
			$m               = PG_WhatsApp::new_order_map();
			$out['tpl']      = 'approved';
			$out['tpl_name'] = $m->template_name;
		}
		return $out;
	}

	public static function ajax_status() {
		PG_Helpers::guard_ajax( true );
		wp_send_json_success( array( 'status' => self::status() ) );
	}

	public static function template_text() {
		$brand = trim( (string) PG_Settings::get( 'brand_name', '' ) );
		$brand = '' !== $brand ? $brand : get_bloginfo( 'name' );
		return "আসসালামু আলাইকুম {{1}},\n" . $brand . " থেকে অর্ডার করার জন্য ধন্যবাদ।\n\nআপনার অর্ডার নম্বর: #{{2}}\nমোট: {{3}}\n\nঅর্ডারটি কনফার্ম করতে নিচের বাটনে চাপুন বা \"জি\" লিখে রিপ্লাই দিন। ঠিকানা বা অর্ডারে কিছু বদলাতে চাইলে এখানেই জানান।";
	}

	public static function ajax_make_tpl() {
		PG_Helpers::guard_ajax( true );
		if ( ! PG_WhatsApp::configured() ) {
			wp_send_json_error( array( 'message' => __( 'Connect WhatsApp first.', 'product-glide' ) ) );
		}
		$text = self::template_text();
		$res  = self::g(
			'POST',
			rawurlencode( PG_Settings::get( 'wa_waba_id' ) ) . '/message_templates',
			PG_Settings::get( 'wa_token' ),
			array(
				'name'       => self::TPL,
				'language'   => 'bn',
				'category'   => 'UTILITY',
				'components' => array(
					array(
						'type'    => 'BODY',
						'text'    => $text,
						'example' => array( 'body_text' => array( array( 'রহিম', '1234', '৯৫০ টাকা' ) ) ),
					),
					array(
						'type'    => 'BUTTONS',
						'buttons' => array(
							array( 'type' => 'QUICK_REPLY', 'text' => 'কনফার্ম করছি' ),
							array( 'type' => 'QUICK_REPLY', 'text' => 'বাতিল করতে চাই' ),
						),
					),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Template sent to Meta for approval. It usually takes a few minutes to a few hours.', 'product-glide' ), 'status' => self::status() ) );
	}

	/** Send the template with sample values to a phone, to see it arrive and test replies. */
	public static function ajax_test() {
		PG_Helpers::guard_ajax( true );
		$to = PG_Helpers::bd_phone( PG_Helpers::post( 'phone', '' ) );
		$m  = PG_WhatsApp::new_order_map();
		if ( ! $to || ! $m ) {
			wp_send_json_error( array( 'message' => $to ? __( 'The template is not approved yet.', 'product-glide' ) : __( 'Enter a valid BD mobile number.', 'product-glide' ) ) );
		}
		$params = array( 'টেস্ট কাস্টমার', 'TEST', '৯৫০ টাকা' );
		$res    = PG_WhatsApp::api(
			'POST',
			rawurlencode( PG_Settings::get( 'wa_phone_id' ) ) . '/messages',
			array(
				'messaging_product' => 'whatsapp',
				'to'                => '88' . $to,
				'type'              => 'template',
				'template'          => array(
					'name'       => $m->template_name,
					'language'   => array( 'code' => $m->language ),
					'components' => array( array( 'type' => 'body', 'parameters' => array_map( function ( $p ) { return array( 'type' => 'text', 'text' => $p ); }, $params ) ) ),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		PG_WA_Inbox::log(
			array(
				'wa_mid'   => isset( $res['messages'][0]['id'] ) ? $res['messages'][0]['id'] : '',
				'phone'    => $to,
				'type'     => 'template',
				'template' => $m->template_name,
				'body'     => str_replace( array( '{{1}}', '{{2}}', '{{3}}' ), $params, self::template_text() ),
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
			<details class="pg-wa-q-preview"><summary><?php esc_html_e( 'Message the customer will get', 'product-glide' ); ?></summary><pre><?php echo esc_html( str_replace( array( '{{1}}', '{{2}}', '{{3}}' ), array( 'রহিম', '1234', '৯৫০ টাকা' ), self::template_text() ) ); ?></pre></details>
		</div>
		<?php
	}
}
