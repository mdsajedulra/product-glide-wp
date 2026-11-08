<?php
/**
 * Bangladesh district / thana data and the keyword matcher.
 *
 * This class is deliberately free of WordPress calls so it can be unit tested
 * from the command line.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PG_TESTING' ) ) {
	exit;
}

class PG_Geo {

	private static $data  = null;
	private static $index = null;
	private static $file  = '';

	public static function set_file( $file ) {
		self::$file  = $file;
		self::$data  = null;
		self::$index = null;
	}

	/**
	 * Dataset: list of districts, each { n: English, b: Bangla, a: aliases[], u: [[en, bn, metro?], ...] }
	 */
	public static function data() {
		if ( null === self::$data ) {
			$file       = self::$file ? self::$file : PG_DIR . 'data/bd-geo.json';
			$raw        = is_readable( $file ) ? file_get_contents( $file ) : '';
			$json       = json_decode( $raw, true );
			self::$data = is_array( $json ) ? $json : array();
		}
		return self::$data;
	}

	public static function district( $di ) {
		$d = self::data();
		return isset( $d[ $di ] ) ? $d[ $di ] : null;
	}

	public static function thana( $di, $ti ) {
		$d = self::data();
		return isset( $d[ $di ]['u'][ $ti ] ) ? $d[ $di ]['u'][ $ti ] : null;
	}

	/**
	 * Lower-case, strip punctuation, ASCII digits, single spaces.
	 */
	public static function norm( $s ) {
		$s = str_replace( array( "'", "\xE2\x80\x99", '`', "\xE2\x80\x8C", "\xE2\x80\x8D" ), '', (string) $s );
		$s = mb_strtolower( $s, 'UTF-8' );
		$s = strtr(
			$s,
			array(
				'০' => '0',
				'১' => '1',
				'২' => '2',
				'৩' => '3',
				'৪' => '4',
				'৫' => '5',
				'৬' => '6',
				'৭' => '7',
				'৮' => '8',
				'৯' => '9',
			)
		);
		$t = preg_replace( '/[^\p{L}\p{N}\p{M}]+/u', ' ', $s );
		if ( null === $t ) {
			$t = $s;
		}
		return trim( preg_replace( '/\s+/u', ' ', $t ) );
	}

	private static function is_bn( $s ) {
		return (bool) preg_match( '/[\x{0980}-\x{09FF}]/u', $s );
	}

	private static function index() {
		if ( null !== self::$index ) {
			return self::$index;
		}
		$districts = array();
		$thanas    = array();
		$names     = array();

		foreach ( self::data() as $di => $d ) {
			$vars = array_merge( array( $d['n'], $d['b'] ), isset( $d['a'] ) ? $d['a'] : array() );
			foreach ( $vars as $v ) {
				$nv = self::norm( $v );
				if ( '' === $nv ) {
					continue;
				}
				$districts[] = array(
					'd'  => $di,
					'v'  => $nv,
					'bn' => self::is_bn( $nv ),
				);
			}
			foreach ( $d['u'] as $ti => $u ) {
				$metro = ! empty( $u[2] );
				$key   = self::norm( $u[0] );
				$names[ $key ][ $di ] = true;
				// Optional 4th item: local area names that belong to this thana (Raptani -> Ashulia).
				$forms = array_merge( array( $u[0], $u[1] ), isset( $u[3] ) && is_array( $u[3] ) ? $u[3] : array() );
				foreach ( $forms as $v ) {
					$nv = self::norm( $v );
					if ( '' === $nv ) {
						continue;
					}
					$thanas[] = array(
						'd'     => $di,
						't'     => $ti,
						'k'     => $key,
						'v'     => $nv,
						'bn'    => self::is_bn( $nv ),
						'metro' => $metro,
						'weak'  => false,
					);
				}
				// "Comilla Sadar" is often written just "Sadar" once the district is known.
				if ( preg_match( '/\ssadar$/i', $u[0] ) ) {
					$thanas[] = array(
						'd'     => $di,
						't'     => $ti,
						'k'     => $key,
						'v'     => 'sadar',
						'bn'    => false,
						'metro' => false,
						'weak'  => true,
					);
					$thanas[] = array(
						'd'     => $di,
						't'     => $ti,
						'k'     => $key,
						'v'     => 'সদর',
						'bn'    => true,
						'metro' => false,
						'weak'  => true,
					);
				}
			}
		}

		self::$index = array(
			'districts' => $districts,
			'thanas'    => $thanas,
			'names'     => $names,
		);
		return self::$index;
	}

	/**
	 * Position of the last whole-word occurrence, or false.
	 * Bangla words take suffixes (ঢাকায়, মিরপুরে) so they are matched as prefixes.
	 */
	private static function find( $hay, $v, $bn ) {
		$needle = $bn ? ' ' . $v : ' ' . $v . ' ';
		return strrpos( $hay, $needle );
	}

	private static function fuzzy_max( $len ) {
		return $len >= 9 ? 2 : 1;
	}

	/**
	 * Analyse a free-text address.
	 *
	 * @return array {district: int|null, thana: int|null, confidence: high|medium|low, refined: string}
	 */
	public static function analyze( $address ) {
		$idx  = self::index();
		$norm = self::norm( $address );
		$hay  = ' ' . $norm . ' ';
		$out  = array(
			'district'   => null,
			'thana'      => null,
			'confidence' => 'low',
			'refined'    => '',
		);
		if ( '' === $norm ) {
			return $out;
		}

		// 1. District: the last explicit mention wins (addresses usually end with it).
		$best = null;
		foreach ( $idx['districts'] as $r ) {
			$pos = self::find( $hay, $r['v'], $r['bn'] );
			if ( false === $pos ) {
				continue;
			}
			$len = strlen( $r['v'] );
			if ( null === $best || $pos > $best['pos'] || ( $pos === $best['pos'] && $len > $best['len'] ) ) {
				$best = array(
					'd'   => $r['d'],
					'pos' => $pos,
					'len' => $len,
				);
			}
		}
		$district_exact = null !== $best;
		$district       = $district_exact ? $best['d'] : null;

		// Misspelled district ("chottogram", "naraynganj").
		if ( null === $district ) {
			$tokens = explode( ' ', $norm );
			$fuzzy  = null;
			foreach ( $idx['districts'] as $r ) {
				if ( $r['bn'] || false !== strpos( $r['v'], ' ' ) || strlen( $r['v'] ) < 6 ) {
					continue;
				}
				foreach ( $tokens as $tk ) {
					if ( strlen( $tk ) < 5 || ! preg_match( '/^[a-z]+$/', $tk ) ) {
						continue;
					}
					$lev = levenshtein( $tk, $r['v'] );
					if ( $lev > 0 && $lev <= self::fuzzy_max( strlen( $r['v'] ) ) && ( null === $fuzzy || $lev < $fuzzy['lev'] ) ) {
						$fuzzy = array(
							'd'   => $r['d'],
							'lev' => $lev,
						);
					}
				}
			}
			if ( $fuzzy ) {
				$district = $fuzzy['d'];
			}
		}

		// 2. Thana candidates.
		$hits = array();
		foreach ( $idx['thanas'] as $r ) {
			if ( null !== $district && $r['d'] !== $district ) {
				continue;
			}
			if ( null === $district && $r['weak'] ) {
				continue;
			}
			$pos = self::find( $hay, $r['v'], $r['bn'] );
			if ( false === $pos ) {
				continue;
			}
			$hits[] = array(
				'd'     => $r['d'],
				't'     => $r['t'],
				'k'     => $r['k'],
				'pos'   => $pos,
				'len'   => strlen( $r['v'] ),
				'weak'  => $r['weak'],
				'metro' => $r['metro'],
			);
		}
		usort(
			$hits,
			function ( $a, $b ) {
				if ( $a['weak'] !== $b['weak'] ) {
					return $a['weak'] ? 1 : -1;
				}
				if ( $a['len'] !== $b['len'] ) {
					return $b['len'] - $a['len'];
				}
				return $b['pos'] - $a['pos'];
			}
		);

		if ( null !== $district ) {
			$out['district'] = $district;
			if ( $hits ) {
				$out['thana']      = $hits[0]['t'];
				$out['confidence'] = ( $district_exact && ! $hits[0]['weak'] ) ? 'high' : 'medium';
			} else {
				$ft = self::fuzzy_thana( $district, $norm );
				if ( null !== $ft ) {
					$out['thana'] = $ft;
				}
				$out['confidence'] = 'medium';
			}
		} elseif ( $hits ) {
			$top      = $hits[0];
			$districts = array_keys( $idx['names'][ $top['k'] ] );
			if ( 1 === count( $districts ) ) {
				$out['district']   = $top['d'];
				$out['thana']      = $top['t'];
				$out['confidence'] = 'high';
			} else {
				// Ambiguous name (Mirpur, Kotwali...). Prefer a single metropolitan thana.
				$metro = array();
				foreach ( $hits as $h ) {
					if ( $h['k'] === $top['k'] && $h['metro'] ) {
						$metro[ $h['d'] . '-' . $h['t'] ] = $h;
					}
				}
				if ( 1 === count( $metro ) ) {
					$m                 = reset( $metro );
					$out['district']   = $m['d'];
					$out['thana']      = $m['t'];
					$out['confidence'] = 'medium';
				}
			}
		}

		$out['refined'] = self::refine( $address, $out['district'], $out['thana'] );
		return $out;
	}

	private static function fuzzy_thana( $di, $norm ) {
		$d = self::district( $di );
		if ( ! $d ) {
			return null;
		}
		$tokens = explode( ' ', $norm );
		$best   = null;
		foreach ( $d['u'] as $ti => $u ) {
			$v = self::norm( $u[0] );
			if ( false !== strpos( $v, ' ' ) || strlen( $v ) < 5 || ! preg_match( '/^[a-z]+$/', $v ) ) {
				continue;
			}
			foreach ( $tokens as $tk ) {
				if ( strlen( $tk ) < 5 || ! preg_match( '/^[a-z]+$/', $tk ) ) {
					continue;
				}
				$lev = levenshtein( $tk, $v );
				if ( $lev > 0 && $lev <= self::fuzzy_max( strlen( $v ) ) && ( null === $best || $lev < $best['lev'] ) ) {
					$best = array(
						't'   => $ti,
						'lev' => $lev,
					);
				}
			}
		}
		return $best ? $best['t'] : null;
	}

	/* ---------- Resolving names returned by the AI ---------- */

	public static function resolve_district( $text ) {
		$norm = self::norm( $text );
		if ( '' === $norm ) {
			return null;
		}
		$sq = str_replace( ' ', '', $norm );
		$idx = self::index();
		foreach ( $idx['districts'] as $r ) {
			if ( str_replace( ' ', '', $r['v'] ) === $sq ) {
				return $r['d'];
			}
		}
		if ( preg_match( '/^[a-z]+$/', $sq ) && strlen( $sq ) >= 6 ) {
			foreach ( $idx['districts'] as $r ) {
				$v = str_replace( ' ', '', $r['v'] );
				if ( $r['bn'] || strlen( $v ) < 6 ) {
					continue;
				}
				if ( levenshtein( $sq, $v ) <= self::fuzzy_max( strlen( $v ) ) ) {
					return $r['d'];
				}
			}
		}
		return null;
	}

	/**
	 * Map a WooCommerce state label ("Cumilla", "Nawabganj", "Faridpur ") to a district index.
	 * Uses the extra "w" names (WooCommerce spellings) that are never used for address text.
	 */
	public static function district_from_wc_state( $name ) {
		$sq = str_replace( ' ', '', self::norm( $name ) );
		if ( '' === $sq ) {
			return null;
		}
		foreach ( self::data() as $di => $d ) {
			$vars = array_merge( array( $d['n'], $d['b'] ), isset( $d['a'] ) ? $d['a'] : array(), isset( $d['w'] ) ? $d['w'] : array() );
			foreach ( $vars as $v ) {
				if ( str_replace( ' ', '', self::norm( $v ) ) === $sq ) {
					return $di;
				}
			}
		}
		return self::resolve_district( $name );
	}

	/**
	 * Index of the Dhaka district, or null.
	 */
	public static function dhaka_index() {
		foreach ( self::data() as $di => $d ) {
			if ( 'Dhaka' === $d['n'] ) {
				return $di;
			}
		}
		return null;
	}

	/**
	 * Is this thana inside Dhaka city (a metropolitan thana), as opposed to the
	 * rest of Dhaka district (Savar, Keraniganj, Dhamrai, Nawabganj, Dohar)?
	 *
	 * @param int      $di    District index.
	 * @param int      $ti    Thana index.
	 * @param string[] $extra English names of extra thanas the shop treats as "inside".
	 */
	public static function is_dhaka_city( $di, $ti, $extra = array() ) {
		if ( $di !== self::dhaka_index() ) {
			return false;
		}
		$u = self::thana( $di, $ti );
		if ( ! $u ) {
			return false;
		}
		if ( ! empty( $u[2] ) ) {
			return true;
		}
		$n = str_replace( ' ', '', self::norm( $u[0] ) );
		foreach ( (array) $extra as $e ) {
			if ( '' !== $e && str_replace( ' ', '', self::norm( $e ) ) === $n ) {
				return true;
			}
		}
		return false;
	}

	public static function resolve_thana( $di, $text ) {
		$d    = self::district( $di );
		$norm = self::norm( $text );
		if ( ! $d || '' === $norm ) {
			return null;
		}
		$sq = str_replace( ' ', '', $norm );
		foreach ( $d['u'] as $ti => $u ) {
			if ( str_replace( ' ', '', self::norm( $u[0] ) ) === $sq || str_replace( ' ', '', self::norm( $u[1] ) ) === $sq ) {
				return $ti;
			}
		}
		if ( preg_match( '/^[a-z]+$/', $sq ) && strlen( $sq ) >= 5 ) {
			$best = null;
			foreach ( $d['u'] as $ti => $u ) {
				$v = str_replace( ' ', '', self::norm( $u[0] ) );
				if ( strlen( $v ) < 5 ) {
					continue;
				}
				$lev = levenshtein( $sq, $v );
				if ( $lev <= self::fuzzy_max( strlen( $v ) ) && ( null === $best || $lev < $best['lev'] ) ) {
					$best = array(
						't'   => $ti,
						'lev' => $lev,
					);
				}
			}
			if ( $best ) {
				return $best['t'];
			}
		}
		return null;
	}

	/* ---------- Rule-based address clean-up ---------- */

	public static function refine( $address, $di = null, $ti = null ) {
		$a = trim( (string) $address );
		$a = preg_replace( '/[\r\n]+/u', ', ', $a );
		$a = preg_replace( '/\s+/u', ' ', $a );
		$a = preg_replace( '/\s*[,;]+\s*/u', ', ', $a );
		$clean_original = trim( $a, " ,;-.\t" );

		$b = '(?<![\p{L}])';
		$e = '(?![\p{L}])';
		$rules = array(
			'/' . $b . '(?:house|hs|h)' . $e . '\.?[\s\-#:]*(?:no\.?[\s\-#:]*)?(\d+[a-z]?)(?!\d)/iu' => 'House $1',
			'/' . $b . '(?:road|rd)' . $e . '\.?[\s\-#:]*(?:no\.?[\s\-#:]*)?(\d+[a-z]?)(?!\d)/iu'    => 'Road $1',
			'/' . $b . 'r' . $e . '[\s\-#:]*(\d+)(?!\d)/iu'                                           => 'Road $1',
			'/' . $b . '(?:flat|apt|f)' . $e . '\.?[\s\-#:]*(\d+[a-z]?)(?![\d])/iu'                  => 'Flat $1',
			'/' . $b . '(?:block|blk|bl)' . $e . '\.?[\s\-#:]*([a-z])' . $e . '/iu'                   => 'Block $1',
			'/' . $b . '(?:sector|sec)' . $e . '\.?[\s\-#:]*(\d+)(?!\d)/iu'                           => 'Sector $1',
			'/' . $b . '(?:avenue|ave)' . $e . '\.?[\s\-#:]*(\d+)(?!\d)/iu'                           => 'Avenue $1',
		);
		foreach ( $rules as $re => $rep ) {
			$a = preg_replace( $re, $rep, $a );
		}
		// Upper-case the block letter ("Block c" -> "Block C").
		$a = preg_replace_callback(
			'/\bBlock ([a-z])\b/u',
			function ( $m ) {
				return 'Block ' . strtoupper( $m[1] );
			},
			$a
		);

		// Capitalise plain lower-case English words, leave Bangla and acronyms alone.
		$small = array( 'er', 'o', 'near', 'by', 'beside', 'of', 'and', 'the', 'in', 'at', 'behind', 'opposite', 'ke', 'kache', 'pase', 'theke' );
		$a     = preg_replace_callback(
			'/(?<![\p{L}\p{N}\'’])[a-z]+(?![\p{L}\p{N}\'’])/u',
			function ( $m ) use ( $small ) {
				return in_array( $m[0], $small, true ) ? $m[0] : ucfirst( $m[0] );
			},
			$a
		);

		// Drop a trailing district / thana: they have their own fields and are added back by compose().
		// A district is dropped wherever it ends the text. A thana only when it is its own comma
		// separated part, so place names like "Uttar Badda" or "Purba Rampura" stay whole.
		$dist_names   = array();
		$thana_names  = array();
		if ( null !== $di && ( $d = self::district( $di ) ) ) {
			$dist_names = array_merge( array( $d['n'], $d['b'] ), isset( $d['a'] ) ? $d['a'] : array() );
		}
		if ( null !== $di && null !== $ti && ( $u = self::thana( $di, $ti ) ) ) {
			$thana_names = array( $u[0], $u[1] );
		}
		$strip_tail = function ( $names, $comma_only ) use ( &$a ) {
			if ( ! $names ) {
				return;
			}
			usort(
				$names,
				function ( $x, $y ) {
					return strlen( $y ) - strlen( $x );
				}
			);
			$alt  = implode( '|', array_map( function ( $s ) { return preg_quote( $s, '/' ); }, $names ) );
			$lead = $comma_only ? '(?:^|,)[\s\-]*' : '[\s,\-]*(?<![\p{L}])';
			for ( $i = 0; $i < 4; $i++ ) {
				// Also drops a trailing 4-digit postcode: it is not needed once district is chosen.
				$next = preg_replace( '/' . $lead . '(?:' . $alt . ')\.?(?:[\s,\-]*\d{4})?[\s,\-]*$/iu', '', $a );
				if ( null === $next || $next === $a || mb_strlen( trim( $next, " ,;-.\t" ), 'UTF-8' ) < 5 ) {
					break;
				}
				$a = $next;
			}
		};
		$strip_tail( $dist_names, false );
		$strip_tail( $thana_names, true );

		// Separate the expanded parts with commas: "House 12 Road 5" -> "House 12, Road 5".
		$a = preg_replace( '/(?<=[\p{L}\p{N}])\s+(?=(?:House|Road|Flat|Sector|Avenue) \d|Block [A-Z](?![\p{L}]))/u', ', ', $a );
		$a = preg_replace( '/(?:,\s*)+/u', ', ', $a );
		$a = preg_replace( '/\s+/u', ' ', $a );
		$a = trim( $a, " ,;-.\t" );

		if ( mb_strlen( $a, 'UTF-8' ) < 5 ) {
			$a = $clean_original;
		}
		return $a;
	}

	/**
	 * Full courier-ready address: cleaned address + thana + district,
	 * e.g. "uttar badda" -> "Uttar Badda, Badda, Dhaka". Bangla names when the customer typed Bangla.
	 */
	public static function compose( $refined, $di, $ti, $typed = '' ) {
		$refined = trim( (string) $refined );
		$bn      = self::is_bn( '' !== trim( (string) $typed ) ? (string) $typed : $refined );
		$d       = null !== $di ? self::district( $di ) : null;
		$u       = ( null !== $di && null !== $ti ) ? self::thana( $di, $ti ) : null;

		// Names already written as the last comma-separated parts ("Tongi, Gazipur") are not repeated.
		$known = array();
		foreach ( array( $d ? array( $d['n'], $d['b'] ) : array(), $u ? array( $u[0], $u[1] ) : array() ) as $names ) {
			foreach ( $names as $n ) {
				$known[ self::norm( $n ) ] = true;
			}
		}
		$segments = '' === $refined ? array() : preg_split( '/\s*,\s*/u', $refined );
		while ( $segments && isset( $known[ self::norm( end( $segments ) ) ] ) ) {
			array_pop( $segments );
		}
		if ( $u ) {
			$segments[] = $bn ? $u[1] : $u[0];
		}
		if ( $d ) {
			$segments[] = $bn ? $d['b'] : $d['n'];
		}
		return implode( ', ', $segments );
	}

	/* ---------- Data for the front end ---------- */

	public static function frontend_map() {
		$out = array();
		foreach ( self::data() as $d ) {
			$vars = array();
			foreach ( array_merge( array( $d['n'], $d['b'] ), isset( $d['a'] ) ? $d['a'] : array(), isset( $d['w'] ) ? $d['w'] : array() ) as $v ) {
				$vars[] = str_replace( ' ', '', self::norm( $v ) );
			}
			$t = array();
			foreach ( $d['u'] as $u ) {
				$t[] = array( $u[0], $u[1] );
			}
			$out[] = array(
				'n' => $d['n'],
				'b' => $d['b'],
				'v' => array_values( array_unique( $vars ) ),
				't' => $t,
			);
		}
		return $out;
	}

	/**
	 * All thana names for the checkout <select>: value => label.
	 */
	public static function thana_options() {
		$opts = array();
		foreach ( self::data() as $d ) {
			foreach ( $d['u'] as $u ) {
				if ( ! isset( $opts[ $u[0] ] ) ) {
					$opts[ $u[0] ] = $u[0] . ' (' . $u[1] . ')';
				}
			}
		}
		ksort( $opts, SORT_NATURAL | SORT_FLAG_CASE );
		return $opts;
	}
}
