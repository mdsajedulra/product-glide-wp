<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin client for the Carrybee Delivery API v2.
 * Every method returns the decoded "data" array on success or WP_Error.
 */
class PG_Carrybee_API {

	const BASES = array(
		'production' => 'https://developers.carrybee.com',
		'sandbox'    => 'https://sandbox.carrybee.com',
	);

	public static function configured() {
		return '' !== (string) PG_Settings::get( 'cb_client_id', '' )
			&& '' !== (string) PG_Settings::get( 'cb_client_secret', '' )
			&& '' !== (string) PG_Settings::get( 'cb_client_context', '' );
	}

	/** Keys set and a pickup store chosen — ready to book parcels. */
	public static function ready() {
		return self::configured() && '' !== (string) PG_Settings::get( 'cb_store_id', '' );
	}

	public static function base() {
		$env = PG_Settings::get( 'cb_env', 'production' );
		return isset( self::BASES[ $env ] ) ? self::BASES[ $env ] : self::BASES['production'];
	}

	public static function request( $method, $path, $body = null, $timeout = 30 ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'pg_cb_keys', __( 'Carrybee Client-ID / Client-Secret / Client-Context are not set (Product Glide → Settings → Carrybee).', 'product-glide' ) );
		}
		$headers = array(
			'Client-ID'      => (string) PG_Settings::get( 'cb_client_id' ),
			'Client-Secret'  => (string) PG_Settings::get( 'cb_client_secret' ),
			'Client-Context' => (string) PG_Settings::get( 'cb_client_context' ),
		);
		if ( null === $body && in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$body = new stdClass();
		}
		$res = PG_Helpers::remote_json( $method, self::base() . $path, $headers, $body, $timeout );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'pg_cb_http', sprintf( /* translators: error */ __( 'Could not reach Carrybee: %s', 'product-glide' ), $res->get_error_message() ) );
		}
		$data = $res['data'];
		if ( $res['code'] >= 400 || ( is_array( $data ) && ! empty( $data['error'] ) ) ) {
			return new WP_Error( 'pg_cb_' . $res['code'], self::message_from( $data, sprintf( 'Carrybee HTTP %d (%s)', $res['code'], $path ) ), array( 'status' => $res['code'], 'body' => $data ? $data : $res['raw'] ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pg_cb_nonjson', sprintf( /* translators: path */ __( 'Carrybee returned a non-JSON answer for %s. If this was a booking, check the Carrybee panel before retrying.', 'product-glide' ), $path ) );
		}
		return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array( 'message' => isset( $data['message'] ) ? $data['message'] : '' );
	}

	/** "Validation error: recipient_phone (required); city_id (integer)". */
	public static function message_from( $data, $fallback ) {
		if ( ! is_array( $data ) ) {
			return $fallback;
		}
		$msg = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';
		if ( ! empty( $data['causes'] ) && is_array( $data['causes'] ) ) {
			$parts = array();
			foreach ( $data['causes'] as $field => $list ) {
				$types = array();
				foreach ( (array) $list as $c ) {
					if ( is_array( $c ) && isset( $c['type'] ) ) {
						$types[] = $c['type'];
					}
				}
				$parts[] = $field . ( $types ? ' (' . implode( ', ', $types ) . ')' : '' );
			}
			$msg = ( $msg ? $msg . ': ' : '' ) . implode( '; ', $parts );
		}
		return $msg ? $msg : $fallback;
	}

	/* Location */

	public static function cities() {
		$d = self::request( 'GET', '/api/v2/cities' );
		return is_wp_error( $d ) ? $d : ( isset( $d['cities'] ) ? $d['cities'] : array() );
	}

	public static function zones( $city_id ) {
		$d = self::request( 'GET', '/api/v2/cities/' . absint( $city_id ) . '/zones' );
		return is_wp_error( $d ) ? $d : ( isset( $d['zones'] ) ? $d['zones'] : array() );
	}

	public static function areas( $city_id, $zone_id ) {
		$d = self::request( 'GET', '/api/v2/cities/' . absint( $city_id ) . '/zones/' . absint( $zone_id ) . '/areas' );
		return is_wp_error( $d ) ? $d : ( isset( $d['areas'] ) ? $d['areas'] : array() );
	}

	public static function area_suggestion( $search ) {
		$d = self::request( 'GET', '/api/v2/area-suggestion?search=' . rawurlencode( $search ) );
		return is_wp_error( $d ) ? $d : ( isset( $d['items'] ) ? $d['items'] : array() );
	}

	/** Address text → array( city_id, zone_id ) or WP_Error. */
	public static function address_details( $query ) {
		return self::request( 'POST', '/api/v2/address-details', array( 'query' => mb_substr( (string) $query, 0, 250 ) ), 15 );
	}

	/* Stores */

	public static function stores() {
		$d = self::request( 'GET', '/api/v2/stores' );
		return is_wp_error( $d ) ? $d : ( isset( $d['stores'] ) ? $d['stores'] : array() );
	}

	public static function create_store( array $s ) {
		return self::request( 'POST', '/api/v2/stores', $s );
	}

	/* Orders */

	public static function create_order( array $p ) {
		$d = self::request( 'POST', '/api/v2/orders', $p, 45 );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$o = isset( $d['order'] ) ? $d['order'] : $d;
		if ( empty( $o['consignment_id'] ) ) {
			return new WP_Error( 'pg_cb_nocid', __( 'Carrybee did not return a consignment ID. Check the Carrybee panel before retrying.', 'product-glide' ) );
		}
		return $o;
	}

	public static function order_details( $cid ) {
		return self::request( 'GET', '/api/v2/orders/' . rawurlencode( (string) $cid ) . '/details' );
	}

	public static function cancel_order( $cid, $reason ) {
		$reason = trim( (string) $reason );
		return self::request( 'POST', '/api/v2/orders/' . rawurlencode( (string) $cid ) . '/cancel', array( 'cancellation_reason' => mb_substr( '' !== $reason ? $reason : 'Cancelled by merchant', 0, 199 ) ) );
	}

	public static function reverse_pickup_from( $cid, array $p ) {
		return self::request( 'POST', '/api/v2/orders/' . rawurlencode( (string) $cid ) . '/reverse-pickup', $p );
	}

	public static function exchange( $cid, array $p ) {
		$d = self::request( 'POST', '/api/v2/orders/' . rawurlencode( (string) $cid ) . '/exchange', $p );
		return is_wp_error( $d ) ? $d : ( isset( $d['order'] ) ? $d['order'] : $d );
	}
}
