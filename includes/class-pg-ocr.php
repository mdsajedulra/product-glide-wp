<?php
defined( 'ABSPATH' ) || exit;

/**
 * Screenshot / visiting-card → order details via an OpenRouter vision model,
 * then fuzzy-match mentioned book names to WooCommerce products.
 */
class PG_OCR {

	const API = 'https://openrouter.ai/api/v1';

	public static function init() {
		add_action( 'wp_ajax_pg_ocr_parse', array( __CLASS__, 'ajax_parse' ) );
		add_action( 'wp_ajax_pg_ocr_match', array( __CLASS__, 'ajax_match' ) );
		add_action( 'wp_ajax_pg_ai_models', array( __CLASS__, 'ajax_models' ) );
		add_action( 'wp_ajax_pg_ai_test', array( __CLASS__, 'ajax_test' ) );
	}

	private static function prompt() {
		return 'You read Bangladeshi customer order screenshots (Messenger/WhatsApp chats, notes, visiting cards). '
			. 'Text may be Bangla, English or Banglish. Extract the customer\'s delivery details and the books/products they want. '
			. 'Return ONLY a JSON object (no markdown, no explanation) with keys: name (string), phone (string, Bangladeshi mobile as written), alt_phone (string), '
			. 'address (string, full delivery address), district (string), thana (string), '
			. 'books (array of {title: string, qty: integer}), note (string, anything else useful such as delivery time). '
			. 'Use empty strings / empty array when unknown. Do not invent data. Convert Bangla digits to English digits in phone numbers.';
	}

	/** POST to OpenRouter chat completions. Returns assistant text or WP_Error. */
	private static function chat( array $content, $max_tokens = 800 ) {
		$key   = PG_Settings::get( 'or_key', '' );
		$model = trim( (string) PG_Settings::get( 'or_model', '' ) );
		if ( ! $key ) {
			return new WP_Error( 'pg_ai', __( 'Add your OpenRouter API key in Settings → AI / OCR.', 'product-glide' ) );
		}
		if ( ! $model ) {
			return new WP_Error( 'pg_ai', __( 'Choose a vision model in Settings → AI / OCR.', 'product-glide' ) );
		}
		$res = PG_Helpers::remote_json(
			'POST',
			self::API . '/chat/completions',
			array(
				'Authorization' => 'Bearer ' . $key,
				'HTTP-Referer'  => home_url( '/' ),
				'X-Title'       => 'Product Glide',
			),
			array(
				'model'       => $model,
				'messages'    => array( array( 'role' => 'user', 'content' => $content ) ),
				'temperature' => 0.1,
				'max_tokens'  => $max_tokens,
			),
			90
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] >= 400 || isset( $res['data']['error'] ) ) {
			$msg = isset( $res['data']['error']['message'] ) ? $res['data']['error']['message'] : 'HTTP ' . $res['code'];
			if ( isset( $res['data']['error']['metadata']['raw'] ) && is_string( $res['data']['error']['metadata']['raw'] ) ) {
				$msg .= ' — ' . mb_substr( $res['data']['error']['metadata']['raw'], 0, 200 );
			}
			if ( 429 === (int) $res['code'] ) {
				$msg = __( 'Rate limit reached for this model (free models have daily limits). Try again later or pick another model.', 'product-glide' ) . ' (' . $msg . ')';
			}
			return new WP_Error( 'pg_ai', 'OpenRouter: ' . $msg );
		}
		$text = isset( $res['data']['choices'][0]['message']['content'] ) ? $res['data']['choices'][0]['message']['content'] : '';
		if ( is_array( $text ) ) { // Some providers return content parts.
			$text = implode( '', wp_list_pluck( $text, 'text' ) );
		}
		return (string) $text;
	}

	/** Pull the first JSON object out of a model reply (handles ```json fences and chatter). */
	private static function extract_json( $text ) {
		$text = trim( preg_replace( '/^```(?:json)?|```$/mi', '', (string) $text ) );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		}
		return is_array( $data ) ? $data : null;
	}

	public static function ajax_parse() {
		PG_Helpers::guard_ajax();
		$img = isset( $_POST['image'] ) ? (string) wp_unslash( $_POST['image'] ) : ''; // phpcs:ignore -- base64 image data, validated below.
		if ( ! preg_match( '#^data:(image/(?:png|jpe?g|webp|heic|heif));base64,([A-Za-z0-9+/=\s]+)$#', $img, $m ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload a PNG, JPG or WEBP image.', 'product-glide' ) ) );
		}
		if ( strlen( $m[2] ) > 14 * MB_IN_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'Image is too large (max ~10 MB).', 'product-glide' ) ) );
		}
		$text = self::chat(
			array(
				array( 'type' => 'text', 'text' => self::prompt() ),
				array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $m[1] . ';base64,' . preg_replace( '/\s+/', '', $m[2] ) ) ),
			)
		);
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}
		$data = self::extract_json( $text );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not read details from this image. Try a clearer screenshot or another model.', 'product-glide' ) ) );
		}
		$clean = array(
			'name'      => sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ),
			'phone'     => sanitize_text_field( isset( $data['phone'] ) ? $data['phone'] : '' ),
			'alt_phone' => sanitize_text_field( isset( $data['alt_phone'] ) ? $data['alt_phone'] : '' ),
			'address'   => sanitize_text_field( isset( $data['address'] ) ? $data['address'] : '' ),
			'district'  => sanitize_text_field( isset( $data['district'] ) ? $data['district'] : '' ),
			'thana'     => sanitize_text_field( isset( $data['thana'] ) ? $data['thana'] : '' ),
			'note'      => sanitize_textarea_field( isset( $data['note'] ) ? $data['note'] : '' ),
			'books'     => array(),
		);
		$clean['phone_bd'] = PG_Helpers::bd_phone( $clean['phone'] );
		foreach ( isset( $data['books'] ) && is_array( $data['books'] ) ? $data['books'] : array() as $b ) {
			$title = sanitize_text_field( is_array( $b ) && isset( $b['title'] ) ? $b['title'] : ( is_string( $b ) ? $b : '' ) );
			if ( '' !== $title ) {
				$clean['books'][] = array( 'title' => $title, 'qty' => max( 1, absint( is_array( $b ) && isset( $b['qty'] ) ? $b['qty'] : 1 ) ) );
			}
		}
		$clean['matches'] = self::match_titles( wp_list_pluck( $clean['books'], 'title' ) );
		wp_send_json_success( array( 'data' => $clean ) );
	}

	/** Vision-capable models from the OpenRouter catalog (cached 6 h). Admin only. */
	public static function ajax_models() {
		PG_Helpers::guard_ajax( true );
		$list = get_transient( 'pg_or_models' );
		if ( ! is_array( $list ) || PG_Helpers::post_int( 'force' ) ) {
			$res = PG_Helpers::remote_json( 'GET', self::API . '/models', array(), null, 30 );
			if ( is_wp_error( $res ) || $res['code'] >= 400 || empty( $res['data']['data'] ) ) {
				wp_send_json_error( array( 'message' => is_wp_error( $res ) ? $res->get_error_message() : __( 'Could not load the OpenRouter model list.', 'product-glide' ) ) );
			}
			$list = array();
			foreach ( $res['data']['data'] as $mdl ) {
				$in = isset( $mdl['architecture']['input_modalities'] ) ? (array) $mdl['architecture']['input_modalities'] : array();
				if ( ! in_array( 'image', $in, true ) ) {
					continue;
				}
				$pp     = isset( $mdl['pricing']['prompt'] ) ? (float) $mdl['pricing']['prompt'] : 0;
				$pc     = isset( $mdl['pricing']['completion'] ) ? (float) $mdl['pricing']['completion'] : 0;
				$list[] = array(
					'id'     => (string) $mdl['id'],
					'name'   => isset( $mdl['name'] ) ? (string) $mdl['name'] : (string) $mdl['id'],
					'free'   => 0.0 === $pp && 0.0 === $pc,
					'in_1m'  => round( $pp * 1000000, 3 ),
					'out_1m' => round( $pc * 1000000, 3 ),
				);
			}
			usort(
				$list,
				function ( $a, $b ) {
					if ( $a['free'] !== $b['free'] ) {
						return $a['free'] ? -1 : 1;
					}
					return $a['in_1m'] <=> $b['in_1m'];
				}
			);
			set_transient( 'pg_or_models', $list, 6 * HOUR_IN_SECONDS );
		}
		wp_send_json_success( array( 'models' => $list, 'current' => PG_Settings::get( 'or_model', '' ) ) );
	}

	/** Quick check that the key + model answer. Admin only. */
	public static function ajax_test() {
		PG_Helpers::guard_ajax( true );
		$t0   = microtime( true );
		$text = self::chat( array( array( 'type' => 'text', 'text' => 'Reply with exactly: OK' ) ), 10 );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => sprintf( 'Connected — %s replied "%s" in %d ms.', PG_Settings::get( 'or_model' ), mb_substr( trim( $text ), 0, 20 ), (int) round( ( microtime( true ) - $t0 ) * 1000 ) ) ) );
	}

	public static function ajax_match() {
		PG_Helpers::guard_ajax();
		$titles = array_filter( array_map( 'sanitize_text_field', (array) PG_Helpers::post( 'titles', array() ) ) );
		wp_send_json_success( array( 'matches' => self::match_titles( $titles ) ) );
	}

	private static function norm( $s ) {
		$s = mb_strtolower( (string) $s );
		$s = str_replace( array( "\u{200D}", "\u{200C}" ), '', $s ); // ZWJ / ZWNJ.
		$s = preg_replace( '/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $s );
		$stop = array( 'বই', 'book', 'books', 'এর', 'জন্য', 'the', 'and', 'মাত্র', 'টাকা', 'কম্বো', 'combo' );
		$words = array_filter(
			preg_split( '/\s+/u', trim( $s ) ),
			function ( $w ) use ( $stop ) {
				return '' !== $w && ! in_array( $w, $stop, true );
			}
		);
		return array_values( $words );
	}

	/** Score 0–100: token overlap blended with character similarity. */
	public static function score( $a, $b ) {
		$ta = self::norm( $a );
		$tb = self::norm( $b );
		if ( ! $ta || ! $tb ) {
			return 0;
		}
		$hit = 0;
		foreach ( $ta as $w ) {
			foreach ( $tb as $v ) {
				if ( $w === $v || ( mb_strlen( $w ) > 2 && ( false !== mb_strpos( $v, $w ) || false !== mb_strpos( $w, $v ) ) ) ) {
					$hit++;
					break;
				}
			}
		}
		$overlap = $hit / count( $ta );
		similar_text( implode( ' ', $ta ), implode( ' ', $tb ), $pct );
		// HSK / level numbers must agree when both mention one.
		preg_match_all( '/\d+/', $a, $na );
		preg_match_all( '/\d+/', $b, $nb );
		$penalty = ( $na[0] && $nb[0] && ! array_intersect( $na[0], $nb[0] ) ) ? 25 : 0;
		return max( 0, (int) round( $overlap * 70 + $pct * 0.3 - $penalty ) );
	}

	public static function match_titles( array $titles ) {
		if ( ! $titles ) {
			return array();
		}
		$catalog = array();
		foreach ( PG_Packages::all_products( 800 ) as $p ) {
			if ( $p->is_type( 'variable' ) ) {
				continue;
			}
			$catalog[] = $p;
		}
		$out = array();
		foreach ( $titles as $t ) {
			$scored = array();
			foreach ( $catalog as $p ) {
				$sc = self::score( $t, $p->get_name() );
				if ( $sc >= 25 ) {
					$scored[] = array( 'score' => $sc, 'product' => PG_Packages::product_row( $p ) );
				}
			}
			usort(
				$scored,
				function ( $x, $y ) {
					return $y['score'] - $x['score'];
				}
			);
			$out[] = array(
				'title'      => $t,
				'candidates' => array_slice( $scored, 0, 5 ),
			);
		}
		return $out;
	}
}
