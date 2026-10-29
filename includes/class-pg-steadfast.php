<?php
defined( 'ABSPATH' ) || exit;

/**
 * Steadfast booking, consignment records, webhook and tools.
 */
class PG_Steadfast {

	const TRACK_URL = 'https://steadfast.com.bd/t/';

	/** Steadfast status → WooCommerce status (without wc-). */
	public static function status_map() {
		return apply_filters(
			'pg_steadfast_status_map',
			array(
				'delivered'         => 'completed',
				'cancelled'         => 'cancelled',
				'in_review'         => 'courier_sent',
				'pending'           => 'courier_pending',
				'partial_delivered' => 'partial_delivered',
			)
		);
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'pg_sf_sync_cron', array( __CLASS__, 'cron_sync' ) );
		if ( ! wp_next_scheduled( 'pg_sf_sync_cron' ) ) {
			wp_schedule_event( time() + 300, 'pg_half_hour', 'pg_sf_sync_cron' );
		}
		$ajax = array( 'send', 'bulk', 'void', 'refresh', 'return_request', 'ping', 'balance', 'returns', 'payments', 'payment', 'tracking', 'pickup', 'police', 'kpis', 'sync' );
		foreach ( $ajax as $a ) {
			add_action( 'wp_ajax_pg_sf_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Consignment records                                                 */
	/* ------------------------------------------------------------------ */

	public static function active( $order_id ) {
		global $wpdb;
		$t = PG_Install::table( 'consignments' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d AND is_active = 1 ORDER BY id DESC LIMIT 1", $order_id ) ); // phpcs:ignore
	}

	/** Active consignments for many orders: order_id => row. */
	public static function active_for( array $order_ids ) {
		global $wpdb;
		$order_ids = array_filter( array_map( 'absint', $order_ids ) );
		if ( ! $order_ids ) {
			return array();
		}
		$t    = PG_Install::table( 'consignments' );
		$in   = implode( ',', $order_ids );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE is_active = 1 AND order_id IN ({$in}) ORDER BY id ASC" ); // phpcs:ignore
		$map  = array();
		foreach ( $rows as $r ) {
			$map[ (int) $r->order_id ] = $r;
		}
		return $map;
	}

	public static function find_by_cid( $cid ) {
		global $wpdb;
		$t = PG_Install::table( 'consignments' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE consignment_id = %s ORDER BY id DESC LIMIT 1", (string) $cid ) ); // phpcs:ignore
	}

	public static function find_by_invoice( $invoice ) {
		global $wpdb;
		$t = PG_Install::table( 'consignments' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE invoice = %s ORDER BY id DESC LIMIT 1", (string) $invoice ) ); // phpcs:ignore
	}

	public static function insert( array $row ) {
		global $wpdb;
		$now = PG_Helpers::now_utc();
		$row = wp_parse_args(
			$row,
			array(
				'provider'           => 'steadfast',
				'courier_status'     => 'in_review',
				'last_synced_status' => '',
				'is_active'          => 1,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		$wpdb->insert( PG_Install::table( 'consignments' ), $row );
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = PG_Helpers::now_utc();
		$wpdb->update( PG_Install::table( 'consignments' ), $data, array( 'id' => (int) $id ) );
	}

	/** Base invoice for an order, plus -R{n} after a void so Steadfast accepts a resend. */
	public static function invoice_for( WC_Order $order ) {
		$base = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $order->get_order_number() );
		$n    = (int) $order->get_meta( '_pg_sf_resend_count' );
		return $n > 0 ? $base . '-R' . $n : $base;
	}

	/** Order ID from an invoice like 8029 or 8029-R1. */
	public static function order_id_from_invoice( $invoice ) {
		$base = preg_replace( '/-R\d+$/i', '', (string) $invoice );
		if ( ctype_digit( $base ) ) {
			$o = wc_get_order( (int) $base );
			if ( $o ) {
				return $o->get_id();
			}
		}
		// Sequential order number plugins: search by order number meta.
		$ids = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_key' => '_order_number', 'meta_value' => $base ) ); // phpcs:ignore
		return $ids ? (int) $ids[0] : 0;
	}

	public static function default_cod( WC_Order $order ) {
		$total = (float) $order->get_total() - (float) $order->get_total_refunded();
		$paid  = $order->get_date_paid() && 'cod' !== $order->get_payment_method();
		return $paid ? 0 : max( 0, $total );
	}

	public static function build_payload( WC_Order $order, array $over = array() ) {
		$note_parts = array();
		$def        = PG_Settings::get( 'sf_default_note', '' );
		if ( $def ) {
			$note_parts[] = $def;
		}
		if ( $order->get_customer_note() ) {
			$note_parts[] = $order->get_customer_note();
		}
		$alt     = $order->get_shipping_phone();
		$payload = array(
			'invoice'           => self::invoice_for( $order ),
			'recipient_name'    => PG_Helpers::order_name( $order ),
			'recipient_phone'   => $order->get_billing_phone(),
			'alternative_phone' => $alt,
			'recipient_email'   => $order->get_billing_email(),
			'recipient_address' => PG_Helpers::order_address( $order ),
			'cod_amount'        => self::default_cod( $order ),
			'note'              => implode( ' | ', $note_parts ),
			'total_lot'         => max( 1, (int) $order->get_item_count() ),
			'delivery_type'     => 0,
		);
		if ( PG_Settings::get( 'sf_item_desc', 1 ) ) {
			$payload['item_description'] = PG_Helpers::items_text( $order );
		}
		foreach ( array( 'cod_amount', 'note', 'recipient_address', 'recipient_name', 'recipient_phone' ) as $k ) {
			if ( isset( $over[ $k ] ) && '' !== $over[ $k ] ) {
				$payload[ $k ] = $over[ $k ];
			}
		}
		return $payload;
	}

	/** Record a successful booking: table row, meta, status, note. */
	public static function record_booking( WC_Order $order, array $c, $payload ) {
		$cid  = (string) $c['consignment_id'];
		$code = isset( $c['tracking_code'] ) ? (string) $c['tracking_code'] : '';
		self::insert(
			array(
				'order_id'       => $order->get_id(),
				'invoice'        => isset( $c['invoice'] ) ? (string) $c['invoice'] : $payload['invoice'],
				'consignment_id' => $cid,
				'tracking_code'  => $code,
				'courier_status' => isset( $c['status'] ) ? (string) $c['status'] : 'in_review',
				'last_synced_status' => 'in_review',
				'cod_amount'     => (float) $payload['cod_amount'],
			)
		);
		$order->update_meta_data( '_pg_courier', 'steadfast' );
		$order->update_meta_data( '_pg_sf_cid', $cid );
		$order->update_meta_data( '_pg_sf_tracking', $code );
		$order->update_meta_data( '_pg_sf_status', 'in_review' );
		$order->save();
		$url = $code ? self::TRACK_URL . rawurlencode( $code ) : '';
		$note = sprintf( 'Steadfast booked. CID: %s | Tracking: %s | COD: %s', $cid, $code, wc_format_decimal( $payload['cod_amount'], 0 ) );
		if ( $url ) {
			$note .= ' | ' . $url;
		}
		$order->add_order_note( $note, 0, true );
		PG_Team::log( $order->get_id(), 'courier', sprintf( 'Steadfast · CID %s · COD %s', $cid, wc_format_decimal( $payload['cod_amount'], 0 ) ), 'courier_sent' );
		if ( 'courier_sent' !== $order->get_status() ) {
			PG_Team::$quiet = true;
			$order->update_status( 'courier_sent', __( 'Sent to Steadfast.', 'product-glide' ) );
			PG_Team::$quiet = false;
		}
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: booking                                                       */
	/* ------------------------------------------------------------------ */

	public static function ajax_send() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		$existing = self::active( $order->get_id() );
		if ( $existing && 'cancelled' !== $existing->courier_status ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: cid */ __( 'Already booked (CID %s). Void it first to resend.', 'product-glide' ), $existing->consignment_id ) ) );
		}
		if ( $existing ) {
			// Previous parcel was cancelled by courier: archive it and resend with a new suffix.
			self::archive_and_bump( $order, $existing );
		}
		$over = array(
			'cod_amount' => isset( $_POST['cod_amount'] ) && '' !== $_POST['cod_amount'] ? (float) $_POST['cod_amount'] : '', // phpcs:ignore
			'note'       => PG_Helpers::post_textarea( 'note', '' ),
		);
		$c = self::book( $order, $over );
		if ( is_wp_error( $c ) ) {
			wp_send_json_error( array( 'message' => $c->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: tracking */ __( 'Booked! Tracking %s', 'product-glide' ), $c['tracking_code'] ),
				'order'   => PG_Orders::order_card_data( wc_get_order( $order->get_id() ) ),
			)
		);
	}

	/** Book one order with Steadfast and record it. Returns the consignment array or WP_Error. */
	public static function book( WC_Order $order, array $over = array() ) {
		$payload = self::build_payload( $order, $over );
		$c       = PG_Steadfast_API::create_order( $payload );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		self::record_booking( $order, $c, $payload );
		return $c;
	}

	private static function archive_and_bump( WC_Order $order, $row ) {
		self::update( $row->id, array( 'is_active' => 0 ) );
		$order->update_meta_data( '_pg_sf_resend_count', (int) $order->get_meta( '_pg_sf_resend_count' ) + 1 );
		$order->save();
	}

	public static function ajax_bulk() {
		PG_Helpers::guard_ajax();
		$ids = array_slice( array_filter( array_map( 'absint', (array) PG_Helpers::post( 'order_ids', array() ) ) ), 0, 500 );
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one order.', 'product-glide' ) ) );
		}
		$results  = array();
		$payloads = array();
		$orders   = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				$results[] = array( 'order_id' => $id, 'ok' => false, 'errors' => array( __( 'Order not found', 'product-glide' ) ) );
				continue;
			}
			$ex = self::active( $id );
			if ( $ex && 'cancelled' !== $ex->courier_status ) {
				$results[] = array( 'order_id' => $id, 'ok' => false, 'errors' => array( sprintf( 'Already booked (CID %s)', $ex->consignment_id ) ) );
				continue;
			}
			if ( $ex ) {
				self::archive_and_bump( $order, $ex );
			}
			$p = PG_Steadfast_API::validate_order( self::build_payload( $order ) );
			if ( is_wp_error( $p ) ) {
				$results[] = array( 'order_id' => $id, 'ok' => false, 'errors' => explode( '; ', $p->get_error_message() ) );
				continue;
			}
			$payloads[ $p['invoice'] ] = $p;
			$orders[ $p['invoice'] ]   = $order;
		}
		if ( $payloads ) {
			$rows = PG_Steadfast_API::create_bulk( $payloads );
			if ( is_wp_error( $rows ) ) {
				foreach ( $orders as $inv => $o ) {
					$results[] = array( 'order_id' => $o->get_id(), 'ok' => false, 'errors' => array( $rows->get_error_message() ) );
				}
			} else {
				$seen = array();
				foreach ( $rows as $r ) {
					if ( ! isset( $orders[ $r['invoice'] ] ) ) {
						continue;
					}
					$o             = $orders[ $r['invoice'] ];
					$seen[ $r['invoice'] ] = true;
					if ( $r['ok'] ) {
						self::record_booking( $o, array( 'consignment_id' => $r['consignment_id'], 'tracking_code' => $r['tracking_code'], 'invoice' => $r['invoice'] ), $payloads[ $r['invoice'] ] );
					}
					$results[] = array( 'order_id' => $o->get_id(), 'ok' => $r['ok'], 'tracking_code' => $r['tracking_code'], 'errors' => $r['errors'] );
				}
				foreach ( $orders as $inv => $o ) {
					if ( empty( $seen[ $inv ] ) ) {
						$results[] = array( 'order_id' => $o->get_id(), 'ok' => false, 'errors' => array( __( 'No result returned — check the Steadfast portal before retrying.', 'product-glide' ) ) );
					}
				}
			}
		}
		wp_send_json_success( array( 'results' => $results ) );
	}

	/** Void a parcel locally (after cancelling it in the Steadfast portal) so it can be resent. */
	public static function ajax_void() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		$row = self::active( $order->get_id() );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'No active consignment on this order.', 'product-glide' ) ) );
		}
		self::update( $row->id, array( 'is_active' => 0, 'courier_status' => 'voided', 'final_at' => PG_Helpers::now_utc() ) );
		$order->update_meta_data( '_pg_sf_resend_count', (int) $order->get_meta( '_pg_sf_resend_count' ) + 1 );
		$order->delete_meta_data( '_pg_sf_cid' );
		$order->delete_meta_data( '_pg_sf_tracking' );
		$order->update_meta_data( '_pg_sf_status', 'voided' );
		$order->save();
		$order->add_order_note( sprintf( 'Steadfast consignment %s voided in Product Glide. Next booking will use invoice %s.', $row->consignment_id, self::invoice_for( $order ) ) );
		$order->update_status( 'processing', __( 'Courier booking voided.', 'product-glide' ) );
		wp_send_json_success( array( 'message' => __( 'Voided. Order is back to Processing.', 'product-glide' ), 'order' => PG_Orders::order_card_data( wc_get_order( $order->get_id() ) ) ) );
	}

	public static function ajax_refresh() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		$row   = $order ? self::active( $order->get_id() ) : null;
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'No active consignment on this order.', 'product-glide' ) ) );
		}
		$data = PG_Steadfast_API::status_by_cid( $row->consignment_id );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		$status = isset( $data['delivery_status'] ) ? strtolower( (string) $data['delivery_status'] ) : '';
		self::apply_status( $row, $status, array( 'tracking_message' => __( 'Manual refresh', 'product-glide' ) ) );
		wp_send_json_success( array( 'message' => 'Steadfast: ' . $status, 'order' => PG_Orders::order_card_data( wc_get_order( $order->get_id() ) ) ) );
	}

	public static function ajax_return_request() {
		PG_Helpers::guard_ajax();
		$cid    = PG_Helpers::post( 'consignment_id' );
		$inv    = PG_Helpers::post( 'invoice' );
		$reason = PG_Helpers::post_textarea( 'reason' );
		$res    = $cid ? PG_Steadfast_API::create_return_request( 'consignment_id', $cid, $reason ) : PG_Steadfast_API::create_return_request( 'invoice', $inv, $reason );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$row = $cid ? self::find_by_cid( $cid ) : self::find_by_invoice( $inv );
		if ( $row && ( $o = wc_get_order( $row->order_id ) ) ) { // phpcs:ignore
			$o->add_order_note( 'Steadfast return request created' . ( $reason ? ': ' . $reason : '' ) );
		}
		wp_send_json_success( array( 'message' => __( 'Return request created.', 'product-glide' ), 'data' => $res ) );
	}

	/* ------------------------------------------------------------------ */
	/* Status sync (webhook + refresh)                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Apply a courier status to a consignment row and its order. Idempotent:
	 * if the mapped Woo status is already set, the order is not touched.
	 */
	public static function apply_status( $row, $status, array $extra = array() ) {
		$status = strtolower( trim( (string) $status ) );
		$upd    = array();
		if ( $status ) {
			$upd['courier_status'] = $status;
		}
		foreach ( array( 'tracking_message', 'last_event_key' ) as $k ) {
			if ( isset( $extra[ $k ] ) && '' !== $extra[ $k ] ) {
				$upd[ $k ] = $extra[ $k ];
			}
		}
		if ( isset( $extra['delivery_charge'] ) && null !== $extra['delivery_charge'] ) {
			$upd['delivery_charge'] = (float) $extra['delivery_charge'];
		}
		if ( in_array( $status, array( 'delivered', 'partial_delivered' ), true ) && isset( $extra['cod_amount'] ) && null !== $extra['cod_amount'] ) {
			$upd['cod_collected'] = (float) $extra['cod_amount'];
		}
		if ( in_array( $status, array( 'delivered', 'partial_delivered', 'cancelled' ), true ) && empty( $row->final_at ) ) {
			$upd['final_at'] = PG_Helpers::now_utc();
		}

		$map    = self::status_map();
		$order  = wc_get_order( $row->order_id );
		$target = isset( $map[ $status ] ) ? $map[ $status ] : '';

		if ( $target ) {
			$upd['last_synced_status'] = $status;
		}
		if ( $upd ) {
			self::update( $row->id, $upd );
		}
		if ( ! $order ) {
			return;
		}
		$changed = false;
		if ( $status && $order->get_meta( '_pg_sf_status' ) !== $status ) {
			$order->update_meta_data( '_pg_sf_status', $status );
			$changed = true;
		}
		if ( $changed ) {
			$order->save();
		}
		if ( $target && $order->get_status() !== $target ) {
			$msg = sprintf( 'Steadfast status: %s', $status );
			if ( ! empty( $extra['tracking_message'] ) ) {
				$msg .= ' — ' . $extra['tracking_message'];
			}
			// Courier updates are not a staff action, even when someone pressed "Sync".
			PG_Team::$quiet = true;
			$order->update_status( $target, $msg );
			PG_Team::$quiet = false;
		}
	}

	public static function routes() {
		register_rest_route(
			'product-glide/v1',
			'/steadfast-webhook',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => array( __CLASS__, 'webhook_auth' ),
			)
		);
	}

	public static function webhook_auth( WP_REST_Request $req ) {
		$token = (string) PG_Settings::get( 'sf_webhook_token', '' );
		if ( '' === $token ) {
			return new WP_Error( 'pg_no_token', 'Webhook token not configured', array( 'status' => 401 ) );
		}
		$auth = (string) $req->get_header( 'authorization' );
		$got  = preg_match( '/^Bearer\s+(.+)$/i', trim( $auth ), $m ) ? trim( $m[1] ) : '';
		if ( '' === $got ) {
			$got = (string) $req->get_header( 'x_api_key' );
		}
		if ( '' !== $got && hash_equals( $token, $got ) ) {
			return true;
		}
		return new WP_Error( 'pg_unauthorized', 'Unauthorized', array( 'status' => 401 ) );
	}

	public static function webhook( WP_REST_Request $req ) {
		$b = $req->get_json_params();
		if ( ! is_array( $b ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Body is not JSON' ), 400 );
		}
		$type = isset( $b['notification_type'] ) ? (string) $b['notification_type'] : 'delivery_status';
		if ( ! in_array( $type, array( 'delivery_status', 'tracking_update' ), true ) ) {
			return new WP_REST_Response( array( 'status' => 'success', 'message' => 'ignored' ), 200 );
		}
		$cid     = isset( $b['consignment_id'] ) ? preg_replace( '/\D/', '', (string) $b['consignment_id'] ) : '';
		$invoice = isset( $b['invoice'] ) ? sanitize_text_field( (string) $b['invoice'] ) : '';
		$key     = (string) $req->get_header( 'idempotency_key' );

		$row = $cid ? self::find_by_cid( $cid ) : null;
		if ( ! $row && $invoice ) {
			$row = self::find_by_invoice( $invoice );
		}
		if ( ! $row && $invoice ) {
			// Booked elsewhere (portal / another plugin): adopt it.
			$oid = self::order_id_from_invoice( $invoice );
			if ( $oid ) {
				$id  = self::insert(
					array(
						'order_id'       => $oid,
						'invoice'        => $invoice,
						'consignment_id' => $cid,
						'tracking_code'  => '',
						'courier_status' => '',
						'cod_amount'     => isset( $b['cod_amount'] ) ? (float) $b['cod_amount'] : 0,
					)
				);
				$row = self::find_by_cid( $cid );
				if ( ! $row ) {
					global $wpdb;
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PG_Install::table( 'consignments' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
				}
			}
		}
		if ( ! $row ) {
			// 200 so Steadfast does not retry something we can never match.
			return new WP_REST_Response( array( 'status' => 'success', 'message' => 'unknown consignment' ), 200 );
		}
		if ( '' !== $key && $key === $row->last_event_key ) {
			return new WP_REST_Response( array( 'status' => 'success', 'message' => 'duplicate' ), 200 );
		}
		$msg = isset( $b['tracking_message'] ) ? sanitize_text_field( (string) $b['tracking_message'] ) : '';
		if ( 'tracking_update' === $type ) {
			self::update( $row->id, array( 'tracking_message' => $msg, 'last_event_key' => $key ) );
			return new WP_REST_Response( array( 'status' => 'success' ), 200 );
		}
		$num = function ( $v ) {
			return ( null === $v || '' === $v || ! is_numeric( $v ) ) ? null : (float) $v;
		};
		self::apply_status(
			$row,
			isset( $b['status'] ) ? (string) $b['status'] : '',
			array(
				'tracking_message' => $msg,
				'last_event_key'   => $key,
				'cod_amount'       => $num( isset( $b['cod_amount'] ) ? $b['cod_amount'] : null ),
				'delivery_charge'  => $num( isset( $b['delivery_charge'] ) ? $b['delivery_charge'] : null ),
			)
		);
		return new WP_REST_Response( array( 'status' => 'success' ), 200 );
	}

	/* ------------------------------------------------------------------ */
	/* KPIs                                                                */
	/* ------------------------------------------------------------------ */

	public static function kpis() {
		global $wpdb;
		$t = PG_Install::table( 'consignments' );
		list( $s, $e ) = PG_Helpers::bd_day_bounds_utc();
		$sent      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s AND created_at < %s AND courier_status NOT IN ('cancelled','voided')", $s, $e ) ); // phpcs:ignore
		$delivered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE final_at >= %s AND final_at < %s AND courier_status IN ('delivered','partial_delivered')", $s, $e ) ); // phpcs:ignore
		$cancelled = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE final_at >= %s AND final_at < %s AND courier_status = 'cancelled'", $s, $e ) ); // phpcs:ignore
		$transit   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE is_active = 1 AND courier_status NOT IN ('delivered','partial_delivered','cancelled','voided')" ); // phpcs:ignore
		return compact( 'sent', 'delivered', 'cancelled', 'transit' );
	}

	public static function ajax_kpis() {
		PG_Helpers::guard_ajax();
		wp_send_json_success( self::kpis() );
	}

	/* ------------------------------------------------------------------ */
	/* Tools                                                               */
	/* ------------------------------------------------------------------ */

	private static function respond( $res, $ok_message = '' ) {
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => $ok_message, 'data' => $res ) );
	}

	public static function ajax_ping() {
		PG_Helpers::guard_ajax( true );
		$t0  = microtime( true );
		$res = PG_Steadfast_API::ping();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$ms   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$bal  = PG_Steadfast_API::configured() ? PG_Steadfast_API::balance() : new WP_Error( 'x', __( 'API keys not set', 'product-glide' ) );
		$auth = is_wp_error( $bal ) ? $bal->get_error_message() : __( 'Keys OK', 'product-glide' );
		wp_send_json_success(
			array(
				'message' => sprintf( 'API reachable (%d ms). %s', $ms, $auth ),
				'data'    => array( 'balance' => is_wp_error( $bal ) ? null : ( isset( $bal['current_balance'] ) ? $bal['current_balance'] : null ) ),
			)
		);
	}

	public static function ajax_balance() {
		PG_Helpers::guard_ajax( true );
		$cached = get_transient( 'pg_sf_balance' );
		if ( false === $cached || PG_Helpers::post_int( 'force' ) ) {
			$res = PG_Steadfast_API::balance();
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			$cached = isset( $res['current_balance'] ) ? $res['current_balance'] : 0;
			set_transient( 'pg_sf_balance', $cached, 5 * MINUTE_IN_SECONDS );
		}
		wp_send_json_success( array( 'data' => array( 'current_balance' => $cached ) ) );
	}

	public static function ajax_returns() {
		PG_Helpers::guard_ajax( true );
		$res = PG_Steadfast_API::return_requests( max( 1, PG_Helpers::post_int( 'page', 1 ) ) );
		if ( ! is_wp_error( $res ) ) {
			$res = PG_Steadfast_API::list_from( $res, array( 'data', 'return_requests' ) );
		}
		self::respond( $res );
	}

	public static function ajax_payments() {
		PG_Helpers::guard_ajax( true );
		$res = PG_Steadfast_API::payments( max( 1, PG_Helpers::post_int( 'page', 1 ) ) );
		if ( ! is_wp_error( $res ) ) {
			$res = PG_Steadfast_API::list_from( $res, array( 'payments', 'data' ) );
		}
		self::respond( $res );
	}

	public static function ajax_payment() {
		PG_Helpers::guard_ajax( true );
		self::respond( PG_Steadfast_API::payment( PG_Helpers::post( 'payment_id' ) ) );
	}

	public static function ajax_tracking() {
		PG_Helpers::guard_ajax( true );
		$q = trim( PG_Helpers::post( 'q' ) );
		if ( '' === $q ) {
			wp_send_json_error( array( 'message' => __( 'Enter an invoice or consignment ID.', 'product-glide' ) ) );
		}
		// Local lookup first: an order number maps to its active invoice.
		$invoice = $q;
		$row     = self::find_by_invoice( $q );
		if ( ! $row && ctype_digit( $q ) ) {
			$row = self::find_by_cid( $q );
			if ( ! $row ) {
				$row = self::active( (int) $q );
			}
		}
		if ( $row ) {
			$invoice = $row->invoice;
		}
		$res = PG_Steadfast_API::trackings_by_invoice( $invoice );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$list = isset( $res['tracking'] ) && is_array( $res['tracking'] ) ? $res['tracking'] : array();
		usort(
			$list,
			function ( $a, $b ) {
				return strcmp( isset( $a['created_at'] ) ? $a['created_at'] : '', isset( $b['created_at'] ) ? $b['created_at'] : '' );
			}
		);
		foreach ( $list as &$ev ) {
			if ( ! empty( $ev['created_at'] ) ) {
				$ev['created_at_bd'] = PG_Helpers::bd_time( $ev['created_at'] );
			}
		}
		$status = PG_Steadfast_API::status_by_invoice( $invoice );
		wp_send_json_success(
			array(
				'data' => array(
					'invoice'  => $invoice,
					'status'   => is_wp_error( $status ) ? '' : ( isset( $status['delivery_status'] ) ? $status['delivery_status'] : '' ),
					'tracking' => $list,
				),
			)
		);
	}

	public static function ajax_pickup() {
		PG_Helpers::guard_ajax( true );
		$res = PG_Steadfast_API::pickup_request(
			array(
				'address_id'        => PG_Helpers::post_int( 'address_id' ),
				'police_station_id' => PG_Helpers::post_int( 'police_station_id' ),
				'address'           => PG_Helpers::post( 'address' ),
				'contact_number'    => PG_Helpers::post( 'contact_number' ),
				'note'              => PG_Helpers::post_textarea( 'note' ),
				'estim_qty'         => PG_Helpers::post_int( 'estim_qty' ),
			)
		);
		self::respond( $res, __( 'Pickup request submitted.', 'product-glide' ) );
	}

	public static function ajax_police() {
		PG_Helpers::guard_ajax( true );
		$list = PG_Steadfast_API::police_stations();
		if ( is_wp_error( $list ) ) {
			wp_send_json_error( array( 'message' => $list->get_error_message() ) );
		}
		$q = mb_strtolower( trim( PG_Helpers::post( 'q' ) ) );
		if ( '' !== $q ) {
			$list = array_values(
				array_filter(
					$list,
					function ( $r ) use ( $q ) {
						return false !== mb_strpos( mb_strtolower( wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) ), $q );
					}
				)
			);
		}
		wp_send_json_success( array( 'data' => array_slice( $list, 0, 200 ), 'total' => count( $list ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Polling sync (Steadfast does not push every change by webhook)      */
	/* ------------------------------------------------------------------ */

	public static function cron_schedules( $s ) {
		$s['pg_half_hour'] = array( 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Every 30 minutes (Product Glide)' );
		return $s;
	}

	/**
	 * Re-check active, unfinished parcels against Steadfast (oldest-checked first).
	 * Returns [checked, changed, errors, changes[]].
	 */
	public static function sync_active( $limit = 40, array $order_ids = array() ) {
		global $wpdb;
		$t   = PG_Install::table( 'consignments' );
		$sql = "SELECT * FROM {$t} WHERE is_active = 1 AND consignment_id <> '' AND courier_status NOT IN ('delivered','partial_delivered','cancelled','voided')";
		if ( $order_ids ) {
			$sql .= ' AND order_id IN (' . implode( ',', array_map( 'absint', $order_ids ) ) . ')';
		}
		$sql .= ' ORDER BY updated_at ASC LIMIT ' . absint( $limit );
		$rows = $wpdb->get_results( $sql ); // phpcs:ignore
		$out  = array( 'checked' => 0, 'changed' => 0, 'errors' => 0, 'changes' => array(), 'left' => 0 );
		$t0   = microtime( true );
		foreach ( $rows as $row ) {
			if ( microtime( true ) - $t0 > 22 ) {
				break; // Keep the request well inside PHP time limits; the rest go next time.
			}
			$out['checked']++;
			$data = PG_Steadfast_API::status_by_cid( $row->consignment_id );
			if ( is_wp_error( $data ) || empty( $data['delivery_status'] ) ) {
				$out['errors']++;
				self::update( $row->id, array() ); // bump updated_at so it moves to the back of the queue
				continue;
			}
			$new = strtolower( (string) $data['delivery_status'] );
			$old = (string) $row->courier_status;
			self::apply_status( $row, $new, array( 'tracking_message' => '' ) );
			if ( $new !== $old ) {
				$out['changed']++;
				$out['changes'][] = array( 'order_id' => (int) $row->order_id, 'from' => $old, 'to' => $new );
			} else {
				self::update( $row->id, array() );
			}
		}
		$out['left'] = max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE is_active = 1 AND consignment_id <> '' AND courier_status NOT IN ('delivered','partial_delivered','cancelled','voided')" ) - $out['checked'] ); // phpcs:ignore
		update_option( 'pg_sf_last_sync', array( 'time' => time(), 'checked' => $out['checked'], 'changed' => $out['changed'] ), false );
		return $out;
	}

	public static function cron_sync() {
		if ( PG_Steadfast_API::configured() ) {
			self::sync_active( 60 );
		}
	}

	public static function ajax_sync() {
		PG_Helpers::guard_ajax();
		if ( ! PG_Steadfast_API::configured() ) {
			wp_send_json_error( array( 'message' => __( 'Steadfast API keys are not set.', 'product-glide' ) ) );
		}
		$ids = array_filter( array_map( 'absint', (array) PG_Helpers::post( 'order_ids', array() ) ) );
		$res = self::sync_active( 40, $ids );
		$msg = sprintf( 'Checked %d parcels, %d updated', $res['checked'], $res['changed'] );
		if ( $res['errors'] ) {
			$msg .= sprintf( ', %d could not be checked', $res['errors'] );
		}
		if ( $res['left'] > 0 && ! $ids ) {
			$msg .= sprintf( '. %d more — tap Sync again.', $res['left'] );
		}
		wp_send_json_success( array( 'message' => $msg, 'data' => $res, 'kpis' => self::kpis() ) );
	}
}
