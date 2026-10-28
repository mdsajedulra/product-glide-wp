<?php
defined( 'ABSPATH' ) || exit;

/**
 * Steadfast fraud / delivery-success score with a DB cache.
 */
class PG_Fraud {

	const PANEL_URL = 'https://steadfast.com.bd/user/frauds/check/';

	public static function init() {
		add_action( 'wp_ajax_pg_fraud_check', array( __CLASS__, 'ajax_check' ) );
	}

	public static function cached( $phone, $ignore_age = false ) {
		global $wpdb;
		$p = PG_Helpers::bd_phone( $phone );
		if ( ! $p ) {
			return null;
		}
		$t   = PG_Install::table( 'fraud_cache' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone = %s", $p ) ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		$age = time() - strtotime( $row->checked_at . ' UTC' );
		if ( ! $ignore_age && $age > HOUR_IN_SECONDS * (int) PG_Settings::get( 'fraud_cache_hours', 24 ) ) {
			return null;
		}
		return self::format( $row );
	}

	/** Cached results for many phones at once: phone => data (no API calls). */
	public static function cached_many( array $phones ) {
		global $wpdb;
		$ps = array_values( array_unique( array_filter( array_map( array( 'PG_Helpers', 'bd_phone' ), $phones ) ) ) );
		if ( ! $ps ) {
			return array();
		}
		$t     = PG_Install::table( 'fraud_cache' );
		$ph    = implode( ',', array_fill( 0, count( $ps ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE phone IN ({$ph})", $ps ) ); // phpcs:ignore
		$out   = array();
		foreach ( $rows as $r ) {
			$out[ $r->phone ] = self::format( $r );
		}
		return $out;
	}

	private static function format( $row ) {
		$payload = json_decode( (string) $row->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		$cats    = isset( $payload['fraud_categories'] ) && is_array( $payload['fraud_categories'] ) ? $payload['fraud_categories'] : array();
		$num     = function ( $k ) use ( $payload ) {
			return isset( $payload[ $k ] ) && is_numeric( $payload[ $k ] ) ? (int) $payload[ $k ] : null;
		};
		$reports = $num( 'fraud_reports' );
		if ( null === $reports ) {
			$reports = (int) $row->total_reports;
		}
		// Individual reports (only some Steadfast endpoints return these).
		$list = array();
		if ( ! empty( $payload['frauds'] ) && is_array( $payload['frauds'] ) ) {
			foreach ( array_slice( $payload['frauds'], 0, 10 ) as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$list[] = array(
					'name'     => isset( $f['name'] ) ? sanitize_text_field( (string) $f['name'] ) : '',
					'details'  => isset( $f['details'] ) ? sanitize_textarea_field( (string) $f['details'] ) : '',
					'category' => ! empty( $f['category_label'] ) ? sanitize_text_field( (string) $f['category_label'] ) : '',
					'date'     => ! empty( $f['created_at'] ) ? PG_Helpers::bd_time( strtotime( $f['created_at'] ), 'd M Y' ) : '',
				);
			}
		}
		$d = array(
			'phone'              => $row->phone,
			'delivery_ratio'     => null === $row->delivery_ratio ? null : (int) $row->delivery_ratio,
			'cancellation_ratio' => null === $row->cancellation_ratio ? null : (int) $row->cancellation_ratio,
			'delivered'          => (int) $num( 'delivered_count' ),
			'cancelled'          => (int) $num( 'cancelled_count' ),
			'total_reports'      => $reports,
			'reports'            => $list,
			'reported_by_you'    => ! empty( $payload['reported_by_you'] ),
			'volume_band'        => $row->volume_band,
			'volume_range'       => isset( $payload['volume_range'] ) ? (string) $payload['volume_range'] : '',
			'categories'         => $cats,
			'checked_at'         => PG_Helpers::bd_time( $row->checked_at ),
			'panel_url'          => self::PANEL_URL . rawurlencode( $row->phone ),
		);
		$d['parcels'] = $d['delivered'] + $d['cancelled'];
		if ( ! $d['parcels'] && null !== $d['delivery_ratio'] && $d['volume_range'] && is_numeric( $d['volume_range'] ) ) {
			$d['parcels'] = (int) $d['volume_range'];
		}
		$d['verdict'] = self::verdict( $d );
		return $d;
	}

	/**
	 * Should this customer get a COD parcel? Returns level (safe|caution|risky|new),
	 * a short title, advice and the reasons behind it — all in Bangla for staff.
	 */
	public static function verdict( array $d ) {
		$r       = $d['delivery_ratio'];
		$total   = (int) $d['parcels'];
		$del     = (int) $d['delivered'];
		$can     = (int) $d['cancelled'];
		$reports = (int) $d['total_reports'];
		$reasons = array();
		$level   = 'safe';
		$rank    = array( 'new' => 0, 'safe' => 1, 'caution' => 2, 'risky' => 3 );
		$raise   = function ( $to ) use ( &$level, $rank ) {
			if ( $rank[ $to ] > $rank[ $level ] ) {
				$level = $to;
			}
		};

		if ( null === $r || ! $total ) {
			$level     = 'new';
			$reasons[] = array( 'info', 'এই নম্বরে আগে কোনো পার্সেল ডেলিভারির রেকর্ড নেই' );
		} else {
			if ( $del || $can ) {
				$reasons[] = array( $r >= 80 ? 'good' : ( $r >= 60 ? 'warn' : 'bad' ), sprintf( '%d টা পার্সেলের মধ্যে %d টা ডেলিভারি হয়েছে (%d%%)', $total, $del, $r ) );
			} else {
				$reasons[] = array( $r >= 80 ? 'good' : ( $r >= 60 ? 'warn' : 'bad' ), sprintf( 'ডেলিভারি সফলতা %d%%', $r ) );
			}
			if ( $can > 0 ) {
				$reasons[] = array( ( $can >= 3 && $r < 70 ) ? 'bad' : 'warn', sprintf( '%d টা পার্সেল বাতিল / ফেরত এসেছে', $can ) );
			}
			if ( $r >= 80 ) {
				$raise( 'safe' );
			} elseif ( $r >= 60 ) {
				$raise( 'caution' );
			} else {
				$raise( 'risky' );
			}
			if ( $can >= 3 && $r < 70 ) {
				$raise( 'risky' );
			}
			if ( $total <= 2 && 'safe' === $level ) {
				$reasons[] = array( 'info', 'রেকর্ড খুব কম, তাই পুরোপুরি নিশ্চিত বলা যাচ্ছে না' );
			}
		}

		if ( $reports > 0 ) {
			$reasons[] = array( 'bad', sprintf( 'অন্য ব্যবসায়ীরা %d বার এই নম্বরকে প্রতারক হিসেবে রিপোর্ট করেছে', $reports ) );
			if ( $reports >= 2 || 'new' === $level || ( null !== $r && $r < 70 ) ) {
				$raise( 'risky' );
				if ( 'new' === $level ) {
					$level = 'risky';
				}
			} else {
				$raise( 'caution' );
			}
		}
		if ( ! empty( $d['reported_by_you'] ) ) {
			$reasons[] = array( 'bad', 'আপনি নিজেই আগে এই নম্বর রিপোর্ট করেছেন' );
			$level     = 'risky';
		}

		$text = array(
			'safe'    => array( 'পার্সেল দেওয়া যাবে', 'নিশ্চিন্তে পাঠাতে পারেন।' ),
			'caution' => array( 'সাবধানে পাঠান', 'পাঠানোর আগে ফোন করে কনফার্ম করুন। সম্ভব হলে ডেলিভারি চার্জ অগ্রিম নিন।' ),
			'risky'   => array( 'ঝুঁকিপূর্ণ — পার্সেল দেবেন না', 'অগ্রিম টাকা (অন্তত ডেলিভারি চার্জ) না নিয়ে পার্সেল পাঠাবেন না।' ),
			'new'     => array( 'নতুন কাস্টমার', 'আগের কোনো রেকর্ড নেই। ফোন করে কনফার্ম করে পাঠান।' ),
		);
		return array(
			'level'   => $level,
			'title'   => $text[ $level ][0],
			'advice'  => $text[ $level ][1],
			'reasons' => $reasons,
		);
	}

	public static function check( $phone, $force = false ) {
		$p = PG_Helpers::bd_phone( $phone );
		if ( ! $p ) {
			return new WP_Error( 'pg_phone', __( 'Not a valid BD mobile number', 'product-glide' ) );
		}
		if ( ! $force ) {
			$c = self::cached( $p );
			if ( $c ) {
				return $c;
			}
		}
		// Short lock so several tabs opening the same order don't all hit the API.
		$lock = 'pg_fraud_lock_' . $p;
		if ( get_transient( $lock ) && ! $force ) {
			$c = self::cached( $p, true );
			if ( $c ) {
				return $c;
			}
		}
		set_transient( $lock, 1, 20 );
		$res = PG_Steadfast_API::fraud_score( $p );
		delete_transient( $lock );
		if ( is_wp_error( $res ) ) {
			$old = self::cached( $p, true );
			return $old ? $old : $res;
		}
		global $wpdb;
		$wpdb->replace(
			PG_Install::table( 'fraud_cache' ),
			array(
				'phone'              => $p,
				'delivery_ratio'     => isset( $res['delivery_ratio'] ) && is_numeric( $res['delivery_ratio'] ) ? (int) $res['delivery_ratio'] : null,
				'cancellation_ratio' => isset( $res['cancellation_ratio'] ) && is_numeric( $res['cancellation_ratio'] ) ? (int) $res['cancellation_ratio'] : null,
				'total_reports'      => isset( $res['fraud_reports'] ) ? (int) $res['fraud_reports'] : ( isset( $res['total_reports'] ) ? (int) $res['total_reports'] : 0 ),
				'volume_band'        => isset( $res['volume_band'] ) ? sanitize_key( $res['volume_band'] ) : '',
				'payload'            => wp_json_encode( $res ),
				'checked_at'         => PG_Helpers::now_utc(),
			)
		);
		return self::cached( $p, true );
	}

	public static function ajax_check() {
		PG_Helpers::guard_ajax();
		$res = self::check( PG_Helpers::post( 'phone' ), (bool) PG_Helpers::post_int( 'force' ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message(), 'panel_url' => self::PANEL_URL . rawurlencode( PG_Helpers::bd_phone( PG_Helpers::post( 'phone' ) ) ) ) );
		}
		wp_send_json_success( array( 'data' => $res ) );
	}
}
