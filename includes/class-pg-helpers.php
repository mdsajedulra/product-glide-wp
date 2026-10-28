<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers used by every module.
 */
class PG_Helpers {

	const TZ = 'Asia/Dhaka';

	/** Verify nonce + capability for an AJAX call, or die with JSON error. */
	public static function guard_ajax( $admin_only = false ) {
		if ( ! check_ajax_referer( 'pg_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page.', 'product-glide' ) ), 403 );
		}
		if ( ! PG_App::can() || ( $admin_only && ! current_user_can( 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'product-glide' ) ), 403 );
		}
	}

	/** Read a POST value, sanitized as text. */
	public static function post( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $default;
		}
		$v = wp_unslash( $_POST[ $key ] ); // phpcs:ignore
		return is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( $v );
	}

	public static function post_textarea( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore
			return $default;
		}
		return sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore
	}

	/** Decode a JSON string posted from JS (already sanitized per field by caller). */
	public static function post_json( $key ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore
			return array();
		}
		$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore
		if ( is_array( $raw ) ) {
			// Sent as key[]=... : each entry may itself be JSON.
			$out = array();
			foreach ( $raw as $k => $v ) {
				$d         = is_string( $v ) ? json_decode( $v, true ) : $v;
				$out[ $k ] = null === $d ? $v : $d;
			}
			return array_values( array_filter( $out, 'is_array' ) );
		}
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	public static function post_int( $key, $default = 0 ) {
		return isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : $default; // phpcs:ignore
	}

	/**
	 * Normalise a Bangladeshi mobile number to 01XXXXXXXXX (11 digits).
	 * Returns '' if it does not look like a BD mobile.
	 */
	public static function bd_phone( $phone ) {
		// Bengali digits → ASCII, then keep digits only.
		$bn = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );
		$d  = preg_replace( '/\D+/', '', str_replace( $bn, range( 0, 9 ), (string) $phone ) );
		if ( 0 === strpos( $d, '880' ) ) {
			$d = substr( $d, 2 );
		} elseif ( 0 === strpos( $d, '88' ) && 13 === strlen( $d ) ) {
			$d = substr( $d, 2 );
		}
		if ( 10 === strlen( $d ) && '1' === $d[0] ) {
			$d = '0' . $d;
		}
		return preg_match( '/^01[3-9]\d{8}$/', $d ) ? $d : '';
	}

	/** Last 10 digits of a phone, used for matching across formats. */
	public static function phone_key( $phone ) {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( $d ) >= 10 ? substr( $d, -10 ) : $d;
	}

	public static function tz() {
		return new DateTimeZone( self::TZ );
	}

	/** Format a WC_DateTime / timestamp / mysql UTC string in Bangladesh time. */
	public static function bd_time( $date, $format = 'd M Y, h:i A' ) {
		if ( empty( $date ) ) {
			return '';
		}
		if ( $date instanceof DateTimeInterface ) {
			$dt = new DateTime( '@' . $date->getTimestamp() );
		} elseif ( is_numeric( $date ) ) {
			$dt = new DateTime( '@' . (int) $date );
		} else {
			$dt = new DateTime( $date, new DateTimeZone( 'UTC' ) );
		}
		$dt->setTimezone( self::tz() );
		return $dt->format( $format );
	}

	/** UTC mysql datetime bounds [start, end) for a BD calendar day (Y-m-d) or today. */
	public static function bd_day_bounds_utc( $ymd = '' ) {
		$tz    = self::tz();
		$start = $ymd ? new DateTime( $ymd . ' 00:00:00', $tz ) : new DateTime( 'today', $tz );
		$end   = clone $start;
		$end->modify( '+1 day' );
		$utc = new DateTimeZone( 'UTC' );
		$start->setTimezone( $utc );
		$end->setTimezone( $utc );
		return array( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
	}

	/** UTC bounds for a BD date range (inclusive dates). */
	public static function bd_range_bounds_utc( $from, $to ) {
		list( $s ) = self::bd_day_bounds_utc( $from );
		list( , $e ) = self::bd_day_bounds_utc( $to );
		return array( $s, $e );
	}

	public static function now_utc() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Map of phone_key => list of [id, status, created(gmt)] for the given phone keys (newest first, trash excluded).
	 * One query, works for HPOS and legacy storage.
	 */
	public static function orders_by_phone_keys( array $keys ) {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( $keys, function ( $k ) {
			return strlen( $k ) >= 10;
		} ) ) );
		if ( empty( $keys ) ) {
			return array();
		}
		$likes = array();
		$args  = array();
		foreach ( $keys as $k ) {
			$likes[] = 'phone_col LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $k );
		}
		$where = implode( ' OR ', $likes );

		if ( self::hpos() ) {
			$addr  = $wpdb->prefix . 'wc_order_addresses';
			$ord   = $wpdb->prefix . 'wc_orders';
			$where = str_replace( 'phone_col', 'a.phone', $where );
			$sql   = "SELECT a.order_id AS id, a.phone AS phone, o.status AS status, o.date_created_gmt AS created FROM {$addr} a INNER JOIN {$ord} o ON o.id = a.order_id
				WHERE a.address_type = 'billing' AND o.type = 'shop_order' AND o.status NOT IN ('trash','auto-draft','checkout-draft') AND ( {$where} )";
		} else {
			$where = str_replace( 'phone_col', 'm.meta_value', $where );
			$sql   = "SELECT m.post_id AS id, m.meta_value AS phone, p.post_status AS status, p.post_date_gmt AS created FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_billing_phone' AND p.post_type = 'shop_order' AND p.post_status NOT IN ('trash','auto-draft','checkout-draft') AND ( {$where} )";
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
		$map  = array();
		foreach ( (array) $rows as $r ) {
			$k = self::phone_key( $r->phone );
			if ( in_array( $k, $keys, true ) ) {
				$map[ $k ][ (int) $r->id ] = array(
					'id'      => (int) $r->id,
					'status'  => preg_replace( '/^wc-/', '', $r->status ),
					'created' => $r->created,
				);
			}
		}
		foreach ( $map as $k => $list ) {
			krsort( $list );
			$map[ $k ] = array_values( $list );
		}
		return $map;
	}

	/** Short human item list: "Book A x1, Book B x2". */
	public static function items_text( WC_Order $order, $sep = ', ' ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$out[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		return implode( $sep, $out );
	}

	/** Full shipping/billing address on one line. */
	public static function order_address( WC_Order $order ) {
		$parts = array(
			$order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
			$order->get_shipping_address_2() ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
			$order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
		);
		$state_code = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		$country    = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		if ( $state_code ) {
			$states  = WC()->countries ? WC()->countries->get_states( $country ? $country : 'BD' ) : array();
			$parts[] = isset( $states[ $state_code ] ) ? $states[ $state_code ] : $state_code;
		}
		$parts = array_filter( array_map( 'trim', $parts ) );
		return implode( ', ', array_unique( $parts ) );
	}

	public static function order_name( WC_Order $order ) {
		$n = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $n ) {
			$n = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		return $n;
	}

	/** Status slug without wc- prefix → label. */
	public static function status_label( $status ) {
		$all = wc_get_order_statuses();
		$key = 'wc-' === substr( $status, 0, 3 ) ? $status : 'wc-' . $status;
		return isset( $all[ $key ] ) ? $all[ $key ] : ucwords( str_replace( array( '_', '-' ), ' ', $status ) );
	}

	/** Remote JSON request helper with sane defaults. */
	public static function remote_json( $method, $url, $headers = array(), $body = null, $timeout = 30 ) {
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		return array(
			'code' => $code,
			'data' => is_array( $data ) ? $data : null,
			'raw'  => $raw,
		);
	}
}
