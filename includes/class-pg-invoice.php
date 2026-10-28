<?php
defined( 'ABSPATH' ) || exit;

/**
 * Invoice printing: one invoice per page, paper size chosen in Settings → Invoice.
 * The colour JPG invoice is drawn client-side (assets/js/invoice-jpg.js).
 */
class PG_Invoice {

	public static function init() {
		add_action( 'admin_post_pg_print', array( __CLASS__, 'render' ) );
	}

	public static function print_url( array $ids ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'pg_print',
					'ids'    => implode( ',', array_map( 'absint', $ids ) ),
				),
				admin_url( 'admin-post.php' )
			),
			'pg_print'
		);
	}

	/** Supported paper sizes: width x height in mm, page margin in mm, base font size in pt. */
	public static function sizes() {
		return array(
			'a4'     => array( 'label' => 'A4 (210 × 297 mm)', 'w' => 210, 'h' => 297, 'm' => 12, 'fs' => 10.5 ),
			'a5'     => array( 'label' => 'A5 (148 × 210 mm)', 'w' => 148, 'h' => 210, 'm' => 8, 'fs' => 8.5 ),
			'a6'     => array( 'label' => 'A6 (105 × 148 mm)', 'w' => 105, 'h' => 148, 'm' => 5, 'fs' => 6.8 ),
			'letter' => array( 'label' => 'Letter (8.5 × 11 in)', 'w' => 215.9, 'h' => 279.4, 'm' => 12, 'fs' => 10.5 ),
			'label'  => array( 'label' => 'Label 4 × 6 in (100 × 150 mm)', 'w' => 101.6, 'h' => 152.4, 'm' => 5, 'fs' => 6.8 ),
		);
	}

	/** Invoice designs. */
	public static function templates() {
		return array(
			'classic' => 'Classic — one invoice per page (uses the paper size below)',
			'courier' => 'Courier slip — Office copy + Customer copy on one A4 page',
		);
	}

	/** Company details read automatically from the website. */
	public static function site_info() {
		$s = PG_Settings::all();

		$logo = '';
		if ( get_theme_mod( 'custom_logo' ) ) {
			$logo = wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' );
		}
		if ( ! $logo ) {
			$logo = get_option( 'woocommerce_email_header_image' );
		}
		if ( ! $logo ) {
			$logo = $s['logo_url'];
		}
		if ( ! $logo ) {
			$logo = get_site_icon_url( 512 );
		}

		$phone = '';
		foreach ( array( 'woocommerce_store_phone', 'woocommerce_pos_store_phone' ) as $opt ) {
			$v = trim( (string) get_option( $opt ) );
			if ( $v ) {
				$phone = $v;
				break;
			}
		}
		if ( ! $phone ) {
			$phone = $s['hotline'];
		}

		// Address: WooCommerce → Settings → General → Store address.
		$lines = array_filter(
			array_map(
				'trim',
				array(
					(string) get_option( 'woocommerce_store_address' ),
					(string) get_option( 'woocommerce_store_address_2' ),
					(string) get_option( 'woocommerce_store_city' ),
					(string) get_option( 'woocommerce_store_postcode' ),
				)
			)
		);
		if ( ! $lines && $s['shop_address'] ) {
			$lines = array( $s['shop_address'] );
		}

		return array(
			'logo'    => (string) $logo,
			'company' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'website' => preg_replace( '#^www\.#', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'phone'   => (string) $phone,
			'email'   => (string) ( get_option( 'woocommerce_email_from_address' ) ? get_option( 'woocommerce_email_from_address' ) : get_option( 'admin_email' ) ),
			'address' => implode( ', ', array_unique( $lines ) ),
		);
	}

	/** Final company details: manual values from Settings → Invoice, else the website's own. */
	private static function company() {
		$s    = PG_Settings::all();
		$auto = self::site_info();
		$pick = function ( $k, $a ) use ( $s, $auto ) {
			return '' !== trim( (string) $s[ 'inv_' . $k ] ) ? trim( $s[ 'inv_' . $k ] ) : $auto[ $a ];
		};
		return array(
			'logo'    => $pick( 'logo', 'logo' ),
			'company' => $pick( 'company', 'company' ),
			'website' => $pick( 'website', 'website' ),
			'phone'   => $pick( 'phone', 'phone' ),
			'email'   => $pick( 'email', 'email' ),
			'address' => $pick( 'address', 'address' ),
			'footer'  => trim( (string) $s['invoice_footer'] ),
		);
	}

	private static function money( $n ) {
		return '৳ ' . number_format( (float) $n, 2, '.', ',' );
	}

	/** Customer address lines (billing, falling back to shipping). */
	private static function bill_to( WC_Order $o ) {
		$pick  = function ( $k ) use ( $o ) {
			$b = $o->{'get_billing_' . $k}();
			return $b ? $b : $o->{'get_shipping_' . $k}();
		};
		$state = $pick( 'state' );
		if ( $state ) {
			$country = $pick( 'country' );
			$states  = WC()->countries ? WC()->countries->get_states( $country ? $country : 'BD' ) : array();
			$state   = isset( $states[ $state ] ) ? $states[ $state ] : $state;
		}
		return array_values( array_filter( array_map( 'trim', array( $pick( 'address_1' ), $pick( 'address_2' ), $pick( 'city' ), $state, $pick( 'postcode' ) ) ) ) );
	}

	/**
	 * Exactly one invoice per page (a very long order may continue onto
	 * further pages, but two invoices never share a page).
	 */
	public static function render() {
		if ( ! PG_App::can() ) {
			wp_die( esc_html__( 'Not allowed', 'product-glide' ) );
		}
		check_admin_referer( 'pg_print' );
		$ids = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
		$ids = array_slice( $ids, 0, 300 );

		$sizes = self::sizes();
		$size  = isset( $_GET['size'] ) ? sanitize_key( wp_unslash( $_GET['size'] ) ) : PG_Settings::get( 'inv_page_size' ); // phpcs:ignore
		$size  = isset( $sizes[ $size ] ) ? $size : 'a4';
		$sz    = $sizes[ $size ];
		$small = $sz['w'] < 140;

		$co     = self::company();
		$orders = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( $o && 'shop_order' === $o->get_type() ) {
				$orders[] = $o;
				PG_Team::log( $o->get_id(), 'printed', count( $ids ) > 1 ? 'Bulk print (' . count( $ids ) . ')' : '' );
			}
		}
		$tpls = self::templates();
		$tpl  = isset( $_GET['tpl'] ) ? sanitize_key( wp_unslash( $_GET['tpl'] ) ) : PG_Settings::get( 'inv_template' ); // phpcs:ignore
		$tpl  = isset( $tpls[ $tpl ] ) ? $tpl : 'classic';
		if ( 'courier' === $tpl ) {
			self::render_courier( $orders, $co );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( 'Invoices (%d)', count( $orders ) ) ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url( PG_URL . 'assets/css/print.css?ver=' . PG_VERSION ); ?>">
<style>
@page { size: <?php echo esc_html( $sz['w'] . 'mm ' . $sz['h'] . 'mm' ); ?>; margin: <?php echo esc_html( $sz['m'] . 'mm' ); ?>; }
:root { --pw: <?php echo esc_html( $sz['w'] ); ?>mm; --ph: <?php echo esc_html( $sz['h'] ); ?>mm; --pm: <?php echo esc_html( $sz['m'] ); ?>mm; --fs: <?php echo esc_html( $sz['fs'] ); ?>pt; }
</style>
</head>
<body class="<?php echo $small ? 'is-small' : ''; ?>">
<div class="pg-print-bar">
	<strong><?php echo esc_html( sprintf( '%d invoice(s) · one per page', count( $orders ) ) ); ?></strong>
	<span>
		<?php self::template_select( $tpl ); ?>
		<select onchange="var u=new URL(location.href);u.searchParams.set('size',this.value);location.href=u.toString();">
			<?php foreach ( $sizes as $k => $v ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $size, $k ); ?>><?php echo esc_html( $v['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<button onclick="window.print()">Print</button>
	</span>
</div>
<?php foreach ( $orders as $o ) :
	$created  = $o->get_date_created();
	$phone    = $o->get_billing_phone() ? $o->get_billing_phone() : $o->get_shipping_phone();
	$alt      = $o->get_shipping_phone() && $o->get_shipping_phone() !== $phone ? $o->get_shipping_phone() : '';
	$name     = trim( $o->get_formatted_billing_full_name() ) ? $o->get_formatted_billing_full_name() : $o->get_formatted_shipping_full_name();
	$ship_via = $o->get_shipping_method();
	$i        = 0;
	?>
<section class="inv">
	<header class="inv-top">
		<div class="inv-brand">
			<?php if ( $co['logo'] ) : ?><img class="inv-logo" src="<?php echo esc_url( $co['logo'] ); ?>" alt=""><?php endif; ?>
		</div>
		<div class="inv-head">
			<h1 class="inv-title">INVOICE</h1>
			<div class="inv-no"><b>Invoice no:</b> <?php echo esc_html( $o->get_order_number() ); ?></div>
			<div><b>Order date:</b> <?php echo esc_html( $created ? PG_Helpers::bd_time( $created, 'm-d-Y' ) : '' ); ?></div>
			<div><b>Payment method:</b> <?php echo esc_html( $o->get_payment_method_title() ); ?></div>
		</div>
	</header>

	<div class="inv-mid">
		<div class="inv-bill">
			<b class="inv-lbl">Bill to</b>
			<div class="inv-name"><?php echo esc_html( $name ); ?></div>
			<?php foreach ( self::bill_to( $o ) as $line ) : ?><div><?php echo esc_html( $line ); ?></div><?php endforeach; ?>
			<div class="inv-phone"><?php echo esc_html( $phone ); ?></div>
			<?php if ( $alt ) : ?><div class="inv-phone inv-phone-alt"><?php echo esc_html( $alt ); ?></div><?php endif; ?>
		</div>
		<div class="inv-from">
			<b class="inv-lbl">From</b>
			<?php if ( $co['company'] ) : ?><div class="inv-name"><?php echo esc_html( $co['company'] ); ?></div><?php endif; ?>
			<?php if ( $co['address'] ) : ?><div><?php echo nl2br( esc_html( $co['address'] ) ); ?></div><?php endif; ?>
			<?php if ( $co['phone'] ) : ?><div><?php echo esc_html( $co['phone'] ); ?></div><?php endif; ?>
			<?php if ( $co['website'] ) : ?><div><?php echo esc_html( $co['website'] ); ?></div><?php endif; ?>
		</div>
	</div>

	<?php if ( $o->get_customer_note() ) : ?>
		<div class="inv-note"><b>Customer note:</b> <?php echo esc_html( $o->get_customer_note() ); ?></div>
	<?php endif; ?>

	<table class="inv-items">
		<thead>
			<tr><th class="c-no">#</th><th>Product</th><th class="c-qty">Qty</th><th class="c-r">Unit price</th><th class="c-r">Total</th></tr>
		</thead>
		<tbody>
		<?php foreach ( $o->get_items() as $item ) :
			$i++;
			$qty = max( 1, (int) $item->get_quantity() );
			?>
			<tr>
				<td class="c-no"><?php echo (int) $i; ?></td>
				<td><?php echo esc_html( $item->get_name() ); ?></td>
				<td class="c-qty"><?php echo (int) $item->get_quantity(); ?></td>
				<td class="c-r"><?php echo esc_html( self::money( (float) $item->get_subtotal() / $qty ) ); ?></td>
				<td class="c-r"><?php echo esc_html( self::money( $item->get_subtotal() ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<table class="inv-totals">
		<tr><td>Subtotal</td><td class="c-r"><?php echo esc_html( self::money( $o->get_subtotal() ) ); ?></td></tr>
		<?php if ( (float) $o->get_total_discount() > 0 ) : ?>
			<tr><td>Discount</td><td class="c-r">-<?php echo esc_html( self::money( $o->get_total_discount() ) ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $o->get_fees() as $fee ) : ?>
			<tr><td><?php echo esc_html( $fee->get_name() ); ?></td><td class="c-r"><?php echo esc_html( self::money( $fee->get_total() ) ); ?></td></tr>
		<?php endforeach; ?>
		<tr><td>Shipping</td><td class="c-r"><?php echo esc_html( self::money( $o->get_shipping_total() ) ); ?><?php echo $ship_via ? ' <small>via ' . esc_html( $ship_via ) . '</small>' : ''; ?></td></tr>
		<?php if ( (float) $o->get_total_tax() > 0 ) : ?>
			<tr><td>Tax</td><td class="c-r"><?php echo esc_html( self::money( $o->get_total_tax() ) ); ?></td></tr>
		<?php endif; ?>
		<tr class="grand"><td>Total</td><td class="c-r"><b><?php echo esc_html( self::money( $o->get_total() ) ); ?></b></td></tr>
	</table>
	<?php if ( $co['footer'] ) : ?><footer class="inv-footer"><?php echo nl2br( esc_html( $co['footer'] ) ); ?></footer><?php endif; ?>
</section>
<?php endforeach; ?>
<script>
(function () {
	var go = function () { setTimeout(function () { window.print(); }, 300); };
	var imgs = Array.prototype.slice.call(document.images);
	var wait = Promise.all(imgs.map(function (im) { return im.complete ? 1 : new Promise(function (r) { im.onload = im.onerror = r; }); }));
	Promise.all([wait, document.fonts && document.fonts.ready ? document.fonts.ready : 1]).then(go);
})();
</script>
</body>
</html>
		<?php
		exit;
	}

	private static function template_select( $current ) {
		echo '<select onchange="var u=new URL(location.href);u.searchParams.set(\'tpl\',this.value);location.href=u.toString();" aria-label="Invoice design">';
		foreach ( array( 'classic' => 'Classic invoice', 'courier' => 'Courier slip (2 copies)' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $current, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/** ৳1,050 (no decimals unless needed). */
	private static function tk( $n ) {
		$n = (float) $n;
		return '৳' . number_format( $n, abs( $n - round( $n ) ) > 0.001 ? 2 : 0, '.', ',' );
	}

	/**
	 * Courier slip: two identical copies (Office / Customer) on one A4 sheet with a
	 * cut line between them. Every order starts on a new sheet.
	 */
	private static function render_courier( array $orders, array $co ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( 'Courier slips (%d)', count( $orders ) ) ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url( PG_URL . 'assets/css/print.css?ver=' . PG_VERSION ); ?>">
<style>@page { size: 210mm 297mm; margin: 0; }</style>
</head>
<body class="cs-body">
<div class="pg-print-bar">
	<strong><?php echo esc_html( sprintf( '%d order(s) · office + customer copy on each A4 page', count( $orders ) ) ); ?></strong>
	<span>
		<?php self::template_select( 'courier' ); ?>
		<button onclick="window.print()">Print</button>
	</span>
</div>
<?php
foreach ( $orders as $o ) :
	$created = $o->get_date_created();
	$phone   = $o->get_billing_phone() ? $o->get_billing_phone() : $o->get_shipping_phone();
	$alt     = $o->get_shipping_phone() && $o->get_shipping_phone() !== $phone ? $o->get_shipping_phone() : '';
	$name    = trim( $o->get_formatted_billing_full_name() ) ? $o->get_formatted_billing_full_name() : $o->get_formatted_shipping_full_name();
	$address = implode( ', ', self::bill_to( $o ) );
	$cons    = class_exists( 'PG_Steadfast' ) ? PG_Steadfast::active( $o->get_id() ) : null;
	$cid     = $cons && 'cancelled' !== $cons->courier_status ? $cons->consignment_id : '';
	$cod     = $cid ? (float) $cons->cod_amount : ( class_exists( 'PG_Steadfast' ) ? PG_Steadfast::default_cod( $o ) : (float) $o->get_total() );
	?>
<section class="cs-page<?php echo count( $o->get_items() ) + count( $o->get_fees() ) + ( (float) $o->get_total_discount() > 0 ? 1 : 0 ) + ( $o->get_customer_note() ? 1 : 0 ) > 4 ? ' cs-long' : ''; ?>">
	<?php foreach ( array( 'Office Copy', 'Customer Copy' ) as $n => $copy ) : ?>
	<?php if ( $n ) : ?><div class="cs-cut"><span>✂ Scissors Cut Here</span></div><?php endif; ?>
	<div class="cs-copy">
		<header class="cs-top">
			<div class="cs-logo"><?php if ( $co['logo'] ) : ?><img src="<?php echo esc_url( $co['logo'] ); ?>" alt=""><?php endif; ?></div>
			<div class="cs-head">
				<span class="cs-tag"><?php echo esc_html( $copy ); ?></span>
				<h1>INVOICE</h1>
				<div>Invoice No: #<?php echo esc_html( $o->get_order_number() ); ?></div>
				<div>Date: <?php echo esc_html( $created ? PG_Helpers::bd_time( $created, 'd-m-y' ) : '' ); ?></div>
			</div>
		</header>

		<div class="cs-parties">
			<div class="cs-to">
				<b>Invoice To:</b>
				<div><?php echo esc_html( $name ); ?></div>
				<div><?php echo esc_html( $address ); ?></div>
				<div class="cs-phone"><?php echo esc_html( $phone ); ?><?php echo $alt ? ' / ' . esc_html( $alt ) : ''; ?></div>
			</div>
			<div class="cs-from">
				<b>Pay To:</b>
				<?php if ( $co['company'] ) : ?><div><?php echo esc_html( $co['company'] ); ?></div><?php endif; ?>
				<?php if ( $co['address'] ) : ?><div><?php echo esc_html( preg_replace( '/\s*\n\s*/', ', ', $co['address'] ) ); ?></div><?php endif; ?>
				<?php if ( $co['phone'] ) : ?><div><?php echo esc_html( $co['phone'] ); ?></div><?php endif; ?>
				<?php if ( ! empty( $co['email'] ) ) : ?><div><?php echo esc_html( $co['email'] ); ?></div><?php endif; ?>
			</div>
		</div>

		<div class="cs-courier">
			<div class="cs-courier-row">
				<b class="cs-courier-name">For SteadFast Courier LTD</b>
				<span class="cs-courier-brand"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Zm9 2.2 6.2-3.1L12 3.5 5.8 6.6 12 9.7Zm-7.6-2v8.1l6.9 3.5v-8.1L4.4 7.7Z"/></svg>steadfast</span>
			</div>
			<div class="cs-courier-row cs-courier-big">
				<span>CN ID: <?php echo $cid ? '#' . esc_html( $cid ) : '<i>not booked yet</i>'; ?></span>
				<span>COD Amount: <?php echo esc_html( self::tk( $cod ) ); ?></span>
			</div>
		</div>

		<table class="cs-items">
			<thead><tr><th>Item</th><th class="cs-c-price">Price</th><th class="cs-c-qty">Qty</th><th class="cs-r">Total</th></tr></thead>
			<tbody>
			<?php foreach ( $o->get_items() as $item ) :
				$qty = max( 1, (int) $item->get_quantity() );
				?>
				<tr>
					<td><?php echo esc_html( $item->get_name() ); ?></td>
					<td class="cs-c-price"><?php echo esc_html( self::tk( (float) $item->get_subtotal() / $qty ) ); ?></td>
					<td class="cs-c-qty"><?php echo (int) $item->get_quantity(); ?></td>
					<td class="cs-r"><?php echo esc_html( self::tk( $item->get_subtotal() ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<div class="cs-bottom">
			<div class="cs-pay">
				<b>Payment info:</b>
				<div><?php echo esc_html( $o->get_payment_method_title() ? $o->get_payment_method_title() : 'Cash on delivery' ); ?></div>
				<?php if ( $o->get_customer_note() ) : ?><div class="cs-note"><b>Note:</b> <?php echo esc_html( $o->get_customer_note() ); ?></div><?php endif; ?>
			</div>
			<table class="cs-totals">
				<tr><td>Subtotal</td><td class="cs-r"><?php echo esc_html( self::tk( $o->get_subtotal() ) ); ?></td></tr>
				<?php if ( (float) $o->get_total_discount() > 0 ) : ?><tr><td>Discount</td><td class="cs-r">-<?php echo esc_html( self::tk( $o->get_total_discount() ) ); ?></td></tr><?php endif; ?>
				<?php foreach ( $o->get_fees() as $fee ) : ?><tr><td><?php echo esc_html( $fee->get_name() ); ?></td><td class="cs-r"><?php echo esc_html( self::tk( $fee->get_total() ) ); ?></td></tr><?php endforeach; ?>
				<tr><td>Shipping</td><td class="cs-r"><?php echo esc_html( self::tk( $o->get_shipping_total() ) ); ?></td></tr>
				<tr class="cs-grand"><td>Grand Total</td><td class="cs-r"><?php echo esc_html( self::tk( $o->get_total() ) ); ?></td></tr>
			</table>
		</div>
	</div>
	<?php endforeach; ?>
</section>
<?php endforeach; ?>
<script>
(function () {
	var go = function () { setTimeout(function () { window.print(); }, 300); };
	var imgs = Array.prototype.slice.call(document.images);
	var wait = Promise.all(imgs.map(function (im) { return im.complete ? 1 : new Promise(function (r) { im.onload = im.onerror = r; }); }));
	Promise.all([wait, document.fonts && document.fonts.ready ? document.fonts.ready : 1]).then(go);
})();
</script>
</body>
</html>
		<?php
	}
}
