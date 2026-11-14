<?php
defined( 'ABSPATH' ) || exit;

/**
 * District / thana detection, delivery charge by zone, and "paste the customer's
 * message → order details" reading for the New order screen.
 *
 * When the BD Smart Address plugin is active its matcher and its delivery-charge
 * settings are used, so admin orders get exactly the charge checkout would give.
 * Otherwise the bundled copy (PG_Geo) and Product Glide's own fees are used.
 */
class PG_Address {

	const SUBURB_DEFAULT = 'Gazipur:Gazipur Sadar, Gazipur:Tongi, Narayanganj:Narayanganj Sadar, Narayanganj:Fatullah, Narayanganj:Siddhirganj, Narayanganj:Bandar';

	public static function init() {
		add_action( 'wp_ajax_pg_geo', array( __CLASS__, 'ajax_geo' ) );
		add_action( 'wp_ajax_pg_parse_text', array( __CLASS__, 'ajax_parse_text' ) );
		add_action( 'wp_ajax_pg_geo_detect', array( __CLASS__, 'ajax_detect' ) );
	}

	/** Use the BD Smart Address plugin when it is there. */
	public static function bdsa() {
		return class_exists( 'BDSA_Geo' ) && class_exists( 'BDSA_Delivery' ) && class_exists( 'BDSA_Plugin' ) && BDSA_Delivery::enabled();
	}

	/** Name of the matcher class (both have the same API). */
	public static function geo() {
		return ( class_exists( 'BDSA_Geo' ) ) ? 'BDSA_Geo' : 'PG_Geo';
	}

	public static function data() {
		$g = self::geo();
		return $g::data();
	}

	/* ---------- Zones and fees ---------- */

	public static function fee( $zone ) {
		if ( self::bdsa() ) {
			return BDSA_Delivery::fee( $zone );
		}
		$k = 'inside' === $zone ? 'del_inside' : ( 'suburb' === $zone ? 'del_suburb' : 'del_outside' );
		return max( 0.0, (float) PG_Settings::get( $k, 'inside' === $zone ? 60 : 120 ) );
	}

	public static function zone_label( $zone ) {
		if ( self::bdsa() ) {
			return BDSA_Delivery::label( $zone );
		}
		$map = array(
			'inside'  => 'ঢাকা সিটির ভেতরে',
			'suburb'  => 'সাব-আরবান ঢাকা',
			'outside' => 'ঢাকার বাইরে',
		);
		return isset( $map[ $zone ] ) ? $map[ $zone ] : '';
	}

	private static function suburb_list() {
		$g   = self::geo();
		$out = array();
		foreach ( explode( ',', (string) PG_Settings::get( 'del_suburb_list', self::SUBURB_DEFAULT ) ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			$p     = array_map( 'trim', explode( ':', $item, 2 ) );
			$out[] = array(
				'd' => str_replace( ' ', '', $g::norm( $p[0] ) ),
				't' => isset( $p[1] ) ? str_replace( ' ', '', $g::norm( $p[1] ) ) : '',
			);
		}
		return $out;
	}

	/** inside | suburb | outside. Unknown district → outside (never under-charge). */
	public static function zone( $di, $ti ) {
		if ( self::bdsa() ) {
			return BDSA_Delivery::zone( $di, $ti );
		}
		if ( null === $di ) {
			return 'outside';
		}
		$g = self::geo();
		$d = $g::district( $di );
		if ( ! $d ) {
			return 'outside';
		}
		if ( $g::dhaka_index() === $di ) {
			if ( null === $ti ) {
				return 'inside';
			}
			return $g::is_dhaka_city( $di, $ti ) ? 'inside' : 'suburb';
		}
		$dn = str_replace( ' ', '', $g::norm( $d['n'] ) );
		$u  = null !== $ti ? $g::thana( $di, $ti ) : null;
		$tn = $u ? str_replace( ' ', '', $g::norm( $u[0] ) ) : '';
		foreach ( self::suburb_list() as $e ) {
			if ( $e['d'] === $dn && ( '' === $e['t'] || ( '' !== $tn && $e['t'] === $tn ) ) ) {
				return 'suburb';
			}
		}
		return 'outside';
	}

	/** District index → WooCommerce state code (BD-13 …). */
	public static function wc_state( $di ) {
		if ( null === $di || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}
		$g = self::geo();
		foreach ( (array) WC()->countries->get_states( 'BD' ) as $code => $name ) {
			if ( $g::district_from_wc_state( $name ) === (int) $di ) {
				return $code;
			}
		}
		return '';
	}

	/* ---------- Analysis ---------- */

	/**
	 * Free-text address (+ optional district / thana words) → indexes, names, zone and fee.
	 */
	public static function analyze( $address, $district_text = '', $thana_text = '' ) {
		$g  = self::geo();
		$di = null;
		$ti = null;
		if ( '' !== trim( (string) $district_text ) ) {
			$di = $g::resolve_district( $district_text );
			if ( null === $di ) {
				$r  = $g::analyze( $district_text );
				$di = $r['district'];
			}
		}
		if ( null !== $di && '' !== trim( (string) $thana_text ) ) {
			$ti = $g::resolve_thana( $di, $thana_text );
		}
		$text = trim( $address . ' ' . $thana_text . ' ' . $district_text );
		$r    = '' !== $text ? $g::analyze( $text ) : array( 'district' => null, 'thana' => null, 'confidence' => 'low', 'refined' => '' );
		if ( null === $di ) {
			$di = $r['district'];
			$ti = $r['thana'];
		} elseif ( null === $ti && $r['district'] === $di ) {
			$ti = $r['thana'];
		}
		$conf = $r['confidence'];
		if ( null === $ti && '' !== $text && class_exists( 'PG_Thana_Finder' ) ) {
			$f = PG_Thana_Finder::find( $text, $di );
			if ( $f && ( null === $di || (int) $f[0] === (int) $di ) ) {
				list( $di, $ti ) = $f;
				$conf            = 'medium';
			}
		}
		return self::describe( $di, $ti, $conf, $address );
	}

	public static function describe( $di, $ti, $confidence = '', $address = '' ) {
		$g    = self::geo();
		$d    = null !== $di ? $g::district( $di ) : null;
		$u    = ( $d && null !== $ti ) ? $g::thana( $di, $ti ) : null;
		$zone = self::zone( $d ? $di : null, $u ? $ti : null );
		$full = '';
		if ( '' !== trim( (string) $address ) ) {
			$full = self::compose( $g::refine( $address, $d ? $di : null, $u ? $ti : null ), $d, $u, $address );
		}
		return array(
			'district'    => $d ? (int) $di : null,
			'thana'       => $u ? (int) $ti : null,
			'district_en' => $d ? $d['n'] : '',
			'district_bn' => $d ? $d['b'] : '',
			'thana_en'    => $u ? $u[0] : '',
			'thana_bn'    => $u ? $u[1] : '',
			'confidence'  => $confidence,
			'zone'        => $zone,
			'zone_label'  => self::zone_label( $zone ),
			'fee'         => self::fee( $zone ),
			'full'        => $full,
		);
	}

	/** Cleaned address + thana + district, without repeating a name the address already has. */
	private static function compose( $refined, $d, $u, $typed ) {
		$g    = self::geo();
		$bn   = (bool) preg_match( '/[\x{0980}-\x{09FF}]/u', (string) $typed );
		$segs = '' === trim( $refined ) ? array() : preg_split( '/\s*,\s*/u', trim( $refined ) );
		$fold = function ( $x ) use ( $g ) {
			// য়/ড়/ঢ় can be typed as one letter or as letter + nukta: compare them the same way.
			return str_replace( array( "\u{09AF}\u{09BC}", "\u{09A1}\u{09BC}", "\u{09A2}\u{09BC}" ), array( "\u{09DF}", "\u{09DC}", "\u{09DD}" ), $g::norm( $x ) );
		};
		$hay  = ' ' . $fold( implode( ' ', $segs ) ) . ' ';
		foreach ( array( $u, $d ) as $x ) {
			if ( ! $x ) {
				continue;
			}
			$names = $u === $x ? array( $x[0], $x[1] ) : array_merge( array( $x['n'], $x['b'] ), isset( $x['a'] ) ? (array) $x['a'] : array() );
			$found = false;
			foreach ( $names as $n ) {
				$nn = $fold( $n );
				if ( '' !== $nn && false !== strpos( $hay, ' ' . $nn ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$segs[] = $u === $x ? ( $bn ? $x[1] : $x[0] ) : ( $bn ? $x['b'] : $x['n'] );
			}
		}
		return implode( ', ', array_filter( $segs, 'strlen' ) );
	}

	/** Districts, thanas and the zone of every thana, for the form dropdowns (cached per settings). */
	public static function ajax_geo() {
		PG_Helpers::guard_ajax();
		$list = array();
		foreach ( self::data() as $di => $d ) {
			$t = array();
			foreach ( $d['u'] as $ti => $u ) {
				$t[] = array( $u[0], $u[1], self::zone( $di, $ti ) );
			}
			$list[] = array(
				'n' => $d['n'],
				'b' => $d['b'],
				'z' => self::zone( $di, null ),
				't' => $t,
			);
		}
		usort(
			$list,
			function ( $a, $b ) {
				return strcmp( $a['n'], $b['n'] );
			}
		);
		// Keep the original index so the server can resolve it again.
		$idx = array();
		foreach ( self::data() as $di => $d ) {
			$idx[ $d['n'] ] = $di;
		}
		foreach ( $list as &$row ) {
			$row['i'] = $idx[ $row['n'] ];
		}
		unset( $row );
		wp_send_json_success(
			array(
				'districts' => $list,
				'fees'      => array(
					'inside'  => self::fee( 'inside' ),
					'suburb'  => self::fee( 'suburb' ),
					'outside' => self::fee( 'outside' ),
				),
				'labels'    => array(
					'inside'  => self::zone_label( 'inside' ),
					'suburb'  => self::zone_label( 'suburb' ),
					'outside' => self::zone_label( 'outside' ),
				),
				'source'    => self::bdsa() ? 'bdsa' : 'pg',
			)
		);
	}

	/** Typed address → district / thana / fee (used while typing on the New order form). */
	public static function ajax_detect() {
		PG_Helpers::guard_ajax();
		wp_send_json_success( array( 'geo' => self::analyze( sanitize_text_field( PG_Helpers::post( 'address', '' ) ) ) ) );
	}

	/* ---------- Pasted message → order details ---------- */

	private static function en_digits( $s ) {
		return str_replace( array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' ), range( 0, 9 ), (string) $s );
	}

	/** All BD mobile numbers in the text, in order, without duplicates. */
	public static function phones( $text ) {
		$t   = self::en_digits( $text );
		$out = array();
		if ( preg_match_all( '/(?:\+?\s*8\s*8[\s\-]*)?0?1[3-9](?:[\s\-\.]*\d){8}/u', $t, $m ) ) {
			foreach ( $m[0] as $raw ) {
				$p = PG_Helpers::bd_phone( $raw );
				if ( $p && ! in_array( $p, $out, true ) ) {
					$out[] = $p;
				}
			}
		}
		return $out;
	}

	/** Label at the start of a line → field. Returns array( field, value ) or null. */
	private static function label( $line ) {
		$labels = array(
			'name'     => 'নাম|name|customer\s*name|কাস্টমার(?:ের)?\s*নাম|গ্রাহকের\s*নাম|প্রাপকের\s*নাম|receiver(?:\s*name)?',
			'phone'    => 'মোবাইল(?:\s*নাম্বার|\s*নম্বর)?|ফোন(?:\s*নাম্বার|\s*নম্বর)?|নাম্বার|নম্বর|phone(?:\s*number)?|mobile(?:\s*number)?|mob|cell|number|contact|whatsapp|বিকল্প\s*নাম্বার|alt(?:ernative)?\s*(?:phone|number)?',
			'address'  => 'ঠিকানা|পূর্ণ\s*ঠিকানা|সম্পূর্ণ\s*ঠিকানা|full\s*address|address|addr|location|লোকেশন|ডেলিভারি\s*ঠিকানা|delivery\s*address',
			'district' => 'জেলা|zilla|zila|jela|jila|district',
			'thana'    => 'থানা|উপজেলা|উপজিলা|upazila|upazilla|upozila|thana|ps|p\.s\.|police\s*station',
			'product'  => 'বই(?:ের)?(?:\s*নাম)?|বইয়ের\s*নাম|book(?:s)?(?:\s*name)?|product(?:s)?|পণ্য|প্রোডাক্ট|item(?:s)?|order|অর্ডার|পরিমাণ|qty|quantity',
			'note'     => 'নোট|note|মন্তব্য|comment',
		);
		foreach ( $labels as $field => $re ) {
			if ( preg_match( '/^\s*(?:' . $re . ')\s*(?:[:：=\-–>]|ঃ)+\s*(.*)$/iu', $line, $m ) ) {
				return array( $field, trim( $m[1] ) );
			}
			// "Name Tanvir", "ঠিকানা মিরপুর ১০" — a label word, a space, then the value.
			if ( in_array( $field, array( 'name', 'phone', 'address', 'district', 'thana' ), true ) && preg_match( '/^\s*(?:' . str_replace( '|ps|p\.s\.', '', $re ) . ')\s+(\S.*)$/iu', $line, $m ) ) {
				return array( $field, trim( $m[1], " .,;" ) );
			}
		}
		return null;
	}

	/**
	 * Read a pasted customer message (Messenger / WhatsApp copy) into order details.
	 * Rule based, instant, free. Works for labelled ("নাম: …") and unlabelled text.
	 */
	public static function parse_text( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text  = preg_replace( '/[\x{200B}\x{FEFF}]/u', '', $text );
		// "…Gazipur. Mobile 017… Name Tanvir" → one field per line.
		$text = preg_replace( '/([.,;|।]\s*|\s{2,})(?=(?:name|নাম|mobile|phone|ফোন|মোবাইল|address|ঠিকানা|জেলা|থানা|উপজেলা|district|thana|upazila)(?:\s|[:：\-=ঃ]))/iu', "$1\n", $text );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );
		$g     = self::geo();

		$out    = array(
			'name'     => '',
			'phone'    => '',
			'alt'      => '',
			'address'  => '',
			'district' => '',
			'thana'    => '',
			'note'     => '',
		);
		$addr   = array();
		$prod   = array();
		$free   = array();
		$phones = self::phones( $text );

		foreach ( $lines as $line ) {
			// Strip chat prefixes like "[10:32 AM, 11/8/2026] Rahim:" or "Rahim, 10:32".
			$line = preg_replace( '/^\[[^\]]{4,40}\]\s*[^:]{0,40}:\s*/u', '', $line );
			$line = trim( preg_replace( '/^[\-\*•▪●◆✅✔☑️➤➡️👉📌📍🏠📞☎️📱]+\s*/u', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^(?:assalamu?\s*alaikum|assalam|salam|আসসালামু\s*আলাইকুম|আস্সালামু\s*আলাইকুম|সালাম|hi|hello|hlw|ভাই|আপু|স্যার|sir|apu|vai|bhai|ok|okay|ঠিক আছে|ধন্যবাদ|thanks|thank you|জি|জ্বি)[\s!.,।]*$/iu', $line ) ) {
				continue;
			}
			$lb = self::label( $line );
			if ( $lb ) {
				list( $f, $v ) = $lb;
				if ( '' === $v ) {
					continue;
				}
				if ( 'phone' === $f ) {
					continue; // numbers are collected from the whole text
				}
				if ( 'address' === $f ) {
					$addr[] = $v;
				} elseif ( 'product' === $f ) {
					$prod[] = $v;
				} elseif ( 'note' === $f ) {
					$out['note'] = trim( $out['note'] . ' ' . $v );
				} elseif ( '' === $out[ $f ] ) {
					$out[ $f ] = $v;
				}
				continue;
			}
			$free[] = $line;
		}

		// Unlabelled lines: phone-only lines are dropped, address-looking lines go to the address,
		// a short leading line with no digits is taken as the name, the rest may be products.
		$rest = array();
		foreach ( $free as $i => $line ) {
			$no_phone = trim( preg_replace( '/(?:\+?\s*8\s*8[\s\-]*)?0?1[3-9](?:[\s\-\.]*\d){8}/u', '', self::en_digits( $line ) ), " ,;:-.\t" );
			if ( '' === $no_phone || mb_strlen( $no_phone ) < 2 ) {
				continue;
			}
			$r       = $g::analyze( $no_phone );
			$looks   = null !== $r['district'] || null !== $r['thana'] || ( preg_match( '/(বাসা|বাড়ি|বাড়ী|রোড|সড়ক|গ্রাম|পোস্ট|পোঃ|ডাকঘর|থানা|উপজেলা|জেলা|মোড়|বাজার|সেক্টর|ব্লক|হাউস|ফ্ল্যাট|মহল্লা|পাড়া|লেন)/u', $no_phone ) || preg_match( '/(?<![a-z])(house|road|rd|sector|block|flat|village|vill|post|p\.?o|lane|bazar|bazaar|more|moor|para|tower|building|bari|bariya|avenue|colony|market|school|college|madrasa|mosque|masjid)(?![a-z])/i', $no_phone ) );
			$words   = preg_split( '/\s+/u', $no_phone );
			$is_name = '' === $out['name'] && ! $looks && count( $words ) <= 4 && ! preg_match( '/\d/', $no_phone ) && ! preg_match( '/(বই|টাকা|৳|অর্ডার|চাই|দিন|লাগবে|পাঠান|ভাই|আপু|স্যার|আসসালামু|সালাম|কেজি|লিটার|গ্রাম|প্যাকেট)/u', $no_phone ) && ! preg_match( '/(?<![a-z])(book|books|hsk|tk|taka|order|please|plz|hi|hello|assalam|salam|kg|gm|ltr|litre|liter|pcs|packet|combo)(?![a-z])/i', $no_phone );
			if ( $is_name ) {
				$out['name'] = $no_phone;
			} elseif ( $looks && ( ! $prod || $addr ) && ! preg_match( '/(বই|কপি|কেজি|লিটার|প্যাকেট)/u', $no_phone ) && ! preg_match( '/(?<![a-z])(book|books|hsk|pcs|kg|gm|ltr|combo)(?![a-z])/i', $no_phone ) ) {
				$addr[] = $no_phone;
			} else {
				$rest[] = $no_phone;
			}
		}

		$out['address'] = trim( implode( ', ', $addr ), ' ,' );
		$out['phone']   = isset( $phones[0] ) ? $phones[0] : '';
		$out['alt']     = isset( $phones[1] ) ? $phones[1] : '';
		$out['name']    = trim( preg_replace( '/\s+/u', ' ', $out['name'] ), " ,.-" );

		$geo         = self::analyze( $out['address'], $out['district'], $out['thana'] );
		$out['geo']  = $geo;
		$out['full'] = $geo['full'] ? $geo['full'] : $out['address'];

		// Products: labelled product lines first, then any other leftover line.
		$segments = array();
		foreach ( array_merge( $prod, $rest ) as $line ) {
			foreach ( preg_split( '/\s*(?:,|،|\+|;|\/| এবং | আর | ও | and | ar | ebong |&)\s*/u', $line ) as $seg ) {
				$seg = trim( $seg );
				if ( mb_strlen( $seg ) >= 2 ) {
					$segments[] = $seg;
				}
			}
		}
		$out['books'] = array();
		foreach ( $segments as $seg ) {
			list( $title, $qty ) = self::qty( $seg );
			if ( '' !== $title ) {
				$out['books'][] = array( 'title' => $title, 'qty' => $qty );
			}
		}
		return $out;
	}

	/** "HSK 1 বই ২টা" → array( "HSK 1 বই", 2 ). Only numbers with a unit or ×/x count as quantity. */
	public static function qty( $seg ) {
		$s   = self::en_digits( $seg );
		$qty = 1;
		$re  = array(
			'/(\d{1,2})\s*(?:টা|টি|খানা|খান|কপি|সেট|পিস|পিচ|pcs?|pieces?|copies|copy|set|ta|ti|piece)(?![\p{L}])/iu',
			'/(?:[x×\*]\s*)(\d{1,2})(?!\d)/iu',
			'/(\d{1,2})\s*(?:[x×])(?![\p{L}])/iu',
			'/(?:qty|quantity|পরিমাণ)\s*[:=\-]?\s*(\d{1,2})/iu',
		);
		foreach ( $re as $r ) {
			if ( preg_match( $r, $s, $m ) ) {
				$qty = max( 1, (int) $m[1] );
				$s   = trim( str_replace( $m[0], ' ', $s ) );
				break;
			}
		}
		$s = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/(?:দিবেন|দেবেন|দিন|চাই|লাগবে|পাঠাবেন|পাঠান|প্লিজ|please|plz|অর্ডার করতে চাই|অর্ডার)\s*$/iu', '', $s ) ), " ,.-:" );
		return array( $s, min( 50, $qty ) );
	}

	public static function ajax_parse_text() {
		PG_Helpers::guard_ajax();
		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Paste the customer message first.', 'product-glide' ) ) );
		}
		$r            = self::parse_text( mb_substr( $text, 0, 5000 ) );
		$r['matches'] = PG_OCR::match_titles( wp_list_pluck( $r['books'], 'title' ) );
		// Drop lines that matched no product at all — they were chat noise, not product names.
		$books = array();
		$mats  = array();
		foreach ( $r['matches'] as $i => $m ) {
			if ( ! empty( $m['candidates'] ) && $m['candidates'][0]['score'] >= 40 ) {
				$books[] = $r['books'][ $i ];
				$mats[]  = $m;
			}
		}
		$r['books']   = $books;
		$r['matches'] = $mats;
		$r['ai']      = (bool) PG_Settings::get( 'or_key', '' );
		wp_send_json_success( array( 'data' => $r ) );
	}

	/**
	 * Save district / thana / zone on a new order (WooCommerce state + city, and the same
	 * meta keys BD Smart Address uses so the order card shows the zone).
	 */
	public static function apply_to_order( WC_Order $order, $di, $ti, $typed ) {
		$di = ( '' === $di || null === $di ) ? null : (int) $di;
		$ti = ( '' === $ti || null === $ti ) ? null : (int) $ti;
		$g  = self::geo();
		if ( null === $di || ! $g::district( $di ) ) {
			return;
		}
		$u     = null !== $ti ? $g::thana( $di, $ti ) : null;
		$state = self::wc_state( $di );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( $state ) {
				$order->{"set_{$type}_state"}( $state );
			}
			if ( $u ) {
				$order->{"set_{$type}_city"}( $u[0] );
			}
		}
		if ( $u && class_exists( 'PG_Thana_Finder' ) ) {
			PG_Thana_Finder::learn( $typed, $di, $ti ); // next time this area is found by itself
		}
		$d = self::describe( $di, $u ? $ti : null, '', $typed );
		$order->update_meta_data( '_bdsa_typed_address', $typed );
		if ( $d['full'] ) {
			$order->update_meta_data( '_bdsa_refined_address', $d['full'] );
		}
		$order->update_meta_data( '_bdsa_zone', $d['zone'] );
	}
}
