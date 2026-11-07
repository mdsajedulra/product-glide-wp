<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reschedule an order to a later date ("send it in 2 days").
 *
 * The order moves to the "Rescheduled" status with a follow-up time. When that
 * time comes, every open dashboard shows a reminder popup (call / ship / snooze),
 * the Orders menu shows a badge and other admin pages show a notice.
 * Moving the order out of "Rescheduled" (any status, or booking a courier) ends it.
 */
class PG_Reschedule {

	const STATUS = 'rescheduled';
	const AT     = '_pg_rs_at';     // follow-up time, UTC timestamp
	const NOTE   = '_pg_rs_note';
	const FROM   = '_pg_rs_from';   // status before rescheduling
	const SNOOZE = '_pg_rs_snooze'; // reminder hidden until (UTC timestamp)
	const COUNT  = '_pg_rs_count';

	public static function init() {
		foreach ( array( 'set', 'due', 'snooze', 'done' ) as $a ) {
			add_action( 'wp_ajax_pg_rs_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 10, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function reasons() {
		return apply_filters(
			'pg_reschedule_reasons',
			array(
				'Customer is out of town',
				'Cannot receive right now',
				'Will decide later',
				'Will pay after salary',
				'Asked to call again',
			)
		);
	}

	/* ------------------------------------------------------------------ */

	/** Follow-up timestamp of a rescheduled order (0 when none). */
	public static function at( WC_Order $order ) {
		return (int) $order->get_meta( self::AT );
	}

	/** "today", "tomorrow", "in 3 days", "1 day overdue" — by BD calendar days. */
	public static function relative( $ts ) {
		$tz    = PG_Helpers::tz();
		$today = new DateTime( 'today', $tz );
		$day   = new DateTime( '@' . $ts );
		$day->setTimezone( $tz );
		$day->setTime( 0, 0, 0 );
		$diff = (int) round( ( $day->getTimestamp() - $today->getTimestamp() ) / DAY_IN_SECONDS );
		if ( 0 === $diff ) {
			return __( 'today', 'product-glide' );
		}
		if ( 1 === $diff ) {
			return __( 'tomorrow', 'product-glide' );
		}
		if ( $diff > 1 ) {
			/* translators: days */
			return sprintf( __( 'in %d days', 'product-glide' ), $diff );
		}
		/* translators: days */
		return sprintf( _n( '%d day overdue', '%d days overdue', -$diff, 'product-glide' ), -$diff );
	}

	/** Data for the order card (null when the order is not rescheduled). */
	public static function card( WC_Order $order ) {
		if ( self::STATUS !== $order->get_status() ) {
			return null;
		}
		$ts = self::at( $order );
		return array(
			'ts'       => $ts,
			'label'    => $ts ? PG_Helpers::bd_time( $ts, 'D, d M Y · h:i A' ) : '',
			'date'     => $ts ? PG_Helpers::bd_time( $ts, 'Y-m-d' ) : '',
			'time'     => $ts ? PG_Helpers::bd_time( $ts, 'H:i' ) : '',
			'relative' => $ts ? self::relative( $ts ) : '',
			'due'      => ! $ts || $ts <= time(),
			'note'     => (string) $order->get_meta( self::NOTE ),
			'count'    => (int) $order->get_meta( self::COUNT ),
		);
	}

	/** IDs of rescheduled orders, soonest first. $due_only: time has come. */
	public static function ids( $due_only = false, $skip_snoozed = false ) {
		$ids = wc_get_orders(
			array(
				'type'   => 'shop_order',
				'status' => array( self::STATUS ),
				'limit'  => 500,
				'return' => 'ids',
			)
		);
		$now  = time();
		$rows = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$at = self::at( $o );
			if ( $due_only && $at > $now ) {
				continue;
			}
			if ( $skip_snoozed && (int) $o->get_meta( self::SNOOZE ) > $now ) {
				continue;
			}
			$rows[ $id ] = $at;
		}
		asort( $rows );
		return array_keys( $rows );
	}

	public static function due_count() {
		$n = get_transient( 'pg_rs_due_n' );
		if ( false === $n ) {
			$n = count( self::ids( true ) );
			set_transient( 'pg_rs_due_n', $n, MINUTE_IN_SECONDS );
		}
		return (int) $n;
	}

	/**
	 * Put an order on a follow-up date. $ts = UTC timestamp.
	 */
	public static function schedule( WC_Order $order, $ts, $note = '' ) {
		$from = $order->get_status();
		$order->update_meta_data( self::AT, (int) $ts );
		$order->update_meta_data( self::NOTE, $note );
		$order->update_meta_data( self::COUNT, (int) $order->get_meta( self::COUNT ) + 1 );
		$order->delete_meta_data( self::SNOOZE );
		if ( self::STATUS !== $from ) {
			$order->update_meta_data( self::FROM, $from );
		}
		$text = sprintf( /* translators: date */ __( 'Rescheduled to %s.', 'product-glide' ), PG_Helpers::bd_time( $ts, 'D, d M Y, h:i A' ) ) . ( '' !== $note ? ' ' . __( 'Reason:', 'product-glide' ) . ' ' . $note : '' );
		delete_transient( 'pg_rs_due_n' );
		if ( self::STATUS !== $from ) {
			$order->set_status( self::STATUS, $text, true );
			$order->save();
		} else {
			$order->save();
			$order->add_order_note( $text, 0, true );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                               */
	/* ------------------------------------------------------------------ */

	public static function on_status( $order_id, $from, $to, $order = null ) {
		if ( self::STATUS !== $from || self::STATUS === $to ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->delete_meta_data( self::AT );
		$order->delete_meta_data( self::SNOOZE );
		$order->save_meta_data();
		delete_transient( 'pg_rs_due_n' );
	}

	/** Gentle poke on wp-admin pages that are not Product Glide screens. */
	public static function notice() {
		if ( ! PG_App::can() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( in_array( $page, PG_Admin::PAGES, true ) ) {
			return;
		}
		$n = self::due_count();
		if ( ! $n ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><b>%s</b> %s <a class="button button-small" href="%s">%s</a></p></div>',
			esc_html__( 'Product Glide:', 'product-glide' ),
			/* translators: count */
			esc_html( sprintf( _n( '%d rescheduled order is due for follow-up.', '%d rescheduled orders are due for follow-up.', $n, 'product-glide' ), $n ) ),
			esc_url( admin_url( 'admin.php?page=product-glide&tab=rescheduled' ) ),
			esc_html__( 'Open', 'product-glide' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	public static function ajax_set() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		$date = sanitize_text_field( PG_Helpers::post( 'date' ) );
		$time = sanitize_text_field( PG_Helpers::post( 'time', '10:00' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Pick a date.', 'product-glide' ) ) );
		}
		if ( ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = '10:00';
		}
		try {
			$dt = new DateTime( $date . ' ' . $time . ':00', PG_Helpers::tz() );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date.', 'product-glide' ) ) );
		}
		$ts = $dt->getTimestamp();
		if ( $ts < time() - 5 * MINUTE_IN_SECONDS ) {
			wp_send_json_error( array( 'message' => __( 'That time has already passed. Pick a later date or time.', 'product-glide' ) ) );
		}
		if ( $ts > time() + 120 * DAY_IN_SECONDS ) {
			wp_send_json_error( array( 'message' => __( 'Pick a date within the next 120 days.', 'product-glide' ) ) );
		}
		$active = PG_Steadfast::active( $order->get_id() );
		if ( $active && ! in_array( $active->courier_status, array( 'cancelled', 'voided' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This order is already with the courier. Cancel / void the parcel first.', 'product-glide' ) ) );
		}
		self::schedule( $order, $ts, mb_substr( sanitize_text_field( PG_Helpers::post( 'note' ) ), 0, 200 ) );
		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: date */ __( 'Rescheduled to %s. You will get a reminder then.', 'product-glide' ), PG_Helpers::bd_time( $ts, 'd M, h:i A' ) ),
				'order'   => PG_Orders::order_card_data( wc_get_order( $order->get_id() ) ),
			)
		);
	}

	/** Reminders that are due now (not snoozed), plus the total due for badges. */
	public static function ajax_due() {
		PG_Helpers::guard_ajax();
		$list = array();
		foreach ( array_slice( self::ids( true, true ), 0, 30 ) as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$c     = self::card( $o );
			$names = array();
			foreach ( $o->get_items() as $it ) {
				$names[] = $it->get_name() . ' ×' . $it->get_quantity();
			}
			$list[] = array(
				'id'       => $o->get_id(),
				'number'   => $o->get_order_number(),
				'name'     => PG_Helpers::order_name( $o ),
				'phone'    => PG_Helpers::bd_phone( $o->get_billing_phone() ) ? PG_Helpers::bd_phone( $o->get_billing_phone() ) : $o->get_billing_phone(),
				'total'    => (float) $o->get_total(),
				'items'    => implode( ', ', $names ),
				'label'    => $c['label'],
				'date'     => $c['date'],
				'time'     => $c['time'],
				'relative' => $c['relative'],
				'note'     => $c['note'],
				'count'    => $c['count'],
			);
		}
		wp_send_json_success(
			array(
				'due'   => $list,
				'total' => self::due_count(),
			)
		);
	}

	public static function ajax_snooze() {
		PG_Helpers::guard_ajax();
		$order = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $order || self::STATUS !== $order->get_status() ) {
			wp_send_json_error( array( 'message' => __( 'This order is no longer rescheduled.', 'product-glide' ) ) );
		}
		$min = max( 5, min( 24 * 60, PG_Helpers::post_int( 'minutes', 60 ) ) );
		$order->update_meta_data( self::SNOOZE, time() + $min * MINUTE_IN_SECONDS );
		$order->save_meta_data();
		wp_send_json_success( array( 'message' => sprintf( /* translators: minutes */ __( 'Reminder snoozed for %d min.', 'product-glide' ), $min ) ) );
	}

	/** Follow-up done: move the order on (default back to Processing, ready to ship). */
	public static function ajax_done() {
		PG_Helpers::guard_ajax();
		$order  = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		$status = sanitize_key( PG_Helpers::post( 'status', 'processing' ) );
		if ( ! $order || ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) || self::STATUS === $status ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order or status.', 'product-glide' ) ) );
		}
		$order->update_status( $status, __( 'Follow-up done after reschedule.', 'product-glide' ), true );
		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: status */ __( 'Moved to %s.', 'product-glide' ), PG_Helpers::status_label( $status ) ),
				'order'   => PG_Orders::order_card_data( wc_get_order( $order->get_id() ) ),
			)
		);
	}
}
