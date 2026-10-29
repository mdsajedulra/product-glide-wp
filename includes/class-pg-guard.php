<?php
defined( 'ABSPATH' ) || exit;

/**
 * Checkout guard (customer-facing checkout only; orders made in the dashboard are never blocked):
 *  - the billing phone must be a valid Bangladeshi mobile number (saved as 01XXXXXXXXX);
 *  - optional: the same phone may place only N orders within X hours;
 *  - optional: numbers on the block list cannot order.
 */
class PG_Guard {

	public static function init() {
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'normalise' ), 5, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'blocks' ), 20, 2 );
	}

	/** Returns '' when the phone may order, otherwise the message to show. */
	public static function check( $raw_phone, $skip_order = 0 ) {
		$s     = PG_Settings::all();
		$phone = PG_Helpers::bd_phone( $raw_phone );
		if ( ! $phone ) {
			return ! empty( $s['guard_bd_phone'] ) ? __( 'অনুগ্রহ করে সঠিক মোবাইল নম্বর দিন (যেমন 017XXXXXXXX)।', 'product-glide' ) : '';
		}
		$key = PG_Helpers::phone_key( $phone );
		if ( ! empty( $s['blocklist'] ) ) {
			foreach ( preg_split( '/[\s,]+/', (string) $s['blocklist'] ) as $b ) {
				if ( strlen( PG_Helpers::phone_key( $b ) ) >= 10 && PG_Helpers::phone_key( $b ) === $key ) {
					return self::fill( $s['block_list_message'] ? $s['block_list_message'] : PG_Settings::defaults()['block_list_message'], null, $s );
				}
			}
		}
		if ( ! empty( $s['block_repeat'] ) ) {
			$max   = max( 1, (int) $s['block_max'] );
			$since = time() - max( 1, (int) $s['block_hours'] ) * HOUR_IN_SECONDS;
			$map   = PG_Helpers::orders_by_phone_keys( array( $key ) );
			$hits  = array();
			foreach ( isset( $map[ $key ] ) ? $map[ $key ] : array() as $o ) {
				if ( (int) $o['id'] !== (int) $skip_order && strtotime( $o['created'] . ' UTC' ) >= $since && ! in_array( $o['status'], array( 'cancelled', 'failed', 'refunded', 'pending', 'checkout-draft' ), true ) ) {
					$hits[] = $o;
				}
			}
			if ( count( $hits ) >= $max ) {
				return self::fill( $s['block_message'] ? $s['block_message'] : PG_Settings::defaults()['block_message'], wc_get_order( $hits[0]['id'] ), $s );
			}
		}
		return '';
	}

	private static function fill( $msg, $order, $s ) {
		$rep = array(
			'%hours%'        => (int) $s['block_hours'],
			'%block_duration%' => (int) $s['block_hours'],
			'%hotline%'      => $s['hotline'],
			'%order_number%' => '',
			'%order_date%'   => '',
			'%order_time%'   => '',
			'%order_status%' => '',
		);
		if ( $order ) {
			$d                        = $order->get_date_created();
			$rep['%order_number%']    = $order->get_order_number();
			$rep['%order_date%']      = $d ? PG_Helpers::bd_time( $d, 'd M Y' ) : '';
			$rep['%order_time%']      = $d ? PG_Helpers::bd_time( $d, 'h:i A' ) : '';
			$rep['%order_status%']    = PG_Helpers::status_label( $order->get_status() );
		}
		return strtr( (string) $msg, $rep );
	}

	public static function classic( $data, $errors ) {
		$msg = self::check( isset( $data['billing_phone'] ) ? $data['billing_phone'] : '' );
		if ( $msg ) {
			$errors->add( 'pg_guard', $msg );
		}
	}

	/** Save the phone in one clean format so search, duplicates and courier all match. */
	public static function normalise( $order ) {
		$p = PG_Helpers::bd_phone( $order->get_billing_phone() );
		if ( $p ) {
			$order->set_billing_phone( $p );
		}
	}

	public static function blocks( $order, $request ) {
		$msg = self::check( $order->get_billing_phone(), $order->get_id() );
		if ( $msg ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pg_guard', $msg, 400 ); // phpcs:ignore
			}
			throw new Exception( $msg ); // phpcs:ignore
		}
		self::normalise( $order );
	}
}
