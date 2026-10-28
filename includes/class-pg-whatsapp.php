<?php
defined( 'ABSPATH' ) || exit;

/**
 * Meta WhatsApp Cloud API: approved templates, status-triggered and manual sends.
 *
 * Variables available for template parameters (comma-separated, in {{1}},{{2}}… order):
 *   name, order_id, items, total, tracking
 */
class PG_WhatsApp {

	const VARS = array( 'name', 'order_id', 'items', 'total', 'tracking' );

	public static function init() {
		add_action( 'wp_ajax_pg_wa_templates', array( __CLASS__, 'ajax_templates' ) );
		add_action( 'wp_ajax_pg_wa_save_map', array( __CLASS__, 'ajax_save_map' ) );
		add_action( 'wp_ajax_pg_wa_send', array( __CLASS__, 'ajax_send' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 20, 4 );
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

	public static function vars_for( WC_Order $order ) {
		$c = PG_Steadfast::active( $order->get_id() );
		return array(
			'name'     => PG_Helpers::order_name( $order ),
			'order_id' => (string) $order->get_order_number(),
			'items'    => mb_substr( PG_Helpers::items_text( $order ), 0, 900 ),
			'total'    => wc_format_decimal( $order->get_total(), 0 ) . ' টাকা',
			'tracking' => $c && $c->tracking_code ? $c->tracking_code : '-',
		);
	}

	public static function send_template( WC_Order $order, $template, $lang, $var_keys, $header_text = false ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'pg_wa', __( 'WhatsApp Cloud API is not configured.', 'product-glide' ) );
		}
		$to = PG_Helpers::bd_phone( $order->get_billing_phone() );
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
		$res = self::call( 'POST', rawurlencode( PG_Settings::get( 'wa_phone_id' ) ) . '/messages', $body );
		if ( is_wp_error( $res ) ) {
			$order->add_order_note( 'WhatsApp send failed (' . $template . '): ' . $res->get_error_message() );
			return $res;
		}
		$mid = isset( $res['messages'][0]['id'] ) ? $res['messages'][0]['id'] : '';
		$order->add_order_note( sprintf( 'WhatsApp template "%s" sent to %s. %s', $template, $to, $mid ) );
		return $res;
	}

	public static function on_status( $order_id, $from, $to, $order ) {
		if ( ! PG_Settings::get( 'wa_auto', 0 ) || ! self::configured() ) {
			return;
		}
		foreach ( self::mappings() as $m ) {
			if ( $m->enabled && $m->trigger_status === $to && $m->template_name ) {
				$key = '_pg_wa_sent_' . $to;
				if ( $order->get_meta( $key ) ) {
					continue; // Never message the same customer twice for the same status.
				}
				$res = self::send_template( $order, $m->template_name, $m->language, $m->variables, (bool) $m->has_header_text );
				if ( ! is_wp_error( $res ) ) {
					$order->update_meta_data( $key, time() );
					$order->save_meta_data();
				}
			}
		}
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
		$statuses = array( 'manual' => __( 'Manual button only', 'product-glide' ) );
		foreach ( wc_get_order_statuses() as $k => $l ) {
			$statuses[ substr( $k, 3 ) ] = $l;
		}
		?>
		<div class="pg-card" id="pg-wa-map" data-rows="<?php echo esc_attr( wp_json_encode( $rows ) ); ?>" data-statuses="<?php echo esc_attr( wp_json_encode( $statuses ) ); ?>">
			<div class="pg-card-head">
				<h2><?php esc_html_e( 'Template mappings', 'product-glide' ); ?></h2>
				<button type="button" class="pg-btn pg-btn-ghost" id="pg-wa-load"><?php esc_html_e( 'Load Approved Templates', 'product-glide' ); ?></button>
			</div>
			<p class="pg-muted"><?php esc_html_e( 'Variables (comma-separated, in {{1}}, {{2}}… order): name, order_id, items, total, tracking', 'product-glide' ); ?></p>
			<div id="pg-wa-rows"></div>
			<div class="pg-form-actions">
				<button type="button" class="pg-btn pg-btn-ghost" id="pg-wa-add">+ <?php esc_html_e( 'Add mapping', 'product-glide' ); ?></button>
				<button type="button" class="pg-btn pg-btn-primary" id="pg-wa-save"><?php esc_html_e( 'Save mappings', 'product-glide' ); ?></button>
			</div>
		</div>
		<?php
	}
}
