<?php
/**
 * Dynamic single product page sections: [pg_product_page part="..."]
 *
 * Every part reads the current product, so one Elementor template serves
 * every book:
 *   badge  – "N+ জন কিনেছেন" (only when there are enough sales)
 *   sub    – short description, if the product has one
 *   facts  – page count / edition / language / author chips from the description
 *   trust  – cash on delivery, delivery charge, hotline
 *   body   – details, "bought together" upsell, how to order, FAQ, final CTA, mobile bar
 *
 * @package ProductGlide
 */

defined( 'ABSPATH' ) || exit;

class PG_Product_Page {

	const LABELS = array( 'বইয়ের নাম', 'লেখক', 'আন্তর্জাতিক বই নং', 'প্রকাশনী', 'সংস্করণ', 'পৃষ্ঠা সংখ্যা', 'দেশ', 'ভাষা', 'অনুবাদক', 'সম্পাদক', 'বাঁধাই', 'ISBN' );

	private static $css_done = false;

	public static function init() {
		add_shortcode( 'pg_product_page', array( __CLASS__, 'shortcode' ) );
	}

	/* ---------- helpers ---------- */

	public static function bn( $n ) {
		return strtr( (string) $n, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
	}

	private static function money( $n ) {
		return '৳' . self::bn( number_format( (float) $n, 0, '.', ',' ) );
	}

	private static function norm( $label ) {
		// Bangla য় can be one code point or য + nukta.
		return str_replace( "য\u{09BC}", 'য়', trim( $label, " \t:：" ) );
	}

	private static function product() {
		global $product;
		$p = $product instanceof WC_Product ? $product : wc_get_product( get_the_ID() );
		return $p instanceof WC_Product ? $p : null;
	}

	/**
	 * "label \n value" pairs written in the description (the format all books use).
	 *
	 * @return array [ facts => [label => value], rest => remaining text (html) ]
	 */
	public static function facts( WC_Product $p ) {
		$html   = (string) $p->get_description();
		$labels = array_map( array( __CLASS__, 'norm' ), self::LABELS );
		$text   = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</h3>', '</h4>', '</td>' ), "\n", $html ) );
		$lines  = array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', $text ) ), 'strlen' ) );
		$facts  = array();
		$used   = 0;
		$n      = count( $lines );
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$l = self::norm( $lines[ $i ] );
			if ( in_array( $l, $labels, true ) && ! in_array( self::norm( $lines[ $i + 1 ] ), $labels, true ) ) {
				$facts[ $l ] = $lines[ $i + 1 ];
				$used       += 2;
				$i++;
			}
		}
		// The description is only the facts list: nothing else to show as prose.
		$only_facts = $facts && $used >= $n - 1;
		return array(
			'facts' => $facts,
			'prose' => $only_facts ? '' : $html,
		);
	}

	/**
	 * Products most often bought in the same order, then the shop's own upsells, then same category.
	 */
	public static function together( WC_Product $p, $limit = 4 ) {
		$id  = $p->get_id();
		$key = 'pg_pp_tog_' . $id;
		$ids = get_transient( $key );
		if ( false === $ids ) {
			global $wpdb;
			$ids   = array();
			$table = $wpdb->prefix . 'wc_order_product_lookup';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore
				$ids = array_map(
					'intval',
					$wpdb->get_col( // phpcs:ignore
						$wpdb->prepare(
							"SELECT b.product_id FROM {$table} a JOIN {$table} b ON b.order_id = a.order_id AND b.product_id <> a.product_id
							 WHERE a.product_id = %d GROUP BY b.product_id HAVING COUNT(DISTINCT b.order_id) >= 3 ORDER BY COUNT(DISTINCT b.order_id) DESC LIMIT 12",
							$id
						)
					)
				);
			}
			set_transient( $key, $ids, 12 * HOUR_IN_SECONDS );
		}
		$from_orders = (bool) $ids;
		$ids         = array_merge( $ids, $p->get_upsell_ids(), $p->get_cross_sell_ids() );
		if ( count( $ids ) < $limit ) {
			$ids = array_merge(
				$ids,
				wc_get_products(
					array(
						'status'   => 'publish',
						'limit'    => 12,
						'return'   => 'ids',
						'orderby'  => 'popularity',
						'category' => wp_list_pluck( get_the_terms( $id, 'product_cat' ) ?: array(), 'slug' ),
						'exclude'  => array( $id ),
					)
				)
			);
		}
		$out = array();
		foreach ( array_unique( $ids ) as $pid ) {
			$q = wc_get_product( $pid );
			if ( ! $q || $pid === $id || 'publish' !== $q->get_status() || ! $q->is_purchasable() || ! $q->is_in_stock() || ! $q->is_type( 'simple' ) || '' === $q->get_price() ) {
				continue;
			}
			$out[] = $pid;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array( $out, $from_orders );
	}

	/* ---------- output ---------- */

	public static function shortcode( $atts ) {
		$a = shortcode_atts( array( 'part' => 'body' ), $atts, 'pg_product_page' );
		$p = self::product();
		if ( ! $p ) {
			return '';
		}
		$fn = 'part_' . sanitize_key( $a['part'] );
		if ( ! method_exists( __CLASS__, $fn ) ) {
			return '';
		}
		$html = self::$fn( $p );
		return $html ? self::css() . '<div class="pgl-page">' . $html . '</div>' : '';
	}

	private static function part_badge( WC_Product $p ) {
		$n = (int) $p->get_total_sales();
		if ( $n < 30 ) {
			return '';
		}
		$r = $n >= 1000 ? floor( $n / 100 ) * 100 : ( $n >= 100 ? floor( $n / 10 ) * 10 : $n );
		return '<span class="pgl-badge"><i></i>' . esc_html( self::bn( number_format( $r ) ) . '+ জন এই বইটি কিনেছেন' ) . '</span>';
	}

	private static function part_sub( WC_Product $p ) {
		$s = trim( wp_strip_all_tags( $p->get_short_description() ) );
		return $s ? '<p class="pgl-sub">' . esc_html( $s ) . '</p>' : '';
	}

	private static function part_facts( WC_Product $p ) {
		$f    = self::facts( $p )['facts'];
		$show = array(
			'পৃষ্ঠা সংখ্যা' => '%s পৃষ্ঠা',
			'সংস্করণ'      => '%s',
			'ভাষা'         => '%s',
			'লেখক'         => 'লেখক: %s',
		);
		$out = '';
		foreach ( $show as $k => $fmt ) {
			if ( ! empty( $f[ $k ] ) ) {
				$out .= '<span>' . esc_html( sprintf( $fmt, $f[ $k ] ) ) . '</span>';
			}
		}
		return $out ? '<div class="pgl-pills">' . $out . '</div>' : '';
	}

	private static function fees() {
		$in  = class_exists( 'PG_Address' ) ? PG_Address::fee( 'inside' ) : PG_Settings::get( 'del_inside', 60 );
		$out = class_exists( 'PG_Address' ) ? PG_Address::fee( 'outside' ) : PG_Settings::get( 'del_outside', 120 );
		return array( self::bn( (int) $in ), self::bn( (int) $out ) );
	}

	private static function phone() {
		return preg_replace( '/[^0-9]/', '', (string) PG_Settings::get( 'hotline', '' ) );
	}

	private static function wa_link( $phone ) {
		$intl = preg_replace( '/^0/', '880', $phone );
		return 'https://wa.me/' . $intl;
	}

	private static function part_trust( WC_Product $p ) {
		list( $in, $out ) = self::fees();
		$ph = self::phone();
		$i  = array(
			'cash'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>',
			'truck' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4h14v11H1zM15 8h4l4 4v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
			'tag'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12l-8 8-9-9V3h8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>',
			'chat'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z"/></svg>',
		);
		$h  = '<div class="pgl-trust">';
		$h .= '<div>' . $i['cash'] . '<span>ক্যাশ অন ডেলিভারি, বই হাতে পেয়ে টাকা</span></div>';
		$h .= '<div>' . $i['truck'] . '<span>সারা দেশে হোম ডেলিভারি</span></div>';
		$h .= '<div>' . $i['tag'] . '<span>ডেলিভারি চার্জ ঢাকায় ' . $in . '৳, বাইরে ' . $out . '৳</span></div>';
		if ( $ph ) {
			$h .= '<div>' . $i['chat'] . '<span>প্রশ্ন থাকলে WhatsApp: <a href="' . esc_url( self::wa_link( $ph ) ) . '" target="_blank" rel="noopener">' . esc_html( $ph ) . '</a></span></div>';
		}
		return $h . '</div>';
	}

	private static function part_body( WC_Product $p ) {
		$id    = $p->get_id();
		$f     = self::facts( $p );
		$price = self::money( $p->get_price() );
		$reg   = (float) $p->get_regular_price() > (float) $p->get_price() ? self::money( $p->get_regular_price() ) : '';
		$co    = add_query_arg( 'add-to-cart', $id, wc_get_checkout_url() );
		$img   = wp_get_attachment_image_url( $p->get_image_id(), 'medium' );
		$pdf   = get_post_meta( $id, '_wppp_pdf_url', true );
		$ph    = self::phone();
		list( $in, $out ) = self::fees();
		$name  = $p->get_name();

		ob_start();

		// 1. About the book.
		if ( $f['prose'] || $f['facts'] ) {
			echo '<section class="pgl-sec"><h2 class="pgl-h">বইটি সম্পর্কে</h2>';
			if ( $f['prose'] ) {
				echo '<div class="pgl-prose">' . wp_kses_post( wpautop( do_shortcode( $f['prose'] ) ) ) . '</div>';
			}
			if ( $f['facts'] && ! $f['prose'] ) {
				echo '<table class="pgl-info">';
				foreach ( $f['facts'] as $k => $v ) {
					echo '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( $v ) . '</td></tr>';
				}
				echo '</table>';
			}
			echo '</section>';
		}

		// 2. Bought together.
		list( $ids, $from_orders ) = self::together( $p, 4 );
		if ( $ids && class_exists( 'PG_Upsell' ) ) {
			echo '<section class="pgl-sec pgl-up">' . PG_Upsell::render( // phpcs:ignore
				array(
					'ids'   => implode( ',', $ids ),
					'title' => $from_orders ? 'এই বইয়ের সাথে আরও যা নিয়েছেন পাঠকেরা' : 'এগুলোও দেখতে পারেন',
					'note'  => $from_orders ? 'যারা এই বইটি কিনেছেন, তাদের অনেকে এগুলোও সাথে নিয়েছেন। একটা চাপেই অর্ডারে যোগ হয়ে যাবে।' : 'একটা চাপেই অর্ডারে যোগ হয়ে যাবে, একসাথে ডেলিভারি।',
				)
			) . '</section>';
		}

		// 3. How to order.
		?>
		<section class="pgl-sec">
			<h2 class="pgl-h">কিভাবে অর্ডার করবেন</h2>
			<div class="pgl-steps" style="margin-top:14px">
				<div><b>"অর্ডার করুন" চাপুন</b>পেজের ওপরে বা নিচের লাল বাটনে চাপ দিন।</div>
				<div><b>নাম, মোবাইল ও ঠিকানা দিন</b>জেলা ও থানা ঠিকমতো লিখুন, ডেলিভারি চার্জ নিজে থেকেই যোগ হবে।</div>
				<div><b>বই হাতে পেয়ে টাকা দিন</b>কুরিয়ার আপনার ঠিকানায় বই পৌঁছে দেবে, তখন টাকা পরিশোধ করবেন।</div>
			</div>
			<?php
			$note = trim( (string) PG_Settings::get( 'pp_note', '' ) );
			if ( $note ) {
				echo '<div class="pgl-warn"><strong class="t">অর্ডার করার আগে গুরুত্বপূর্ণ অনুরোধ</strong>' . wp_kses_post( wpautop( esc_html( $note ) ) ) . '</div>';
			}
			?>
		</section>

		<section class="pgl-sec pgl-faq">
			<h2 class="pgl-h" style="margin-bottom:16px">সাধারণ প্রশ্ন</h2>
			<?php if ( $pdf ) : ?>
				<details><summary>কেনার আগে কি বইয়ের ভেতরটা দেখা যাবে?</summary><p>হ্যাঁ। ওপরের "একটু পড়ে দেখুন" বাটনে চাপ দিলে বইয়ের কিছু পাতা পড়ে দেখতে পারবেন।</p></details>
			<?php endif; ?>
			<details><summary>টাকা কখন দিতে হবে?</summary><p>ক্যাশ অন ডেলিভারি। বই হাতে পাওয়ার পর কুরিয়ারকে টাকা দেবেন। আগে কোনো টাকা পাঠাতে হবে না।</p></details>
			<details><summary>ডেলিভারি চার্জ কত?</summary><p>ঢাকা সিটির ভেতরে <?php echo esc_html( $in ); ?> টাকা, ঢাকার বাইরে <?php echo esc_html( $out ); ?> টাকা। চেকআউটে জেলা দিলে চার্জ নিজে থেকেই হিসাব হয়ে যাবে।</p></details>
			<details><summary>একসাথে কয়েকটা বই নেওয়া যাবে?</summary><p>হ্যাঁ। ওপরের তালিকা থেকে "সাথে নিন" চাপুন, তারপর "অর্ডার করুন" চাপলে সব বই একসাথে একই পার্সেলে আসবে।</p></details>
			<?php if ( $ph ) : ?>
				<details><summary>অর্ডার নিয়ে প্রশ্ন থাকলে কোথায় যোগাযোগ করব?</summary><p>কল বা WhatsApp করুন: <a href="<?php echo esc_url( self::wa_link( $ph ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ph ); ?></a></p></details>
			<?php endif; ?>
			<?php if ( $f['facts'] && $f['prose'] ) : ?>
				<details><summary>বইয়ের বিস্তারিত তথ্য</summary><table class="pgl-info" style="margin-bottom:15px">
					<?php foreach ( $f['facts'] as $k => $v ) : ?>
						<tr><td><?php echo esc_html( $k ); ?></td><td><?php echo esc_html( $v ); ?></td></tr>
					<?php endforeach; ?>
				</table></details>
			<?php endif; ?>
		</section>

		<div class="pgl-final">
			<?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy"><?php endif; ?>
			<div><h3><?php echo esc_html( $name ); ?></h3><p>ক্যাশ অন ডেলিভারি, সারা দেশে হোম ডেলিভারি</p><div class="pr"><?php echo esc_html( $price ); ?><?php if ( $reg ) : ?><del><?php echo esc_html( $reg ); ?></del><?php endif; ?></div></div>
			<a class="pgl-btn pgl-order" href="<?php echo esc_url( $co ); ?>">অর্ডার করুন</a>
		</div>

		<div class="pgl-sticky" id="pgl-sticky"><div class="t"><b><?php echo esc_html( $price ); ?></b><small>ক্যাশ অন ডেলিভারি</small></div><a class="pgl-btn pgl-order" href="<?php echo esc_url( $co ); ?>">অর্ডার করুন</a></div>
		<?php
		echo self::js(); // phpcs:ignore
		return ob_get_clean();
	}

	private static function js() {
		return <<<'JS'
<script>
(function(){
 if (window.pglBound) return; window.pglBound = true;
 document.addEventListener('click',function(e){
  var o=e.target.closest('.pgl-order');
  if(o){var b=document.querySelector('form.cart .bwb-order-btn'); if(b){e.preventDefault(); o.textContent='অপেক্ষা করুন...'; b.click();} return;}
  var k=e.target.closest('.pgl-peek');
  if(k){var m=document.getElementById('wppp-modal'),ob=document.getElementById('wppp-open-modal'); if(m&&ob){e.preventDefault(); m.setAttribute('data-pdf',k.dataset.pdf); ob.click();} else {window.open(k.dataset.pdf,'_blank');}}
 });
 function sticky(){
  var s=document.getElementById('pgl-sticky'), f=document.querySelector('form.cart'), fin=document.querySelector('.pgl-final');
  if(!s||!f||!('IntersectionObserver' in window)) return;
  var heroIn=true, finIn=false, up=function(){s.classList.toggle('on',!heroIn&&!finIn);};
  new IntersectionObserver(function(es){heroIn=es[0].isIntersecting||es[0].boundingClientRect.top>0;up();}).observe(f);
  if(fin) new IntersectionObserver(function(es){finIn=es[0].isIntersecting;up();}).observe(fin);
 }
 if(document.readyState!=='loading') sticky(); else document.addEventListener('DOMContentLoaded',sticky);
})();
</script>
JS;
	}

	private static function css() {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;
		$file = PG_DIR . 'assets/css/product-page.css';
		return file_exists( $file ) ? '<style id="pg-pp-css">' . file_get_contents( $file ) . '</style>' : ''; // phpcs:ignore
	}
}
