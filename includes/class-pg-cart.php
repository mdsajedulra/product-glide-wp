<?php
/**
 * Cart behaviour for first-time online buyers.
 *
 * 1. No surprise quantity: adding a product that is already in the cart
 *    replaces that line, so "Order" clicked twice (today, or on a return
 *    visit) still means 1 copy, not 2.
 * 2. Fresh cart per visit: when a customer comes back after a pause
 *    (default 30 minutes) the old cart is emptied before the page loads.
 * 3. No saved cart for logged-in users (WooCommerce "persistent cart").
 *
 * @package ProductGlide
 */

defined( 'ABSPATH' ) || exit;

class PG_Cart {

	const SEEN = 'pg_last_seen';

	public static function init() {
		if ( PG_Settings::get( 'cart_no_dupe', 1 ) ) {
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'replace_existing' ), 1, 3 );
		}
		if ( PG_Settings::get( 'cart_fresh', 1 ) ) {
			add_filter( 'woocommerce_persistent_cart_enabled', '__return_false' );
			add_action( 'wp_loaded', array( __CLASS__, 'fresh_visit' ), 5 );
			add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'touch' ) );
		}
	}

	/**
	 * Remove the same product from the cart before WooCommerce adds it again.
	 */
	public static function replace_existing( $passed, $product_id, $qty ) {
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}
		$variation = isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0; // phpcs:ignore
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] === (int) $product_id && ( ! $variation || (int) $item['variation_id'] === $variation ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}
		return $passed;
	}

	public static function touch() {
		if ( WC()->session ) {
			WC()->session->set( self::SEEN, time() );
		}
	}

	/**
	 * Only a normal page view (GET, not ajax, not the checkout page) may empty the cart,
	 * so a customer who is slow on the checkout form never loses the order.
	 */
	private static function is_page_view() {
		if ( is_admin() || wp_doing_ajax() || isset( $_GET['wc-ajax'] ) || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) { // phpcs:ignore
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore
		$keep = array( wc_get_checkout_url(), wc_get_cart_url() );
		foreach ( $keep as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( $path && '/' !== $path && 0 === strpos( trailingslashit( $uri ), trailingslashit( $path ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_frontend_request() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) || defined( 'DOING_CRON' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Empty a cart that was left behind on an earlier visit.
	 */
	public static function fresh_visit() {
		if ( ! self::is_frontend_request() || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return;
		}
		if ( ! WC()->session->has_session() ) {
			return;
		}
		$now  = time();
		$last = (int) WC()->session->get( self::SEEN, 0 );
		if ( ! self::is_page_view() ) {
			if ( $now - $last > 60 ) {
				WC()->session->set( self::SEEN, $now );
			}
			return;
		}
		$gap  = max( 1, (int) PG_Settings::get( 'cart_fresh_minutes', 30 ) ) * MINUTE_IN_SECONDS;
		// Old carts from before this feature have no timestamp: treat them as a new visit too.
		if ( ( ! $last || $now - $last > $gap ) && ! WC()->cart->is_empty() ) {
			WC()->cart->empty_cart( true );
		}
		if ( $now - $last > 60 ) {
			WC()->session->set( self::SEEN, $now );
		}
	}
}
