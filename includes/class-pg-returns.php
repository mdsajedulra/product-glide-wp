<?php
/**
 * Items coming back from the customer: partial deliveries and exchanges.
 *
 * The rule: stock goes up only when the returned item is in your hands.
 *
 * Partial delivery
 *   Steadfast says "partial_delivered" and how much cash it collected. We
 *   guess which items came back by matching the collected money to the item
 *   prices, and show it on the order card. "Received" puts those items back in
 *   stock and records a WooCommerce refund for them (the customer did not pay
 *   for them), so order totals, stock, profit and the parcel ledger agree.
 *
 * Exchange (wrong item sent)
 *   From the original order, create a linked exchange order with the right
 *   item; it reduces stock like any order and goes to the courier with a note
 *   telling the rider to bring the wrong item back. The money the customer
 *   already paid moves to the exchange order as credit, so the exchange COD is
 *   only what the customer still has to pay. "Received" puts the wrong item
 *   back in stock (refund of ৳0 on the original order: no money moves).
 *
 * Order meta _pg_ret = array(
 *   state    => pending | received | closed,
 *   source   => partial | exchange,
 *   items    => array( item_id => qty ),
 *   guess    => bool (items were guessed from the collected amount),
 *   exchange => exchange order id (source = exchange),
 *   at, by, done_at, done_by
 * )
 *
 * @package ProductGlide
 */

defined( 'ABSPATH' ) || exit;

class PG_Returns {

	const META = '_pg_ret';

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 30, 4 );
		foreach ( array( 'save', 'receive', 'close', 'exchange' ) as $a ) {
			add_action( 'wp_ajax_pg_ret_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ---------- reading ---------- */

	public static function get( WC_Order $o ) {
		$r = $o->get_meta( self::META );
		return is_array( $r ) && ! empty( $r['state'] ) ? $r : null;
	}

	/** Unit price the customer pays for one of this line (after discounts). */
	private static function unit( WC_Order_Item_Product $it ) {
		$q = max( 1, (int) $it->get_quantity() );
		return round( (float) $it->get_total() / $q, 2 );
	}

	/** Lines still on the order (minus anything already refunded). */
	private static function lines( WC_Order $o ) {
		$out = array();
		foreach ( $o->get_items() as $id => $it ) {
			$left = (int) $it->get_quantity() - absint( $o->get_qty_refunded_for_item( $id ) );
			if ( $left > 0 ) {
				$out[ $id ] = array( 'item' => $it, 'qty' => $left, 'unit' => self::unit( $it ) );
			}
		}
		return $out;
	}

	/** Card data for the order list. */
	public static function card( WC_Order $o ) {
		$r     = self::get( $o );
		$lines = self::lines( $o );
		$ex    = $o->get_meta( '_pg_exchange_of' );
		$out   = array(
			'can_exchange' => (bool) $lines && ! $ex && ! ( $r && 'pending' === $r['state'] ) && in_array( $o->get_status(), array( 'completed', 'partial_delivered', 'courier_pending' ), true ),
			'exchange_of'  => $ex ? array( 'id' => (int) $ex, 'number' => ( $eo = wc_get_order( $ex ) ) ? $eo->get_order_number() : (string) $ex ) : null,
			'exchanges'    => array(),
			'lines'        => array(),
			'ret'          => null,
		);
		foreach ( (array) $o->get_meta( '_pg_exchanges' ) as $xid ) {
			$xo = $xid ? wc_get_order( $xid ) : null;
			if ( $xo ) {
				$out['exchanges'][] = array( 'id' => $xo->get_id(), 'number' => $xo->get_order_number(), 'status' => PG_Helpers::status_label( $xo->get_status() ) );
			}
		}
		foreach ( $lines as $id => $l ) {
			$out['lines'][] = array( 'item_id' => $id, 'name' => $l['item']->get_name(), 'qty' => $l['qty'], 'unit' => $l['unit'] );
		}
		if ( $r ) {
			$items = array();
			foreach ( (array) $r['items'] as $id => $q ) {
				$it = $o->get_item( $id );
				if ( $it ) {
					$items[] = array( 'item_id' => (int) $id, 'name' => $it->get_name(), 'qty' => (int) $q );
				}
			}
			$out['ret'] = array(
				'state'  => $r['state'],
				'source' => $r['source'],
				'guess'  => ! empty( $r['guess'] ),
				'items'  => $items,
				'note'   => isset( $r['note'] ) ? $r['note'] : '',
				'done'   => ! empty( $r['done_at'] ) ? PG_Helpers::bd_time( gmdate( 'Y-m-d H:i:s', (int) $r['done_at'] ) ) : '',
			);
		}
		return $out;
	}

	/* ---------- partial delivery: guess what came back ---------- */

	public static function on_status( $order_id, $from, $to, $order ) {
		if ( 'partial_delivered' !== $to || ! $order instanceof WC_Order || self::get( $order ) ) {
			return;
		}
		$c         = PG_Steadfast::active( $order->get_id() );
		$collected = $c && null !== $c->cod_collected ? (float) $c->cod_collected : null;
		$guess     = self::guess( $order, $collected, $c ? (float) $c->cod_amount : null );
		$order->update_meta_data(
			self::META,
			array(
				'state'  => 'pending',
				'source' => 'partial',
				'items'  => $guess['items'],
				'guess'  => true,
				'note'   => $guess['note'],
				'at'     => time(),
			)
		);
		$order->save_meta_data();
	}

	/**
	 * Which units came back: the kept units are the combination whose price best
	 * matches the money the courier collected (minus the delivery charge, which
	 * the customer pays either way).
	 *
	 * @return array [ items => [item_id => qty returned], note => text ]
	 */
	public static function guess( WC_Order $o, $collected, $cod = null ) {
		$lines = self::lines( $o );
		$units = array();
		foreach ( $lines as $id => $l ) {
			for ( $i = 0; $i < $l['qty']; $i++ ) {
				$units[] = array( $id, $l['unit'] );
			}
		}
		$n = count( $units );
		if ( ! $n ) {
			return array( 'items' => array(), 'note' => '' );
		}
		$all      = array_sum( array_column( $units, 1 ) );
		$advance  = $cod !== null ? max( 0, (float) $o->get_total() - (float) $o->get_total_refunded() - $cod ) : 0;
		$shipping = (float) $o->get_shipping_total() + (float) $o->get_shipping_tax();
		$fees     = 0;
		foreach ( $o->get_fees() as $f ) {
			$fees += (float) $f->get_total();
		}
		if ( null === $collected ) {
			return array( 'items' => array(), 'note' => 'collected_unknown' );
		}
		$target = $collected + $advance - $shipping - $fees; // value of the kept items
		$best   = null;
		if ( $n <= 14 ) {
			for ( $mask = 0; $mask < ( 1 << $n ) - 1; $mask++ ) { // at least one unit returned
				$kept = 0;
				for ( $b = 0; $b < $n; $b++ ) {
					if ( $mask & ( 1 << $b ) ) {
						$kept += $units[ $b ][1];
					}
				}
				$d = abs( $kept - $target );
				if ( null === $best || $d < $best[0] - 0.001 ) {
					$best = array( $d, $mask );
				}
			}
		} else {
			// Many units: keep the most expensive units while they fit.
			usort( $units, function ( $a, $b ) { return $b[1] <=> $a[1]; } );
			$mask = 0;
			$kept = 0;
			foreach ( $units as $b => $u ) {
				if ( $kept + $u[1] <= $target + 1 ) {
					$kept += $u[1];
					$mask |= ( 1 << min( $b, 60 ) );
				}
			}
			$best = array( abs( $kept - $target ), $mask );
		}
		$ret = array();
		for ( $b = 0; $b < $n; $b++ ) {
			if ( ! ( $best[1] & ( 1 << min( $b, 60 ) ) ) ) {
				$ret[ $units[ $b ][0] ] = ( $ret[ $units[ $b ][0] ] ?? 0 ) + 1;
			}
		}
		$note = $best[0] > 5 ? 'no_exact_match' : '';
		if ( $target >= $all - 0.5 ) {
			$note = 'nothing_missing'; // collected covers everything: maybe only the delivery charge was cut
		}
		return array( 'items' => $ret, 'note' => $note );
	}

	/* ---------- actions ---------- */

	private static function order_or_die() {
		PG_Helpers::guard_ajax();
		$o = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $o || 'shop_order' !== $o->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'product-glide' ) ) );
		}
		return $o;
	}

	private static function posted_items( WC_Order $o ) {
		$lines = self::lines( $o );
		$raw   = isset( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore
		if ( isset( $_POST['items'] ) && is_string( $_POST['items'] ) ) { // phpcs:ignore
			$raw = json_decode( wp_unslash( $_POST['items'] ), true ); // phpcs:ignore
		}
		$out = array();
		foreach ( (array) $raw as $id => $q ) {
			$id = absint( $id );
			$q  = absint( $q );
			if ( $q > 0 && isset( $lines[ $id ] ) ) {
				$out[ $id ] = min( $q, $lines[ $id ]['qty'] );
			}
		}
		return $out;
	}

	private static function out( WC_Order $o ) {
		$o = wc_get_order( $o->get_id() );
		wp_send_json_success( array( 'order' => PG_Orders::order_card_data( $o ) ) );
	}

	/** Correct which items are coming back. */
	public static function ajax_save() {
		$o = self::order_or_die();
		$r = self::get( $o );
		if ( ! $r || 'pending' !== $r['state'] ) {
			$r = array( 'state' => 'pending', 'source' => 'partial', 'at' => time() );
		}
		$r['items'] = self::posted_items( $o );
		$r['guess'] = false;
		$r['note']  = '';
		$o->update_meta_data( self::META, $r );
		$o->save_meta_data();
		self::out( $o );
	}

	/** The returned items are in hand: back to stock (+ refund for a partial delivery). */
	public static function ajax_receive() {
		$o = self::order_or_die();
		$r = self::get( $o );
		if ( ! $r || 'pending' !== $r['state'] ) {
			wp_send_json_error( array( 'message' => __( 'Nothing is waiting to come back on this order.', 'product-glide' ) ) );
		}
		$items = self::posted_items( $o );
		if ( ! $items ) {
			$items = self::posted_items_from( $o, (array) $r['items'] );
		}
		if ( ! $items ) {
			wp_send_json_error( array( 'message' => __( 'Choose which items came back.', 'product-glide' ) ) );
		}
		$res = self::restock( $o, $items, 'partial' === $r['source'] );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$r['items']   = $items;
		$r['state']   = 'received';
		$r['done_at'] = time();
		$r['done_by'] = get_current_user_id();
		$o            = wc_get_order( $o->get_id() );
		$o->update_meta_data( self::META, $r );
		$o->save_meta_data();
		if ( class_exists( 'PG_Team' ) ) {
			PG_Team::log( $o->get_id(), 'status', 'Return received (' . count( $items ) . ' line)' );
		}
		self::out( $o );
	}

	private static function posted_items_from( WC_Order $o, array $map ) {
		$lines = self::lines( $o );
		$out   = array();
		foreach ( $map as $id => $q ) {
			if ( isset( $lines[ (int) $id ] ) && (int) $q > 0 ) {
				$out[ (int) $id ] = min( (int) $q, $lines[ (int) $id ]['qty'] );
			}
		}
		return $out;
	}

	/** Nothing will come back (lost / kept by the courier): close without touching stock. */
	public static function ajax_close() {
		$o = self::order_or_die();
		$r = self::get( $o );
		if ( ! $r ) {
			wp_send_json_error( array( 'message' => __( 'Nothing is waiting to come back on this order.', 'product-glide' ) ) );
		}
		$r['state']   = 'closed';
		$r['done_at'] = time();
		$r['done_by'] = get_current_user_id();
		$o->update_meta_data( self::META, $r );
		$o->save_meta_data();
		$o->add_order_note( __( 'Return closed without restocking (items did not come back).', 'product-glide' ) );
		self::out( $o );
	}

	/**
	 * WooCommerce refund with restock. $charge = refund the items' price too (partial delivery);
	 * otherwise a ৳0 refund that only puts the items back in stock (exchange: the money moved to the exchange order).
	 */
	private static function restock( WC_Order $o, array $items, $charge ) {
		$lines  = self::lines( $o );
		$refund = array();
		$amount = 0;
		$names  = array();
		foreach ( $items as $id => $q ) {
			if ( ! isset( $lines[ $id ] ) ) {
				continue;
			}
			$money         = $charge ? round( $lines[ $id ]['unit'] * $q, 2 ) : 0;
			$refund[ $id ] = array( 'qty' => $q, 'refund_total' => $money, 'refund_tax' => array() );
			$amount       += $money;
			$names[]       = $lines[ $id ]['item']->get_name() . ' ×' . $q;
		}
		if ( ! $refund ) {
			return new WP_Error( 'pg_ret', __( 'Choose which items came back.', 'product-glide' ) );
		}
		$amount = min( $amount, max( 0, (float) $o->get_total() - (float) $o->get_total_refunded() ) );
		// Bookkeeping only: never email the customer about these refunds.
		$off = function () { return false; };
		add_filter( 'woocommerce_email_enabled_customer_refunded_order', $off, 99 );
		add_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', $off, 99 );
		$r      = wc_create_refund(
			array(
				'order_id'       => $o->get_id(),
				'amount'         => wc_format_decimal( $amount, 2 ),
				'reason'         => ( $charge ? __( 'Partial delivery — returned to stock: ', 'product-glide' ) : __( 'Exchange — wrong item returned to stock: ', 'product-glide' ) ) . implode( ', ', $names ),
				'line_items'     => $refund,
				'refund_payment' => false,
				'restock_items'  => true,
			)
		);
		remove_filter( 'woocommerce_email_enabled_customer_refunded_order', $off, 99 );
		remove_filter( 'woocommerce_email_enabled_customer_partially_refunded_order', $off, 99 );
		return is_wp_error( $r ) ? $r : true;
	}

	/* ---------- exchange ---------- */

	public static function ajax_exchange() {
		$o    = self::order_or_die();
		$back = self::posted_items( $o ); // wrong items to collect
		if ( ! $back ) {
			wp_send_json_error( array( 'message' => __( 'Choose the item the rider must bring back.', 'product-glide' ) ) );
		}
		$send = isset( $_POST['send'] ) ? json_decode( wp_unslash( $_POST['send'] ), true ) : array(); // phpcs:ignore
		$add  = array();
		foreach ( (array) $send as $s ) {
			$p = wc_get_product( absint( isset( $s['id'] ) ? $s['id'] : 0 ) );
			$q = max( 1, absint( isset( $s['qty'] ) ? $s['qty'] : 1 ) );
			if ( $p ) {
				$add[] = array( $p, $q );
			}
		}
		if ( ! $add ) {
			wp_send_json_error( array( 'message' => __( 'Choose the item to send instead.', 'product-glide' ) ) );
		}
		$cod = max( 0, (float) PG_Helpers::post( 'cod', 0 ) );

		$x = wc_create_order( array( 'created_via' => 'product-glide' ) );
		if ( is_wp_error( $x ) ) {
			wp_send_json_error( array( 'message' => $x->get_error_message() ) );
		}
		$goods = 0;
		foreach ( $add as $a ) {
			$x->add_product( $a[0], $a[1] );
			$goods += (float) $a[0]->get_price() * $a[1];
		}
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ) as $f ) {
			if ( is_callable( array( $o, 'get_billing_' . $f ) ) ) {
				$x->{'set_billing_' . $f}( $o->{'get_billing_' . $f}() );
			}
			if ( 'email' !== $f && is_callable( array( $o, 'get_shipping_' . $f ) ) ) {
				$x->{'set_shipping_' . $f}( $o->{'get_shipping_' . $f}() );
			}
		}
		foreach ( $o->get_meta_data() as $m ) {
			if ( 0 === strpos( $m->key, '_bdsa_' ) ) {
				$x->update_meta_data( $m->key, $m->value );
			}
		}
		// Credit for what the customer already paid, so the order total = what the courier collects.
		$credit = round( $cod - $goods, 2 );
		if ( abs( $credit ) >= 0.01 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( sprintf( /* translators: %s: order number */ __( 'Exchange for order #%s', 'product-glide' ), $o->get_order_number() ) );
			$fee->set_amount( $credit );
			$fee->set_total( $credit );
			$fee->set_tax_status( 'none' );
			$x->add_item( $fee );
		}
		$x->set_payment_method( 'cod' );
		$x->set_payment_method_title( __( 'Cash on delivery', 'product-glide' ) );
		$x->calculate_totals( false );
		$names = array();
		foreach ( $back as $id => $q ) {
			$names[] = $o->get_item( $id )->get_name() . ' ×' . $q;
		}
		$x->update_meta_data( '_pg_exchange_of', $o->get_id() );
		$x->update_meta_data( '_pg_exchange_back', implode( ', ', $names ) );
		$x->set_customer_note( sprintf( /* translators: 1: items, 2: order number */ __( 'EXCHANGE: collect %1$s from the customer (order #%2$s).', 'product-glide' ), implode( ', ', $names ), $o->get_order_number() ) );
		$x->save();
		// "processing" takes the new item out of stock; the team made it, so it is confirmed already.
		$x->update_status( 'processing', sprintf( __( 'Exchange order for #%s created from Product Glide.', 'product-glide' ), $o->get_order_number() ) );
		if ( wc_is_order_status( 'wc-confirmed' ) ) {
			$x = wc_get_order( $x->get_id() );
			$x->update_meta_data( '_pg_confirmed', 'call' );
			$x->update_meta_data( '_pg_confirmed_at', time() );
			$x->update_meta_data( '_pg_confirmed_by', get_current_user_id() );
			$x->update_status( 'confirmed', __( 'Exchange: confirmed by the team.', 'product-glide' ) );
		}

		$list   = array_filter( (array) $o->get_meta( '_pg_exchanges' ) );
		$list[] = $x->get_id();
		$o->update_meta_data( '_pg_exchanges', array_values( $list ) );
		$o->update_meta_data(
			self::META,
			array(
				'state'    => 'pending',
				'source'   => 'exchange',
				'items'    => $back,
				'exchange' => $x->get_id(),
				'at'       => time(),
				'by'       => get_current_user_id(),
			)
		);
		$o->save();
		$o->add_order_note( sprintf( __( 'Exchange order #%1$s created. Waiting for %2$s to come back.', 'product-glide' ), $x->get_order_number(), implode( ', ', $names ) ) );
		wp_send_json_success(
			array(
				'order'    => PG_Orders::order_card_data( wc_get_order( $o->get_id() ) ),
				'exchange' => array( 'id' => $x->get_id(), 'number' => $x->get_order_number(), 'total' => (float) $x->get_total() ),
			)
		);
	}

	/** Courier note for an exchange parcel. */
	public static function courier_note( WC_Order $o ) {
		$of = $o->get_meta( '_pg_exchange_of' );
		if ( ! $of ) {
			return '';
		}
		$back = (string) $o->get_meta( '_pg_exchange_back' );
		$eo   = wc_get_order( $of );
		return sprintf( 'EXCHANGE: deliver this and collect %s from the customer (old order #%s)', $back, $eo ? $eo->get_order_number() : $of );
	}

	/** Orders waiting for something to come back (for the "Returns" list). */
	public static function pending_ids( $limit = 300 ) {
		global $wpdb;
		$t = PG_Helpers::hpos() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$c = PG_Helpers::hpos() ? 'order_id' : 'post_id';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT {$c} FROM {$t} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY {$c} DESC LIMIT %d", self::META, '%"state";s:7:"pending"%', $limit ) ) ); // phpcs:ignore
	}
}
