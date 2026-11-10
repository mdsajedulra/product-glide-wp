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
	const RESEND  = '_pg_resend_req';
	const TAB     = 'call';
	const WORK    = array( 'processing', 'on-hold', 'pending' );              // needs someone now
	const OPEN    = array( 'processing', 'on-hold', 'pending', 'confirmed', 'call-later', 'unreachable' ); // not at the courier yet

	public static function init() {
		add_action( 'wp_ajax_pg_confirm_set', array( __CLASS__, 'ajax_set' ) );
		add_action( 'wp_ajax_pg_call_outcome', array( __CLASS__, 'ajax_call' ) );
		add_action( 'pg_wa_incoming', array( __CLASS__, 'on_wa' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush' ) );
		add_action( 'pg_call_cron', array( __CLASS__, 'cron' ) );
		if ( ! wp_next_scheduled( 'pg_call_cron' ) ) {
			wp_schedule_event( time() + 300, 'pg_quarter_hour', 'pg_call_cron' );
		}
	}

	/** Move an order between the work lists without counting it as a staff status change. */
	public static function move( WC_Order $o, $to, $note = '' ) {
		if ( $o->get_status() === $to ) {
			return;
		}
		PG_Team::$quiet = true;
		$o->update_status( $to, $note );
		PG_Team::$quiet = false;
		self::flush();
	}

	/** Every 15 minutes: "Call later" orders whose time has come go back to Processing. */
	public static function cron() {
		$ids = wc_get_orders( array( 'type' => 'shop_order', 'status' => array( 'call-later' ), 'limit' => 200, 'return' => 'ids' ) );
		foreach ( $ids as $id ) {
			$o    = wc_get_order( $id );
			$next = $o ? (int) $o->get_meta( self::NEXT ) : 0;
			if ( $o && $next <= time() ) {
				self::move( $o, 'processing', sprintf( 'Time to call again (call %d).', (int) $o->get_meta( self::TRIES ) + 1 ) );
			}
		}
	}

	public static function flush() {
		delete_transient( 'pg_call_n' );
	}

	/* ------------------------------------------------------------------ */
	/* Reading WhatsApp replies                                            */
	/* ------------------------------------------------------------------ */

	/** yes | send | later | no | other */
	public static function classify( $text ) {
		$t = trim( mb_strtolower( (string) $text ) );
		if ( '' === $t ) {
			return 'other';
		}
		if ( preg_match( '/^(?:এখন\s*পাঠান|আবার\s*পাঠান|ekhon\s*pathan|abar\s*pathan|send\s*(?:now|again))/u', $t ) ) {
			return 'send';
		}
		if ( preg_match( '/^(?:আরও\s*পরে|আরো\s*পরে|পরে\s*(?:পাঠান|দিন|নিব|নেব)|later|pore\s*(?:pathan|din|nibo))/u', $t ) ) {
			return 'later';
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

	/** Orders of this phone in a state where a WhatsApp answer means something (last 7 days). */
	private static function phone_orders( $key ) {
		$k   = PG_Helpers::phone_key( $key );
		$map = PG_Helpers::orders_by_phone_keys( array( $k ) );
		$out = array( 'open' => array(), 'resched' => array(), 'failed' => array() );
		foreach ( array_slice( isset( $map[ $k ] ) ? $map[ $k ] : array(), 0, 6 ) as $r ) {
			$o = wc_get_order( $r['id'] );
			if ( ! $o || ( $o->get_date_created() && $o->get_date_created()->getTimestamp() < time() - 30 * DAY_IN_SECONDS ) ) {
				continue;
			}
			$st = $o->get_status();
			if ( PG_Reschedule::STATUS === $st ) {
				$out['resched'][] = $o;
			} elseif ( 'cancelled' === $st && PG_WhatsApp::courier_failed( $o->get_id() ) && $o->get_meta( '_pg_wa_sent_courier_failed' ) ) {
				$out['failed'][] = $o;
			} elseif ( in_array( $st, self::OPEN, true ) && ! PG_Steadfast::active( $o->get_id() ) && $o->get_date_created() && $o->get_date_created()->getTimestamp() > time() - 7 * DAY_IN_SECONDS ) {
				$out['open'][] = $o;
			}
		}
		return $out;
	}

	public static function on_wa( $key, $type, $text ) {
		$kind  = ( in_array( $type, array( 'text', 'button', 'interactive' ), true ) || '' !== trim( (string) $text ) ) ? self::classify( $text ) : 'other';
		$short = mb_substr( trim( (string) $text ), 0, 120 );
		$all   = self::phone_orders( $key );

		// Rescheduled order: "এখন পাঠান" / "জি" → back to the shipping queue, confirmed.
		foreach ( $all['resched'] as $o ) {
			$asked = (string) $o->get_meta( '_pg_wa_sent_resched_due' );
			if ( '' === $asked || $asked !== (string) $o->get_meta( PG_Reschedule::AT ) ) {
				continue; // only an answer to the "date arrived" message moves it
			}
			if ( in_array( $kind, array( 'send', 'yes' ), true ) ) {
				$from = (string) $o->get_meta( PG_Reschedule::FROM );
				$o->update_status( in_array( $from, self::OPEN, true ) ? $from : 'processing', 'Customer said on WhatsApp to send it now: "' . $short . '"' );
				self::confirm( wc_get_order( $o->get_id() ), 'wa', $short, 0 );
			} elseif ( 'later' === $kind ) {
				$o->add_order_note( 'Customer asked on WhatsApp to wait longer: "' . $short . '" — set a new date.' );
			} elseif ( 'no' === $kind ) {
				$o->update_meta_data( self::CANCEL, $short );
				$o->save_meta_data();
				$o->add_order_note( 'Customer asked to cancel on WhatsApp: "' . $short . '" — call to check before cancelling.' );
			}
		}
		// Courier could not deliver: "আবার পাঠান" → ask the team to book again.
		foreach ( $all['failed'] as $o ) {
			if ( in_array( $kind, array( 'send', 'yes' ), true ) ) {
				$o->update_meta_data( self::RESEND, $short );
				$o->save_meta_data();
				$o->update_status( 'on-hold', 'Customer wants it sent again (WhatsApp): "' . $short . '" — book the courier again when the parcel is back.' );
			} elseif ( 'no' === $kind ) {
				$o->add_order_note( 'Customer does not want it sent again (WhatsApp): "' . $short . '"' );
			}
		}
		foreach ( $all['open'] as $o ) {
			if ( in_array( $kind, array( 'yes', 'send' ), true ) ) {
				if ( ! $o->get_meta( self::HOW ) ) {
					self::confirm( $o, 'wa', $short, 0 );
				}
			} elseif ( 'no' === $kind ) {
				$o->update_meta_data( self::CANCEL, $short );
				$o->save_meta_data();
				$o->add_order_note( 'Customer asked to cancel on WhatsApp: "' . $short . '" — call to check before cancelling.' );
				self::move( $o, 'processing' );
			} else {
				$o->update_meta_data( self::REPLIED, time() );
				$o->save_meta_data();
				if ( 'later' === $kind ) {
					$o->add_order_note( 'Customer asked on WhatsApp to send it later: "' . $short . '" — reschedule it.' );
				}
				if ( in_array( $o->get_status(), array( 'call-later', 'unreachable' ), true ) ) {
					self::move( $o, 'processing', 'Customer replied on WhatsApp — read the chat.' );
				}
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
		// Out of Processing into Confirmed — unless a risky customer still needs a call.
		if ( in_array( $o->get_status(), self::OPEN, true ) ) {
			list( $bucket ) = self::classify_order( $o );
			if ( 'call' === $how || 'call' !== $bucket ) {
				self::move( $o, 'confirmed', 'call' === $how ? 'Confirmed by phone.' : 'Confirmed on WhatsApp.' );
			}
		}
	}

	public static function unconfirm( WC_Order $o ) {
		foreach ( array( self::HOW, self::AT, self::BY, self::TEXT, self::CANCEL ) as $k ) {
			$o->delete_meta_data( $k );
		}
		$o->save_meta_data();
		if ( 'confirmed' === $o->get_status() ) {
			self::move( $o, 'processing', 'Confirmation removed.' );
		}
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

	/* ------------------------------------------------------------------ */
	/* Call attempts                                                       */
	/* ------------------------------------------------------------------ */

	const TRIES = '_pg_call_tries';
	const NEXT  = '_pg_call_next';  // UTC timestamp of the next try
	const LAST  = '_pg_call_last';  // noans | off | busy
	const LASTT = '_pg_call_last_at';

	/** A typed note like "Call not received" / "ফোন বন্ধ" → which call outcome it means ('' = none). */
	public static function outcome_from_text( $text ) {
		$t = mb_strtolower( (string) $text );
		if ( preg_match( '/(switched\s*off|phone\s*off|ফোন\s*বন্ধ|বন্ধ\s*পাওয়া)/u', $t ) ) {
			return 'off';
		}
		if ( preg_match( '/(busy|ব্যস্ত|ওয়েটিং|waiting)/u', $t ) ) {
			return 'busy';
		}
		if ( preg_match( '/(call\s*not\s*received|not\s*received|no\s*answer|not\s*reachable|ফোন\s*ধর(?:ে|ছে)\s*না|কল\s*ধর(?:ে|ছে)\s*না|রিসিভ\s*কর(?:ে|ছে)\s*না)/u', $t ) ) {
			return 'noans';
		}
		return '';
	}

	public static function outcomes() {
		return array(
			'noans' => array( 'Call not received', 'No answer' ),
			'off'   => array( 'Phone switched off', 'Switched off' ),
			'busy'  => array( 'Phone busy', 'Busy' ),
		);
	}

	public static function max_tries() {
		return max( 1, (int) self::setting( 'call_max_tries', 3 ) );
	}

	/** Next try: N hours later, moved to 10 AM if that falls at night (Bangladesh time). */
	private static function next_try_time() {
		$ts = time() + (int) ( max( 0.5, self::setting( 'call_retry_hours', 3 ) ) * HOUR_IN_SECONDS );
		$tz = PG_Helpers::tz();
		$dt = new DateTime( '@' . $ts );
		$dt->setTimezone( $tz );
		$h = (int) $dt->format( 'G' );
		if ( $h >= 21 ) {
			$dt->modify( '+1 day' );
			$dt->setTime( 10, 0 );
		} elseif ( $h < 9 ) {
			$dt->setTime( 10, 0 );
		}
		return $dt->getTimestamp();
	}

	/** Staff tried to call: count it, note it, and hide the order until the next try. */
	public static function log_call( WC_Order $o, $outcome, $with_note = true ) {
		$outs = self::outcomes();
		if ( 'reset' === $outcome ) {
			foreach ( array( self::TRIES, self::NEXT, self::LAST, self::LASTT ) as $k ) {
				$o->delete_meta_data( $k );
			}
			$o->save_meta_data();
			self::move( $o, 'processing', 'Call attempts reset — back in Processing.' );
			self::flush();
			return;
		}
		if ( ! isset( $outs[ $outcome ] ) ) {
			return;
		}
		$tries = (int) $o->get_meta( self::TRIES ) + 1;
		$max   = self::max_tries();
		$o->update_meta_data( self::TRIES, $tries );
		$o->delete_meta_data( self::REPLIED ); // the team has dealt with the reply
		$o->update_meta_data( self::LAST, $outcome );
		$o->update_meta_data( self::LASTT, time() );
		if ( $tries < $max ) {
			$o->update_meta_data( self::NEXT, self::next_try_time() );
		} else {
			$o->delete_meta_data( self::NEXT );
		}
		$o->save_meta_data();
		$text = $outs[ $outcome ][0];
		if ( $with_note ) {
			PG_Orders::add_staff_note( $o, $text . ' (call ' . $tries . ( $tries >= $max ? ' — not reachable, decide whether to cancel' : '' ) . ')' );
			PG_Team::log( $o->get_id(), 'call', $text );
			do_action( 'pg_staff_note', $o, $text ); // WhatsApp "we could not reach you" (once)
		}
		// Out of Processing: into "Call later" until the next try, or "Not reachable".
		if ( in_array( $o->get_status(), self::OPEN, true ) || PG_Reschedule::STATUS === $o->get_status() ) {
			self::move( $o, $tries < $max ? 'call-later' : 'unreachable', $tries < $max ? sprintf( 'Call again %s.', self::when_label( (int) $o->get_meta( self::NEXT ) ) ) : 'Not reachable after ' . $tries . ' calls.' );
		}
		self::flush();
	}

	public static function calls_card( WC_Order $o ) {
		$tries = (int) $o->get_meta( self::TRIES );
		if ( ! $tries ) {
			return null;
		}
		$outs = self::outcomes();
		$last = (string) $o->get_meta( self::LAST );
		$next = (int) $o->get_meta( self::NEXT );
		$lt   = (int) $o->get_meta( self::LASTT );
		return array(
			'tries' => $tries,
			'max'   => self::max_tries(),
			'last'  => isset( $outs[ $last ] ) ? $outs[ $last ][1] : '',
			'at'    => $lt ? PG_Helpers::bd_time( $lt, 'h:i A' ) : '',
			'next'  => $next ? self::when_label( $next ) : '',
			'wait'  => $next > time(),
		);
	}

	private static function when_label( $ts ) {
		$today = PG_Helpers::bd_time( time(), 'Y-m-d' );
		$d     = PG_Helpers::bd_time( $ts, 'Y-m-d' );
		if ( $d === $today ) {
			return PG_Helpers::bd_time( $ts, 'h:i A' );
		}
		return ( PG_Helpers::bd_time( time() + DAY_IN_SECONDS, 'Y-m-d' ) === $d ? 'tomorrow ' : PG_Helpers::bd_time( $ts, 'd M ' ) ) . PG_Helpers::bd_time( $ts, 'h:i A' );
	}

	private static function ordinal( $n ) {
		$s = array( 1 => '1st', 2 => '2nd', 3 => '3rd' );
		return isset( $s[ $n ] ) ? $s[ $n ] : $n . 'th';
	}

	/* ------------------------------------------------------------------ */
	/* Buckets: call now / call later / not reachable                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Where the order belongs and why.
	 * Returns array( bucket, reasons ) — bucket: call | later | unreachable | '' (nothing to do).
	 * Each reason: array( code, label, level ). $fraud: the cached score row if known.
	 */
	public static function classify_order( WC_Order $o, $fraud = false ) {
		$parcel = PG_Steadfast::active( $o->get_id() );
		if ( ! in_array( $o->get_status(), self::OPEN, true ) || ( $parcel && ! in_array( $parcel->courier_status, array( 'cancelled', 'voided' ), true ) ) ) {
			return array( '', array() ); // at the courier already (a cancelled parcel does not count)
		}
		$out    = array();
		$how    = (string) $o->get_meta( self::HOW );
		$cancel = (string) $o->get_meta( self::CANCEL );
		$resend = (string) $o->get_meta( self::RESEND );
		$tries  = (int) $o->get_meta( self::TRIES );
		$next   = (int) $o->get_meta( self::NEXT );
		$age_h  = $o->get_date_created() ? ( time() - $o->get_date_created()->getTimestamp() ) / HOUR_IN_SECONDS : 99;
		$wait_h = self::setting( 'call_after_hours', 2 );
		$wa_on  = PG_WhatsApp::configured();
		$state  = (string) $o->get_meta( '_pg_wa_state' );
		$bucket = '';

		if ( '' !== $resend ) {
			$out[]  = array( 'resend', 'Wants it sent again — book the courier again', 'warn' );
			$bucket = 'call';
		}
		if ( '' !== $cancel ) {
			$out[]  = array( 'cancel', 'Asked to cancel on WhatsApp: "' . $cancel . '"', 'bad' );
			$bucket = 'call';
		} elseif ( '' === $how && '' === $resend ) {
			$outs = self::outcomes();
			$last = (string) $o->get_meta( self::LAST );
			$lastl = isset( $outs[ $last ] ) ? $outs[ $last ][1] : '';
			if ( $o->get_meta( self::REPLIED ) ) {
				$out[]  = array( 'replied', 'Replied on WhatsApp — read the chat first', 'info' );
				$bucket = 'call';
			} elseif ( $tries >= self::max_tries() ) {
				$out[]  = array( 'unreachable', 'No answer after ' . $tries . ' calls (last: ' . $lastl . ') — cancel or try once more', 'bad' );
				$bucket = 'unreachable';
			} elseif ( $tries && $next > time() ) {
				$out[]  = array( 'later', $lastl . ' · ' . $tries . '/' . self::max_tries() . ' calls · next try ' . self::when_label( $next ), 'info' );
				$bucket = 'later';
			} elseif ( $tries ) {
				$out[]  = array( 'retry', self::ordinal( $tries + 1 ) . ' try — last time: ' . $lastl, 'warn' );
				$bucket = 'call';
			} elseif ( ! $wa_on ) {
				$out[]  = array( 'new', 'Not confirmed yet', 'info' );
				$bucket = 'call';
			} elseif ( 'none' === $state ) {
				$out[]  = array( 'nowa', 'No WhatsApp on this number', 'bad' );
				$bucket = 'call';
			} elseif ( 'failed' === $state ) {
				$out[]  = array( 'wafail', 'WhatsApp message failed', 'bad' );
				$bucket = 'call';
			} elseif ( $age_h >= $wait_h ) {
				$label  = 'read' === $state ? 'Read on WhatsApp but no reply' : ( '' === $state ? 'Not confirmed yet' : 'No WhatsApp reply' );
				$out[]  = array( 'noreply', $label . ' (' . self::ago( $age_h ) . ')', 'warn' );
				$bucket = 'call';
			}
		}
		// Risky orders stay on the list even after a WhatsApp "yes".
		if ( '' === $cancel && 'call' !== $how && 'unreachable' !== $bucket ) {
			if ( false === $fraud ) {
				$p     = PG_Helpers::bd_phone( $o->get_billing_phone() );
				$f     = $p ? PG_Fraud::cached_many( array( $p ) ) : array();
				$fraud = $p && isset( $f[ $p ] ) ? $f[ $p ] : null;
			}
			$risk = array();
			$min  = self::setting( 'call_low_score', 50 );
			if ( $min > 0 && is_array( $fraud ) && null !== $fraud['delivery_ratio'] && ( ! empty( $fraud['parcels'] ) || ! in_array( (string) $fraud['volume_range'], array( '', '0' ), true ) ) && $fraud['delivery_ratio'] < $min ) {
				$risk[] = array( 'score', 'Low delivery score (' . (int) $fraud['delivery_ratio'] . '%)', 'bad' );
			}
			$hv = self::setting( 'call_high_value', 0 );
			if ( $hv > 0 && (float) $o->get_total() >= $hv ) {
				$risk[] = array( 'value', 'High-value order', 'warn' );
			}
			if ( $risk ) {
				$out = array_merge( $out, $risk );
				if ( '' === $bucket ) {
					$bucket = 'call';
				}
			}
		}
		return array( $bucket, $out );
	}

	/** Reasons shown on the card (any bucket). */
	public static function reasons( WC_Order $o, $fraud = false ) {
		$c = self::classify_order( $o, $fraud );
		return $c[1];
	}

	public static function bucket( WC_Order $o ) {
		$c = self::classify_order( $o );
		return $c[0];
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

	/** All open orders sorted into the three lists (computed once per request). */
	public static function lists() {
		static $lists = null;
		if ( null !== $lists ) {
			return $lists;
		}
		$cand  = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::OPEN,
				'limit'        => 400,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '>' . ( time() - 21 * DAY_IN_SECONDS ),
				'return'       => 'ids',
			)
		);
		$rank  = array( 'cancel' => 0, 'resend' => 1, 'replied' => 1, 'nowa' => 2, 'wafail' => 2, 'score' => 3, 'retry' => 4, 'noreply' => 5, 'new' => 5, 'value' => 6 );
		$rows  = array( 'call' => array(), 'later' => array(), 'unreachable' => array() );
		foreach ( $cand as $i => $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			list( $b, $r ) = self::classify_order( $o );
			if ( ! $b ) {
				continue;
			}
			$key = $i;
			if ( 'call' === $b ) {
				$key = min( array_map( function ( $x ) use ( $rank ) { return isset( $rank[ $x[0] ] ) ? $rank[ $x[0] ] : 9; }, $r ) ) * 100000 + $i;
			} elseif ( 'later' === $b ) {
				$key = (int) $o->get_meta( self::NEXT );
			}
			$rows[ $b ][] = array( $id, $key );
		}
		$lists = array();
		foreach ( $rows as $b => $list ) {
			usort( $list, function ( $x, $y ) { return $x[1] <=> $y[1]; } );
			$lists[ $b ] = array_map( function ( $r ) { return (int) $r[0]; }, $list );
		}
		set_transient( 'pg_call_n', array_map( 'count', $lists ), 120 );
		return $lists;
	}

	/** Call list ids (bucket "call" by default). */
	public static function ids( $bucket = 'call' ) {
		$l = self::lists();
		return isset( $l[ $bucket ] ) ? $l[ $bucket ] : array();
	}

	public static function counts() {
		$n = get_transient( 'pg_call_n' );
		return is_array( $n ) ? $n : array_map( 'count', self::lists() );
	}

	public static function count() {
		$c = self::counts();
		return isset( $c['call'] ) ? (int) $c['call'] : 0;
	}

	public static function ajax_call() {
		PG_Helpers::guard_ajax();
		$o       = wc_get_order( PG_Helpers::post_int( 'order_id' ) );
		$outcome = sanitize_key( PG_Helpers::post( 'outcome', '' ) );
		if ( ! $o || ( 'reset' !== $outcome && ! isset( self::outcomes()[ $outcome ] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request', 'product-glide' ) ) );
		}
		self::log_call( $o, $outcome );
		$o   = wc_get_order( $o->get_id() );
		$c   = self::calls_card( $o );
		$msg = 'reset' === $outcome ? __( 'Back on the Call list.', 'product-glide' ) : ( $c && $c['tries'] >= $c['max'] ? __( 'Not reachable — moved to the Unreachable list.', 'product-glide' ) : sprintf( /* translators: time */ __( 'Saved. Next try %s.', 'product-glide' ), $c ? $c['next'] : '' ) );
		wp_send_json_success( array( 'message' => $msg, 'order' => PG_Orders::order_card_data( $o ) ) );
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
