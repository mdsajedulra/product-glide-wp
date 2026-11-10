<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order dashboard data: listing, search, notes, history, status changes.
 */
class PG_Orders {

	public static function tabs() {
		return array(
			'all'               => __( 'All', 'product-glide' ),
			'call'              => __( '📞 Call now', 'product-glide' ),
			'rescheduled'       => __( "📅 Customer's date", 'product-glide' ),
			'later'             => __( '⏳ Call later', 'product-glide' ),
			'unreachable'       => __( '❌ Not reachable', 'product-glide' ),
			'processing'        => __( 'Processing', 'product-glide' ),
			'courier_sent'      => __( 'Courier Sent', 'product-glide' ),
			'courier_pending'   => __( 'Courier Pending', 'product-glide' ),
			'completed'         => __( 'Delivered', 'product-glide' ),
			'partial_delivered' => __( 'Partial', 'product-glide' ),
			'on-hold'           => __( 'On Hold', 'product-glide' ),
			'cancelled'         => __( 'Cancelled', 'product-glide' ),
		);
	}

	public static function quick_notes() {
		return apply_filters(
			'pg_quick_notes',
			array(
				'Call not received',
				'Phone switched off',
				'Will call again later',
				'Customer confirmed the order',
				'Customer asked to cancel',
				'Order canceled',
				'Wrong phone number',
				'Address incomplete — asked customer',
				'Customer will confirm later',
				'Out of stock — waiting for restock',
				'Customer requested hold',
				'Fake / fraud order suspected',
			)
		);
	}

	public static function init() {
		foreach ( array( 'list', 'notes', 'add_note', 'edit_note', 'delete_note', 'history', 'set_status', 'detail', 'counts', 'save' ) as $a ) {
			add_action( 'wp_ajax_pg_orders_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ------------------------------------------------------------------ */

	public static function order_card_data( WC_Order $order, $dup_rows = null ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$qty     = max( 1, (int) $item->get_quantity() );
			$items[] = array(
				'item_id'    => $item->get_id(),
				'thumb'      => $product && $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
				'name'       => $item->get_name(),
				'qty'        => (int) $item->get_quantity(),
				'unit'       => (float) wc_format_decimal( $item->get_subtotal() / $qty, 2 ),
				'total'      => (float) $item->get_total(),
				'subtotal'   => (float) $item->get_subtotal(),
				'product_id' => $product ? $product->get_id() : 0,
				'sku'        => $product ? $product->get_sku() : '',
			);
		}
		$c       = PG_Steadfast::active( $order->get_id() );
		$phone   = $order->get_billing_phone();
		$created = $order->get_date_created();
		$data    = array(
			'id'            => $order->get_id(),
			'number'        => $order->get_order_number(),
			'date'          => $created ? PG_Helpers::bd_time( $created ) : '',
			'date_short'    => $created ? PG_Helpers::bd_time( $created, 'd/m/Y' ) : '',
			'status'        => $order->get_status(),
			'status_label'  => PG_Helpers::status_label( $order->get_status() ),
			'name'          => PG_Helpers::order_name( $order ),
			'phone'         => $phone,
			'phone_bd'      => PG_Helpers::bd_phone( $phone ),
			'alt_phone'     => $order->get_shipping_phone(),
			'city'          => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
			'address_1'     => $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
			'coupons'       => $order->get_coupon_codes(),
			'email'         => $order->get_billing_email(),
			'address'       => PG_Helpers::order_address( $order ),
			'addr_typed'    => (string) $order->get_meta( '_bdsa_typed_address' ),
			'addr_clean'    => (string) $order->get_meta( '_bdsa_refined_address' ),
			'addr_zone'     => (string) $order->get_meta( '_bdsa_zone' ),
			'customer_note' => $order->get_customer_note(),
			'items'         => $items,
			'item_count'    => (int) $order->get_item_count(),
			'subtotal'      => (float) $order->get_subtotal(),
			'shipping'      => (float) $order->get_shipping_total(),
			'discount'      => (float) $order->get_total_discount(),
			'total'         => (float) $order->get_total(),
			'cod'           => PG_Steadfast::default_cod( $order ),
			'payment'       => $order->get_payment_method_title(),
			'txn_id'        => (string) $order->get_transaction_id(),
			'ship_method'   => (string) $order->get_shipping_method(),
			'company'       => $order->get_shipping_company() ? $order->get_shipping_company() : $order->get_billing_company(),
			'source'        => self::source_label( $order ),
			'account'       => self::account_label( $order ),
			'edit_url'      => $order->get_edit_order_url(),
			'courier'       => $c ? array(
				'cid'      => $c->consignment_id,
				'tracking' => $c->tracking_code,
				'status'   => $c->courier_status,
				'invoice'  => $c->invoice,
				'cod'      => (float) $c->cod_amount,
				'message'  => (string) $c->tracking_message,
				'url'      => PG_Courier::tracking_url( $c->provider, $c->tracking_code ),
				'provider' => $c->provider ? $c->provider : 'steadfast',
				'label'    => PG_Courier::label( $c->provider ? $c->provider : 'steadfast' ),
				'charge'   => null === $c->delivery_charge ? null : (float) $c->delivery_charge,
				'sent_at'  => PG_Helpers::bd_time( $c->created_at ),
			) : null,
			'next_invoice'  => PG_Steadfast::invoice_for( $order ),
			'resched'       => PG_Reschedule::card( $order ),
			'wa'            => self::wa_card( $order ),
			'confirm'       => PG_Confirm::card( $order ),
			'call_why'      => PG_Confirm::reasons( $order ),
			'call_bucket'   => PG_Confirm::bucket( $order ),
			'calls'         => PG_Confirm::calls_card( $order ),
			'staff_notes'   => self::staff_notes( $order->get_id() ),
			'dup'           => null,
		);
		if ( null === $dup_rows ) {
			$map      = PG_Helpers::orders_by_phone_keys( array( PG_Helpers::phone_key( $phone ) ) );
			$dup_rows = isset( $map[ PG_Helpers::phone_key( $phone ) ] ) ? $map[ PG_Helpers::phone_key( $phone ) ] : array();
		}
		$data['dup'] = self::dup_info( $order, $dup_rows );
		return $data;
	}

	/** Where the order came from (WooCommerce order attribution), e.g. "facebook / cpc". */
	private static function source_label( WC_Order $order ) {
		$type = (string) $order->get_meta( '_wc_order_attribution_source_type' );
		$src  = (string) $order->get_meta( '_wc_order_attribution_utm_source' );
		$med  = (string) $order->get_meta( '_wc_order_attribution_utm_medium' );
		if ( 'admin' === $type ) {
			return __( 'Created by admin', 'product-glide' );
		}
		if ( 'typein' === $type ) {
			return __( 'Direct visit', 'product-glide' );
		}
		if ( 'mobile_app' === $type ) {
			return __( 'Mobile app', 'product-glide' );
		}
		$out = $src && '(direct)' !== $src ? $src : '';
		if ( $out && $med && '(none)' !== $med ) {
			$out .= ' / ' . $med;
		}
		if ( ! $out ) {
			$via = (string) $order->get_created_via();
			if ( 'product-glide' === $via || 'admin' === $via ) {
				return __( 'Created by admin', 'product-glide' );
			}
		}
		return $out;
	}

	/** Customer account name, or '' for guest checkouts. */
	private static function account_label( WC_Order $order ) {
		$uid = $order->get_customer_id();
		if ( ! $uid ) {
			return '';
		}
		$u = get_userdata( $uid );
		return $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : '';
	}

	/**
	 * "Duplicate" = another non-cancelled order from the same phone within 7 days.
	 * "Repeat"    = any other order from the same phone.
	 */
	private static function dup_info( WC_Order $order, array $rows ) {
		$others = array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $order ) {
					return $r['id'] !== $order->get_id();
				}
			)
		);
		if ( ! $others ) {
			return null;
		}
		$mine = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		$dup  = array();
		foreach ( $others as $r ) {
			$ts = strtotime( $r['created'] . ' UTC' );
			if ( abs( $ts - $mine ) <= 7 * DAY_IN_SECONDS && ! in_array( $r['status'], array( 'cancelled', 'failed', 'refunded' ), true ) ) {
				$dup[] = $r['id'];
			}
		}
		return array(
			'type'   => $dup ? 'duplicate' : 'repeat',
			'count'  => count( $others ) + 1,
			'others' => wp_list_pluck( $others, 'id' ),
			'dup_ids' => $dup,
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	public static function ajax_counts() {
		PG_Helpers::guard_ajax();
		wp_send_json_success( self::counts() );
	}

	public static function counts() {
		$counts = array();
		$total  = 0;
		foreach ( array_keys( wc_get_order_statuses() ) as $st ) {
			$n                         = (int) wc_orders_count( substr( $st, 3 ) );
			$counts[ substr( $st, 3 ) ] = $n;
			$total                    += $n;
		}
		$counts['all']  = $total;
		$cc                    = PG_Confirm::counts();
		$counts['call']        = isset( $cc['call'] ) ? (int) $cc['call'] : 0;
		$counts['later']       = isset( $cc['later'] ) ? (int) $cc['later'] : 0;
		$counts['unreachable'] = isset( $cc['unreachable'] ) ? (int) $cc['unreachable'] : 0;
		return $counts;
	}

	public static function ajax_list() {
		PG_Helpers::guard_ajax();
		$status   = sanitize_key( PG_Helpers::post( 'status', 'all' ) );
		$q        = trim( PG_Helpers::post( 'q', '' ) );
		$page     = max( 1, PG_Helpers::post_int( 'page', 1 ) );
		$per_page = PG_Helpers::post_int( 'per_page', 25 );
		$per_page = in_array( $per_page, array( 10, 25, 50 ), true ) ? $per_page : 25;

		$valid = array_map(
			function ( $s ) {
				return substr( $s, 3 );
			},
			array_keys( wc_get_order_statuses() )
		);
		$status_arg = ( 'all' === $status || ! in_array( $status, $valid, true ) ) ? $valid : array( $status );
		if ( in_array( $status, array( 'call', 'later', 'unreachable' ), true ) ) {
			$status_arg = PG_Confirm::OPEN;
		}

		if ( '' !== $q ) {
			$ids   = self::search_ids( $q );
			$found = array();
			foreach ( $ids as $id ) {
				$o = wc_get_order( $id );
				if ( $o && 'shop_order' === $o->get_type() && in_array( $o->get_status(), $status_arg, true ) ) {
					$found[] = $o;
				}
			}
			usort(
				$found,
				function ( $a, $b ) {
					return $b->get_id() - $a->get_id();
				}
			);
			$total  = count( $found );
			$orders = array_slice( $found, ( $page - 1 ) * $per_page, $per_page );
		} elseif ( in_array( $status, array( 'call', 'later', 'unreachable' ), true ) && '' === $q ) {
			// Only the orders someone needs to call, most urgent first.
			$all    = PG_Confirm::ids( $status );
			$total  = count( $all );
			$orders = array_filter( array_map( 'wc_get_order', array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) ) );
		} elseif ( PG_Reschedule::STATUS === $status ) {
			// Soonest follow-up first.
			$all    = PG_Reschedule::ids();
			$total  = count( $all );
			$orders = array_filter( array_map( 'wc_get_order', array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) ) );
		} else {
			$res    = wc_get_orders(
				array(
					'type'     => 'shop_order',
					'status'   => $status_arg,
					'limit'    => $per_page,
					'paged'    => $page,
					'paginate' => true,
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			);
			$orders = $res->orders;
			$total  = (int) $res->total;
		}

		$keys = array();
		foreach ( $orders as $o ) {
			$keys[] = PG_Helpers::phone_key( $o->get_billing_phone() );
		}
		$dups  = PG_Helpers::orders_by_phone_keys( $keys );
		self::prefetch_notes(
			array_map(
				function ( $o ) {
					return $o->get_id();
				},
				$orders
			)
		);
		$fraud = PG_Fraud::cached_many(
			array_map(
				function ( $o ) {
					return $o->get_billing_phone();
				},
				$orders
			)
		);
		$out = array();
		foreach ( $orders as $o ) {
			$k     = PG_Helpers::phone_key( $o->get_billing_phone() );
			$row   = self::order_card_data( $o, isset( $dups[ $k ] ) ? $dups[ $k ] : array() );
			$p     = PG_Helpers::bd_phone( $o->get_billing_phone() );
			$row['fraud'] = $p && isset( $fraud[ $p ] ) ? $fraud[ $p ] : null;
			$out[] = $row;
		}

		wp_send_json_success(
			array(
				'orders'   => $out,
				'total'    => $total,
				'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
				'page'     => $page,
				'counts'   => self::counts(),
				'kpis'     => PG_Steadfast::kpis(),
			)
		);
	}

	/** Order IDs matching an order number, phone, or name. */
	public static function search_ids( $q ) {
		$ids    = array();
		$digits = preg_replace( '/\D+/', '', $q );
		$is_num = '' !== $digits && preg_match( '/^[\s#+\d\-]+$/', $q );

		if ( $is_num && strlen( $digits ) <= 8 ) {
			$o = wc_get_order( (int) $digits );
			if ( $o ) {
				$ids[] = $o->get_id();
			}
		}
		if ( $is_num && strlen( $digits ) >= 10 ) {
			$map = PG_Helpers::orders_by_phone_keys( array( PG_Helpers::phone_key( $digits ) ) );
			foreach ( $map as $rows ) {
				$ids = array_merge( $ids, wp_list_pluck( $rows, 'id' ) );
			}
		}
		if ( ! $is_num || ( strlen( $digits ) > 3 && strlen( $digits ) < 10 && ! $ids ) ) {
			if ( PG_Helpers::hpos() ) {
				$found = wc_get_orders(
					array(
						's'      => $q,
						'type'   => 'shop_order',
						'limit'  => 300,
						'return' => 'ids',
					)
				);
			} else {
				$ds    = WC_Data_Store::load( 'order' );
				$found = is_callable( array( $ds, 'search_orders' ) ) ? $ds->search_orders( $q ) : array();
			}
			$ids = array_merge( $ids, array_map( 'intval', (array) $found ) );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		rsort( $ids );
		return array_slice( $ids, 0, 300 );
	}

	/** Staff notes shown on the card without opening the Notes panel. */
	private static $note_cache = array();

	/** True for notes a person wrote (not WooCommerce / status-change notes). */
	public static function is_staff_note( $content, $author ) {
		if ( '' === (string) $author || 'woocommerce' === strtolower( (string) $author ) || 'system' === strtolower( (string) $author ) ) {
			return false;
		}
		$t = trim( wp_strip_all_tags( (string) $content ) );
		// Leave out automatic notes (status changes, courier bookings, edits, stock).
		return ! preg_match( '/Order status changed from|^Status changed from Product Glide|^Rescheduled to |^Follow-up done after reschedule|^(Steadfast|Carrybee) booked|^Carrybee .{0,40}(created|cancelled)|voided in Product Glide|^Order edited in Product Glide|^Created from Product Glide|^Adjusted stock|^(Added|Deleted|Removed) line items?|^Steadfast return request|^WhatsApp (template|send)/i', $t );
	}

	/** Latest staff notes for many orders in one query: id => array( total, notes[] ). */
	public static function prefetch_notes( array $ids, $per = 3 ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		foreach ( $ids as $id ) {
			self::$note_cache[ $id ] = array( 'total' => 0, 'notes' => array() );
		}
		if ( ! $ids ) {
			return;
		}
		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT c.comment_ID, c.comment_post_ID, c.comment_content, c.comment_author, c.comment_date_gmt, m.meta_value AS is_customer
			 FROM {$wpdb->comments} c LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'is_customer_note'
			 WHERE c.comment_type = 'order_note' AND c.comment_post_ID IN (" . implode( ',', $ids ) . ')
			 ORDER BY c.comment_ID DESC'
		);
		foreach ( $rows as $r ) {
			if ( ! self::is_staff_note( $r->comment_content, $r->comment_author ) ) {
				continue;
			}
			$id = (int) $r->comment_post_ID;
			self::$note_cache[ $id ]['total']++;
			if ( count( self::$note_cache[ $id ]['notes'] ) < $per ) {
				self::$note_cache[ $id ]['notes'][] = array(
					'content'  => wp_strip_all_tags( $r->comment_content ),
					'by'       => (string) $r->comment_author,
					'date'     => PG_Helpers::bd_time( $r->comment_date_gmt, 'd M, h:i A' ),
					'customer' => ! empty( $r->is_customer ),
				);
			}
		}
	}

	public static function staff_notes( $order_id ) {
		if ( ! isset( self::$note_cache[ $order_id ] ) ) {
			self::prefetch_notes( array( $order_id ) );
		}
		return self::$note_cache[ $order_id ];
	}

	public static function notes_data( $order_id ) {
		$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
		$out   = array();
		foreach ( $notes as $n ) {
			$system = 'system' === strtolower( (string) $n->added_by ) || '' === (string) $n->added_by;
			$type   = $n->customer_note ? 'customer' : ( $system ? 'system' : 'private' );
			$out[]  = array(
				'id'       => (int) $n->id,
				'content'  => wp_strip_all_tags( $n->content ),
				'date'     => PG_Helpers::bd_time( $n->date_created ),
				'by'       => $system ? 'System' : (string) $n->added_by,
				'type'     => $type,
				'editable' => 'private' === $type,
				'staff'    => ! $system && self::is_staff_note( $n->content, $n->added_by ),
				'short'    => PG_Helpers::bd_time( $n->date_created, 'd M, h:i A' ),
			);
		}
		return $out;
	}

	public static function ajax_notes() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		wp_send_json_success( array( 'notes' => self::notes_data( $order->get_id() ) ) );
	}

	public static function ajax_add_note() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		$text  = PG_Helpers::post_textarea( 'note' );
		if ( ! $order || '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Write a note first.', 'product-glide' ) ) );
		}
		$is_customer = (bool) PG_Helpers::post_int( 'customer' );
		self::add_staff_note( $order, $text, $is_customer );
		PG_Team::log( $order->get_id(), 'note', $text );
		$outcome = PG_Confirm::outcome_from_text( $text );
		if ( $outcome && in_array( $order->get_status(), PG_Confirm::OPEN, true ) && ! $order->get_meta( PG_Confirm::HOW ) ) {
			PG_Confirm::log_call( $order, $outcome, false ); // a typed "Call not received" counts as a call attempt
		}
		do_action( 'pg_staff_note', $order, $text );
		wp_send_json_success( array( 'notes' => self::notes_data( $order->get_id() ) ) );
	}

	/** WhatsApp delivery state + unread replies for the order card. */
	public static function wa_card( $order ) {
		if ( ! PG_WhatsApp::configured() ) {
			return null;
		}
		global $wpdb;
		$key = PG_WA_Inbox::key( $order->get_billing_phone() );
		$c   = $key ? $wpdb->get_row( $wpdb->prepare( 'SELECT unread, has_wa FROM ' . PG_WA_Inbox::t_contact() . ' WHERE phone = %s', $key ) ) : null; // phpcs:ignore
		$st  = (string) $order->get_meta( '_pg_wa_state' );
		if ( $c && '0' === (string) $c->has_wa && '' === $st ) {
			$st = 'none';
		}
		return array(
			'state'  => $st,
			'unread' => $c ? (int) $c->unread : 0,
			'phone'  => $key,
		);
	}

	/**
	 * Add a note in the name of the logged-in staff member.
	 * WooCommerce only names the author when the user has edit_shop_orders; Order Managers do not,
	 * so their notes were saved as "WooCommerce" and looked like system notes.
	 */
	public static function add_staff_note( $order, $text, $is_customer = false ) {
		$user = wp_get_current_user();
		$f    = null;
		if ( $user && $user->exists() ) {
			$f = function ( $data ) use ( $user ) {
				$data['comment_author']       = $user->display_name ? $user->display_name : $user->user_login;
				$data['comment_author_email'] = $user->user_email;
				return $data;
			};
			add_filter( 'woocommerce_new_order_note_data', $f, 99 );
		}
		$id = $order->add_order_note( $text, $is_customer ? 1 : 0, true );
		if ( $f ) {
			remove_filter( 'woocommerce_new_order_note_data', $f, 99 );
		}
		return $id;
	}

	/**
	 * One-time repair: notes Order Managers wrote before 1.10.3 were saved as "WooCommerce".
	 * The team activity log knows who wrote each note, so give those notes their real author.
	 */
	public static function repair_note_authors() {
		if ( get_option( 'pg_note_authors_fixed' ) ) {
			return;
		}
		update_option( 'pg_note_authors_fixed', 1, false );
		global $wpdb;
		$log  = PG_Team::table();
		$rows = $wpdb->get_results( "SELECT user_id, order_id, detail, created_at FROM {$log} WHERE action = 'note' ORDER BY id DESC LIMIT 5000" ); // phpcs:ignore
		$done = 0;
		foreach ( (array) $rows as $r ) {
			$u = get_userdata( (int) $r->user_id );
			if ( ! $u ) {
				continue;
			}
			$cid = $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type = 'order_note' AND comment_author IN ('WooCommerce','System','') AND LEFT(comment_content, 250) = %s AND comment_date_gmt BETWEEN DATE_SUB(%s, INTERVAL 3 MINUTE) AND DATE_ADD(%s, INTERVAL 3 MINUTE) LIMIT 1",
					(int) $r->order_id,
					(string) $r->detail,
					$r->created_at,
					$r->created_at
				)
			);
			if ( $cid ) {
				$wpdb->update( $wpdb->comments, array( 'comment_author' => $u->display_name ? $u->display_name : $u->user_login, 'comment_author_email' => $u->user_email ), array( 'comment_ID' => (int) $cid ) ); // phpcs:ignore
				clean_comment_cache( (int) $cid );
				++$done;
			}
		}
		update_option( 'pg_note_authors_fixed', max( 1, $done ), false );
	}

	private static function own_note( $order_id, $note_id ) {
		$c = get_comment( $note_id );
		if ( ! $c || (int) $c->comment_post_ID !== (int) $order_id || 'order_note' !== $c->comment_type ) {
			return null;
		}
		if ( get_comment_meta( $note_id, 'is_customer_note', true ) ) {
			return null;
		}
		$by = (string) $c->comment_author;
		if ( '' === $by || 'woocommerce' === strtolower( $by ) || 'system' === strtolower( $by ) ) {
			return null;
		}
		return $c;
	}

	public static function ajax_edit_note() {
		PG_Helpers::guard_ajax();
		$oid  = PG_Helpers::post_int( 'order_id' );
		$nid  = PG_Helpers::post_int( 'note_id' );
		$text = PG_Helpers::post_textarea( 'note' );
		if ( ! self::own_note( $oid, $nid ) || '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Only your own private notes can be edited.', 'product-glide' ) ) );
		}
		wp_update_comment( array( 'comment_ID' => $nid, 'comment_content' => $text ) );
		wp_send_json_success( array( 'notes' => self::notes_data( $oid ) ) );
	}

	public static function ajax_delete_note() {
		PG_Helpers::guard_ajax();
		$oid = PG_Helpers::post_int( 'order_id' );
		$nid = PG_Helpers::post_int( 'note_id' );
		if ( ! self::own_note( $oid, $nid ) ) {
			wp_send_json_error( array( 'message' => __( 'Only private notes can be deleted.', 'product-glide' ) ) );
		}
		wc_delete_order_note( $nid );
		wp_send_json_success( array( 'notes' => self::notes_data( $oid ) ) );
	}

	public static function ajax_history() {
		PG_Helpers::guard_ajax();
		$phone = PG_Helpers::post( 'phone' );
		$key   = PG_Helpers::phone_key( $phone );
		$map   = PG_Helpers::orders_by_phone_keys( array( $key ) );
		$rows  = isset( $map[ $key ] ) ? array_slice( $map[ $key ], 0, 50 ) : array();
		$out   = array();
		foreach ( $rows as $r ) {
			$o = wc_get_order( $r['id'] );
			if ( ! $o ) {
				continue;
			}
			$notes = array_values(
				array_filter(
					self::notes_data( $o->get_id() ),
					function ( $n ) {
						return 'system' !== $n['type'];
					}
				)
			);
			$c     = PG_Steadfast::active( $o->get_id() );
			$out[] = array(
				'id'           => $o->get_id(),
				'number'       => $o->get_order_number(),
				'date'         => PG_Helpers::bd_time( $o->get_date_created() ),
				'status'       => $o->get_status(),
				'status_label' => PG_Helpers::status_label( $o->get_status() ),
				'total'        => (float) $o->get_total(),
				'items'        => PG_Helpers::items_text( $o ),
				'name'         => PG_Helpers::order_name( $o ),
				'address'      => PG_Helpers::order_address( $o ),
				'courier'      => $c ? PG_Courier::label( $c->provider ) . ' · ' . $c->courier_status . ( $c->tracking_code ? ' · ' . $c->tracking_code : '' ) : '',
				'notes'        => array_slice( $notes, 0, 5 ),
				'edit_url'     => $o->get_edit_order_url(),
			);
		}
		wp_send_json_success( array( 'orders' => $out, 'phone' => PG_Helpers::bd_phone( $phone ) ) );
	}

	public static function ajax_set_status() {
		PG_Helpers::guard_ajax();
		$order  = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		$status = sanitize_key( PG_Helpers::post( 'status' ) );
		if ( ! $order || ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order or status.', 'product-glide' ) ) );
		}
		$order->update_status( $status, __( 'Status changed from Product Glide.', 'product-glide' ), true );
		wp_send_json_success( array( 'order' => self::order_card_data( wc_get_order( $order->get_id() ) ) ) );
	}

	public static function ajax_detail() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		$d          = self::order_card_data( $order );
		$d['notes'] = self::notes_data( $order->get_id() );
		wp_send_json_success( array( 'order' => $d ) );
	}

	/**
	 * Save edits from the order editor: customer, items (price/qty/add/remove),
	 * delivery charge and an extra discount spread across lines. Stock is adjusted
	 * the same way the WooCommerce order screen does it.
	 */
	public static function ajax_save() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		$c     = PG_Helpers::post_json( 'customer' );
		$items = PG_Helpers::post_json( 'items' );
		if ( ! $items ) {
			wp_send_json_error( array( 'message' => __( 'An order needs at least one product.', 'product-glide' ) ) );
		}
		$phone = PG_Helpers::bd_phone( isset( $c['phone'] ) ? $c['phone'] : '' );
		if ( ! $phone ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid BD mobile number.', 'product-glide' ) ) );
		}
		$changes = array();

		/* Customer */
		$name  = sanitize_text_field( isset( $c['name'] ) ? $c['name'] : '' );
		$parts = preg_split( '/\s+/', trim( $name ), 2 );
		$addr  = array(
			'first_name' => $parts[0],
			'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
			'address_1'  => sanitize_text_field( isset( $c['address'] ) ? $c['address'] : '' ),
			'city'       => sanitize_text_field( isset( $c['city'] ) ? $c['city'] : '' ),
		);
		$old_name = PG_Helpers::order_name( $order );
		$old_addr = PG_Helpers::order_address( $order );
		$old_ph   = $order->get_billing_phone();
		foreach ( $addr as $k => $v ) {
			$order->{'set_billing_' . $k}( $v );
			$order->{'set_shipping_' . $k}( $v );
		}
		$order->set_billing_address_2( '' );
		$order->set_shipping_address_2( '' );
		$order->set_billing_phone( $phone );
		$alt = PG_Helpers::bd_phone( isset( $c['alt_phone'] ) ? $c['alt_phone'] : '' );
		$order->set_shipping_phone( $alt ? $alt : '' );
		if ( isset( $c['note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $c['note'] ) );
		}
		if ( isset( $c['email'] ) ) {
			$email = sanitize_email( $c['email'] );
			if ( '' !== trim( $c['email'] ) && ! is_email( $email ) ) {
				wp_send_json_error( array( 'message' => __( 'Enter a valid email address or leave it empty.', 'product-glide' ) ) );
			}
			if ( $email !== $order->get_billing_email() ) {
				$order->set_billing_email( $email );
				$changes[] = 'email';
			}
		}
		if ( $old_name !== $name ) {
			$changes[] = 'name';
		}
		if ( PG_Helpers::bd_phone( $old_ph ) !== $phone ) {
			$changes[] = 'phone';
		}
		if ( $old_addr !== PG_Helpers::order_address( $order ) ) {
			$changes[] = 'address';
		}

		/* Items */
		$existing = $order->get_items();
		$keep     = array();
		$lines    = array();
		foreach ( $items as $it ) {
			$qty   = max( 1, absint( isset( $it['qty'] ) ? $it['qty'] : 1 ) );
			$price = max( 0, (float) ( isset( $it['price'] ) ? $it['price'] : 0 ) );
			$iid   = absint( isset( $it['item_id'] ) ? $it['item_id'] : 0 );
			if ( $iid && isset( $existing[ $iid ] ) ) {
				$item = $existing[ $iid ];
				if ( (int) $item->get_quantity() !== $qty || abs( (float) $item->get_subtotal() - $price * $qty ) > 0.001 ) {
					$changes[] = $item->get_name() . ' x' . $qty . ' @' . $price;
				}
				$item->set_quantity( $qty );
				$keep[ $iid ] = true;
			} else {
				$product = wc_get_product( absint( isset( $it['product_id'] ) ? $it['product_id'] : 0 ) );
				if ( ! $product ) {
					continue;
				}
				$iid  = $order->add_product( $product, $qty, array( 'subtotal' => $price * $qty, 'total' => $price * $qty ) );
				$item = $order->get_item( $iid, false );
				if ( ! $item ) {
					continue;
				}
				$keep[ $iid ] = true;
				$changes[]    = '+ ' . $product->get_name() . ' x' . $qty;
			}
			$lines[] = array( 'item' => $item, 'line' => round( $price * $qty, 2 ) );
		}
		if ( ! $lines ) {
			wp_send_json_error( array( 'message' => __( 'An order needs at least one product.', 'product-glide' ) ) );
		}
		foreach ( $existing as $iid => $item ) {
			if ( empty( $keep[ $iid ] ) ) {
				if ( function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) {
					wc_maybe_adjust_line_item_product_stock( $item, 0 );
				}
				$changes[] = '- ' . $item->get_name();
				$order->remove_item( $iid );
			}
		}

		/* Discount: an absolute amount spread across lines (replaces coupons). */
		$gross    = array_sum( wp_list_pluck( $lines, 'line' ) );
		$discount = min( max( 0, (float) PG_Helpers::post( 'discount', 0 ) ), $gross );
		if ( abs( $discount - (float) $order->get_total_discount() ) > 0.001 ) {
			$changes[] = 'discount ' . wc_format_decimal( $discount, 2 );
		}
		foreach ( $order->get_items( 'coupon' ) as $cid => $coupon ) {
			$order->remove_item( $cid );
		}
		$left  = $discount;
		$count = count( $lines );
		foreach ( $lines as $i => $l ) {
			$share = ( $i === $count - 1 ) ? $left : ( $gross > 0 ? round( $discount * $l['line'] / $gross, 2 ) : 0 );
			$left -= $share;
			$l['item']->set_subtotal( $l['line'] );
			$l['item']->set_total( max( 0, round( $l['line'] - $share, 2 ) ) );
			$l['item']->set_subtotal_tax( 0 );
			$l['item']->set_total_tax( 0 );
			$l['item']->set_taxes( false );
			$l['item']->save();
			if ( function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) {
				wc_maybe_adjust_line_item_product_stock( $l['item'] );
			}
		}

		/* Delivery charge */
		$delivery = max( 0, (float) PG_Helpers::post( 'delivery', 0 ) );
		$ships    = $order->get_items( 'shipping' );
		if ( abs( $delivery - (float) $order->get_shipping_total() ) > 0.001 ) {
			$changes[] = 'delivery ' . wc_format_decimal( $delivery, 2 );
		}
		if ( $ships ) {
			$first = array_shift( $ships );
			$first->set_total( $delivery );
			$first->set_taxes( false );
			$first->save();
			foreach ( $ships as $sid => $s ) {
				$order->remove_item( $sid );
			}
		} elseif ( $delivery > 0 ) {
			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_title( __( 'Delivery charge', 'product-glide' ) );
			$ship->set_method_id( 'flat_rate' );
			$ship->set_total( $delivery );
			$order->add_item( $ship );
		}

		$order->calculate_totals( false );
		$order->save();
		$c_row = PG_Steadfast::active( $order->get_id() );
		$note  = 'Order edited in Product Glide' . ( $changes ? ': ' . implode( '; ', array_unique( $changes ) ) : '' ) . '. New total: ' . wc_format_decimal( $order->get_total(), 2 );
		$order->add_order_note( $note, 0, true );
		PG_Team::log( $order->get_id(), 'edited', $changes ? implode( '; ', array_unique( $changes ) ) : 'No changes' );

		$warn = '';
		if ( $c_row && abs( (float) $c_row->cod_amount - PG_Steadfast::default_cod( $order ) ) > 0.5 ) {
			$lbl  = PG_Courier::label( $c_row->provider );
			$warn = sprintf( /* translators: 1 courier 2 amount 3 amount 4 courier */ __( 'Already booked with %1$s at COD %2$s. Update the COD to %3$s in the %4$s panel too.', 'product-glide' ), $lbl, wc_format_decimal( $c_row->cod_amount, 0 ), wc_format_decimal( PG_Steadfast::default_cod( $order ), 0 ), $lbl );
		}
		wp_send_json_success(
			array(
				'message' => __( 'Order saved.', 'product-glide' ),
				'warning' => $warn,
				'order'   => self::order_card_data( wc_get_order( $order->get_id() ) ),
			)
		);
	}
}
