<?php
defined( 'ABSPATH' ) || exit;

/**
 * Incomplete orders: customers who typed their details on the checkout page but
 * never pressed "Place order". The checkout form is saved (only once a valid BD
 * mobile number is there) so the team can call, then turn it into a real order
 * with one click — optionally booking Steadfast at the same time.
 *
 * Status: open (waiting) · hold (call later) · cancelled · converted (we made the
 * order) · ordered (the customer ordered by themselves).
 */
class PG_Leads {

	const STATUSES = array( 'open', 'hold', 'cancelled', 'converted', 'ordered' );
	const COOKIE   = 'pg_lead_sk';

	public static function table() {
		return PG_Install::table( 'leads' );
	}

	public static function init() {
		// Front end: capture.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ), 20 );
		add_action( 'wp_ajax_pg_lead_track', array( __CLASS__, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_pg_lead_track', array( __CLASS__, 'ajax_track' ) );
		// The customer finished the order themselves.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_placed' ), 30, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_block_order_placed' ), 30, 1 );
		// Dashboard.
		add_action( 'wp_ajax_pg_leads_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_pg_leads_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_pg_leads_note', array( __CLASS__, 'ajax_note' ) );
		add_action( 'wp_ajax_pg_leads_confirm', array( __CLASS__, 'ajax_confirm' ) );
		add_action( 'wp_ajax_pg_leads_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_pg_leads_import_od', array( __CLASS__, 'ajax_import_od' ) );
		// Housekeeping.
		add_action( 'pg_leads_cleanup', array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( 'pg_leads_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pg_leads_cleanup' );
		}
	}

	public static function enabled() {
		return (bool) PG_Settings::get( 'lead_enabled', 1 );
	}

	/** Minutes of silence after which a waiting entry counts as abandoned. */
	public static function idle_minutes() {
		return max( 2, (int) PG_Settings::get( 'lead_minutes', 10 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Capture (front end)                                                 */
	/* ------------------------------------------------------------------ */

	private static function is_checkout_page() {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' ) ) {
			return true;
		}
		// CartFlows / funnel checkout steps and landing pages with an embedded checkout.
		$post = get_post();
		if ( $post && ( 'cartflows_step' === $post->post_type || has_shortcode( $post->post_content, 'woocommerce_checkout' ) || has_block( 'woocommerce/checkout', $post ) ) ) {
			return true;
		}
		return false;
	}

	public static function front_assets() {
		if ( ! self::enabled() || is_admin() || ! self::is_checkout_page() ) {
			return;
		}
		wp_enqueue_script( 'pg-lead-capture', PG_URL . 'assets/js/lead-capture.js', array(), PG_VERSION, true );
		wp_localize_script(
			'pg-lead-capture',
			'PG_LEAD',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'wait'  => 1500,
			)
		);
	}

	private static function session_key() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			$id = (string) WC()->session->get_customer_id();
			if ( '' !== $id ) {
				return 'wc_' . substr( sanitize_key( $id ), 0, 60 );
			}
		}
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			return 'ck_' . substr( sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), 0, 60 );
		}
		$k = wp_generate_password( 32, false, false );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $k, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		return 'ck_' . strtolower( $k );
	}

	/** Never keep anything that looks like a password, card or security value. */
	private static function clean_fields( $raw ) {
		$out = array();
		foreach ( (array) $raw as $k => $v ) {
			$k = sanitize_key( $k );
			if ( '' === $k || is_array( $v ) || preg_match( '/pass|card|cvc|cvv|expiry|token|nonce|security|otp|account_|createaccount|_wpnonce|stripe|terms/', $k ) ) {
				continue;
			}
			if ( ! preg_match( '/^(billing_|shipping_|order_comments$|payment_method$|ship_to_different_address$)/', $k ) ) {
				continue;
			}
			$v = (string) $v;
			$v = false !== strpos( $k, 'email' ) ? sanitize_email( $v ) : ( 'order_comments' === $k ? sanitize_textarea_field( $v ) : sanitize_text_field( $v ) );
			if ( '' !== $v ) {
				$out[ $k ] = mb_substr( $v, 0, 500 );
			}
		}
		return $out;
	}

	private static function state_name( $country, $state ) {
		if ( ! $state || ! WC()->countries ) {
			return (string) $state;
		}
		$list = WC()->countries->get_states( $country ? $country : 'BD' );
		return isset( $list[ $state ] ) ? html_entity_decode( $list[ $state ] ) : $state;
	}

	/** Turn the raw checkout fields into the columns we show. */
	private static function customer_from_fields( array $f ) {
		$ship = ! empty( $f['ship_to_different_address'] ) && '0' !== $f['ship_to_different_address'];
		$pick = function ( $key ) use ( $f, $ship ) {
			$b = isset( $f[ 'billing_' . $key ] ) ? $f[ 'billing_' . $key ] : '';
			$s = isset( $f[ 'shipping_' . $key ] ) ? $f[ 'shipping_' . $key ] : '';
			return $ship && '' !== $s ? $s : ( '' !== $b ? $b : $s );
		};
		$name    = trim( $pick( 'first_name' ) . ' ' . $pick( 'last_name' ) );
		$country = $pick( 'country' );
		$addr    = trim( $pick( 'address_1' ) . ( $pick( 'address_2' ) ? ', ' . $pick( 'address_2' ) : '' ) );
		return array(
			'name'    => $name,
			'phone'   => isset( $f['billing_phone'] ) ? $f['billing_phone'] : $pick( 'phone' ),
			'email'   => isset( $f['billing_email'] ) ? $f['billing_email'] : '',
			'address' => $addr,
			'city'    => $pick( 'city' ),
			'state'   => self::state_name( $country, $pick( 'state' ) ),
			'note'    => isset( $f['order_comments'] ) ? $f['order_comments'] : '',
		);
	}

	private static function cart_items() {
		$items = array();
		foreach ( WC()->cart->get_cart() as $ci ) {
			$p = isset( $ci['data'] ) ? $ci['data'] : null;
			if ( ! $p || ! is_object( $p ) ) {
				continue;
			}
			$qty     = max( 1, (int) $ci['quantity'] );
			$sub     = isset( $ci['line_subtotal'] ) ? (float) $ci['line_subtotal'] : (float) $p->get_price() * $qty;
			$items[] = array(
				'product_id' => (int) $p->get_id(),
				'name'       => wp_strip_all_tags( $p->get_name() ),
				'qty'        => $qty,
				'price'      => round( $sub / $qty, 2 ),
				'total'      => isset( $ci['line_total'] ) ? (float) $ci['line_total'] : $sub,
				'thumb'      => $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '',
			);
		}
		return $items;
	}

	public static function ajax_track() {
		// No nonce on purpose: checkout/funnel pages are often cached, and a guest nonce
		// is tied to one visitor's session — a cached one would block every other customer.
		// Nothing is saved unless this visitor's own session has a cart and a valid BD number.
		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'reason' => 'off' ), 200 );
		}
		if ( function_exists( 'WC' ) && ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'reason' => 'nocart' ), 200 );
		}
		if ( WC()->cart->is_empty() ) {
			WC()->cart->get_cart_from_session();
		}
		if ( WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'reason' => 'empty' ), 200 );
		}
		$raw    = isset( $_POST['f'] ) ? json_decode( wp_unslash( $_POST['f'] ), true ) : array(); // phpcs:ignore -- cleaned below.
		$fields = self::clean_fields( is_array( $raw ) ? $raw : array() );
		$c      = self::customer_from_fields( $fields );
		$phone  = PG_Helpers::bd_phone( $c['phone'] );
		if ( ! $phone ) {
			wp_send_json_success( array( 'saved' => false, 'reason' => 'phone' ) );
		}
		$id = self::save( self::session_key(), $phone, $c, $fields, self::cart_items(), WC()->cart );
		wp_send_json_success( array( 'saved' => (bool) $id ) );
	}

	private static function save( $session, $phone, array $c, array $fields, array $items, $cart ) {
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql', true );
		$row = array(
			'phone'            => $phone,
			'phone_key'        => PG_Helpers::phone_key( $phone ),
			'name'             => mb_substr( $c['name'], 0, 150 ),
			'email'            => mb_substr( $c['email'], 0, 190 ),
			'address'          => mb_substr( $c['address'], 0, 500 ),
			'city'             => mb_substr( $c['city'], 0, 100 ),
			'state'            => mb_substr( $c['state'], 0, 100 ),
			'note'             => mb_substr( $c['note'], 0, 1000 ),
			'items'            => wp_json_encode( $items ),
			'item_count'       => array_sum( wp_list_pluck( $items, 'qty' ) ),
			'subtotal'         => (float) $cart->get_subtotal(),
			'discount'         => (float) $cart->get_discount_total(),
			'shipping'         => (float) $cart->get_shipping_total(),
			'total'            => (float) $cart->get_total( 'edit' ),
			'fields'           => wp_json_encode( $fields ),
			'ip'               => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'last_activity_at' => $now,
			'updated_at'       => $now,
		);
		// Same browser, still waiting → update it. A different device with the same phone → merge too.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE session_key = %s AND status IN ('open','hold','cancelled') ORDER BY id DESC LIMIT 1", $session ) ); // phpcs:ignore
		if ( ! $existing ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE phone_key = %s AND status IN ('open','hold') AND last_activity_at > %s ORDER BY id DESC LIMIT 1", $row['phone_key'], gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore
		}
		if ( $existing ) {
			if ( 'cancelled' === $existing->status ) {
				$row['status'] = 'open'; // They came back — worth another call.
			}
			$wpdb->update( $t, $row, array( 'id' => (int) $existing->id ) ); // phpcs:ignore
			return (int) $existing->id;
		}
		$row['session_key'] = $session;
		$row['status']      = 'open';
		$row['source']      = 'checkout';
		$row['created_at']  = $now;
		$wpdb->insert( $t, $row ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	/* ------------------------------------------------------------------ */
	/* The customer completed the order                                    */
	/* ------------------------------------------------------------------ */

	public static function on_order_placed( $order_id, $posted = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			self::mark_ordered( $order, true );
		}
	}

	public static function on_block_order_placed( $order ) {
		if ( $order instanceof WC_Order ) {
			self::mark_ordered( $order, true );
		}
	}

	private static function mark_ordered( WC_Order $order, $with_session ) {
		global $wpdb;
		$t    = self::table();
		$now  = current_time( 'mysql', true );
		$key  = PG_Helpers::phone_key( $order->get_billing_phone() );
		$sess = '';
		if ( $with_session && function_exists( 'WC' ) && WC()->session && WC()->session->get_customer_id() ) {
			$sess = 'wc_' . substr( sanitize_key( (string) WC()->session->get_customer_id() ), 0, 60 );
		}
		$where = array();
		$args  = array( $order->get_id(), $now );
		if ( $sess ) {
			$where[] = 'session_key = %s';
			$args[]  = $sess;
		}
		if ( strlen( $key ) >= 10 ) {
			$where[] = '( phone_key = %s AND created_at > %s )';
			$args[]  = $key;
			$args[]  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		}
		if ( ! $where ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'ordered', order_id = %d, updated_at = %s WHERE status IN ('open','hold','cancelled') AND ( " . implode( ' OR ', $where ) . ' )', $args ) ); // phpcs:ignore
	}

	/**
	 * Safety net for orders that did not pass through the checkout hooks (phone
	 * orders, other plugins): any waiting entry whose phone placed an order after it
	 * was captured is marked "ordered".
	 */
	private static function reconcile( array $rows ) {
		global $wpdb;
		$keys = array();
		foreach ( $rows as $r ) {
			if ( in_array( $r->status, array( 'open', 'hold' ), true ) ) {
				$keys[] = $r->phone_key;
			}
		}
		if ( ! $keys ) {
			return $rows;
		}
		$map = PG_Helpers::orders_by_phone_keys( $keys );
		$t   = self::table();
		foreach ( $rows as $r ) {
			if ( ! in_array( $r->status, array( 'open', 'hold' ), true ) || empty( $map[ $r->phone_key ] ) ) {
				continue;
			}
			foreach ( $map[ $r->phone_key ] as $o ) {
				if ( strtotime( $o['created'] . ' UTC' ) >= strtotime( $r->created_at . ' UTC' ) - 60 && ! in_array( $o['status'], array( 'cancelled', 'failed' ), true ) ) {
					$wpdb->update( $t, array( 'status' => 'ordered', 'order_id' => $o['id'], 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $r->id ) ); // phpcs:ignore
					$r->status   = 'ordered';
					$r->order_id = $o['id'];
					break;
				}
			}
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                           */
	/* ------------------------------------------------------------------ */

	public static function tabs() {
		return array(
			'waiting'   => __( 'Incomplete', 'product-glide' ),
			'active'    => __( 'On checkout now', 'product-glide' ),
			'hold'      => __( 'Call later', 'product-glide' ),
			'converted' => __( 'Confirmed', 'product-glide' ),
			'ordered'   => __( 'Ordered by customer', 'product-glide' ),
			'cancelled' => __( 'Cancelled', 'product-glide' ),
			'all'       => __( 'All', 'product-glide' ),
		);
	}

	private static function tab_where( $tab, &$args ) {
		$cut = gmdate( 'Y-m-d H:i:s', time() - self::idle_minutes() * MINUTE_IN_SECONDS );
		switch ( $tab ) {
			case 'waiting':
				$args[] = $cut;
				return "status = 'open' AND last_activity_at <= %s";
			case 'active':
				$args[] = $cut;
				return "status = 'open' AND last_activity_at > %s";
			case 'all':
				return '1=1';
			default:
				$args[] = in_array( $tab, self::STATUSES, true ) ? $tab : 'open';
				return 'status = %s';
		}
	}

	/** Number on the menu: incomplete entries from the last 3 days that nobody handled yet. */
	public static function badge_count() {
		global $wpdb;
		$c = get_transient( 'pg_leads_badge' );
		if ( false === $c ) {
			$t   = self::table();
			$cut = gmdate( 'Y-m-d H:i:s', time() - self::idle_minutes() * MINUTE_IN_SECONDS );
			$c   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'open' AND last_activity_at <= %s AND last_activity_at > %s", $cut, gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
			set_transient( 'pg_leads_badge', $c, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $c;
	}

	public static function counts() {
		delete_transient( 'pg_leads_badge' );
		global $wpdb;
		$t   = self::table();
		$cut = gmdate( 'Y-m-d H:i:s', time() - self::idle_minutes() * MINUTE_IN_SECONDS );
		$r   = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status='open' AND last_activity_at <= %s) waiting, SUM(status='open' AND last_activity_at > %s) active, SUM(status='hold') hold, SUM(status='converted') converted, SUM(status='ordered') ordered, SUM(status='cancelled') cancelled, COUNT(*) total FROM {$t}", $cut, $cut ), ARRAY_A ); // phpcs:ignore
		$out = array();
		foreach ( array_keys( self::tabs() ) as $k ) {
			$out[ $k ] = 'all' === $k ? (int) $r['total'] : (int) $r[ $k ];
		}
		return $out;
	}

	private static function user_name( $uid ) {
		static $c = array();
		if ( ! $uid ) {
			return '';
		}
		if ( ! isset( $c[ $uid ] ) ) {
			$u         = get_userdata( $uid );
			$c[ $uid ] = $u ? $u->display_name : '';
		}
		return $c[ $uid ];
	}

	public static function format( $r ) {
		$items = json_decode( (string) $r->items, true );
		$notes = json_decode( (string) $r->staff_notes, true );
		$ago   = time() - strtotime( $r->last_activity_at . ' UTC' );
		$order = $r->order_id ? wc_get_order( (int) $r->order_id ) : null;
		return array(
			'id'          => (int) $r->id,
			'status'      => $r->status,
			'live'        => 'open' === $r->status && $ago < self::idle_minutes() * MINUTE_IN_SECONDS,
			'name'        => $r->name,
			'phone'       => $r->phone,
			'phone_bd'    => PG_Helpers::bd_phone( $r->phone ),
			'email'       => $r->email,
			'address'     => $r->address,
			'city'        => $r->city,
			'state'       => $r->state,
			'full_address' => implode( ', ', array_filter( array_unique( array( $r->address, $r->city, $r->state ) ) ) ),
			'note'        => $r->note,
			'items'       => is_array( $items ) ? $items : array(),
			'item_count'  => (int) $r->item_count,
			'subtotal'    => (float) $r->subtotal,
			'discount'    => (float) $r->discount,
			'shipping'    => (float) $r->shipping,
			'total'       => (float) $r->total,
			'source'      => $r->source,
			'created'     => PG_Helpers::bd_time( $r->created_at ),
			'last'        => PG_Helpers::bd_time( $r->last_activity_at ),
			'ago_min'     => (int) floor( $ago / 60 ),
			'notes'       => is_array( $notes ) ? $notes : array(),
			'handled_by'  => self::user_name( (int) $r->user_id ),
			'order'       => $order ? array(
				'id'     => $order->get_id(),
				'number' => $order->get_order_number(),
				'status' => PG_Helpers::status_label( $order->get_status() ),
				'url'    => add_query_arg( 'q', $order->get_order_number(), PG_App::is_manager_only() ? PG_App::url() : admin_url( 'admin.php?page=product-glide' ) ),
			) : null,
		);
	}

	public static function ajax_list() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$t    = self::table();
		$tab  = sanitize_key( PG_Helpers::post( 'tab', 'waiting' ) );
		$q    = trim( PG_Helpers::post( 'q', '' ) );
		$per  = min( 100, max( 5, PG_Helpers::post_int( 'per_page', 25 ) ) );
		$page = max( 1, PG_Helpers::post_int( 'page', 1 ) );
		$args = array();
		$w    = self::tab_where( isset( self::tabs()[ $tab ] ) ? $tab : 'waiting', $args );
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$digits = preg_replace( '/\D+/', '', $q );
			$w     .= ' AND ( name LIKE %s OR email LIKE %s OR address LIKE %s' . ( strlen( $digits ) >= 4 ? ' OR phone LIKE %s' : '' ) . ' )';
			array_push( $args, $like, $like, $like );
			if ( strlen( $digits ) >= 4 ) {
				$args[] = '%' . $wpdb->esc_like( $digits ) . '%';
			}
		}
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $args ) ); // phpcs:ignore
		$order  = in_array( $tab, array( 'waiting', 'active' ), true ) ? 'last_activity_at DESC' : 'updated_at DESC';
		$args2  = array_merge( $args, array( $per, ( $page - 1 ) * $per ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY {$order} LIMIT %d OFFSET %d", $args2 ) ); // phpcs:ignore
		$rows   = self::reconcile( (array) $rows );
		$keys   = array();
		foreach ( $rows as $r ) {
			$keys[] = $r->phone_key;
		}
		$history = PG_Helpers::orders_by_phone_keys( $keys );
		$fraud   = PG_Fraud::cached_many( wp_list_pluck( $rows, 'phone' ) );
		$out     = array();
		foreach ( $rows as $r ) {
			$d          = self::format( $r );
			$prev       = isset( $history[ $r->phone_key ] ) ? $history[ $r->phone_key ] : array();
			$own        = (int) $r->order_id;
			$prev       = array_values(
				array_filter(
					$prev,
					function ( $o ) use ( $own ) {
						return $o['id'] !== $own;
					}
				)
			);
			$d['past']  = array(
				'count'  => count( $prev ),
				'recent' => array_slice(
					array_map(
						function ( $o ) {
							return array( 'id' => $o['id'], 'status' => PG_Helpers::status_label( $o['status'] ), 'date' => PG_Helpers::bd_time( $o['created'], 'd M Y' ) );
						},
						$prev
					),
					0,
					3
				),
			);
			$d['fraud'] = isset( $fraud[ $d['phone_bd'] ] ) ? $fraud[ $d['phone_bd'] ] : null;
			$out[]      = $d;
		}
		wp_send_json_success(
			array(
				'leads'  => $out,
				'counts' => self::counts(),
				'page'   => $page,
				'pages'  => max( 1, (int) ceil( $total / $per ) ),
				'total'  => $total,
			)
		);
	}

	private static function get_row( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	private static function add_note( $row, $text, $kind = 'note' ) {
		$notes   = json_decode( (string) $row->staff_notes, true );
		$notes   = is_array( $notes ) ? $notes : array();
		$notes[] = array(
			'text' => mb_substr( $text, 0, 500 ),
			'kind' => $kind,
			'by'   => self::user_name( get_current_user_id() ),
			'at'   => PG_Helpers::bd_time( time(), 'd M, h:i A' ),
		);
		return wp_json_encode( array_slice( $notes, -30 ) );
	}

	public static function ajax_status() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$row    = self::get_row( PG_Helpers::post_int( 'id' ) );
		$status = sanitize_key( PG_Helpers::post( 'status' ) );
		if ( ! $row || ! in_array( $status, array( 'open', 'hold', 'cancelled' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Not found.', 'product-glide' ) ) );
		}
		if ( in_array( $row->status, array( 'converted', 'ordered' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This one already became an order.', 'product-glide' ) ) );
		}
		$labels = array( 'open' => 'আবার অসম্পূর্ণ তালিকায়', 'hold' => 'পরে কল', 'cancelled' => 'বাতিল' );
		$note   = PG_Helpers::post_textarea( 'note', '' );
		$wpdb->update( // phpcs:ignore
			self::table(),
			array(
				'status'      => $status,
				'user_id'     => get_current_user_id(),
				'staff_notes' => self::add_note( $row, $labels[ $status ] . ( $note ? ': ' . $note : '' ), 'status' ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row->id )
		);
		wp_send_json_success( array( 'lead' => self::format( self::get_row( $row->id ) ), 'counts' => self::counts() ) );
	}

	public static function ajax_note() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$row  = self::get_row( PG_Helpers::post_int( 'id' ) );
		$text = trim( PG_Helpers::post_textarea( 'note', '' ) );
		if ( ! $row || '' === $text ) {
			wp_send_json_error( array( 'message' => __( 'Write a note first.', 'product-glide' ) ) );
		}
		$wpdb->update( self::table(), array( 'staff_notes' => self::add_note( $row, $text ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
		wp_send_json_success( array( 'lead' => self::format( self::get_row( $row->id ) ) ) );
	}

	public static function ajax_delete() {
		PG_Helpers::guard_ajax( true );
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) PG_Helpers::post( 'ids', array() ) ) );
		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore -- integers.
		}
		wp_send_json_success( array( 'counts' => self::counts() ) );
	}

	/** Make the real order (and optionally book Steadfast). */
	public static function ajax_confirm() {
		PG_Helpers::guard_ajax();
		global $wpdb;
		$row = self::get_row( PG_Helpers::post_int( 'id' ) );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Not found.', 'product-glide' ) ) );
		}
		if ( in_array( $row->status, array( 'converted', 'ordered' ), true ) && $row->order_id ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: order id */ __( 'Already an order (#%s).', 'product-glide' ), $row->order_id ) ) );
		}
		$c    = PG_Helpers::post_json( 'customer' );
		$cust = array();
		foreach ( array( 'name', 'phone', 'address', 'city', 'email', 'payment' ) as $k ) {
			$cust[ $k ] = isset( $c[ $k ] ) ? sanitize_text_field( $c[ $k ] ) : '';
		}
		$cust['note'] = isset( $c['note'] ) ? sanitize_textarea_field( $c['note'] ) : '';
		$items        = array();
		foreach ( PG_Helpers::post_json( 'items' ) as $it ) {
			$items[] = array(
				'product_id' => absint( isset( $it['product_id'] ) ? $it['product_id'] : 0 ),
				'qty'        => absint( isset( $it['qty'] ) ? $it['qty'] : 1 ),
				'price'      => isset( $it['price'] ) && '' !== $it['price'] ? (float) $it['price'] : '',
			);
		}
		$order = PG_Packages::create_order( $cust, $items, (float) PG_Helpers::post( 'discount', 0 ), (float) PG_Helpers::post( 'delivery', 0 ), 'lead', (string) $row->id );
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( array( 'message' => $order->get_error_message() ) );
		}
		$order->update_meta_data( '_pg_lead_id', (int) $row->id );
		$order->save();
		PG_Team::log( $order->get_id(), 'recovered', sprintf( 'Incomplete order #%d', $row->id ) );

		$wpdb->update( // phpcs:ignore
			self::table(),
			array(
				'status'      => 'converted',
				'order_id'    => $order->get_id(),
				'user_id'     => get_current_user_id(),
				'staff_notes' => self::add_note( $row, sprintf( 'কনফার্ম → অর্ডার #%s%s', $order->get_order_number(), PG_Helpers::post_int( 'send_sf' ) ? ' (Steadfast)' : '' ), 'status' ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row->id )
		);

		$msg     = sprintf( /* translators: order no */ __( 'Order #%s created.', 'product-glide' ), $order->get_order_number() );
		$warning = '';
		if ( PG_Helpers::post_int( 'send_sf' ) ) {
			if ( ! PG_Steadfast_API::configured() ) {
				$warning = __( 'Steadfast is not set up — the order is in Processing. Send it from the Orders page.', 'product-glide' );
			} else {
				$cod = PG_Helpers::post( 'cod', '' );
				$res = PG_Steadfast::book( wc_get_order( $order->get_id() ), array( 'cod_amount' => '' !== $cod ? (float) $cod : '', 'note' => PG_Helpers::post_textarea( 'sf_note', '' ) ) );
				if ( is_wp_error( $res ) ) {
					$warning = sprintf( /* translators: error */ __( 'Order created, but Steadfast booking failed: %s. It is in Processing — send it again from the Orders page.', 'product-glide' ), $res->get_error_message() );
				} else {
					$msg .= ' ' . sprintf( /* translators: tracking */ __( 'Sent to Steadfast (tracking %s).', 'product-glide' ), $res['tracking_code'] );
				}
			}
		}
		wp_send_json_success(
			array(
				'message' => $msg,
				'warning' => $warning,
				'lead'    => self::format( self::get_row( $row->id ) ),
				'counts'  => self::counts(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Import from Order Detect, clean-up                                  */
	/* ------------------------------------------------------------------ */

	public static function od_table() {
		global $wpdb;
		$t = $wpdb->prefix . 'od_incomplate_orders';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ? $t : ''; // phpcs:ignore
	}

	public static function od_pending() {
		global $wpdb;
		$od = self::od_table();
		if ( ! $od ) {
			return 0;
		}
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$od} o WHERE NOT EXISTS (SELECT 1 FROM {$t} l WHERE l.session_key = CONCAT('od_', o.id))" ); // phpcs:ignore
	}

	public static function import_od( $limit = 500 ) {
		global $wpdb;
		$od = self::od_table();
		if ( ! $od ) {
			return 0;
		}
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.* FROM {$od} o WHERE NOT EXISTS (SELECT 1 FROM {$t} l WHERE l.session_key = CONCAT('od_', o.id)) ORDER BY o.id ASC LIMIT %d", $limit ) ); // phpcs:ignore
		$map  = array( 'active' => 'open', 'abandoned' => 'open', 'on-hold' => 'hold', 'cancelled' => 'cancelled', 'completed' => 'converted' );
		$n    = 0;
		foreach ( (array) $rows as $o ) {
			$fields = json_decode( (string) $o->checkout_payload, true );
			$fields = self::clean_fields( is_array( $fields ) ? $fields : array() );
			$c      = self::customer_from_fields( $fields );
			if ( '' === $c['name'] ) {
				$c['name'] = trim( $o->first_name . ' ' . $o->last_name );
			}
			$snap  = is_serialized( (string) $o->cart_snapshot ) ? @unserialize( (string) $o->cart_snapshot, array( 'allowed_classes' => false ) ) : json_decode( (string) $o->cart_snapshot, true ); // phpcs:ignore
			$items = array();
			foreach ( is_array( $snap ) ? $snap : array() as $it ) {
				$qty     = max( 1, (int) ( isset( $it['quantity'] ) ? $it['quantity'] : 1 ) );
				$line    = (float) ( isset( $it['line_total'] ) ? $it['line_total'] : 0 );
				$items[] = array(
					'product_id' => (int) ( isset( $it['product_id'] ) ? $it['product_id'] : 0 ),
					'name'       => isset( $it['name'] ) ? wp_strip_all_tags( (string) $it['name'] ) : '',
					'qty'        => $qty,
					'price'      => round( $line / $qty, 2 ),
					'total'      => $line,
					'thumb'      => isset( $it['image'] ) ? esc_url_raw( (string) $it['image'] ) : '',
				);
			}
			$phone = PG_Helpers::bd_phone( $o->phone );
			$sub   = array_sum( wp_list_pluck( $items, 'total' ) );
			$wpdb->insert( // phpcs:ignore
				$t,
				array(
					'session_key'      => 'od_' . (int) $o->id,
					'status'           => isset( $map[ $o->status ] ) ? $map[ $o->status ] : 'open',
					'source'           => 'order-detect',
					'phone'            => $phone ? $phone : (string) $o->phone,
					'phone_key'        => PG_Helpers::phone_key( $o->phone ),
					'name'             => mb_substr( $c['name'], 0, 150 ),
					'email'            => mb_substr( $c['email'] ? $c['email'] : (string) $o->email, 0, 190 ),
					'address'          => mb_substr( $c['address'], 0, 500 ),
					'city'             => mb_substr( $c['city'], 0, 100 ),
					'state'            => mb_substr( $c['state'], 0, 100 ),
					'note'             => mb_substr( $c['note'], 0, 1000 ),
					'items'            => wp_json_encode( $items ),
					'item_count'       => (int) $o->item_count,
					'subtotal'         => $sub,
					'discount'         => 0,
					'shipping'         => max( 0, (float) $o->cart_total - $sub ),
					'total'            => (float) $o->cart_total,
					'fields'           => wp_json_encode( $fields ),
					'ip'               => (string) $o->customer_ip,
					// Order Detect stores every time in UTC (MySQL TIMESTAMP / gmdate).
					'created_at'       => $o->created_at && $o->created_at < $o->last_activity_at ? $o->created_at : $o->last_activity_at,
					'last_activity_at' => $o->last_activity_at,
					'updated_at'       => $o->updated_at ? $o->updated_at : $o->last_activity_at,
				)
			);
			$n++;
		}
		return $n;
	}

	public static function ajax_import_od() {
		PG_Helpers::guard_ajax( true );
		$n = self::import_od( 2000 );
		wp_send_json_success( array( 'imported' => $n, 'left' => self::od_pending(), 'message' => sprintf( /* translators: count */ __( 'Imported %d entries from Order Detect.', 'product-glide' ), $n ) ) );
	}

	public static function cleanup() {
		global $wpdb;
		$days = max( 7, (int) PG_Settings::get( 'lead_keep_days', 365 ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE updated_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}
}
