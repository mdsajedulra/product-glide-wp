<?php
defined( 'ABSPATH' ) || exit;

/**
 * Carrybee courier: booking, status (webhook + polling), cancel, return pickup,
 * exchange and the settings helpers. Parcels live in the same consignments
 * table as Steadfast (provider = 'carrybee'), so the Orders page, KPIs, profit,
 * invoices and WhatsApp all work the same way.
 */
class PG_Carrybee {

	const PROVIDER = 'carrybee';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array( 'test', 'stores', 'return', 'exchange', 'cities', 'zones', 'areas', 'create_store' ) as $a ) {
			add_action( 'wp_ajax_pg_cb_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function webhook_url() {
		return rest_url( 'product-glide/v1/carrybee-webhook' );
	}

	/* ------------------------------------------------------------------ */
	/* Booking                                                             */
	/* ------------------------------------------------------------------ */

	/** Parcel weight in grams: product weights when set, otherwise the default from Settings. */
	public static function weight_grams( WC_Order $order ) {
		$unit  = get_option( 'woocommerce_weight_unit', 'kg' );
		$mul   = array( 'kg' => 1000, 'g' => 1, 'lbs' => 453.592, 'oz' => 28.3495 );
		$total = 0;
		$known = true;
		foreach ( $order->get_items() as $item ) {
			$p = $item->get_product();
			$w = $p ? (float) $p->get_weight() : 0;
			if ( $w <= 0 ) {
				$known = false;
				break;
			}
			$total += $w * ( isset( $mul[ $unit ] ) ? $mul[ $unit ] : 1000 ) * max( 1, (int) $item->get_quantity() );
		}
		if ( ! $known || $total <= 0 ) {
			$total = (float) PG_Settings::get( 'cb_item_weight', 500 );
		}
		return (int) max( 1, min( 25000, round( $total ) ) );
	}

	private static function cut( $s, $max ) {
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max - 1 ) . '…' : $s;
	}

	public static function build_payload( WC_Order $order, array $over = array() ) {
		$phone = PG_Helpers::bd_phone( $order->get_billing_phone() );
		$alt   = PG_Helpers::bd_phone( $order->get_shipping_phone() );
		$name  = self::cut( PG_Helpers::order_name( $order ), 99 );
		$addr  = PG_Helpers::order_address( $order );
		if ( mb_strlen( $addr ) < 10 ) {
			$addr = trim( $addr . ', Bangladesh', ', ' );
		}
		$notes = array();
		$def   = (string) PG_Settings::get( 'cb_default_note', '' );
		if ( '' !== $def ) {
			$notes[] = $def;
		}
		if ( isset( $over['note'] ) && '' !== trim( (string) $over['note'] ) ) {
			$notes[] = $over['note'];
		} elseif ( $order->get_customer_note() ) {
			$notes[] = $order->get_customer_note();
		}
		$cod = isset( $over['cod_amount'] ) && '' !== $over['cod_amount'] && null !== $over['cod_amount'] ? (float) $over['cod_amount'] : PG_Steadfast::default_cod( $order );
		$p   = array(
			'store_id'            => (string) PG_Settings::get( 'cb_store_id', '' ),
			'merchant_order_id'   => mb_substr( PG_Steadfast::invoice_for( $order ), 0, 49 ),
			'delivery_type'       => 2 === (int) PG_Settings::get( 'cb_delivery_type', 1 ) ? 2 : 1,
			'product_type'        => in_array( (int) PG_Settings::get( 'cb_product_type', 1 ), array( 1, 2, 3 ), true ) ? (int) PG_Settings::get( 'cb_product_type', 1 ) : 1,
			'recipient_phone'     => $phone ? $phone : (string) $order->get_billing_phone(),
			'recipient_name'      => mb_strlen( $name ) >= 2 ? $name : 'Customer',
			'recipient_address'   => self::cut( $addr, 200 ),
			'item_weight'         => self::weight_grams( $order ),
			'item_quantity'       => (int) max( 1, min( 200, (int) $order->get_item_count() ) ),
			'collectable_amount'  => (int) max( 0, min( 100000, round( $cod ) ) ),
			'product_description' => self::cut( PG_Helpers::items_text( $order ), 254 ),
		);
		if ( $alt && $alt !== $phone ) {
			$p['recipient_secendary_phone'] = $alt; // Spelling as in the Carrybee API.
		}
		if ( $notes ) {
			$p['special_instruction'] = self::cut( implode( ' | ', $notes ), 254 );
		}
		return $p;
	}

	/** Basic checks so obvious mistakes are caught before calling Carrybee. */
	public static function validate( array $p ) {
		$e = array();
		if ( '' === $p['store_id'] ) {
			$e[] = __( 'Choose a Carrybee pickup store in Settings → Carrybee.', 'product-glide' );
		}
		if ( ! PG_Helpers::bd_phone( $p['recipient_phone'] ) ) {
			$e[] = __( 'Customer phone is not a valid BD mobile number.', 'product-glide' );
		}
		if ( mb_strlen( $p['recipient_address'] ) < 10 ) {
			$e[] = __( 'Address is too short (Carrybee needs at least 10 characters).', 'product-glide' );
		}
		return $e ? new WP_Error( 'pg_cb_invalid', implode( '; ', $e ) ) : $p;
	}

	/** City / zone from the address text (Carrybee routes faster with them). */
	private static function add_location( array $p ) {
		if ( ! PG_Settings::get( 'cb_auto_area', 1 ) ) {
			return $p;
		}
		$q = $p['recipient_address'];
		if ( mb_strlen( $q ) < 10 ) {
			return $p;
		}
		$loc = PG_Carrybee_API::address_details( $q );
		if ( ! is_wp_error( $loc ) && ! empty( $loc['city_id'] ) && ! empty( $loc['zone_id'] ) ) {
			$p['city_id'] = (int) $loc['city_id'];
			$p['zone_id'] = (int) $loc['zone_id'];
			if ( ! empty( $loc['area_id'] ) ) {
				$p['area_id'] = (int) $loc['area_id'];
			}
		}
		return $p;
	}

	/** Book an order. Returns array( consignment_id, tracking_code, delivery_fee, cod_fee ) or WP_Error. */
	public static function book( WC_Order $order, array $over = array() ) {
		$existing = PG_Steadfast::active( $order->get_id() );
		if ( $existing && ! in_array( $existing->courier_status, array( 'cancelled', 'voided' ), true ) ) {
			return new WP_Error( 'pg_cb_dup', sprintf( /* translators: 1 courier 2 cid */ __( 'Already booked with %1$s (CID %2$s). Void it first to resend.', 'product-glide' ), PG_Courier::label( $existing->provider ), $existing->consignment_id ) );
		}
		if ( $existing ) {
			PG_Steadfast::update( $existing->id, array( 'is_active' => 0 ) );
			$order->update_meta_data( '_pg_sf_resend_count', (int) $order->get_meta( '_pg_sf_resend_count' ) + 1 );
			$order->save();
		}
		$p = self::validate( self::build_payload( $order, $over ) );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$p = self::add_location( $p );
		$o = PG_Carrybee_API::create_order( $p );
		if ( is_wp_error( $o ) && isset( $p['city_id'] ) && 422 === (int) ( $o->get_error_data()['status'] ?? 0 ) && false !== strpos( $o->get_error_message(), '_id' ) ) {
			// The guessed city/zone was rejected — try once without it.
			unset( $p['city_id'], $p['zone_id'], $p['area_id'] );
			$o = PG_Carrybee_API::create_order( $p );
		}
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		$fee = self::fees( $o );
		self::record_booking( $order, (string) $o['consignment_id'], $p, $fee );
		return array(
			'consignment_id' => (string) $o['consignment_id'],
			'tracking_code'  => (string) $o['consignment_id'],
			'delivery_fee'   => $fee,
		);
	}

	/** delivery_fee + cod_fee, or null when Carrybee did not say. */
	private static function fees( array $d ) {
		$has = false;
		$sum = 0.0;
		foreach ( array( 'delivery_fee', 'cod_fee' ) as $k ) {
			if ( isset( $d[ $k ] ) && is_numeric( $d[ $k ] ) ) {
				$sum += (float) $d[ $k ];
				$has  = true;
			}
		}
		return $has ? round( $sum, 2 ) : null;
	}

	public static function record_booking( WC_Order $order, $cid, array $p, $fee = null ) {
		$row = array(
			'order_id'           => $order->get_id(),
			'provider'           => self::PROVIDER,
			'invoice'            => $p['merchant_order_id'],
			'consignment_id'     => $cid,
			'tracking_code'      => $cid,
			'courier_status'     => 'in_review',
			'last_synced_status' => 'in_review',
			'cod_amount'         => (float) $p['collectable_amount'],
		);
		if ( null !== $fee ) {
			$row['delivery_charge'] = $fee;
		}
		PG_Steadfast::insert( $row );
		$order->update_meta_data( '_pg_courier', self::PROVIDER );
		$order->update_meta_data( '_pg_cb_cid', $cid );
		$order->update_meta_data( '_pg_sf_status', 'in_review' );
		$order->save();
		$order->add_order_note( sprintf( 'Carrybee booked. CID: %s | COD: %s%s', $cid, wc_format_decimal( $p['collectable_amount'], 0 ), null !== $fee ? ' | Charge: ' . wc_format_decimal( $fee, 2 ) : '' ), 0, true );
		PG_Team::log( $order->get_id(), 'courier', sprintf( 'Carrybee · CID %s · COD %s', $cid, wc_format_decimal( $p['collectable_amount'], 0 ) ), 'courier_sent' );
		if ( 'courier_sent' !== $order->get_status() ) {
			PG_Team::$quiet = true;
			$order->update_status( 'courier_sent', __( 'Sent to Carrybee.', 'product-glide' ) );
			PG_Team::$quiet = false;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Status                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Carrybee event / transfer_status → the shared status words.
	 * Returns '' for events that do not change the parcel status (e.g. "paid").
	 */
	public static function normalize( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/^order\./', '', $s );
		$s = trim( preg_replace( '/[^a-z]+/', '-', $s ), '-' );
		if ( '' === $s ) {
			return '';
		}
		if ( false !== strpos( $s, 'partial' ) ) {
			return 'partial_delivered';
		}
		if ( false !== strpos( $s, 'return' ) || false !== strpos( $s, 'cancel' ) ) {
			return 'cancelled';
		}
		if ( false !== strpos( $s, 'hold' ) || 'delivery-failed' === $s || 'failed-delivery' === $s || 'failed' === $s ) {
			return 'hold';
		}
		if ( false !== strpos( $s, 'assigned-for-delivery' ) || false !== strpos( $s, 'transit' ) || false !== strpos( $s, 'hub' ) || false !== strpos( $s, 'warehouse' ) || false !== strpos( $s, 'sorting' ) || false !== strpos( $s, 'out-for-delivery' ) || preg_match( '/(^|-)picked(-|$)/', $s ) ) {
			return 'pending';
		}
		if ( preg_match( '/(^|-)delivered$/', $s ) || 'exchange' === $s || 'exchanged' === $s ) {
			return 'delivered';
		}
		if ( preg_match( '/created|updated|pickup|requested|pending|review|new|draft/', $s ) ) {
			return 'in_review';
		}
		if ( preg_match( '/(^|-)paid$/', $s ) ) {
			return '';
		}
		return 'pending';
	}

	/** Friendly English text for an event, for the order card and notes. */
	public static function event_text( $event, array $b = array() ) {
		$t = ucfirst( str_replace( '-', ' ', preg_replace( '/^order\./', '', (string) $event ) ) );
		if ( ! empty( $b['agent_name'] ) ) {
			$t .= ' — rider ' . sanitize_text_field( $b['agent_name'] ) . ( ! empty( $b['agent_phone'] ) ? ' (' . sanitize_text_field( $b['agent_phone'] ) . ')' : '' );
		}
		if ( ! empty( $b['attempt'] ) && (int) $b['attempt'] > 1 ) {
			$t .= ' · attempt ' . (int) $b['attempt'];
		}
		foreach ( array( 'reason', 'remarks' ) as $k ) {
			if ( ! empty( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
				$t .= ' · ' . sanitize_text_field( $b[ $k ] );
			}
		}
		if ( ! empty( $b['invoice_id'] ) ) {
			$t .= ' · invoice ' . sanitize_text_field( $b['invoice_id'] );
		}
		return mb_substr( $t, 0, 250 );
	}

	/** Current status from Carrybee for one consignment row: array( status, extra ) or WP_Error. */
	public static function fetch_status( $row ) {
		$d = PG_Carrybee_API::order_details( $row->consignment_id );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$raw    = isset( $d['transfer_status'] ) ? (string) $d['transfer_status'] : '';
		$status = self::normalize( $raw );
		$extra  = array(
			'tracking_message' => self::event_text( $raw, $d ),
			'delivery_charge'  => self::fees( $d ),
			'cod_amount'       => isset( $d['collected_amount'] ) && is_numeric( $d['collected_amount'] ) ? (float) $d['collected_amount'] : null,
		);
		return array( $status, $extra, $raw );
	}

	/* ------------------------------------------------------------------ */
	/* Webhook                                                             */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			'product-glide/v1',
			'/carrybee-webhook',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Checked inside: the secret header must be echoed back.
			)
		);
	}

	private static function reply( $secret, $body = array( 'status' => 'success' ), $code = 202 ) {
		$r = new WP_REST_Response( $body, $code );
		if ( '' !== $secret ) {
			$r->header( 'X-CB-Webhook-Integration-Header', $secret );
		}
		return $r;
	}

	public static function webhook( WP_REST_Request $req ) {
		$got   = trim( (string) $req->get_header( 'x_cb_webhook_integration_header' ) );
		$saved = trim( (string) PG_Settings::get( 'cb_webhook_secret', '' ) );
		if ( '' === $saved && '' !== $got ) {
			// First contact from the Carrybee panel: remember its secret.
			$s                      = (array) get_option( 'pg_settings', array() );
			$s['cb_webhook_secret'] = sanitize_text_field( $got );
			update_option( 'pg_settings', $s, false );
			$saved = $got;
		}
		if ( '' === $got || ! hash_equals( $saved, $got ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid webhook secret' ), 401 );
		}
		$b = $req->get_json_params();
		if ( ! is_array( $b ) || empty( $b['event'] ) ) {
			return self::reply( $got ); // Integration / test call.
		}
		$event = sanitize_text_field( (string) $b['event'] );
		$cid   = isset( $b['consignment_id'] ) ? sanitize_text_field( (string) $b['consignment_id'] ) : '';
		$inv   = isset( $b['merchant_order_id'] ) ? sanitize_text_field( (string) $b['merchant_order_id'] ) : '';
		$key   = mb_substr( $event . '|' . ( isset( $b['timestamptz'] ) ? (string) $b['timestamptz'] : '' ) . '|' . $cid, 0, 120 );

		if ( 'order.create-failed' === $event ) {
			$oid = $inv ? PG_Steadfast::order_id_from_invoice( $inv ) : 0;
			$o   = $oid ? wc_get_order( $oid ) : null;
			if ( $o ) {
				$o->add_order_note( 'Carrybee could not create the parcel: ' . self::event_text( $event, $b ) . ( ! empty( $b['causes'] ) ? ' ' . PG_Carrybee_API::message_from( array( 'causes' => $b['causes'] ), '' ) : '' ) );
			}
			return self::reply( $got );
		}

		$row = $cid ? PG_Steadfast::find_by_cid( $cid ) : null;
		if ( ! $row && $inv ) {
			$row = PG_Steadfast::find_by_invoice( $inv );
		}
		if ( ! $row && $inv && $cid ) {
			// Booked from the Carrybee panel with our order number: adopt it.
			$oid = PG_Steadfast::order_id_from_invoice( $inv );
			if ( $oid && ! PG_Steadfast::active( $oid ) ) {
				PG_Steadfast::insert(
					array(
						'order_id'       => $oid,
						'provider'       => self::PROVIDER,
						'invoice'        => $inv,
						'consignment_id' => $cid,
						'tracking_code'  => $cid,
						'courier_status' => '',
						'cod_amount'     => isset( $b['collectable_amount'] ) && is_numeric( $b['collectable_amount'] ) ? (float) $b['collectable_amount'] : 0,
					)
				);
				$row = PG_Steadfast::find_by_cid( $cid );
			}
		}
		if ( ! $row ) {
			return self::reply( $got, array( 'status' => 'success', 'message' => 'unknown consignment' ) );
		}
		if ( $key === $row->last_event_key ) {
			return self::reply( $got, array( 'status' => 'success', 'message' => 'duplicate' ) );
		}
		$status = self::normalize( $event );
		$extra  = array(
			'tracking_message' => self::event_text( $event, $b ),
			'last_event_key'   => $key,
			'delivery_charge'  => self::fees( $b ),
			'cod_amount'       => isset( $b['collected_amount'] ) && is_numeric( $b['collected_amount'] ) ? (float) $b['collected_amount'] : null,
		);
		self::apply( $row, $status, $extra );
		return self::reply( $got );
	}

	/**
	 * Apply a status. Active parcels drive the order status (shared logic);
	 * return pickups and exchanges (inactive rows) only update their own record
	 * and leave a note on the order.
	 */
	public static function apply( $row, $status, array $extra ) {
		if ( (int) $row->is_active ) {
			if ( '' === $status ) {
				$upd = array();
				foreach ( array( 'tracking_message', 'last_event_key' ) as $k ) {
					if ( ! empty( $extra[ $k ] ) ) {
						$upd[ $k ] = $extra[ $k ];
					}
				}
				if ( isset( $extra['delivery_charge'] ) && null !== $extra['delivery_charge'] ) {
					$upd['delivery_charge'] = $extra['delivery_charge'];
				}
				PG_Steadfast::update( $row->id, $upd );
				return;
			}
			PG_Steadfast::apply_status( $row, $status, $extra );
			return;
		}
		$upd = array( 'tracking_message' => isset( $extra['tracking_message'] ) ? $extra['tracking_message'] : '' );
		if ( ! empty( $extra['last_event_key'] ) ) {
			$upd['last_event_key'] = $extra['last_event_key'];
		}
		if ( $status ) {
			$upd['courier_status'] = $status;
		}
		if ( isset( $extra['delivery_charge'] ) && null !== $extra['delivery_charge'] ) {
			$upd['delivery_charge'] = $extra['delivery_charge'];
		}
		PG_Steadfast::update( $row->id, $upd );
		if ( $status && $status !== $row->courier_status && in_array( $status, array( 'delivered', 'partial_delivered', 'cancelled', 'hold' ), true ) ) {
			$o = wc_get_order( $row->order_id );
			if ( $o ) {
				$o->add_order_note( sprintf( 'Carrybee %s %s: %s', false !== strpos( $row->invoice, '-EX' ) ? 'exchange' : 'return pickup', $row->consignment_id, $upd['tracking_message'] ? $upd['tracking_message'] : $status ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Cancel, return pickup, exchange                                     */
	/* ------------------------------------------------------------------ */

	public static function cancel( $row, $reason = '' ) {
		return PG_Carrybee_API::cancel_order( $row->consignment_id, $reason );
	}

	private static function order_and_row() {
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		$row   = $order ? PG_Steadfast::active( $order->get_id() ) : null;
		if ( ! $row || self::PROVIDER !== $row->provider ) {
			wp_send_json_error( array( 'message' => __( 'This order has no Carrybee parcel.', 'product-glide' ) ) );
		}
		return array( $order, $row );
	}

	private static function record_extra( WC_Order $order, array $d, $suffix, array $p, $label ) {
		$cid = isset( $d['consignment_id'] ) ? (string) $d['consignment_id'] : '';
		if ( '' === $cid ) {
			return;
		}
		$row = array(
			'order_id'       => $order->get_id(),
			'provider'       => self::PROVIDER,
			'invoice'        => isset( $p['merchant_order_id'] ) ? $p['merchant_order_id'] : PG_Steadfast::invoice_for( $order ) . $suffix,
			'consignment_id' => $cid,
			'tracking_code'  => $cid,
			'courier_status' => 'in_review',
			'cod_amount'     => isset( $p['collectable_amount'] ) ? (float) $p['collectable_amount'] : 0,
			'is_active'      => 0,
		);
		$fee = self::fees( $d );
		if ( null !== $fee ) {
			$row['delivery_charge'] = $fee;
		}
		PG_Steadfast::insert( $row );
		$order->add_order_note( sprintf( 'Carrybee %s created. CID: %s%s', $label, $cid, null !== $fee ? ' | Charge: ' . wc_format_decimal( $fee, 2 ) : '' ), 0, true );
	}

	public static function ajax_return() {
		PG_Helpers::guard_ajax();
		list( $order, $row ) = self::order_and_row();
		$p = array( 'merchant_order_id' => mb_substr( PG_Steadfast::invoice_for( $order ) . '-RP', 0, 49 ) );
		$note = trim( PG_Helpers::post_textarea( 'reason', '' ) );
		if ( '' !== $note ) {
			$p['special_instruction'] = self::cut( $note, 254 );
		}
		$d = PG_Carrybee_API::reverse_pickup_from( $row->consignment_id, $p );
		if ( is_wp_error( $d ) ) {
			wp_send_json_error( array( 'message' => $d->get_error_message() ) );
		}
		self::record_extra( $order, $d, '-RP', $p, 'return pickup' );
		wp_send_json_success( array( 'message' => sprintf( /* translators: cid */ __( 'Return pickup created (CID %s).', 'product-glide' ), isset( $d['consignment_id'] ) ? $d['consignment_id'] : '' ) ) );
	}

	public static function ajax_exchange() {
		PG_Helpers::guard_ajax();
		list( $order, $row ) = self::order_and_row();
		$p = array(
			'merchant_order_id'  => mb_substr( PG_Steadfast::invoice_for( $order ) . '-EX', 0, 49 ),
			'collectable_amount' => (int) max( 0, min( 100000, round( (float) PG_Helpers::post( 'collectable_amount', 0 ) ) ) ),
			'item_quantity'      => (int) max( 1, min( 200, PG_Helpers::post_int( 'item_quantity', 1 ) ) ),
		);
		$desc = trim( PG_Helpers::post( 'description', '' ) );
		if ( '' !== $desc ) {
			$p['product_description'] = self::cut( $desc, 254 );
		}
		$note = trim( PG_Helpers::post_textarea( 'note', '' ) );
		if ( '' !== $note ) {
			$p['special_instruction'] = self::cut( $note, 254 );
		}
		$d = PG_Carrybee_API::exchange( $row->consignment_id, $p );
		if ( is_wp_error( $d ) ) {
			wp_send_json_error( array( 'message' => $d->get_error_message() ) );
		}
		self::record_extra( $order, $d, '-EX', $p, 'exchange' );
		wp_send_json_success( array( 'message' => sprintf( /* translators: cid */ __( 'Exchange created (CID %s).', 'product-glide' ), isset( $d['consignment_id'] ) ? $d['consignment_id'] : '' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings helpers                                                    */
	/* ------------------------------------------------------------------ */

	private static function out( $res, $msg = '' ) {
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => $msg, 'data' => $res ) );
	}

	public static function ajax_test() {
		PG_Helpers::guard_ajax( true );
		$t0  = microtime( true );
		$res = PG_Carrybee_API::stores();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		self::out( $res, sprintf( /* translators: 1 ms 2 env 3 count */ __( 'Connected to Carrybee %2$s (%1$d ms). %3$d pickup store(s) found.', 'product-glide' ), $ms, PG_Settings::get( 'cb_env', 'production' ), count( $res ) ) );
	}

	public static function ajax_stores() {
		PG_Helpers::guard_ajax( true );
		self::out( PG_Carrybee_API::stores() );
	}

	public static function ajax_cities() {
		PG_Helpers::guard_ajax( true );
		self::out( PG_Carrybee_API::cities() );
	}

	public static function ajax_zones() {
		PG_Helpers::guard_ajax( true );
		self::out( PG_Carrybee_API::zones( PG_Helpers::post_int( 'city_id' ) ) );
	}

	public static function ajax_areas() {
		PG_Helpers::guard_ajax( true );
		self::out( PG_Carrybee_API::areas( PG_Helpers::post_int( 'city_id' ), PG_Helpers::post_int( 'zone_id' ) ) );
	}

	public static function ajax_create_store() {
		PG_Helpers::guard_ajax( true );
		$s = array(
			'name'                  => mb_substr( PG_Helpers::post( 'name' ), 0, 30 ),
			'contact_person_name'   => mb_substr( PG_Helpers::post( 'contact_person_name' ), 0, 30 ),
			'contact_person_number' => PG_Helpers::bd_phone( PG_Helpers::post( 'contact_person_number' ) ),
			'address'               => mb_substr( PG_Helpers::post( 'address' ), 0, 100 ),
			'city_id'               => PG_Helpers::post_int( 'city_id' ),
			'zone_id'               => PG_Helpers::post_int( 'zone_id' ),
			'area_id'               => PG_Helpers::post_int( 'area_id' ),
		);
		$res = PG_Carrybee_API::create_store( $s );
		self::out( $res, __( 'Store created. Carrybee may need to approve it before it can be used.', 'product-glide' ) );
	}
}
