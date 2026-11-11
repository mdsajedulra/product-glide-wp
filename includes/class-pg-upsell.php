<?php
/**
 * [pg_upsell] shortcode: "add these too" product cards for single product pages.
 *
 * Each card adds its product to the cart over WooCommerce's own wc-ajax
 * add_to_cart endpoint, so the customer stays on the page and the item
 * goes to checkout with the main product.
 *
 * Usage: [pg_upsell ids="3439,1153" title="সাথে নিন"]
 *
 * @package ProductGlide
 */

defined( 'ABSPATH' ) || exit;

class PG_Upsell {

	private static $assets_done = false;

	public static function init() {
		add_shortcode( 'pg_upsell', array( __CLASS__, 'render' ) );
	}

	private static function bn( $n ) {
		return strtr( (string) $n, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
	}

	private static function money( $n ) {
		return '৳' . self::bn( number_format( (float) $n, 0, '.', ',' ) );
	}

	public static function render( $atts ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$a   = shortcode_atts(
			array(
				'ids'   => '',
				'title' => '',
				'note'  => '',
			),
			$atts,
			'pg_upsell'
		);
		$ids = array_filter( array_map( 'absint', explode( ',', $a['ids'] ) ) );
		$items = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_purchasable() || ! $p->is_in_stock() || ! $p->is_type( 'simple' ) ) {
				continue;
			}
			$items[] = $p;
		}
		if ( ! $items ) {
			return '';
		}
		ob_start();
		self::assets();
		?>
		<div class="pgu">
			<?php if ( $a['title'] ) : ?><h3 class="pgu-title"><?php echo esc_html( $a['title'] ); ?></h3><?php endif; ?>
			<?php if ( $a['note'] ) : ?><p class="pgu-note"><?php echo esc_html( $a['note'] ); ?></p><?php endif; ?>
			<div class="pgu-grid">
				<?php
				foreach ( $items as $p ) :
					$reg  = (float) $p->get_regular_price();
					$pr   = (float) $p->get_price();
					$img  = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
					?>
					<div class="pgu-card">
						<a class="pgu-img" href="<?php echo esc_url( $p->get_permalink() ); ?>" target="_blank" rel="noopener">
							<?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $p->get_name() ); ?>" loading="lazy"><?php endif; ?>
						</a>
						<div class="pgu-body">
							<a class="pgu-name" href="<?php echo esc_url( $p->get_permalink() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p->get_name() ); ?></a>
							<div class="pgu-price">
								<b><?php echo esc_html( self::money( $pr ) ); ?></b>
								<?php if ( $reg > $pr ) : ?><del><?php echo esc_html( self::money( $reg ) ); ?></del><?php endif; ?>
							</div>
							<button type="button" class="pgu-add" data-id="<?php echo esc_attr( $p->get_id() ); ?>">+ সাথে নিন</button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="pgu-done" hidden></div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function assets() {
		if ( self::$assets_done ) {
			return;
		}
		self::$assets_done = true;
		$endpoint = class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '';
		?>
		<style>
		.pgu{font-family:inherit}
		.pgu-title{margin:0 0 4px;font-size:22px;font-weight:700;color:#111827}
		.pgu-note{margin:0 0 16px;color:#4b5563;font-size:15px}
		.pgu-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
		.pgu-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;transition:border-color .15s,box-shadow .15s}
		.pgu-card:hover{border-color:#fca5a5;box-shadow:0 6px 18px rgba(0,0,0,.06)}
		.pgu-card.is-added{border-color:#16a34a;box-shadow:0 0 0 2px rgba(22,163,74,.15)}
		.pgu-img{display:flex;align-items:center;justify-content:center;background:#f9fafb;aspect-ratio:3/4;padding:10px}
		.pgu-img img{max-width:100%;max-height:100%;object-fit:contain;display:block}
		.pgu-body{padding:10px 12px 12px;display:flex;flex-direction:column;gap:6px;flex:1}
		.pgu-name{color:#111827;font-weight:600;font-size:14px;line-height:1.4;text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
		.pgu-price{display:flex;gap:8px;align-items:baseline}
		.pgu-price b{color:#b91c1c;font-size:17px}
		.pgu-price del{color:#9ca3af;font-size:13px}
		.pgu-add{margin-top:auto;width:100%;border:1.5px solid #b91c1c;background:#fff;color:#b91c1c;border-radius:10px;padding:9px 8px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;line-height:1.2}
		.pgu-add:hover{background:#fef2f2}
		.pgu-add[disabled]{opacity:.7;cursor:default}
		.pgu-card.is-added .pgu-add{background:#16a34a;border-color:#16a34a;color:#fff}
		.pgu-done{margin-top:14px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:10px 14px;font-size:15px}
		@media (max-width:900px){.pgu-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}}
		</style>
		<script>
		(function(){
			var EP = <?php echo wp_json_encode( $endpoint ); ?>;
			var added = 0;
			document.addEventListener('click', function(e){
				var b = e.target.closest('.pgu-add');
				if (!b || b.disabled || !EP) return;
				e.preventDefault();
				var card = b.closest('.pgu-card');
				if (card.classList.contains('is-added')) return;
				b.disabled = true; b.textContent = 'যোগ হচ্ছে...';
				var fd = new FormData(); fd.append('product_id', b.dataset.id); fd.append('quantity', 1);
				fetch(EP, {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
					if (!d || d.error) throw 0;
					card.classList.add('is-added'); b.textContent = '✓ যোগ হয়েছে'; added++;
					var box = card.closest('.pgu').querySelector('.pgu-done');
					box.hidden = false;
					box.textContent = String(added).replace(/\d/g,function(c){return '০১২৩৪৫৬৭৮৯'[c];}) + ' টি বই অর্ডারে যোগ হয়েছে। এখন "অর্ডার করুন" চাপলে সব একসাথে আসবে।';
					if (window.jQuery && d.fragments) { jQuery(document.body).trigger('added_to_cart', [d.fragments, d.cart_hash]); }
				}).catch(function(){
					b.disabled = false; b.textContent = '+ সাথে নিন';
					alert('যোগ করা যায়নি, আবার চেষ্টা করুন।');
				});
			});
		})();
		</script>
		<?php
	}
}
