<?php
defined( 'ABSPATH' ) || exit;

/**
 * Daily automatic Steadfast rider pickup request.
 *
 * At the chosen time (Bangladesh time) on the chosen days it counts the
 * Steadfast parcels booked since the last pickup and, if there are enough,
 * sends one pickup request. One request per day at most; every run is logged.
 */
class PG_SF_Pickup {

	const HOOK     = 'pg_sf_pickup_cron';
	const LOG      = 'pg_sf_pickup_log';
	const LATE_MAX = 2 * HOUR_IN_SECONDS; // WP-Cron can run late on quiet sites; skip if this late.

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'wp_ajax_pg_sf_pickup_now', array( __CLASS__, 'ajax_now' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
	}

	public static function enabled() {
		return (bool) PG_Settings::get( 'sf_pickup_auto', 0 ) && PG_Courier::enabled( 'steadfast' );
	}

	/** Allowed weekdays, 0 = Sunday … 6 = Saturday. */
	public static function days() {
		$d = PG_Settings::get( 'sf_pickup_days', array( 0, 1, 2, 3, 4, 5, 6 ) );
		return array_map( 'intval', is_array( $d ) ? $d : array() );
	}

	/** "15:00" → array( 15, 0 ). */
	public static function time_parts() {
		$t = (string) PG_Settings::get( 'sf_pickup_time', '15:00' );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $t, $m ) ) {
			return array( 15, 0 );
		}
		return array( min( 23, (int) $m[1] ), min( 59, (int) $m[2] ) );
	}

	/** Timestamp of today's (BD) pickup time. */
	public static function today_slot( $now = null ) {
		$tz = PG_Helpers::tz();
		$dt = new DateTime( '@' . ( null === $now ? time() : $now ) );
		$dt->setTimezone( $tz );
		list( $h, $i ) = self::time_parts();
		$dt->setTime( $h, $i, 0 );
		return $dt->getTimestamp();
	}

	/** Next allowed pickup time from now. */
	public static function next_run() {
		$days = self::days();
		if ( ! $days ) {
			return 0;
		}
		$tz   = PG_Helpers::tz();
		$slot = self::today_slot();
		for ( $n = 0; $n < 8; $n++ ) {
			$ts = $slot + $n * DAY_IN_SECONDS;
			$dt = new DateTime( '@' . $ts );
			$dt->setTimezone( $tz );
			list( $h, $i ) = self::time_parts();
			$dt->setTime( $h, $i, 0 ); // keeps the wall-clock time exact
			$ts = $dt->getTimestamp();
			if ( $ts > time() && in_array( (int) $dt->format( 'w' ), $days, true ) ) {
				return $ts;
			}
		}
		return 0;
	}

	/** Keep exactly one event queued at the next pickup time (or none when off). */
	public static function ensure_schedule( $force = false ) {
		$queued = wp_next_scheduled( self::HOOK );
		if ( ! self::enabled() ) {
			if ( $queued ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		$next = self::next_run();
		if ( $queued && ! $force && abs( $queued - $next ) < 60 ) {
			return;
		}
		if ( $queued && ! $force && $queued > time() - self::LATE_MAX && $queued < $next ) {
			return; // an earlier, still valid slot is waiting
		}
		wp_clear_scheduled_hook( self::HOOK );
		if ( $next ) {
			wp_schedule_single_event( $next, self::HOOK );
		}
	}

	/** Steadfast parcels booked since the last successful pickup (max 2 days back). */
	public static function parcel_count() {
		global $wpdb;
		$last  = (int) get_option( 'pg_sf_pickup_last_ok', 0 );
		$since = max( $last, time() - 2 * DAY_IN_SECONDS );
		$t     = PG_Install::table( 'consignments' );
		return (int) $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE provider = 'steadfast' AND is_active = 1 AND consignment_id <> '' AND courier_status NOT IN ('cancelled','voided') AND created_at >= %s", // phpcs:ignore
				gmdate( 'Y-m-d H:i:s', $since )
			)
		);
	}

	public static function request_body( $qty ) {
		return array(
			'address_id'        => (int) PG_Settings::get( 'sf_pickup_address_id', 0 ),
			'police_station_id' => (int) PG_Settings::get( 'sf_pickup_ps_id', 0 ),
			'address'           => (string) PG_Settings::get( 'sf_pickup_address', '' ),
			'contact_number'    => (string) PG_Settings::get( 'sf_pickup_phone', PG_Settings::get( 'hotline', '' ) ),
			'note'              => (string) PG_Settings::get( 'sf_pickup_note', '' ),
			'estim_qty'         => max( 1, (int) $qty ),
		);
	}

	public static function cron() {
		$res = self::run( false );
		self::ensure_schedule( true );
		return $res;
	}

	/**
	 * $manual: true from the "Send pickup now" button (skips the day/time/once-a-day checks).
	 * Returns array( 'ok' => bool, 'status' => sent|skipped|failed, 'message' => '' ).
	 */
	public static function run( $manual ) {
		$today = PG_Helpers::bd_time( time(), 'Y-m-d' );
		if ( ! $manual ) {
			if ( ! self::enabled() ) {
				return self::result( 'skipped', __( 'Auto pickup is off.', 'product-glide' ), 0, false );
			}
			if ( get_option( 'pg_sf_pickup_last_day' ) === $today ) {
				return self::result( 'skipped', __( 'A pickup was already requested today.', 'product-glide' ), 0, false );
			}
			$slot = self::today_slot();
			if ( time() < $slot - 120 ) {
				return self::result( 'skipped', __( 'Too early.', 'product-glide' ), 0, false );
			}
			if ( time() > $slot + self::LATE_MAX ) {
				return self::result( 'skipped', sprintf( /* translators: time */ __( 'Missed the %s slot (the site had no visits to run the scheduler). Nothing sent.', 'product-glide' ), PG_Settings::get( 'sf_pickup_time', '15:00' ) ) );
			}
			$dow = (int) PG_Helpers::bd_time( time(), 'w' );
			if ( ! in_array( $dow, self::days(), true ) ) {
				return self::result( 'skipped', __( 'Pickup is not set for this day.', 'product-glide' ), 0, false );
			}
		}
		if ( ! PG_Courier::ready( 'steadfast' ) ) {
			return self::result( 'failed', __( 'Steadfast is off or its API keys are not set.', 'product-glide' ) );
		}
		$count = self::parcel_count();
		$min   = (int) PG_Settings::get( 'sf_pickup_min', 1 );
		if ( ! $manual && $count < $min ) {
			update_option( 'pg_sf_pickup_last_day', $today, false );
			return self::result( 'skipped', sprintf( /* translators: 1 count 2 min */ __( 'Only %1$d new Steadfast parcel(s) since the last pickup (minimum %2$d). No request sent.', 'product-glide' ), $count, $min ), $count );
		}
		$res = PG_Steadfast_API::pickup_request( self::request_body( $count ) );
		if ( is_wp_error( $res ) ) {
			return self::result( 'failed', $res->get_error_message(), $count );
		}
		update_option( 'pg_sf_pickup_last_day', $today, false );
		update_option( 'pg_sf_pickup_last_ok', time(), false );
		$id = '';
		if ( is_array( $res ) ) {
			if ( isset( $res['id'] ) ) {
				$id = $res['id'];
			} elseif ( isset( $res['data']['id'] ) ) {
				$id = $res['data']['id'];
			}
		}
		$msg = sprintf( /* translators: count */ __( 'Pickup request sent for about %d parcel(s).', 'product-glide' ), max( 1, $count ) ) . ( '' !== (string) $id ? ' ID: ' . $id : '' ) . ( $manual ? ' (' . __( 'manual', 'product-glide' ) . ')' : '' );
		return self::result( 'sent', $msg, $count );
	}

	private static function result( $status, $message, $count = 0, $log = true ) {
		$r = array(
			'ok'      => 'sent' === $status,
			'status'  => $status,
			'message' => $message,
			'count'   => (int) $count,
			'time'    => time(),
		);
		if ( $log ) {
			$all = (array) get_option( self::LOG, array() );
			array_unshift( $all, $r );
			update_option( self::LOG, array_slice( $all, 0, 15 ), false );
		}
		return $r;
	}

	public static function log() {
		return (array) get_option( self::LOG, array() );
	}

	public static function ajax_now() {
		PG_Helpers::guard_ajax( true );
		$r = self::run( true );
		if ( $r['ok'] ) {
			wp_send_json_success( array( 'message' => $r['message'] ) );
		}
		wp_send_json_error( array( 'message' => $r['message'] ) );
	}
}
