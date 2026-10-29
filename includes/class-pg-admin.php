<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, assets and page shells (pages are rendered by JS).
 */
class PG_Admin {

	const PAGES = array( 'product-glide', 'pg-leads', 'pg-packages', 'pg-ocr', 'pg-profit', 'pg-steadfast', 'pg-team', 'pg-settings' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PG_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function menu() {
		$cap = PG_App::CAP;
		add_menu_page( 'Product Glide', 'Product Glide', $cap, 'product-glide', array( __CLASS__, 'page_orders' ), 'dashicons-airplane', 56 );
		add_submenu_page( 'product-glide', __( 'Orders', 'product-glide' ), __( 'Orders', 'product-glide' ), $cap, 'product-glide', array( __CLASS__, 'page_orders' ) );
		$n = PG_Leads::enabled() ? (int) PG_Leads::badge_count() : 0;
		add_submenu_page( 'product-glide', __( 'Incomplete Orders', 'product-glide' ), __( 'Incomplete Orders', 'product-glide' ) . ( $n ? ' <span class="awaiting-mod">' . $n . '</span>' : '' ), $cap, 'pg-leads', array( __CLASS__, 'page_leads' ) );
		add_submenu_page( 'product-glide', __( 'Package Builder', 'product-glide' ), __( 'Package Builder', 'product-glide' ), $cap, 'pg-packages', array( __CLASS__, 'page_packages' ) );
		add_submenu_page( 'product-glide', __( 'Screenshot Order', 'product-glide' ), __( 'Screenshot Order', 'product-glide' ), $cap, 'pg-ocr', array( __CLASS__, 'page_ocr' ) );
		add_submenu_page( 'product-glide', __( 'Profit & Stock', 'product-glide' ), __( 'Profit & Stock', 'product-glide' ), 'manage_options', 'pg-profit', array( __CLASS__, 'page_profit' ) );
		add_submenu_page( 'product-glide', __( 'Team', 'product-glide' ), __( 'Team', 'product-glide' ), 'manage_options', 'pg-team', array( __CLASS__, 'page_team' ) );
		add_submenu_page( 'product-glide', __( 'Steadfast Tools', 'product-glide' ), __( 'Steadfast Tools', 'product-glide' ), 'manage_options', 'pg-steadfast', array( __CLASS__, 'page_steadfast' ) );
		add_submenu_page( 'product-glide', __( 'Settings', 'product-glide' ), __( 'Settings', 'product-glide' ), 'manage_options', 'pg-settings', array( 'PG_Settings', 'render' ) );
		add_submenu_page( 'product-glide', __( 'Open Order Hub', 'product-glide' ), __( 'Open Order Hub ↗', 'product-glide' ), $cap, 'pg-open-hub', '__return_null' );
		global $submenu;
		if ( isset( $submenu['product-glide'] ) ) {
			foreach ( $submenu['product-glide'] as &$item ) {
				if ( 'pg-open-hub' === $item[2] ) {
					$item[2] = PG_App::url();
				}
			}
		}
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pg-settings' ) ) . '">' . esc_html__( 'Settings', 'product-glide' ) . '</a>' );
		return $links;
	}

	private static function current_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
	}

	public static function body_class( $classes ) {
		if ( in_array( self::current_page(), self::PAGES, true ) ) {
			$classes .= ' pg-app';
		}
		return $classes;
	}

	public static function assets() {
		$page = self::current_page();
		if ( in_array( $page, self::PAGES, true ) ) {
			self::enqueue( $page, 'admin' );
		}
	}

	public static function enqueue_styles_only() {
		wp_register_style( 'pg-fonts', 'https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;600;700;800&display=swap', array(), null ); // phpcs:ignore
		wp_register_style( 'pg-admin', PG_URL . 'assets/css/admin.css', array(), PG_VERSION );
	}

	/** JS config shared by wp-admin pages and the front-end Order Hub. */
	public static function config( $context = 'admin' ) {
		$s    = PG_Settings::all();
		$logo = $s['logo_url'];
		if ( ! $logo && has_custom_logo() ) {
			$logo = wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'medium' );
		}
		$statuses = array();
		foreach ( wc_get_order_statuses() as $k => $l ) {
			$statuses[ substr( $k, 3 ) ] = $l;
		}
		$front = 'front' === $context;
		return array(
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'pg_ajax' ),
			'printBase'   => PG_Invoice::print_url( array() ),
			'statuses'    => $statuses,
			'tabs'        => PG_Orders::tabs(),
			'quickNotes'  => PG_Orders::quick_notes(),
			'currency'    => html_entity_decode( get_woocommerce_currency_symbol() ),
			'brand'       => array(
				'name'    => $s['brand_name'],
				'logo'    => $logo ? $logo : '',
				'hotline' => $s['hotline'],
				'address' => $s['shop_address'],
				'footer'  => $s['invoice_footer'],
				'site'    => wp_parse_url( home_url(), PHP_URL_HOST ),
			),
			'sfReady'     => PG_Steadfast_API::configured(),
			'waReady'     => PG_WhatsApp::configured(),
			'fraudAuto'   => (bool) $s['fraud_auto'],
			'pkgDelivery' => (float) $s['pkg_delivery_charge'],
			'fraudPanel'  => PG_Fraud::PANEL_URL,
			'trackUrl'    => PG_Steadfast::TRACK_URL,
			'adminUrl'    => admin_url(),
			'isAdmin'     => current_user_can( 'manage_options' ),
			'canWoo'      => current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ),
			'front'       => $front,
			'ordersUrl'   => $front ? PG_App::url() : admin_url( 'admin.php?page=product-glide' ),
			'newOrderUrl' => $front ? PG_App::url( 'new' ) : admin_url( 'admin.php?page=pg-ocr' ),
		);
	}

	/**
	 * Enqueue the assets for one dashboard page. Returns the script handles so the
	 * front-end Order Hub can print exactly these (and nothing from the theme).
	 */
	public static function enqueue( $page, $context = 'admin' ) {
		$v = PG_VERSION;
		self::enqueue_styles_only();
		wp_enqueue_style( 'pg-fonts' );
		wp_enqueue_style( 'pg-admin' );
		wp_enqueue_script( 'pg-app', PG_URL . 'assets/js/app.js', array(), $v, true );
		wp_localize_script( 'pg-app', 'PG', self::config( $context ) );
		$handles = array( 'pg-app' );

		switch ( $page ) {
			case 'product-glide':
				wp_enqueue_script( 'jsbarcode', 'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js', array(), '3.11.6', true );
				wp_enqueue_script( 'pg-invoice-jpg', PG_URL . 'assets/js/invoice-jpg.js', array( 'pg-app', 'jsbarcode' ), $v, true );
				wp_enqueue_script( 'pg-order-form', PG_URL . 'assets/js/order-form.js', array( 'pg-app' ), $v, true );
				wp_enqueue_script( 'pg-product-picker', PG_URL . 'assets/js/product-picker.js', array( 'pg-app' ), $v, true );
				wp_enqueue_script( 'pg-ocr', PG_URL . 'assets/js/ocr.js', array( 'pg-app', 'pg-order-form', 'pg-product-picker' ), $v, true );
				wp_enqueue_script( 'pg-orders', PG_URL . 'assets/js/orders.js', array( 'pg-app', 'pg-invoice-jpg', 'pg-ocr', 'pg-product-picker' ), $v, true );
				$handles = array( 'jsbarcode', 'pg-app', 'pg-invoice-jpg', 'pg-order-form', 'pg-product-picker', 'pg-ocr', 'pg-orders' );
				break;
			case 'pg-leads':
				wp_enqueue_script( 'pg-product-picker', PG_URL . 'assets/js/product-picker.js', array( 'pg-app' ), $v, true );
				wp_enqueue_script( 'pg-leads', PG_URL . 'assets/js/leads.js', array( 'pg-app', 'pg-product-picker' ), $v, true );
				wp_localize_script( 'pg-leads', 'PG_LEADS', array( 'tabs' => PG_Leads::tabs(), 'idle' => PG_Leads::idle_minutes(), 'enabled' => PG_Leads::enabled(), 'settingsUrl' => admin_url( 'admin.php?page=pg-settings&tab=leads' ) ) );
				$handles = array( 'pg-app', 'pg-product-picker', 'pg-leads' );
				break;
			case 'pg-packages':
				wp_enqueue_script( 'pg-order-form', PG_URL . 'assets/js/order-form.js', array( 'pg-app' ), $v, true );
				wp_enqueue_script( 'pg-packages', PG_URL . 'assets/js/packages.js', array( 'pg-app', 'pg-order-form' ), $v, true );
				wp_localize_script( 'pg-packages', 'PG_PKGS', array_values( PG_Packages::saved() ) );
				$handles = array( 'pg-app', 'pg-order-form', 'pg-packages' );
				break;
			case 'pg-ocr':
				wp_enqueue_script( 'pg-order-form', PG_URL . 'assets/js/order-form.js', array( 'pg-app' ), $v, true );
				wp_enqueue_script( 'pg-product-picker', PG_URL . 'assets/js/product-picker.js', array( 'pg-app' ), $v, true );
				wp_enqueue_script( 'pg-ocr', PG_URL . 'assets/js/ocr.js', array( 'pg-app', 'pg-order-form', 'pg-product-picker' ), $v, true );
				$handles = array( 'pg-app', 'pg-order-form', 'pg-product-picker', 'pg-ocr' );
				break;
			case 'pg-profit':
				wp_enqueue_script( 'pg-profit', PG_URL . 'assets/js/profit.js', array( 'pg-app' ), $v, true );
				$handles = array( 'pg-app', 'pg-profit' );
				break;
			case 'pg-steadfast':
				wp_enqueue_script( 'pg-steadfast', PG_URL . 'assets/js/steadfast.js', array( 'pg-app' ), $v, true );
				$handles = array( 'pg-app', 'pg-steadfast' );
				break;
			case 'pg-team':
				wp_enqueue_script( 'pg-team', PG_URL . 'assets/js/team.js', array( 'pg-app' ), $v, true );
				$handles = array( 'pg-app', 'pg-team' );
				break;
			case 'pg-settings':
				wp_enqueue_media();
				wp_enqueue_script( 'pg-settings', PG_URL . 'assets/js/settings.js', array( 'pg-app', 'jquery' ), $v, true );
				$handles = array( 'pg-app', 'pg-settings' );
				break;
		}
		return $handles;
	}

	private static function header( $title, $extra = '' ) {
		echo '<div class="pg-header"><h1>' . esc_html( $title ) . '</h1>' . $extra . '</div>'; // phpcs:ignore -- $extra is static markup.
	}

	public static function page_orders() {
		echo '<div class="pg-wrap" id="pg-orders">';
		self::header(
			__( 'Orders', 'product-glide' ),
			'<div class="pg-header-actions"><button type="button" class="pg-btn pg-btn-primary" id="pg-new-order">+ ' . esc_html__( 'New order', 'product-glide' ) . '</button></div>'
		);
		?>
		<div class="pg-kpis" id="pg-kpis">
			<div class="pg-kpi"><span><?php esc_html_e( 'Sent today', 'product-glide' ); ?></span><b data-k="sent">–</b></div>
			<div class="pg-kpi pg-kpi-ok"><span><?php esc_html_e( 'Delivered today', 'product-glide' ); ?></span><b data-k="delivered">–</b></div>
			<div class="pg-kpi pg-kpi-bad"><span><?php esc_html_e( 'Cancelled today', 'product-glide' ); ?></span><b data-k="cancelled">–</b></div>
			<div class="pg-kpi pg-kpi-warn"><span><?php esc_html_e( 'In transit', 'product-glide' ); ?></span><b data-k="transit">–</b></div>
		</div>
		<div class="pg-toolbar">
			<input type="search" id="pg-q" placeholder="<?php esc_attr_e( 'Search order #, phone or name…', 'product-glide' ); ?>" autocomplete="off">
			<select id="pg-per-page"><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option></select>
			<button type="button" class="pg-btn" id="pg-sync" title="<?php esc_attr_e( 'Fetch the latest status of all parcels in transit from Steadfast', 'product-glide' ); ?>">&#8635; <?php esc_html_e( 'Sync courier', 'product-glide' ); ?></button>
		</div>
		<?php
		$last = get_option( 'pg_sf_last_sync' );
		if ( is_array( $last ) && ! empty( $last['time'] ) ) {
			echo '<p class="pg-muted pg-sync-note" id="pg-sync-note">' . esc_html( sprintf( /* translators: time */ __( 'Courier status last synced: %s (auto-sync every 30 min)', 'product-glide' ), PG_Helpers::bd_time( (int) $last['time'], 'd M, h:i A' ) ) ) . '</p>';
		} else {
			echo '<p class="pg-muted pg-sync-note" id="pg-sync-note">' . esc_html__( 'Courier status auto-syncs every 30 min. Tap Sync courier to update now.', 'product-glide' ) . '</p>';
		}
		?>
		<div class="pg-status-tabs" id="pg-status-tabs"></div>
		<div class="pg-selectbar" id="pg-selectbar" hidden>
			<label class="pg-check"><input type="checkbox" id="pg-select-all"> <span id="pg-sel-count">0</span> <?php esc_html_e( 'selected', 'product-glide' ); ?></label>
		</div>
		<div id="pg-list" class="pg-list"></div>
		<div id="pg-pager" class="pg-pager"></div>
		<div class="pg-bottom-bar" id="pg-bottom-bar" hidden>
			<span id="pg-bottom-count"></span>
			<button class="pg-btn pg-btn-ghost" data-bulk="clear"><?php esc_html_e( 'Clear', 'product-glide' ); ?></button>
			<button class="pg-btn pg-btn-ghost" data-bulk="sf"><?php esc_html_e( 'Send to Steadfast', 'product-glide' ); ?></button>
			<button class="pg-btn pg-btn-primary" data-bulk="print"><?php esc_html_e( 'Print Selected Invoices', 'product-glide' ); ?></button>
		</div>
		<?php
		echo '</div>';
	}

	public static function page_leads() {
		echo '<div class="pg-wrap" id="pg-leads">';
		self::header( __( 'Incomplete Orders', 'product-glide' ) );
		echo '<div id="pg-leads-root"></div></div>';
	}

	public static function page_packages() {
		echo '<div class="pg-wrap" id="pg-packages">';
		self::header( __( 'Package Builder', 'product-glide' ) );
		echo '<div id="pg-pkg-root"></div></div>';
	}

	public static function page_ocr() {
		echo '<div class="pg-wrap" id="pg-ocr">';
		self::header( __( 'New Order from Screenshot', 'product-glide' ) );
		echo '<div id="pg-ocr-root"></div></div>';
	}

	public static function page_profit() {
		echo '<div class="pg-wrap" id="pg-profit">';
		self::header( __( 'Profit & Stock', 'product-glide' ) );
		echo '<div id="pg-profit-root"></div></div>';
	}

	public static function page_team() {
		echo '<div class="pg-wrap" id="pg-team">';
		self::header( __( 'Team performance', 'product-glide' ) );
		echo '<div id="pg-team-root"></div></div>';
	}

	public static function page_steadfast() {
		echo '<div class="pg-wrap" id="pg-sf">';
		self::header( __( 'Steadfast Tools', 'product-glide' ) );
		echo '<div id="pg-sf-root"></div></div>';
	}
}
