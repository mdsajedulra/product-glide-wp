<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin client for the Steadfast (Packzy) merchant API v1.
 * Every method returns array on success or WP_Error.
 */
class PG_Steadfast_API {

	const FIELD_LIMITS = array(
		'invoice'           => 100,
		'recipient_name'    => 100,
		'recipient_address' => 490,
		'note'              => 480,
		'item_description'  => 255,
	);

	public static function configured() {
		return '' !== PG_Settings::get( 'sf_api_key', '' ) && '' !== PG_Settings::get( 'sf_secret_key', '' );
	}

	private static function base() {
		return untrailingslashit( PG_Settings::get( 'sf_base_url', 'https://portal.packzy.com/api/v1' ) );
	}

	public static function request( $method, $path, $body = null, $auth = true, $timeout = 30 ) {
		if ( $auth && ! self::configured() ) {
			return new WP_Error( 'pg_sf_keys', __( 'Steadfast API key / secret are not set (Product Glide → Settings → Steadfast).', 'product-glide' ) );
		}
		$headers = array();
		if ( $auth ) {
			$headers['Api-Key']    = PG_Settings::get( 'sf_api_key' );
			$headers['Secret-Key'] = PG_Settings::get( 'sf_secret_key' );
		}
		$res = PG_Helpers::remote_json( $method, self::base() . $path, $headers, $body, $timeout );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'pg_sf_http', sprintf( /* translators: error */ __( 'Could not reach Steadfast: %s', 'product-glide' ), $res->get_error_message() ) );
		}
		$data = $res['data'];
		if ( $res['code'] >= 400 ) {
			return new WP_Error( 'pg_sf_' . $res['code'], self::message_from( $data, sprintf( 'HTTP %d from %s', $res['code'], $path ) ), array( 'body' => $data ? $data : $res['raw'] ) );
		}
		if ( ! $auth ) {
			return $data ? $data : array( 'raw' => $res['raw'] );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pg_sf_nonjson', sprintf( /* translators: path */ __( 'Steadfast returned a non-JSON answer for %s. If this was a booking, check the portal before retrying.', 'product-glide' ), $path ) );
		}
		if ( isset( $data['status'] ) && is_numeric( $data['status'] ) && (int) $data['status'] >= 400 ) {
			return new WP_Error( 'pg_sf_' . (int) $data['status'], self::message_from( $data, 'Steadfast error ' . (int) $data['status'] ), array( 'body' => $data ) );
		}
		return $data;
	}

	private static function message_from( $data, $fallback ) {
		if ( ! is_array( $data ) ) {
			return $fallback;
		}
		$msg = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';
		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$flat = array();
			array_walk_recursive(
				$data['errors'],
				function ( $v ) use ( &$flat ) {
					$flat[] = $v;
				}
			);
			if ( $flat ) {
				$msg = $msg ? $msg . ': ' . implode( '; ', $flat ) : implode( '; ', $flat );
			}
		}
		return $msg ? $msg : $fallback;
	}

	/** Validate + normalise one order payload. Returns array or WP_Error. */
	public static function validate_order( array $o ) {
		$errors  = array();
		$invoice = isset( $o['invoice'] ) ? trim( (string) $o['invoice'] ) : '';
		if ( '' === $invoice || ! preg_match( '/^[A-Za-z0-9_-]+$/', $invoice ) ) {
			$errors[] = __( 'Invoice may only contain letters, digits, - and _', 'product-glide' );
		}
		$o['invoice'] = $invoice;
		if ( empty( $o['recipient_name'] ) ) {
			$errors[] = __( 'Customer name is empty', 'product-glide' );
		}
		if ( empty( $o['recipient_address'] ) ) {
			$errors[] = __( 'Address is empty', 'product-glide' );
		}
		$phone = PG_Helpers::bd_phone( isset( $o['recipient_phone'] ) ? $o['recipient_phone'] : '' );
		if ( ! $phone ) {
			$errors[] = __( 'Phone is not a valid BD mobile number', 'product-glide' );
		}
		$o['recipient_phone'] = $phone;
		if ( ! empty( $o['alternative_phone'] ) ) {
			$alt = PG_Helpers::bd_phone( $o['alternative_phone'] );
			if ( $alt && $alt !== $phone ) {
				$o['alternative_phone'] = $alt;
			} else {
				unset( $o['alternative_phone'] );
			}
		}
		$o['cod_amount'] = (int) round( (float) ( isset( $o['cod_amount'] ) ? $o['cod_amount'] : 0 ) );
		if ( $o['cod_amount'] < 0 || $o['cod_amount'] > 1000000 ) {
			$errors[] = __( 'COD amount must be 0 – 1,000,000', 'product-glide' );
		}
		// Steadfast silently truncates long values; trim them ourselves (multibyte safe).
		foreach ( self::FIELD_LIMITS as $k => $max ) {
			if ( isset( $o[ $k ] ) && is_string( $o[ $k ] ) && mb_strlen( $o[ $k ] ) > $max ) {
				if ( 'invoice' === $k ) {
					$errors[] = __( 'Invoice is too long', 'product-glide' );
				} else {
					$o[ $k ] = mb_substr( $o[ $k ], 0, $max );
				}
			}
		}
		foreach ( array( 'note', 'item_description', 'recipient_email', 'alternative_phone' ) as $k ) {
			if ( isset( $o[ $k ] ) && '' === $o[ $k ] ) {
				unset( $o[ $k ] );
			}
		}
		if ( $errors ) {
			return new WP_Error( 'pg_sf_validation', implode( '; ', $errors ) );
		}
		return $o;
	}

	public static function ping() {
		return self::request( 'GET', '/ping', null, false, 15 );
	}

	public static function create_order( array $order ) {
		$o = self::validate_order( $order );
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		$data = self::request( 'POST', '/create_order', $o );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['consignment'] ) || ! is_array( $data['consignment'] ) ) {
			return new WP_Error( 'pg_sf_unexpected', __( 'Steadfast answered without a consignment. Check the portal before retrying.', 'product-glide' ), array( 'body' => $data ) );
		}
		return $data['consignment'];
	}

	/**
	 * Extended bulk booking (max 500). Returns list of rows:
	 * [ invoice, ok, consignment_id, tracking_code, errors[] ].
	 */
	public static function create_bulk( array $orders ) {
		$data = self::request( 'POST', '/create_order/bulk-order/extended', array( 'data' => array_values( $orders ) ), true, 60 );
		if ( is_wp_error( $data ) && 400 === self::err_code( $data ) ) {
			// Older encoding: data as a JSON string.
			$data = self::request( 'POST', '/create_order/bulk-order/extended', array( 'data' => wp_json_encode( array_values( $orders ) ) ), true, 60 );
		}
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : ( isset( $data[0] ) ? $data : array() );
		$out  = array();
		foreach ( $rows as $r ) {
			$ok    = isset( $r['status'] ) && 'success' === $r['status'] && ! empty( $r['consignment_id'] );
			$errs  = array();
			if ( ! $ok ) {
				$e = isset( $r['error'] ) ? $r['error'] : ( isset( $r['errors'] ) ? $r['errors'] : __( 'Unknown error', 'product-glide' ) );
				if ( is_string( $e ) ) {
					$dec = json_decode( $e, true );
					$e   = is_array( $dec ) ? $dec : array( $e );
				}
				array_walk_recursive(
					$e,
					function ( $v ) use ( &$errs ) {
						$errs[] = (string) $v;
					}
				);
			}
			$out[] = array(
				'invoice'        => isset( $r['invoice'] ) ? (string) $r['invoice'] : '',
				'ok'             => $ok,
				'consignment_id' => $ok ? (string) $r['consignment_id'] : '',
				'tracking_code'  => isset( $r['tracking_code'] ) ? (string) $r['tracking_code'] : '',
				'errors'         => $errs,
			);
		}
		return $out;
	}

	private static function err_code( WP_Error $e ) {
		return (int) str_replace( 'pg_sf_', '', $e->get_error_code() );
	}

	public static function status_by_cid( $cid ) {
		return self::request( 'GET', '/status_by_cid/' . rawurlencode( $cid ) );
	}

	public static function status_by_invoice( $inv ) {
		return self::request( 'GET', '/status_by_invoice/' . rawurlencode( $inv ) );
	}

	public static function trackings_by_invoice( $inv ) {
		return self::request( 'GET', '/trackings_by_invoice/' . rawurlencode( $inv ) );
	}

	public static function balance() {
		return self::request( 'GET', '/get_balance' );
	}

	public static function return_requests( $page = 1 ) {
		return self::request( 'GET', '/get_return_requests' . ( $page > 1 ? '?page=' . (int) $page : '' ) );
	}

	public static function create_return_request( $target_key, $target_value, $reason = '' ) {
		$body = array( $target_key => 'consignment_id' === $target_key ? (int) $target_value : (string) $target_value );
		if ( '' !== $reason ) {
			$body['reason'] = mb_substr( $reason, 0, 500 );
		}
		return self::request( 'POST', '/create_return_request', $body );
	}

	public static function payments( $page = 1 ) {
		return self::request( 'GET', '/payments' . ( $page > 1 ? '?page=' . (int) $page : '' ) );
	}

	public static function payment( $id ) {
		if ( ! preg_match( '/^(?:SFC-)?(\d+)$/i', trim( (string) $id ), $m ) ) {
			return new WP_Error( 'pg_sf_validation', __( 'Payment ID must look like SFC-88213 or 88213', 'product-glide' ) );
		}
		return self::request( 'GET', '/payments/' . $m[1] );
	}

	public static function police_stations() {
		$cached = get_transient( 'pg_sf_police_stations' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = self::request( 'GET', '/police_stations' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$list = self::list_from( $data, array( 'data', 'police_stations' ) );
		set_transient( 'pg_sf_police_stations', $list, DAY_IN_SECONDS );
		return $list;
	}

	public static function pickup_request( array $req ) {
		$phone = PG_Helpers::bd_phone( isset( $req['contact_number'] ) ? $req['contact_number'] : '' );
		if ( ! $phone ) {
			return new WP_Error( 'pg_sf_validation', __( 'Contact number is not a valid BD mobile number', 'product-glide' ) );
		}
		if ( empty( $req['address'] ) ) {
			return new WP_Error( 'pg_sf_validation', __( 'Pickup address is required', 'product-glide' ) );
		}
		$body = array(
			'address'           => mb_substr( $req['address'], 0, 255 ),
			'contact_number'    => $phone,
		);
		foreach ( array( 'address_id', 'police_station_id', 'estim_qty' ) as $k ) {
			if ( ! empty( $req[ $k ] ) ) {
				$body[ $k ] = (int) $req[ $k ];
			}
		}
		if ( ! empty( $req['note'] ) ) {
			$body['note'] = mb_substr( $req['note'], 0, 500 );
		}
		return self::request( 'POST', '/create_pickup_request', $body );
	}

	public static function fraud_score( $phone ) {
		$p = PG_Helpers::bd_phone( $phone );
		if ( ! $p ) {
			return new WP_Error( 'pg_sf_validation', __( 'Not a valid BD mobile number', 'product-glide' ) );
		}
		return self::request( 'GET', '/fraud_check/score/' . $p, null, true, 20 );
	}

	public static function list_from( $body, array $keys ) {
		if ( isset( $body[0] ) ) {
			return $body;
		}
		foreach ( $keys as $k ) {
			if ( isset( $body[ $k ] ) && is_array( $body[ $k ] ) ) {
				if ( isset( $body[ $k ]['data'] ) && is_array( $body[ $k ]['data'] ) ) {
					return $body[ $k ]['data'];
				}
				return $body[ $k ];
			}
		}
		return array();
	}
}
