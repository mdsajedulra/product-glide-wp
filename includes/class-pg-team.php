<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff performance: logs what each Order Manager / admin does to orders
 * and reports it per person and per day (admin only).
 *
 * Actions: created, recovered, confirmed, courier, cancelled, status, edited, note, printed.
 */
class PG_Team {

	/** Set true while a step logs its own action, so the status hook stays quiet. */
	public static $quiet = false;

	const LABELS = array(
		'created'   => 'Created order',
		'recovered' => 'Recovered incomplete order',
		'confirmed' => 'Confirmed',
		'courier'   => 'Sent to courier',
		'cancelled' => 'Cancelled',
		'status'    => 'Changed status',
		'edited'    => 'Edited order',
		'note'      => 'Added note',
		'printed'   => 'Printed invoice',
	);

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 20, 4 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'on_admin_save' ), 99, 1 );
		add_action( 'woocommerce_order_note_added', array( __CLASS__, 'on_admin_note' ), 10, 2 );
		add_action( 'wp_ajax_pg_team_report', array( __CLASS__, 'ajax_report' ) );
		add_action( 'wp_ajax_pg_team_activity', array( __CLASS__, 'ajax_activity' ) );
		if ( ! get_option( 'pg_team_backfilled' ) ) {
			// After every plugin has registered its order statuses.
			add_action( 'init', array( __CLASS__, 'backfill' ), 99 );
		}
	}

	public static function table() {
		return PG_Install::table( 'activity' );
	}

	/* ------------------------------------------------------------------ */
	/* Logging                                                             */
	/* ------------------------------------------------------------------ */

	/** Only people who work in the dashboard are tracked (not customers / cron / webhooks). */
	private static function staff_id() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return 0;
		}
		return ( user_can( $uid, PG_App::CAP ) || user_can( $uid, 'manage_options' ) ) ? $uid : 0;
	}

	public static function log( $order_id, $action, $detail = '', $status_to = '', $user_id = 0, $time_gmt = '' ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : self::staff_id();
		if ( ! $user_id || ! $order_id || ! isset( self::LABELS[ $action ] ) ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore
			self::table(),
			array(
				'user_id'    => $user_id,
				'order_id'   => (int) $order_id,
				'action'     => $action,
				'status_to'  => substr( (string) $status_to, 0, 40 ),
				'detail'     => mb_substr( (string) $detail, 0, 250 ),
				'created_at' => $time_gmt ? $time_gmt : current_time( 'mysql', true ),
			)
		);
	}

	/** Turn a status change into the most meaningful action. */
	private static function status_action( $from, $to ) {
		if ( 'cancelled' === $to ) {
			return 'cancelled';
		}
		if ( 'courier_sent' === $to ) {
			return 'courier';
		}
		if ( 'processing' === $to && in_array( $from, array( 'pending', 'on-hold', 'checkout-draft', 'failed', 'payment_pending' ), true ) ) {
			return 'confirmed';
		}
		return 'status';
	}

	public static function on_status( $order_id, $from, $to, $order = null ) {
		if ( self::$quiet ) {
			return;
		}
		$labels = wc_get_order_statuses();
		$lf     = isset( $labels[ 'wc-' . $from ] ) ? $labels[ 'wc-' . $from ] : $from;
		$lt     = isset( $labels[ 'wc-' . $to ] ) ? $labels[ 'wc-' . $to ] : $to;
		self::log( $order_id, self::status_action( $from, $to ), $lf . ' → ' . $lt, $to );
	}

	/** Order saved from the normal WooCommerce edit screen. */
	public static function on_admin_save( $order_id ) {
		global $wpdb;
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$t     = self::table();
		$known = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE order_id = %d AND action = 'created'", $order_id ) ); // phpcs:ignore
		$new   = 'admin' === $order->get_created_via() && ! $known && ( time() - ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0 ) ) < 3600;
		self::log( $order_id, $new ? 'created' : 'edited', $new ? 'WooCommerce admin' : 'WooCommerce edit screen' );
	}

	/** Private note added from the WooCommerce edit screen. */
	public static function on_admin_note( $note_id, $order ) {
		if ( doing_action( 'wp_ajax_woocommerce_add_order_note' ) && $order ) {
			$c = get_comment( $note_id );
			self::log( $order->get_id(), 'note', $c ? wp_strip_all_tags( $c->comment_content ) : '' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* One-time import from order notes written by staff                   */
	/* ------------------------------------------------------------------ */

	public static function backfill( $days = 90 ) {
		$days = is_numeric( $days ) ? (int) $days : 90;
		global $wpdb;
		$t = self::table();
		// add_option fails if another request already started the import.
		if ( ! add_option( 'pg_team_backfilled', time(), '', false ) ) {
			return 0;
		}
		$staff = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator', PG_App::ROLE, 'shop_manager' ) ) ) as $u ) {
			$staff[ strtolower( $u->user_email ) ] = $u->ID;
		}
		$first = $wpdb->get_var( "SELECT MIN(created_at) FROM {$t}" ); // phpcs:ignore -- import only what happened before live tracking began.
		$until = $first ? $first : current_time( 'mysql', true );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID AS oid, comment_author_email AS email, comment_date_gmt AS t, comment_content AS c
				 FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND comment_date_gmt >= %s AND comment_date_gmt < %s
				 AND comment_author_email <> '' ORDER BY comment_ID ASC",
				$since,
				$until
			)
		);
		$labels = array_flip( array_map( 'strtolower', wc_get_order_statuses() ) );
		$n      = 0;
		foreach ( $rows as $r ) {
			$uid = isset( $staff[ strtolower( $r->email ) ] ) ? $staff[ strtolower( $r->email ) ] : 0;
			if ( ! $uid ) {
				continue;
			}
			$text = wp_strip_all_tags( $r->c );
			if ( 0 === strpos( $text, 'Steadfast booked' ) ) {
				self::log( $r->oid, 'courier', $text, 'courier_sent', $uid, $r->t );
			} elseif ( 0 === strpos( $text, 'Order edited in Product Glide' ) ) {
				self::log( $r->oid, 'edited', $text, '', $uid, $r->t );
			} elseif ( 0 === strpos( $text, 'Created from Product Glide' ) ) {
				self::log( $r->oid, 'created', 'Product Glide', '', $uid, $r->t );
			} elseif ( preg_match( '/Order status changed from (.+?) to (.+?)\.\s*$/u', $text, $m ) ) {
				$from = isset( $labels[ strtolower( $m[1] ) ] ) ? substr( $labels[ strtolower( $m[1] ) ], 3 ) : '';
				$to   = isset( $labels[ strtolower( $m[2] ) ] ) ? substr( $labels[ strtolower( $m[2] ) ], 3 ) : '';
				self::log( $r->oid, self::status_action( $from, $to ), $m[1] . ' → ' . $m[2], $to, $uid, $r->t );
			} else {
				self::log( $r->oid, 'note', $text, '', $uid, $r->t );
			}
			$n++;
		}
		return $n;
	}

	/* ------------------------------------------------------------------ */
	/* Reports                                                             */
	/* ------------------------------------------------------------------ */

	/** BD-date range from the request → [from, to, utc_start, utc_end). */
	private static function range() {
		$today = PG_Helpers::bd_time( time(), 'Y-m-d' );
		$from  = PG_Helpers::post( 'from' );
		$to    = PG_Helpers::post( 'to' );
		$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : $today;
		$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : $from;
		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}
		list( $a, $b ) = PG_Helpers::bd_range_bounds_utc( $from, $to );
		return array( $from, $to, $a, $b );
	}

	private static function bd( $gmt, $format ) {
		return PG_Helpers::bd_time( $gmt, $format );
	}

	private static function people() {
		$out = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator', PG_App::ROLE, 'shop_manager' ), 'orderby' => 'display_name' ) ) as $u ) {
			$out[ $u->ID ] = array(
				'id'    => $u->ID,
				'name'  => $u->display_name,
				'role'  => in_array( PG_App::ROLE, (array) $u->roles, true ) ? 'Order Manager' : ( in_array( 'administrator', (array) $u->roles, true ) ? 'Admin' : 'Shop Manager' ),
				'staff' => in_array( PG_App::ROLE, (array) $u->roles, true ),
			);
		}
		return $out;
	}

	public static function ajax_report() {
		PG_Helpers::guard_ajax( true );
		global $wpdb;
		$t = self::table();
		list( $from, $to, $ga, $gb ) = self::range();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, order_id, action, created_at FROM {$t} WHERE created_at >= %s AND created_at < %s", $ga, $gb ) ); // phpcs:ignore
		$people = self::people();
		$blank  = array_fill_keys( array_keys( self::LABELS ), 0 );
		$stats  = array();
		$days   = array();
		$sent   = array();

		foreach ( $rows as $r ) {
			$u = (int) $r->user_id;
			if ( ! isset( $stats[ $u ] ) ) {
				$stats[ $u ] = array( 'orders' => array(), 'acts' => $blank, 'actions' => 0, 'last' => '', 'days' => array() );
			}
			$s                      = &$stats[ $u ];
			$s['orders'][ $r->order_id ] = true;
			$s['acts'][ $r->action ]++;
			$s['actions']++;
			$s['last'] = max( $s['last'], $r->created_at );
			$d         = self::bd( $r->created_at, 'Y-m-d' );
			if ( ! isset( $s['days'][ $d ] ) ) {
				$s['days'][ $d ] = array( 'orders' => array(), 'courier' => 0, 'created' => 0, 'confirmed' => 0, 'cancelled' => 0 );
			}
			$s['days'][ $d ]['orders'][ $r->order_id ] = true;
			if ( isset( $s['days'][ $d ][ $r->action ] ) ) {
				$s['days'][ $d ][ $r->action ]++;
			}
			if ( 'courier' === $r->action ) {
				$sent[ $u ][ $r->order_id ] = true;
			}
			unset( $s );
		}

		// Outcome of the parcels each person sent: current status of those orders.
		$all_sent = array();
		foreach ( $sent as $ids ) {
			$all_sent += $ids;
		}
		$status_of = array();
		if ( $all_sent ) {
			$ids = implode( ',', array_map( 'intval', array_keys( $all_sent ) ) );
			$sql = PG_Helpers::hpos()
				? "SELECT id, status FROM {$wpdb->prefix}wc_orders WHERE id IN ({$ids})"
				: "SELECT ID AS id, post_status AS status FROM {$wpdb->posts} WHERE ID IN ({$ids})";
			foreach ( $wpdb->get_results( $sql ) as $o ) { // phpcs:ignore -- ids are integers.
				$status_of[ (int) $o->id ] = preg_replace( '/^wc-/', '', $o->status );
			}
		}

		$list = array();
		foreach ( $people as $uid => $p ) {
			$s = isset( $stats[ $uid ] ) ? $stats[ $uid ] : null;
			if ( ! $s && ! $p['staff'] ) {
				continue; // Admins appear only when they worked in the range.
			}
			$out = array( 'delivered' => 0, 'partial' => 0, 'returned' => 0, 'transit' => 0 );
			if ( ! empty( $sent[ $uid ] ) ) {
				foreach ( array_keys( $sent[ $uid ] ) as $oid ) {
					$st = isset( $status_of[ $oid ] ) ? $status_of[ $oid ] : '';
					if ( 'completed' === $st ) {
						$out['delivered']++;
					} elseif ( 'partial_delivered' === $st ) {
						$out['partial']++;
					} elseif ( in_array( $st, array( 'cancelled', 'refunded', 'failed', 'cancelled_apppend' ), true ) ) {
						$out['returned']++;
					} else {
						$out['transit']++;
					}
				}
			}
			$day_rows = array();
			if ( $s ) {
				ksort( $s['days'] );
				foreach ( $s['days'] as $d => $v ) {
					$day_rows[] = array( 'day' => $d, 'orders' => count( $v['orders'] ), 'courier' => $v['courier'], 'created' => $v['created'], 'confirmed' => $v['confirmed'], 'cancelled' => $v['cancelled'] );
				}
			}
			$list[] = $p + array(
				'orders'  => $s ? count( $s['orders'] ) : 0,
				'actions' => $s ? $s['actions'] : 0,
				'acts'    => $s ? $s['acts'] : $blank,
				'last'    => $s && $s['last'] ? self::bd( $s['last'], 'd M, h:i A' ) : '',
				'outcome' => $out,
				'days'    => $day_rows,
			);
		}
		usort(
			$list,
			function ( $a, $b ) {
				return $b['orders'] - $a['orders'] ?: strcmp( $a['name'], $b['name'] );
			}
		);

		$total_orders = array();
		foreach ( $rows as $r ) {
			$total_orders[ $r->order_id ] = true;
		}
		wp_send_json_success(
			array(
				'from'     => $from,
				'to'       => $to,
				'people'   => $list,
				'labels'   => self::LABELS,
				'totals'   => array(
					'orders'  => count( $total_orders ),
					'courier' => array_sum( wp_list_pluck( wp_list_pluck( $list, 'acts' ), 'courier' ) ),
					'created' => array_sum( wp_list_pluck( wp_list_pluck( $list, 'acts' ), 'created' ) ),
					'active'  => count( array_filter( $list, function ( $p ) { return $p['actions'] > 0; } ) ),
				),
				'since'    => self::tracking_since(),
			)
		);
	}

	private static function tracking_since() {
		global $wpdb;
		$t     = self::table();
		$first = $wpdb->get_var( "SELECT MIN(created_at) FROM {$t}" ); // phpcs:ignore
		return $first ? self::bd( $first, 'd M Y' ) : '';
	}

	/** Every action one person did in the range, with the order's current state. */
	public static function ajax_activity() {
		PG_Helpers::guard_ajax( true );
		global $wpdb;
		$t   = self::table();
		$uid = PG_Helpers::post_int( 'user_id' );
		list( , , $ga, $gb ) = self::range();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, action, detail, status_to, created_at FROM {$t} WHERE user_id = %d AND created_at >= %s AND created_at < %s ORDER BY created_at DESC, id DESC LIMIT 1000", $uid, $ga, $gb ) ); // phpcs:ignore

		$orders   = array();
		$statuses = wc_get_order_statuses();
		foreach ( array_unique( wp_list_pluck( $rows, 'order_id' ) ) as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o ) {
				$orders[ $oid ] = array( 'id' => (int) $oid, 'number' => (string) $oid, 'missing' => true );
				continue;
			}
			$orders[ $oid ] = array(
				'id'           => $o->get_id(),
				'number'       => $o->get_order_number(),
				'name'         => PG_Helpers::order_name( $o ),
				'phone'        => $o->get_billing_phone(),
				'total'        => (float) $o->get_total(),
				'status'       => $o->get_status(),
				'status_label' => isset( $statuses[ 'wc-' . $o->get_status() ] ) ? $statuses[ 'wc-' . $o->get_status() ] : $o->get_status(),
				'date'         => $o->get_date_created() ? PG_Helpers::bd_time( $o->get_date_created(), 'd M' ) : '',
			);
		}
		$acts = array();
		foreach ( $rows as $r ) {
			$acts[] = array(
				'order_id' => (int) $r->order_id,
				'action'   => $r->action,
				'detail'   => $r->detail,
				'day'      => self::bd( $r->created_at, 'Y-m-d' ),
				'time'     => self::bd( $r->created_at, 'h:i A' ),
			);
		}
		wp_send_json_success( array( 'activity' => $acts, 'orders' => $orders ) );
	}
}
