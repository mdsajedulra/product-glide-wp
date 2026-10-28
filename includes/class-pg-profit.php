<?php
defined( 'ABSPATH' ) || exit;

/**
 * Purchase cost (COGS), inventory valuation and net profit.
 */
class PG_Profit {

	const META = '_pg_cost';

	public static function init() {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'field_simple' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'field_variation' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'wp_ajax_pg_inventory', array( __CLASS__, 'ajax_inventory' ) );
		add_action( 'wp_ajax_pg_cost_save', array( __CLASS__, 'ajax_cost_save' ) );
		add_action( 'wp_ajax_pg_profit', array( __CLASS__, 'ajax_profit' ) );
		add_action( 'wp_ajax_pg_ads_save', array( __CLASS__, 'ajax_ads_save' ) );
		add_action( 'wp_ajax_pg_ads_delete', array( __CLASS__, 'ajax_ads_delete' ) );
	}

	/* Cost field --------------------------------------------------------- */

	public static function field_simple() {
		woocommerce_wp_text_input(
			array(
				'id'          => self::META,
				'label'       => __( 'Purchase / Production cost (BDT)', 'product-glide' ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Used by Product Glide to calculate profit.', 'product-glide' ),
			)
		);
	}

	public static function save_simple( $product ) {
		if ( isset( $_POST[ self::META ] ) ) { // phpcs:ignore -- WooCommerce verifies the product nonce.
			self::set_cost( $product, wc_format_decimal( wp_unslash( $_POST[ self::META ] ) ) ); // phpcs:ignore
		}
	}

	public static function field_variation( $loop, $data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'            => self::META . '_' . $loop,
				'name'          => self::META . '[' . $loop . ']',
				'value'         => get_post_meta( $variation->ID, self::META, true ),
				'label'         => __( 'Purchase cost (BDT)', 'product-glide' ),
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-first',
			)
		);
	}

	public static function save_variation( $variation, $i ) {
		if ( isset( $_POST[ self::META ][ $i ] ) ) { // phpcs:ignore
			self::set_cost( $variation, wc_format_decimal( wp_unslash( $_POST[ self::META ][ $i ] ) ) ); // phpcs:ignore
		}
	}

	/** @param WC_Product|int $product */
	public static function set_cost( $product, $cost ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product ) {
			return;
		}
		$cost = '' === $cost ? '' : max( 0, (float) $cost );
		$product->update_meta_data( self::META, $cost );
		if ( $product->get_id() ) {
			$product->save_meta_data();
			global $wpdb;
			if ( '' === $cost ) {
				$wpdb->delete( PG_Install::table( 'costs' ), array( 'product_id' => $product->get_id() ) );
			} else {
				$wpdb->replace( PG_Install::table( 'costs' ), array( 'product_id' => $product->get_id(), 'cost' => $cost, 'updated_at' => PG_Helpers::now_utc() ) );
			}
		}
	}

	public static function get_cost( $product_id ) {
		static $cache = array();
		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}
		$v = get_post_meta( $product_id, self::META, true );
		if ( '' === $v ) {
			$parent = wp_get_post_parent_id( $product_id );
			$v      = $parent ? get_post_meta( $parent, self::META, true ) : '';
		}
		$cache[ $product_id ] = '' === $v ? null : (float) $v;
		return $cache[ $product_id ];
	}

	/* Inventory ---------------------------------------------------------- */

	public static function ajax_inventory() {
		PG_Helpers::guard_ajax( true );
		$rows = array();
		$tot  = array( 'stock' => 0, 'cost_value' => 0, 'retail_value' => 0, 'missing_cost' => 0 );
		foreach ( PG_Packages::all_products( 1000 ) as $p ) {
			if ( $p->is_type( 'variable' ) ) {
				continue;
			}
			$stock = $p->managing_stock() ? (int) $p->get_stock_quantity() : null;
			$cost  = self::get_cost( $p->get_id() );
			$price = (float) $p->get_price();
			$qty   = null === $stock ? 0 : max( 0, $stock );
			$cv    = null === $cost ? null : $cost * $qty;
			$rv    = $price * $qty;
			$rows[] = array(
				'id'           => $p->get_id(),
				'name'         => $p->get_name(),
				'status'       => $p->get_status(),
				'sku'          => $p->get_sku(),
				'stock'        => $stock,
				'price'        => $price,
				'cost'         => $cost,
				'cost_value'   => $cv,
				'retail_value' => $rv,
				'margin'       => null === $cost ? null : $price - $cost,
				'margin_pct'   => ( null === $cost || $price <= 0 ) ? null : round( ( $price - $cost ) / $price * 100, 1 ),
				'edit_url'     => get_edit_post_link( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id(), 'raw' ),
			);
			$tot['stock']        += $qty;
			$tot['cost_value']   += (float) $cv;
			$tot['retail_value'] += $rv;
			if ( null === $cost ) {
				$tot['missing_cost']++;
			}
		}
		$tot['potential_margin'] = $tot['retail_value'] - $tot['cost_value'];
		wp_send_json_success( array( 'rows' => $rows, 'totals' => $tot ) );
	}

	public static function ajax_cost_save() {
		PG_Helpers::guard_ajax( true );
		$id   = PG_Helpers::post_int( 'product_id' );
		$cost = PG_Helpers::post( 'cost', '' );
		self::set_cost( $id, '' === $cost ? '' : wc_format_decimal( $cost ) );
		wp_send_json_success( array( 'cost' => self::get_cost_fresh( $id ) ) );
	}

	private static function get_cost_fresh( $id ) {
		$v = get_post_meta( $id, self::META, true );
		return '' === $v ? null : (float) $v;
	}

	/* Ad spend ----------------------------------------------------------- */

	public static function ads() {
		$a = get_option( 'pg_ad_spend', array() );
		return is_array( $a ) ? array_values( $a ) : array();
	}

	public static function ajax_ads_save() {
		PG_Helpers::guard_ajax( true );
		$from = PG_Helpers::post( 'from' );
		$to   = PG_Helpers::post( 'to' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
			wp_send_json_error( array( 'message' => __( 'Pick a valid date range.', 'product-glide' ) ) );
		}
		$list   = self::ads();
		$list[] = array(
			'id'       => 'a' . time() . wp_rand( 10, 99 ),
			'from'     => $from,
			'to'       => $to,
			'platform' => sanitize_text_field( PG_Helpers::post( 'platform', 'Facebook Ads' ) ),
			'amount'   => max( 0, (float) PG_Helpers::post( 'amount', 0 ) ),
			'note'     => sanitize_text_field( PG_Helpers::post( 'note', '' ) ),
		);
		update_option( 'pg_ad_spend', $list, false );
		wp_send_json_success( array( 'ads' => $list ) );
	}

	public static function ajax_ads_delete() {
		PG_Helpers::guard_ajax( true );
		$id   = sanitize_text_field( PG_Helpers::post( 'id' ) );
		$list = array_values(
			array_filter(
				self::ads(),
				function ( $a ) use ( $id ) {
					return $a['id'] !== $id;
				}
			)
		);
		update_option( 'pg_ad_spend', $list, false );
		wp_send_json_success( array( 'ads' => $list ) );
	}

	/** Ad spend within [from,to], prorated by overlapping days. */
	public static function ad_spend_between( $from, $to ) {
		$total = 0;
		$f     = strtotime( $from );
		$t     = strtotime( $to );
		foreach ( self::ads() as $a ) {
			$af = strtotime( $a['from'] );
			$at = strtotime( $a['to'] );
			$s  = max( $f, $af );
			$e  = min( $t, $at );
			if ( $e < $s ) {
				continue;
			}
			$days_entry   = (int) round( ( $at - $af ) / DAY_IN_SECONDS ) + 1;
			$days_overlap = (int) round( ( $e - $s ) / DAY_IN_SECONDS ) + 1;
			$total       += (float) $a['amount'] * $days_overlap / max( 1, $days_entry );
		}
		return round( $total, 2 );
	}

	/* Profit engine ------------------------------------------------------ */

	public static function ajax_profit() {
		PG_Helpers::guard_ajax( true );
		$from = PG_Helpers::post( 'from' );
		$to   = PG_Helpers::post( 'to' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$now  = new DateTime( 'now', PG_Helpers::tz() );
			$to   = $now->format( 'Y-m-d' );
			$from = $now->format( 'Y-m-01' );
		}
		$courier  = sanitize_key( PG_Helpers::post( 'courier', 'all' ) );
		$statuses = array_filter( array_map( 'sanitize_key', (array) PG_Helpers::post( 'statuses', array() ) ) );
		if ( ! $statuses ) {
			$statuses = (array) PG_Settings::get( 'profit_statuses', array( 'completed', 'partial_delivered' ) );
		}
		wp_send_json_success( self::calculate( $from, $to, $statuses, $courier ) );
	}

	public static function calculate( $from, $to, array $statuses, $courier = 'all' ) {
		global $wpdb;
		list( $s_utc, $e_utc ) = PG_Helpers::bd_range_bounds_utc( $from, $to );
		$ts_from = strtotime( $s_utc . ' UTC' );
		$ts_to   = strtotime( $e_utc . ' UTC' ) - 1;

		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => $statuses,
				'date_created' => $ts_from . '...' . $ts_to,
				'limit'        => 5000,
			)
		);
		$ids      = array_map(
			function ( $o ) {
				return $o->get_id();
			},
			$orders
		);
		$consigns = PG_Steadfast::active_for( $ids );

		$revenue       = 0;
		$cogs          = 0;
		$missing_cost  = array();
		$count         = 0;
		$products      = array();
		foreach ( $orders as $o ) {
			$c = isset( $consigns[ $o->get_id() ] ) ? $consigns[ $o->get_id() ] : null;
			if ( 'steadfast' === $courier && ! $c ) {
				continue;
			}
			if ( 'none' === $courier && $c ) {
				continue;
			}
			$count++;
			$total = (float) $o->get_total() - (float) $o->get_total_refunded();
			if ( $c && 'partial_delivered' === $o->get_status() && null !== $c->cod_collected ) {
				$advance = max( 0, (float) $o->get_total() - (float) $c->cod_amount );
				$total   = (float) $c->cod_collected + $advance;
			}
			$revenue += $total;
			foreach ( $o->get_items() as $item ) {
				$pid  = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$qty  = (int) $item->get_quantity() - absint( $o->get_qty_refunded_for_item( $item->get_id() ) );
				$cost = self::get_cost( $pid );
				if ( null === $cost ) {
					$missing_cost[ $pid ] = $item->get_name();
					$cost                 = 0;
				}
				$cogs += $cost * $qty;
				if ( ! isset( $products[ $pid ] ) ) {
					$products[ $pid ] = array( 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0, 'cogs' => 0 );
				}
				$products[ $pid ]['qty']     += $qty;
				$products[ $pid ]['revenue'] += (float) $item->get_total();
				$products[ $pid ]['cogs']    += $cost * $qty;
			}
		}

		// Courier costs: every parcel booked in the range (delivered, partial, returned).
		$courier_delivery = 0;
		$return_penalty   = 0;
		$parcels          = array( 'delivered' => 0, 'cancelled' => 0, 'transit' => 0 );
		if ( 'none' !== $courier ) {
			$t    = PG_Install::table( 'consignments' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT courier_status, delivery_charge FROM {$t} WHERE created_at >= %s AND created_at < %s AND courier_status <> 'voided'", $s_utc, $e_utc ) ); // phpcs:ignore
			$fd   = (float) PG_Settings::get( 'fallback_delivery', 70 );
			$fr   = (float) PG_Settings::get( 'fallback_return', 70 );
			foreach ( $rows as $r ) {
				if ( in_array( $r->courier_status, array( 'delivered', 'partial_delivered' ), true ) ) {
					$courier_delivery += null === $r->delivery_charge ? $fd : (float) $r->delivery_charge;
					$parcels['delivered']++;
				} elseif ( 'cancelled' === $r->courier_status ) {
					$return_penalty += null === $r->delivery_charge ? $fr : (float) $r->delivery_charge;
					$parcels['cancelled']++;
				} else {
					$parcels['transit']++;
				}
			}
		}
		$ads   = self::ad_spend_between( $from, $to );
		$gross = $revenue - $cogs;
		$net   = $gross - $courier_delivery - $return_penalty - $ads;

		uasort(
			$products,
			function ( $a, $b ) {
				return $b['qty'] - $a['qty'];
			}
		);

		return array(
			'from'             => $from,
			'to'               => $to,
			'orders'           => $count,
			'revenue'          => round( $revenue, 2 ),
			'cogs'             => round( $cogs, 2 ),
			'gross_profit'     => round( $gross, 2 ),
			'courier_delivery' => round( $courier_delivery, 2 ),
			'return_penalty'   => round( $return_penalty, 2 ),
			'ad_spend'         => $ads,
			'net_profit'       => round( $net, 2 ),
			'margin_pct'       => $revenue > 0 ? round( $net / $revenue * 100, 1 ) : 0,
			'parcels'          => $parcels,
			'missing_cost'     => array_values( $missing_cost ),
			'products'         => array_slice( array_values( $products ), 0, 20 ),
			'ads'              => self::ads(),
		);
	}
}
