<?php
/**
 * Plugin Name:       Product Glide - All-in-One WooCommerce Courier & Order Hub
 * Description:       Fast, mobile-friendly order dashboard with Steadfast and Carrybee courier, fraud check, invoice printing, package builder, WhatsApp Cloud API, profit tracking and screenshot order creator. Runs natively inside WordPress (no external REST round-trips for order work).
 * Version:           1.20.0
 * Author:            Md Sajedul Islam
 * Text Domain:       product-glide
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'PG_VERSION', '1.20.0' );
define( 'PG_DB_VERSION', '1.4.0' );
define( 'PG_FILE', __FILE__ );
define( 'PG_DIR', plugin_dir_path( __FILE__ ) );
define( 'PG_URL', plugin_dir_url( __FILE__ ) );

require_once PG_DIR . 'includes/class-pg-helpers.php';
require_once PG_DIR . 'includes/class-pg-install.php';
require_once PG_DIR . 'includes/class-pg-settings.php';
require_once PG_DIR . 'includes/class-pg-statuses.php';
require_once PG_DIR . 'includes/class-pg-steadfast-api.php';
require_once PG_DIR . 'includes/class-pg-steadfast.php';
require_once PG_DIR . 'includes/class-pg-carrybee-api.php';
require_once PG_DIR . 'includes/class-pg-carrybee.php';
require_once PG_DIR . 'includes/class-pg-courier.php';
require_once PG_DIR . 'includes/class-pg-sf-pickup.php';
require_once PG_DIR . 'includes/class-pg-reschedule.php';
require_once PG_DIR . 'includes/class-pg-fraud.php';
require_once PG_DIR . 'includes/class-pg-orders.php';
require_once PG_DIR . 'includes/class-pg-invoice.php';
require_once PG_DIR . 'includes/class-pg-packages.php';
require_once PG_DIR . 'includes/class-pg-whatsapp.php';
require_once PG_DIR . 'includes/class-pg-profit.php';
require_once PG_DIR . 'includes/class-pg-ocr.php';
require_once PG_DIR . 'includes/class-pg-geo.php';
require_once PG_DIR . 'includes/class-pg-address.php';
require_once PG_DIR . 'includes/class-pg-thana-finder.php';
require_once PG_DIR . 'includes/class-pg-wa-inbox.php';
require_once PG_DIR . 'includes/class-pg-wa-setup.php';
require_once PG_DIR . 'includes/class-pg-confirm.php';
require_once PG_DIR . 'includes/class-pg-upsell.php';
require_once PG_DIR . 'includes/class-pg-cart.php';
require_once PG_DIR . 'includes/class-pg-product-page.php';
require_once PG_DIR . 'includes/class-pg-admin.php';
require_once PG_DIR . 'includes/class-pg-app.php';
require_once PG_DIR . 'includes/class-pg-team.php';
require_once PG_DIR . 'includes/class-pg-leads.php';
require_once PG_DIR . 'includes/class-pg-guard.php';
require_once PG_DIR . 'includes/class-pg-updater.php';

register_activation_hook( __FILE__, array( 'PG_Install', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'pg_sf_sync_cron' );
		wp_clear_scheduled_hook( 'pg_sf_pickup_cron' );
		wp_clear_scheduled_hook( 'pg_leads_cleanup' );
	}
);

// HPOS + cart/checkout blocks compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Updates from GitHub work even if WooCommerce is switched off.
add_action( 'plugins_loaded', array( 'PG_Updater', 'init' ), 5 );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Product Glide needs WooCommerce to be active.', 'product-glide' ) . '</p></div>';
				}
			);
			return;
		}
		PG_App::init();
		PG_Install::maybe_upgrade();
		if ( is_admin() || wp_doing_ajax() ) {
			PG_Orders::repair_note_authors();
		}
		PG_Statuses::init();
		PG_Steadfast::init();
		PG_Carrybee::init();
		PG_SF_Pickup::init();
		PG_Reschedule::init();
		PG_Fraud::init();
		PG_Orders::init();
		PG_Invoice::init();
		PG_Packages::init();
		PG_WhatsApp::init();
		PG_Profit::init();
		PG_OCR::init();
		PG_Address::init();
		PG_Thana_Finder::init();
		PG_WA_Inbox::init();
		PG_WA_Setup::init();
		PG_Confirm::init();
		PG_Upsell::init();
		PG_Cart::init();
		PG_Product_Page::init();
		PG_Settings::init();
		PG_Admin::init();
		PG_Team::init();
		PG_Leads::init();
		PG_Guard::init();
	},
	20
);
