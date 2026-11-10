<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order confirmation without a phone call when possible.
 *
 *  - A WhatsApp reply like "জি / ok / কনফার্ম" (or the template's Confirm button) marks the
 *    order Confirmed. "বাতিল / cancel / লাগবে না" flags a cancel request. Anything else is
 *    "replied — read the chat".
 *  - The Call list tab shows only the orders someone actually needs to call, with the reason:
 *    no WhatsApp, no reply after N hours, cancel request, low delivery score, high value.
 *  - Staff can mark an order Confirmed after a call; it is logged in the Team report.
 */
class PG_Confirm {

	const HOW     = '_pg_confirmed';     // wa | call
	const AT      = '_pg_confirmed_at';
	const BY      = '_pg_confirmed_by';
	const TEXT    = '_pg_confirm_text';
	const CANCEL  = '_pg_cancel_req';
	const REPLIED = '_pg_wa_replied';
	const TAB     = 'call';
	const OPEN    = array( 'processing', 'on-hold', 'pending' );

	public static function init() {
		add_action( 'wp_ajax_pg_confirm_set', array( __CLASS__, 'ajax_set' ) );
		add_action( 'pg_wa_incoming', array( __CLASS__, 'on_wa' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		delete_transient( 'pg_call_n' );
	}

	/* ------------------------------------------------------------------ */
	/* Reading WhatsApp replies                                            */
	/* ------------------------------------------------------------------ */

	/** yes | no | other */
	public static function classify( $text ) {
		$t = trim( mb_strtolower( (string) $text ) );
		if ( '' === $t ) {
			return 'other';
		}
		$no = '/(বাতিল|ক্যান্সেল|cancel|লাগবে\s*না|লাগবেনা|lagbe\s*na|দরকার\s*নেই|দরকার\s*নাই|dorkar\s*(?:nai|nei)|নিব\s*না|নিবো\s*না|নেব\s*না|নেবো\s*না|nibo\s*na|nebo\s*na|চাই\s*না|chai\s*na|অর্ডার\s*করিনি|order\s*kori\s*ni|ভুল\s*করে|❌|^না$|^na$|^no$|^nah$)/u';
		if ( preg_match( $no, $t ) ) {
			return 'no';
		}
		$yes = '/^(?:হ্যাঁ|হ্যা|হা|হাঁ|জি|জ্বি|জী|জ্বী|ji|jee|jii|ha|haa|hae|hmm+|হুম+|ok|okay|okk+|oky|ওকে|ঠিক\s*আছে|thik\s*a(?:ch|s)e|yes|yeah|ya|yep|confirm(?:ed)?|কনফার্ম|done|👍|✅|নিবো|নিব|নেবো|nibo|nebo|পাঠান|পাঠিয়ে\s*দিন|দিয়ে\s*দিন|pathan|pathiye\s*din|diye\s*din|লাগবে|lagbe|অবশ্যই|sure)/u';
		$short = mb_strlen( $t ) <= 60 && false === strpos( $t, '?' ) && false === strpos( $t, '؟' );
		if ( preg_match( $yes, $t ) && $short ) {
			return 'yes';
		}
		if ( $short && preg_match( '/(কনফার্ম|confirm|👍|✅)/u', $t ) ) {
			return 'yes';
		}
		return 'other';
	}

	/** Open orders of this phone that still wait for confirmation (last 5 days, not at the courier). */
	private static function waiting_orders( $key ) {
		$k   = PG_Helpers::phone_key( $key );
		$map = PG_Helpers::orders_by_phone_keys( array( $k ) );
		$out = array();
		foreach ( array_slice( isset( $map[ $k ] ) ? $map[ $k ] : array(), 0, 5 ) as $r ) {
			$o = wc_get_order( $r['id'] );
			if ( ! $o || ! in_array( $o->get_status(), self::OPEN, true ) ) {
				continue;
			}
			if ( $o->get_date_created() && $o->get_date_created()->getTimestamp() < time() - 5 * DAY_IN_SECONDS ) {
				continue;
			}
			if ( PG_Steadfast::active( $o->get_id() ) ) {
				continue;
			}
			$out[] = $o;
		}
		return $out;
	}

	public static function on_wa( $key, $type, $text ) {
		if ( ! in_array( $type, array( 'text', 'button', 'interactive' ), true ) && '' === trim( (string) $text ) ) {
			$kind = 'other';
		} else {
			$kind = self::classify( $text );
		}
		foreach ( self::waiting_orders( $key ) as $o ) {
			$short = mb_substr( trim( (string) $text ), 0, 120 );
			if ( 'yes' === $kind ) {
				if ( ! $o->get_meta( self::HOW ) ) {
					self::confirm( $o, 'wa', $short, 0 );
				}
			} elseif ( 'no' === $kind ) {
				$o->update_meta_data( self::CANCEL, $short );
				$o->save_meta_data();
				$o->add_order_note( 'Customer asked to cancel on WhatsApp: "' . $short . '" — call to check before cancelling.' );
			} else {
				$o->update_meta_data( self::REPLIED, time() );
				$o->save_meta_data();
			}
		}
		self::flush();
	}

	public static function confirm( WC_Order $o, $how, $text = '', $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$o->update_meta_data( self::HOW, $how );
		$o->update_meta_data( self::AT, time() );
		$o->update_meta_data( self::BY, $user_id );
		$o->update_meta_data( self::TEXT, $text );
		$o->delete_meta_data( self::CANCEL );
		$o->save_meta_data();
		if ( 'wa' === $how ) {
			$o->add_order_note( 'Customer confirmed on WhatsApp' . ( '' !== $text ? ': "' . $text . '"' : '' ) );
		} else {
			$u = $user_id ? get_userdata( $user_id ) : null;
			$o->add_order_note( 'Confirmed by phone call' . ( $u ? ' — ' . $u->display_name : '' ) );
			PG_Team::log( $o->get_id(), 'confirmed', 'Phone call' );
		}
		self::flush();
	}

	public static function unconfirm( WC_Order $o ) {
		foreach ( array( self::HOW, self::AT, self::BY, self::TEXT, self::CANCEL ) as $k ) {
			$o->delete_meta_data( $k );
		}
		$o->save_meta_data();
		self::flush();
	}

	/* ------------------------------------------------------------------ */
	/* Order card + Call list                                              */
	/* ------------------------------------------------------------------ */

	public static function card( WC_Order $o ) {
		$how    = (string) $o->get_meta( self::HOW );
		$cancel = (string) $o->get_meta( self::CANCEL );
		$rep    = (int) $o->get_meta( self::REPLIED );
		if ( '' === $how && '' === $cancel && ! $rep ) {
			return null;
		}
		$by = (int) $o->get_meta( self::BY );
		$u  = $by ? get_userdata( $by ) : null;
		$at = (int) $o->get_meta( self::AT );
		return array(
			'how'     => $how,
			'when'    => $at ? PG_Helpers::bd_time( $at, 'd M, h:i A' ) : '',
			'by'      => $u ? $u->display_name : '',
			'text'    => (string) $o->get_meta( self::TEXT ),
			'cancel'  => $cancel,
			'replied' => $rep ? PG_Helpers::bd_time( $rep, 'h:i A' ) : '',
		);
	}

	private static function setting( $k, $def ) {
		$v = PG_Settings::get( $k, $def );
		return is_numeric( $v ) ? (float) $v : $def;
	}

	/**
	 * Why this order should be called (empty = no call needed right now).
	 * Each reason: array( code, label, level ). $fraud: the cached score row if known.
	 */
	public static function reasons( WC_Order $o, $fraud = false ) {
		if ( ! in_array( $o->get_status(), self::OPEN, true ) || PG_Steadfast::active( $o->get_id() ) ) {
			return array();
		}
		$out    = array();
		$how    = (string) $o->get_meta( self::HOW );
		$cancel = (string) $o->get_meta( self::CANCEL );
		$age_h  = $o->get_date_created() ? ( time() - $o->get_date_created()->getTimestamp() ) / HOUR_IN_SECONDS : 99;
		$wait_h = self::setting( 'call_after_hours', 2 );
		$wa_on  = PG_WhatsApp::configured();
		$state  = (string) $o->get_meta( '_pg_wa_state' );

		if ( '' !== $cancel ) {
			$out[] = array( 'cancel', 'Asked to cancel on WhatsApp: "' . $cancel . '"', 'bad' );
		} elseif ( '' === $how ) {
			if ( ! $wa_on ) {
				$out[] = array( 'new', 'Not confirmed yet', 'info' );
			} elseif ( 'none' === $state ) {
				$out[] = array( 'nowa', 'No WhatsApp on this number', 'bad' );
			} elseif ( 'failed' === $state ) {
				$out[] = array( 'wafail', 'WhatsApp message failed', 'bad' );
			} elseif ( $o->get_meta( self::REPLIED ) ) {
				$out[] = array( 'replied', 'Replied on WhatsApp — read the chat first', 'info' );
			} elseif ( $age_h >= $wait_h ) {
				$label = 'read' === $state ? 'Read on WhatsApp but no reply' : ( '' === $state ? 'Not confirmed yet' : 'No WhatsApp reply' );
				$out[] = array( 'noreply', $label . ' (' . self::ago( $age_h ) . ')', 'warn' );
			}
		}
		// Risky orders stay on the list even after a WhatsApp "yes".
		if ( '' === $cancel && 'call' !== $how ) {
			if ( false === $fraud ) {
				$p     = PG_Helpers::bd_phone( $o->get_billing_phone() );
				$f     = $p ? PG_Fraud::cached_many( array( $p ) ) : array();
				$fraud = $p && isset( $f[ $p ] ) ? $f[ $p ] : null;
			}
			$min = self::setting( 'call_low_score', 50 );
			if ( $min > 0 && is_array( $fraud ) && null !== $fraud['delivery_ratio'] && ( ! empty( $fraud['parcels'] ) || ! in_array( (string) $fraud['volume_range'], array( '', '0' ), true ) ) && $fraud['delivery_ratio'] < $min ) {
				$out[] = array( 'score', 'Low delivery score (' . (int) $fraud['delivery_ratio'] . '%)', 'bad' );
			}
			$hv = self::setting( 'call_high_value', 0 );
			if ( $hv > 0 && (float) $o->get_total() >= $hv ) {
				$out[] = array( 'value', 'High-value order', 'warn' );
			}
		}
		return $out;
	}

	private static function ago( $h ) {
		if ( $h < 1 ) {
			return max( 1, (int) round( $h * 60 ) ) . ' min';
		}
		if ( $h < 24 ) {
			return (int) floor( $h ) . ' h';
		}
		return (int) floor( $h / 24 ) . ' d';
	}

	/** Orders on the Call list, most urgent first. */
	public static function ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$cand = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::OPEN,
				'limit'        => 400,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '>' . ( time() - 14 * DAY_IN_SECONDS ),
				'return'       => 'ids',
			)
		);
		$rank = array( 'cancel' => 0, 'nowa' => 1, 'wafail' => 1, 'score' => 2, 'noreply' => 3, 'new' => 3, 'value' => 4, 'replied' => 5 );
		$rows = array();
		foreach ( $cand as $i => $id ) {
			$o = wc_get_order( $id );
			$r = $o ? self::reasons( $o ) : array();
			if ( $r ) {
				$best   = min( array_map( function ( $x ) use ( $rank ) { return isset( $rank[ $x[0] ] ) ? $rank[ $x[0] ] : 9; }, $r ) );
				$rows[] = array( $id, $best, $i );
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $a[1] === $b[1] ? $a[2] - $b[2] : $a[1] - $b[1];
			}
		);
		$ids = array_map(
			function ( $r ) {
				return (int) $r[0];
			},
			$rows
		);
		set_transient( 'pg_call_n', count( $ids ), 120 );
		return $ids;
	}

	public static function count() {
		$n = get_transient( 'pg_call_n' );
		return false === $n ? count( self::ids() ) : (int) $n;
	}

	/* ------------------------------------------------------------------ */

	public static function ajax_set() {
		PG_Helpers::guard_ajax();
		$o = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		if ( ! $o ) {
			wp_send_json_error( array( 'message' => __( 'Order not found', 'product-glide' ) ) );
		}
		if ( PG_Helpers::post_int( 'undo' ) ) {
			self::unconfirm( $o );
			$msg = __( 'Confirmation removed.', 'product-glide' );
		} else {
			self::confirm( $o, 'call' );
			$msg = __( 'Marked as confirmed.', 'product-glide' );
		}
		$o = wc_get_order( $o->get_id() );
		wp_send_json_success( array( 'message' => $msg, 'order' => PG_Orders::order_card_data( $o ) ) );
	}
}
