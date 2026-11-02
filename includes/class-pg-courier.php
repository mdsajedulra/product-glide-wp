<?php
defined( 'ABSPATH' ) || exit;

/**
 * Courier registry: which couriers are set up, their names, and booking an order
 * with the chosen one. Steadfast and Carrybee share the consignments table
 * (column `provider`) and the same status words:
 * in_review → pending → hold → delivered / partial_delivered / cancelled.
 */
class PG_Courier {

	public static function all() {
		return array(
			'steadfast' => 'Steadfast',
			'carrybee'  => 'Carrybee',
		);
	}

	public static function label( $p ) {
		$all = self::all();
		return isset( $all[ $p ] ) ? $all[ $p ] : ucfirst( (string) $p );
	}

	/** Long name for the courier slip. */
	public static function company( $p ) {
		return 'carrybee' === $p ? 'Carrybee' : 'SteadFast Courier LTD';
	}

	/** Turned on in Settings → General → Couriers. */
	public static function enabled( $p ) {
		if ( 'carrybee' === $p ) {
			return (bool) PG_Settings::get( 'cb_enabled', 1 );
		}
		if ( 'steadfast' === $p ) {
			return (bool) PG_Settings::get( 'sf_enabled', 1 );
		}
		return false;
	}

	public static function ready( $p ) {
		if ( ! self::enabled( $p ) ) {
			return false;
		}
		if ( 'carrybee' === $p ) {
			return PG_Carrybee_API::ready();
		}
		if ( 'steadfast' === $p ) {
			return PG_Steadfast_API::configured();
		}
		return false;
	}

	/** Couriers that can book right now: slug => label. */
	public static function available() {
		$out = array();
		foreach ( self::all() as $k => $l ) {
			if ( self::ready( $k ) ) {
				$out[ $k ] = $l;
			}
		}
		return $out;
	}

	/** The courier picked first in the send boxes. */
	public static function default_courier() {
		$av = self::available();
		$d  = (string) PG_Settings::get( 'default_courier', 'steadfast' );
		if ( isset( $av[ $d ] ) ) {
			return $d;
		}
		return $av ? (string) key( $av ) : 'steadfast';
	}

	public static function tracking_url( $p, $code ) {
		if ( ! $code ) {
			return '';
		}
		if ( 'steadfast' === $p || '' === (string) $p ) {
			return PG_Steadfast::TRACK_URL . rawurlencode( $code );
		}
		return '';
	}

	/**
	 * Book one order. Returns array( 'consignment_id', 'tracking_code', 'provider' ) or WP_Error.
	 * $over: cod_amount, note.
	 */
	public static function book( WC_Order $order, $provider, array $over = array() ) {
		if ( ! self::ready( $provider ) ) {
			return new WP_Error(
				'pg_courier_off',
				'carrybee' === $provider
					? ( self::enabled( 'carrybee' ) ? __( 'Carrybee is not set up yet (Settings → Carrybee: keys and pickup store).', 'product-glide' ) : __( 'Carrybee is turned off (Settings → General → Couriers).', 'product-glide' ) )
					: ( self::enabled( 'steadfast' ) ? __( 'Steadfast API keys are not set (Settings → Steadfast).', 'product-glide' ) : __( 'Steadfast is turned off (Settings → General → Couriers).', 'product-glide' ) )
			);
		}
		$res = 'carrybee' === $provider ? PG_Carrybee::book( $order, $over ) : PG_Steadfast::book( $order, $over );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'provider'       => $provider,
			'consignment_id' => isset( $res['consignment_id'] ) ? (string) $res['consignment_id'] : '',
			'tracking_code'  => isset( $res['tracking_code'] ) && '' !== (string) $res['tracking_code'] ? (string) $res['tracking_code'] : ( isset( $res['consignment_id'] ) ? (string) $res['consignment_id'] : '' ),
		);
	}
}
