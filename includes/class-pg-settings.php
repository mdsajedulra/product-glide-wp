<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings (single option `pg_settings`).
 */
class PG_Settings {

	const SECRET_KEYS = array( 'sf_api_key', 'sf_secret_key', 'wa_token', 'or_key', 'cb_client_secret', 'cb_client_context' );

	public static function init() {
		add_action( 'admin_post_pg_save_settings', array( __CLASS__, 'save' ) );
	}

	public static function defaults() {
		return array(
			// General.
			'brand_name'          => get_bloginfo( 'name' ),
			'logo_url'            => '',
			'hotline'             => '01516549037',
			'shop_address'        => '',
			'items_per_slip'      => 3,
			'invoice_footer'      => 'ধন্যবাদ! আমাদের সাথে কেনাকাটার জন্য।',
			'pkg_delivery_charge' => 60,
			// Invoice (blank = taken from the website automatically).
			'inv_page_size'       => 'a4',
			'inv_template'        => 'classic',
			// Updates (GitHub releases).
			'gh_repo'             => 'mdsajedulra/product-glide-wp',
			'inv_email'           => '',
			'inv_logo'            => '',
			'inv_company'         => '',
			'inv_website'         => '',
			'inv_phone'           => '',
			'inv_address'         => '',
			'app_slug'            => 'order-hub',
			// Steadfast.
			'sf_api_key'          => '',
			'sf_secret_key'       => '',
			'sf_base_url'         => 'https://portal.packzy.com/api/v1',
			'sf_webhook_token'    => '',
			'sf_default_note'     => '',
			'sf_item_desc'        => 1,
			'sf_pickup_auto'      => 0,
			'sf_pickup_time'      => '15:00',
			'sf_pickup_days'      => array( 0, 1, 2, 3, 4, 5, 6 ),
			'sf_pickup_min'       => 1,
			'sf_pickup_address_id' => 0,
			'sf_pickup_ps_id'     => 0,
			'sf_pickup_address'   => '',
			'sf_pickup_phone'     => '',
			'sf_pickup_note'      => '',
			// Carrybee.
			'cb_env'              => 'production',
			'cb_client_id'        => '',
			'cb_client_secret'    => '',
			'cb_client_context'   => '',
			'cb_store_id'         => '',
			'cb_delivery_type'    => 1,
			'cb_product_type'     => 1,
			'cb_item_weight'      => 500,
			'cb_auto_area'        => 1,
			'cb_default_note'     => '',
			'cb_webhook_secret'   => '',
			'default_courier'     => 'steadfast',
			'sf_enabled'          => 1,
			'cb_enabled'          => 1,
			// Fraud.
			'fraud_auto'          => 1,
			'fraud_cache_hours'   => 24,
			// WhatsApp.
			'wa_phone_id'         => '',
			'wa_token'            => '',
			'wa_waba_id'          => '',
			'wa_lang'             => 'bn',
			'wa_api_version'      => 'v21.0',
			'wa_auto'             => 0,
			// AI / OCR (OpenRouter).
			'or_key'              => '',
			'or_model'            => '',
			// Incomplete orders + checkout guard.
			'lead_enabled'        => 1,
			'lead_minutes'        => 10,
			'lead_keep_days'      => 365,
			'guard_bd_phone'      => 1,
			'block_repeat'        => 0,
			'block_max'           => 1,
			'block_hours'         => 24,
			'block_message'       => 'আপনি %order_date% %order_time% সময়ে একটি অর্ডার করেছেন (অর্ডার নম্বর %order_number%, অবস্থা: %order_status%)। একই নম্বর থেকে %hours% ঘণ্টার মধ্যে আবার অর্ডার করা যাচ্ছে না। নতুন কিছু লাগলে কল করুন: %hotline%',
			'blocklist'           => '',
			'block_list_message'  => 'দুঃখিত, এই নম্বর থেকে এই মুহূর্তে অর্ডার নেওয়া যাচ্ছে না। অর্ডার করতে কল করুন: %hotline%',
			// Profit.
			'fallback_delivery'   => 70,
			'fallback_return'     => 70,
			'profit_statuses'     => array( 'completed', 'partial_delivered' ),
		);
	}

	public static function all() {
		static $cache = null;
		if ( null === $cache ) {
			$cache = wp_parse_args( (array) get_option( 'pg_settings', array() ), self::defaults() );
		}
		return $cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return isset( $all[ $key ] ) && '' !== $all[ $key ] ? $all[ $key ] : ( null === $default && isset( self::defaults()[ $key ] ) ? self::defaults()[ $key ] : $default );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed', 'product-glide' ) );
		}
		check_admin_referer( 'pg_save_settings' );

		$old  = (array) get_option( 'pg_settings', array() );
		$new  = $old;
		$defs = self::defaults();
		$in   = wp_unslash( $_POST ); // phpcs:ignore

		$text_keys = array( 'brand_name', 'logo_url', 'hotline', 'sf_base_url', 'sf_default_note', 'wa_phone_id', 'wa_waba_id', 'wa_lang', 'wa_api_version', 'or_model', 'inv_logo', 'inv_company', 'inv_website', 'inv_phone', 'inv_email', 'gh_repo', 'cb_client_id', 'cb_store_id', 'cb_default_note', 'cb_webhook_secret', 'sf_pickup_address', 'sf_pickup_phone', 'sf_pickup_note' );
		foreach ( $text_keys as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = in_array( $k, array( 'logo_url', 'sf_base_url', 'inv_logo' ), true ) ? esc_url_raw( trim( $in[ $k ] ) ) : sanitize_text_field( $in[ $k ] );
			}
		}
		if ( isset( $in['inv_template'] ) ) {
			$tpl                 = sanitize_key( $in['inv_template'] );
			$new['inv_template'] = isset( PG_Invoice::templates()[ $tpl ] ) ? $tpl : 'classic';
		}
		if ( isset( $in['cb_env'] ) ) {
			$new['cb_env'] = 'sandbox' === $in['cb_env'] ? 'sandbox' : 'production';
		}
		foreach ( array( 'cb_delivery_type' => array( 1, 2 ), 'cb_product_type' => array( 1, 2, 3 ) ) as $k => $ok ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = in_array( (int) $in[ $k ], $ok, true ) ? (int) $in[ $k ] : $ok[0];
			}
		}
		if ( isset( $in['default_courier'] ) ) {
			$new['default_courier'] = isset( PG_Courier::all()[ $in['default_courier'] ] ) ? $in['default_courier'] : 'steadfast';
		}
		if ( isset( $in['inv_page_size'] ) ) {
			$size                 = sanitize_key( $in['inv_page_size'] );
			$new['inv_page_size'] = isset( PG_Invoice::sizes()[ $size ] ) ? $size : 'a4';
		}
		foreach ( array( 'shop_address', 'invoice_footer', 'inv_address', 'block_message', 'blocklist', 'block_list_message' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = sanitize_textarea_field( $in[ $k ] );
			}
		}
		foreach ( array( 'items_per_slip', 'fraud_cache_hours', 'lead_minutes', 'lead_keep_days', 'block_max', 'block_hours', 'cb_item_weight' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = max( 1, absint( $in[ $k ] ) );
			}
		}
		foreach ( array( 'pkg_delivery_charge', 'fallback_delivery', 'fallback_return' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = max( 0, (float) $in[ $k ] );
			}
		}
		if ( isset( $in['app_slug'] ) ) {
			$slug            = sanitize_title( $in['app_slug'] );
			$new['app_slug'] = $slug ? $slug : 'order-hub';
			if ( ( isset( $old['app_slug'] ) ? $old['app_slug'] : 'order-hub' ) !== $new['app_slug'] ) {
				update_option( 'pg_flush_rewrite', 1, false );
			}
		}
		foreach ( array( 'sf_pickup_min', 'sf_pickup_address_id', 'sf_pickup_ps_id' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = absint( $in[ $k ] );
			}
		}
		if ( isset( $in['sf_pickup_time'] ) ) {
			$new['sf_pickup_time'] = preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', trim( $in['sf_pickup_time'] ) ) ? trim( $in['sf_pickup_time'] ) : '15:00';
		}
		$section = isset( $in['pg_section'] ) ? sanitize_key( $in['pg_section'] ) : '';
		if ( 'steadfast' === $section ) {
			$new['sf_pickup_days'] = isset( $in['sf_pickup_days'] ) ? array_values( array_intersect( array( 0, 1, 2, 3, 4, 5, 6 ), array_map( 'intval', (array) $in['sf_pickup_days'] ) ) ) : array();
		}
		$checkbox_by_section = array(
			'steadfast' => array( 'sf_item_desc', 'sf_pickup_auto' ),
			'fraud'     => array( 'fraud_auto' ),
			'whatsapp'  => array( 'wa_auto' ),
			'leads'     => array( 'lead_enabled', 'guard_bd_phone', 'block_repeat' ),
			'carrybee'  => array( 'cb_auto_area' ),
			'general'   => array( 'sf_enabled', 'cb_enabled' ),
		);
		if ( isset( $checkbox_by_section[ $section ] ) ) {
			foreach ( $checkbox_by_section[ $section ] as $k ) {
				$new[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
		}
		if ( 'profit' === $section ) {
			$new['profit_statuses'] = isset( $in['profit_statuses'] ) ? array_map( 'sanitize_key', (array) $in['profit_statuses'] ) : array();
		}
		foreach ( self::SECRET_KEYS as $k ) {
			if ( isset( $in[ $k ] ) && '' !== trim( $in[ $k ] ) ) {
				$new[ $k ] = sanitize_text_field( trim( $in[ $k ] ) );
			}
			if ( ! empty( $in[ 'clear_' . $k ] ) ) {
				$new[ $k ] = '';
			}
		}
		if ( ! empty( $in['regen_webhook_token'] ) || empty( $new['sf_webhook_token'] ) ) {
			$new['sf_webhook_token'] = wp_generate_password( 40, false, false );
		}

		update_option( 'pg_settings', wp_parse_args( $new, $defs ), false );
		if ( 'steadfast' === $section || 'general' === $section ) {
			PG_SF_Pickup::ensure_schedule( true );
		}
		if ( 'updates' === $section ) {
			delete_site_transient( 'pg_update_release' );
			delete_site_transient( 'pg_update_release_err' );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pg-settings', 'tab' => $section, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */

	public static function render() {
		$s   = self::all();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore
		$tabs = array(
			'general'   => __( 'General', 'product-glide' ),
			'invoice'   => __( 'Invoice', 'product-glide' ),
			'steadfast' => __( 'Steadfast', 'product-glide' ),
			'carrybee'  => __( 'Carrybee', 'product-glide' ),
			'fraud'     => __( 'Fraud Check', 'product-glide' ),
			'whatsapp'  => __( 'WhatsApp', 'product-glide' ),
			'ai'        => __( 'AI / OCR', 'product-glide' ),
			'profit'    => __( 'Profit', 'product-glide' ),
			'leads'     => __( 'Incomplete & Checkout', 'product-glide' ),
			'updates'   => __( 'Updates', 'product-glide' ),
		);
		if ( ! PG_Courier::enabled( 'carrybee' ) ) {
			unset( $tabs['carrybee'] );
		}
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		?>
		<div class="pg-wrap">
			<div class="pg-header"><h1><?php esc_html_e( 'Settings', 'product-glide' ); ?></h1></div>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore ?>
				<div class="pg-alert pg-alert-ok"><?php esc_html_e( 'Settings saved.', 'product-glide' ); ?></div>
			<?php endif; ?>
			<div class="pg-tabs">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a class="pg-tab <?php echo $k === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'pg-settings', 'tab' => $k ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pg-card pg-form">
				<input type="hidden" name="action" value="pg_save_settings">
				<input type="hidden" name="pg_section" value="<?php echo esc_attr( $tab ); ?>">
				<?php wp_nonce_field( 'pg_save_settings' ); ?>
				<?php
				switch ( $tab ) {
					case 'steadfast':
						self::field_text( 'sf_base_url', __( 'API base URL', 'product-glide' ), $s );
						self::field_secret( 'sf_api_key', __( 'API Key', 'product-glide' ), $s );
						self::field_secret( 'sf_secret_key', __( 'Secret Key', 'product-glide' ), $s );
						self::field_text( 'sf_default_note', __( 'Default rider note (added before customer note)', 'product-glide' ), $s );
						self::field_check( 'sf_item_desc', __( 'Send book titles as item description', 'product-glide' ), $s );
						$url = rest_url( 'product-glide/v1/steadfast-webhook' );
						?>
						<div class="pg-field">
							<label><?php esc_html_e( 'Webhook URL (paste in Steadfast panel → API → Webhook)', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" readonly value="<?php echo esc_attr( $url ); ?>"><button type="button" class="pg-btn pg-btn-ghost" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'product-glide' ); ?></button></div>
						</div>
						<div class="pg-field">
							<label><?php esc_html_e( 'Webhook Auth Token (Bearer)', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" readonly value="<?php echo esc_attr( $s['sf_webhook_token'] ); ?>"><button type="button" class="pg-btn pg-btn-ghost" data-copy="<?php echo esc_attr( $s['sf_webhook_token'] ); ?>"><?php esc_html_e( 'Copy', 'product-glide' ); ?></button></div>
							<label class="pg-check"><input type="checkbox" name="regen_webhook_token" value="1"> <?php esc_html_e( 'Generate a new token on save', 'product-glide' ); ?></label>
						</div>
						<?php
						self::render_pickup( $s );
						break;
					case 'carrybee':
						$cb_ready = PG_Carrybee_API::configured();
						echo '<p class="pg-muted">' . esc_html__( 'Get the three keys from the Carrybee merchant panel → API Credentials. Sandbox and Production have different keys.', 'product-glide' ) . '</p>';
						self::field_select( 'cb_env', __( 'Environment', 'product-glide' ), $s, array( 'production' => 'Production (real parcels)', 'sandbox' => 'Sandbox (testing)' ) );
						self::field_text( 'cb_client_id', __( 'Client-ID', 'product-glide' ), $s );
						self::field_secret( 'cb_client_secret', __( 'Client-Secret', 'product-glide' ), $s );
						self::field_secret( 'cb_client_context', __( 'Client-Context', 'product-glide' ), $s );
						echo '<div class="pg-field"><button type="button" class="pg-btn" id="pg-cb-test"' . ( $cb_ready ? '' : ' disabled title="' . esc_attr__( 'Save the keys first', 'product-glide' ) . '"' ) . '>' . esc_html__( 'Test connection', 'product-glide' ) . '</button> <span class="pg-muted" id="pg-cb-test-out"></span></div>';
						?>
						<div class="pg-field">
							<label for="pg-cb_store_id"><?php esc_html_e( 'Pickup store (parcels are picked up from here)', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" id="pg-cb_store_id" name="cb_store_id" value="<?php echo esc_attr( $s['cb_store_id'] ); ?>" placeholder="<?php esc_attr_e( 'Store ID', 'product-glide' ); ?>"><button type="button" class="pg-btn pg-btn-ghost" id="pg-cb-stores"<?php disabled( ! $cb_ready ); ?>><?php esc_html_e( 'Load my stores', 'product-glide' ); ?></button></div>
							<div id="pg-cb-store-list"></div>
							<details class="pg-cb-newstore"<?php echo $cb_ready ? '' : ' hidden'; ?>><summary><?php esc_html_e( 'No store yet? Create one', 'product-glide' ); ?></summary><div id="pg-cb-store-form"></div></details>
						</div>
						<?php
						self::field_select( 'cb_delivery_type', __( 'Delivery type', 'product-glide' ), $s, array( 1 => 'Normal', 2 => 'Express' ) );
						self::field_select( 'cb_product_type', __( 'Product type', 'product-glide' ), $s, array( 1 => 'Parcel', 2 => 'Book', 3 => 'Document' ) );
						self::field_number( 'cb_item_weight', __( 'Default parcel weight (grams) — used when products have no weight set', 'product-glide' ), $s );
						self::field_check( 'cb_auto_area', __( 'Find city / zone from the customer address automatically', 'product-glide' ), $s );
						self::field_text( 'cb_default_note', __( 'Default rider note (added before the customer note)', 'product-glide' ), $s );
						self::field_select( 'default_courier', __( 'Courier picked first when you press Send', 'product-glide' ), $s, PG_Courier::all() );
						$url = PG_Carrybee::webhook_url();
						?>
						<div class="pg-field">
							<label><?php esc_html_e( 'Webhook URL (paste in Carrybee panel → Webhook Integration)', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" readonly value="<?php echo esc_attr( $url ); ?>"><button type="button" class="pg-btn pg-btn-ghost" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'product-glide' ); ?></button></div>
						</div>
						<?php
						self::field_text( 'cb_webhook_secret', __( 'Webhook secret (X-CB-Webhook-Integration-Header) — saved automatically on the first webhook; you can also paste it', 'product-glide' ), $s );
						echo '<p class="pg-muted">' . esc_html__( 'Status updates arrive by webhook. Parcels in transit are also re-checked every 30 minutes and with the "Sync courier" button.', 'product-glide' ) . '</p>';
						break;
					case 'fraud':
						self::field_check( 'fraud_auto', __( 'Auto-check delivery success score when an order is opened', 'product-glide' ), $s );
						self::field_number( 'fraud_cache_hours', __( 'Cache result for (hours)', 'product-glide' ), $s );
						echo '<p class="pg-muted">' . esc_html__( 'Uses the Steadfast API keys from the Steadfast tab.', 'product-glide' ) . '</p>';
						break;
					case 'whatsapp':
						self::field_text( 'wa_phone_id', __( 'Phone Number ID', 'product-glide' ), $s );
						self::field_text( 'wa_waba_id', __( 'WhatsApp Business Account ID (WABA)', 'product-glide' ), $s );
						self::field_secret( 'wa_token', __( 'Access Token (permanent system-user token)', 'product-glide' ), $s );
						self::field_select( 'wa_lang', __( 'Default language', 'product-glide' ), $s, array( 'bn' => 'Bangla (bn)', 'en' => 'English (en)', 'en_US' => 'English US (en_US)' ) );
						self::field_text( 'wa_api_version', __( 'Graph API version', 'product-glide' ), $s );
						self::field_check( 'wa_auto', __( 'Send automatic messages on status change (uses the mappings below)', 'product-glide' ), $s );
						break;
					case 'ai':
						self::field_secret( 'or_key', __( 'OpenRouter API Key', 'product-glide' ), $s );
						?>
						<div class="pg-field">
							<label for="pg-or_model"><?php esc_html_e( 'Vision model (must accept images)', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" id="pg-or_model" name="or_model" value="<?php echo esc_attr( $s['or_model'] ); ?>" placeholder="e.g. google/gemini-2.5-flash-lite"><button type="button" class="pg-btn pg-btn-ghost" id="pg-or-load"><?php esc_html_e( 'Load vision models', 'product-glide' ); ?></button></div>
							<div id="pg-or-models"></div>
							<p class="pg-muted"><?php esc_html_e( 'Click "Load vision models" and pick one. Models marked FREE cost nothing but have daily limits and may be slower. Screenshots contain customer names, phones and addresses — free models may log prompts.', 'product-glide' ); ?></p>
						</div>
						<div class="pg-field"><button type="button" class="pg-btn" id="pg-or-test"><?php esc_html_e( 'Test connection', 'product-glide' ); ?></button> <span id="pg-or-test-out" class="pg-muted"></span></div>
						<?php
						break;
					case 'invoice':
						$auto = PG_Invoice::site_info();
						$opts = array();
						foreach ( PG_Invoice::sizes() as $k => $sz ) {
							$opts[ $k ] = $sz['label'];
						}
						self::field_select( 'inv_template', __( 'Invoice design', 'product-glide' ), $s, PG_Invoice::templates() );
						self::field_select( 'inv_page_size', __( 'Paper size (classic design only — the courier slip is always A4)', 'product-glide' ), $s, $opts );
						echo '<p class="pg-muted">' . esc_html__( 'Leave any field below empty to use the website\'s own information automatically (shown in grey). Type a value only if you want to override it.', 'product-glide' ) . '</p>';
						?>
						<div class="pg-field">
							<label for="pg-inv_logo"><?php esc_html_e( 'Company logo', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" id="pg-inv_logo" name="inv_logo" value="<?php echo esc_attr( $s['inv_logo'] ); ?>" placeholder="<?php echo esc_attr( $auto['logo'] ); ?>"><button type="button" class="pg-btn pg-btn-ghost" data-pick-image="pg-inv_logo"><?php esc_html_e( 'Choose', 'product-glide' ); ?></button></div>
							<?php $show = $s['inv_logo'] ? $s['inv_logo'] : $auto['logo']; ?>
							<?php if ( $show ) : ?><img src="<?php echo esc_url( $show ); ?>" alt="" style="max-height:48px;max-width:220px;margin-top:8px;display:block"><?php endif; ?>
						</div>
						<?php
						self::field_auto( 'inv_company', __( 'Company name', 'product-glide' ), $s, $auto['company'] );
						self::field_auto( 'inv_website', __( 'Website', 'product-glide' ), $s, $auto['website'] );
						self::field_auto( 'inv_phone', __( 'Phone number', 'product-glide' ), $s, $auto['phone'] );
						self::field_auto( 'inv_email', __( 'Email (shown on the courier slip)', 'product-glide' ), $s, $auto['email'] );
						?>
						<div class="pg-field"><label for="pg-inv_address"><?php esc_html_e( 'Address', 'product-glide' ); ?></label><textarea id="pg-inv_address" name="inv_address" rows="3" placeholder="<?php echo esc_attr( $auto['address'] ); ?>"><?php echo esc_textarea( $s['inv_address'] ); ?></textarea></div>
						<?php
						self::field_textarea( 'invoice_footer', __( 'Invoice footer text', 'product-glide' ), $s );
						break;
					case 'leads':
						echo '<h3 class="pg-set-h">' . esc_html__( 'Incomplete orders', 'product-glide' ) . '</h3>';
						self::field_check( 'lead_enabled', __( 'Save checkout details when a customer types a valid mobile number but does not place the order', 'product-glide' ), $s );
						self::field_number( 'lead_minutes', __( 'Count as incomplete after this many minutes without typing', 'product-glide' ), $s );
						self::field_number( 'lead_keep_days', __( 'Delete entries older than (days)', 'product-glide' ), $s );
						echo '<p class="pg-muted">' . esc_html__( 'Nothing is saved without a valid Bangladeshi mobile number, and password / card fields are never saved. When the customer completes the order themselves, the entry is marked "Ordered by customer" automatically.', 'product-glide' ) . '</p>';
						$od = PG_Leads::od_table() ? PG_Leads::od_pending() : -1;
						if ( $od >= 0 ) {
							echo '<div class="pg-field"><label>' . esc_html__( 'Order Detect plugin data', 'product-glide' ) . '</label>';
							if ( $od > 0 ) {
								echo '<p class="pg-muted">' . esc_html( sprintf( /* translators: count */ __( '%d incomplete orders from Order Detect have not been copied yet.', 'product-glide' ), $od ) ) . '</p><button type="button" class="pg-btn" id="pg-od-import">' . esc_html__( 'Import from Order Detect', 'product-glide' ) . '</button> <span class="pg-muted" id="pg-od-import-out"></span>';
							} else {
								echo '<p class="pg-muted">' . esc_html__( 'Everything from Order Detect is already imported.', 'product-glide' ) . '</p>';
							}
							echo '</div>';
						}
						echo '<h3 class="pg-set-h">' . esc_html__( 'Checkout protection', 'product-glide' ) . '</h3>';
						self::field_check( 'guard_bd_phone', __( 'Accept only valid Bangladeshi mobile numbers at checkout (saved as 01XXXXXXXXX)', 'product-glide' ), $s );
						self::field_check( 'block_repeat', __( 'Stop the same phone number from ordering again too soon', 'product-glide' ), $s );
						self::field_number( 'block_max', __( 'Orders allowed per phone in that time', 'product-glide' ), $s );
						self::field_number( 'block_hours', __( 'Time window (hours)', 'product-glide' ), $s );
						self::field_textarea( 'block_message', __( 'Message for a repeat order — %order_number% %order_date% %order_time% %order_status% %hours% %hotline%', 'product-glide' ), $s );
						self::field_textarea( 'blocklist', __( 'Blocked numbers (one per line) — these numbers cannot order from the website', 'product-glide' ), $s );
						self::field_textarea( 'block_list_message', __( 'Message for a blocked number — %hotline%', 'product-glide' ), $s );
						echo '<p class="pg-muted">' . esc_html__( 'Cancelled, failed and unpaid online orders do not count. Orders you create in Product Glide are never blocked.', 'product-glide' ) . '</p>';
						break;
					case 'updates':
						if ( isset( $_GET['checked'] ) ) { // phpcs:ignore
							$c = sanitize_key( $_GET['checked'] ); // phpcs:ignore
							$m = array(
								'available' => array( 'ok', __( 'A new version is available — go to Plugins and press "Update now".', 'product-glide' ) ),
								'latest'    => array( 'ok', __( 'Checked: you already have the latest version.', 'product-glide' ) ),
								'error'     => array( 'bad', __( 'Could not reach the release — see the message below.', 'product-glide' ) ),
							);
							if ( isset( $m[ $c ] ) ) {
								echo '<div class="pg-alert pg-alert-' . esc_attr( $m[ $c ][0] ) . '">' . esc_html( $m[ $c ][1] ) . '</div>';
							}
						}
						PG_Updater::render_status();
						self::field_text( 'gh_repo', __( 'GitHub repository (owner/name)', 'product-glide' ), $s );
						echo '<p class="pg-muted">' . esc_html__( 'When a new release is published on GitHub, this site shows "Update available" on the Plugins page within a few hours (or right away with "Check now"). Press "Update now" to install it — settings and data are kept.', 'product-glide' ) . '</p>';
						break;
					case 'profit':
						self::field_number( 'fallback_delivery', __( 'Fallback courier delivery charge per parcel (BDT, used when the webhook did not report one)', 'product-glide' ), $s );
						self::field_number( 'fallback_return', __( 'Fallback return charge per cancelled parcel (BDT)', 'product-glide' ), $s );
						?>
						<div class="pg-field"><label><?php esc_html_e( 'Statuses that count as delivered revenue', 'product-glide' ); ?></label>
							<div class="pg-checks">
							<?php
							foreach ( wc_get_order_statuses() as $k => $label ) {
								$k = substr( $k, 3 );
								printf(
									'<label class="pg-check"><input type="checkbox" name="profit_statuses[]" value="%s" %s> %s</label>',
									esc_attr( $k ),
									checked( in_array( $k, (array) $s['profit_statuses'], true ), true, false ),
									esc_html( $label )
								);
							}
							?>
							</div>
						</div>
						<?php
						break;
					default:
						$hub = PG_App::url();
						?>
						<div class="pg-field">
							<label><?php esc_html_e( 'Order Hub (dashboard outside wp-admin)', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" readonly value="<?php echo esc_attr( $hub ); ?>"><a class="pg-btn pg-btn-ghost" href="<?php echo esc_url( $hub ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'product-glide' ); ?></a></div>
							<p class="pg-muted"><?php esc_html_e( 'Administrators and users with the "Order Manager" role can sign in here. Create staff accounts in Users → Add New and pick the Order Manager role.', 'product-glide' ); ?></p>
						</div>
						<?php
						self::field_text( 'app_slug', __( 'Order Hub address (slug)', 'product-glide' ), $s );
						self::field_text( 'brand_name', __( 'Brand name (invoice header)', 'product-glide' ), $s );
						?>
						<div class="pg-field">
							<label><?php esc_html_e( 'Logo URL (landscape logo works best)', 'product-glide' ); ?></label>
							<div class="pg-copyrow"><input type="text" name="logo_url" id="pg-logo-url" value="<?php echo esc_attr( $s['logo_url'] ); ?>"><button type="button" class="pg-btn pg-btn-ghost" id="pg-pick-logo"><?php esc_html_e( 'Choose', 'product-glide' ); ?></button></div>
						</div>
						<?php
						self::field_text( 'hotline', __( 'Hotline / WhatsApp number', 'product-glide' ), $s );
						self::field_textarea( 'shop_address', __( 'Shop address', 'product-glide' ), $s );
						self::field_number( 'items_per_slip', __( 'Items per printed slip (larger orders continue on the next slip)', 'product-glide' ), $s );
						self::field_number( 'pkg_delivery_charge', __( 'Default package delivery charge (BDT)', 'product-glide' ), $s );
						echo '<h3 class="pg-set-h">' . esc_html__( 'Couriers', 'product-glide' ) . '</h3>';
						echo '<p class="pg-muted">' . esc_html__( 'Turn on the couriers you use. A courier that is off is hidden everywhere (Send buttons, courier choice, its menu and settings tab). Parcels already sent with it keep updating until they finish.', 'product-glide' ) . '</p>';
						self::field_check( 'sf_enabled', __( 'Steadfast', 'product-glide' ), $s );
						self::field_check( 'cb_enabled', __( 'Carrybee', 'product-glide' ), $s );
						echo '<p class="pg-muted">' . esc_html__( 'The courier report (fraud check) keeps using the Steadfast keys even when Steadfast is off for sending.', 'product-glide' ) . '</p>';
						break;
				}
				?>
				<div class="pg-form-actions"><button type="submit" class="pg-btn pg-btn-primary"><?php esc_html_e( 'Save settings', 'product-glide' ); ?></button></div>
			</form>
			<?php
			if ( 'whatsapp' === $tab ) {
				PG_WhatsApp::render_mapping_ui();
			}
			?>
		</div>
		<?php
	}

	private static function render_pickup( $s ) {
		echo '<h3 class="pg-set-h">' . esc_html__( 'Daily rider pickup request', 'product-glide' ) . '</h3>';
		echo '<p class="pg-muted">' . esc_html__( 'Sends one Steadfast pickup request a day at the time below (Bangladesh time). The parcel count is the Steadfast parcels booked since the last pickup.', 'product-glide' ) . '</p>';
		self::field_check( 'sf_pickup_auto', __( 'Send the pickup request automatically', 'product-glide' ), $s );
		printf( '<div class="pg-field"><label for="pg-sf_pickup_time">%s</label><input type="time" id="pg-sf_pickup_time" name="sf_pickup_time" value="%s"></div>', esc_html__( 'Time', 'product-glide' ), esc_attr( $s['sf_pickup_time'] ) );
		$days = is_array( $s['sf_pickup_days'] ) ? array_map( 'intval', $s['sf_pickup_days'] ) : array();
		$names = array( 6 => 'Sat', 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri' );
		echo '<div class="pg-field"><label>' . esc_html__( 'Days', 'product-glide' ) . '</label><div class="pg-days">';
		foreach ( $names as $n => $l ) {
			printf( '<label class="pg-check"><input type="checkbox" name="sf_pickup_days[]" value="%d" %s> %s</label> ', (int) $n, checked( in_array( $n, $days, true ), true, false ), esc_html( $l ) );
		}
		echo '</div></div>';
		self::field_number( 'sf_pickup_min', __( 'Only send if at least this many new parcels (0 = send every day)', 'product-glide' ), $s );
		self::field_number( 'sf_pickup_address_id', __( 'Pickup address ID (from Steadfast panel, 0 = default)', 'product-glide' ), $s );
		self::field_number( 'sf_pickup_ps_id', __( 'Police station ID (optional)', 'product-glide' ), $s );
		self::field_text( 'sf_pickup_address', __( 'Pickup address', 'product-glide' ), $s );
		self::field_auto( 'sf_pickup_phone', __( 'Contact number', 'product-glide' ), $s, (string) $s['hotline'] );
		self::field_text( 'sf_pickup_note', __( 'Note for the rider', 'product-glide' ), $s );
		$next = wp_next_scheduled( PG_SF_Pickup::HOOK );
		echo '<div class="pg-field"><p>';
		if ( PG_SF_Pickup::enabled() && $next ) {
			echo '<b>' . esc_html__( 'Next run:', 'product-glide' ) . '</b> ' . esc_html( PG_Helpers::bd_time( $next ) ) . ' &middot; ';
		} else {
			echo esc_html__( 'Auto pickup is off.', 'product-glide' ) . ' &middot; ';
		}
		echo esc_html( sprintf( /* translators: n */ __( 'New Steadfast parcels since the last pickup: %d', 'product-glide' ), PG_SF_Pickup::parcel_count() ) ) . '</p>';
		echo '<button type="button" class="pg-btn" id="pg-sf-pickup-now">' . esc_html__( 'Send pickup request now', 'product-glide' ) . '</button> <span class="pg-muted">' . esc_html__( 'Uses the saved details above. Save first if you changed them.', 'product-glide' ) . '</span><div id="pg-sf-pickup-out"></div></div>';
		$log = PG_SF_Pickup::log();
		if ( $log ) {
			echo '<div class="pg-field"><label>' . esc_html__( 'Recent pickup runs', 'product-glide' ) . '</label><table class="pg-table"><tbody>';
			foreach ( $log as $r ) {
				$cls = 'sent' === $r['status'] ? 'pg-badge-ok' : ( 'failed' === $r['status'] ? 'pg-badge-bad' : 'pg-badge-info' );
				printf( '<tr><td>%s</td><td><span class="pg-badge %s">%s</span></td><td>%s</td></tr>', esc_html( PG_Helpers::bd_time( $r['time'] ) ), esc_attr( $cls ), esc_html( ucfirst( $r['status'] ) ), esc_html( $r['message'] ) );
			}
			echo '</tbody></table></div>';
		}
	}

	private static function field_text( $k, $label, $s ) {
		printf( '<div class="pg-field"><label for="pg-%1$s">%2$s</label><input type="text" id="pg-%1$s" name="%1$s" value="%3$s"></div>', esc_attr( $k ), esc_html( $label ), esc_attr( is_array( $s[ $k ] ) ? '' : $s[ $k ] ) );
	}

	private static function field_auto( $k, $label, $s, $auto ) {
		printf( '<div class="pg-field"><label for="pg-%1$s">%2$s</label><input type="text" id="pg-%1$s" name="%1$s" value="%3$s" placeholder="%4$s"></div>', esc_attr( $k ), esc_html( $label ), esc_attr( $s[ $k ] ), esc_attr( $auto ) );
	}

	private static function field_number( $k, $label, $s ) {
		printf( '<div class="pg-field"><label for="pg-%1$s">%2$s</label><input type="number" step="any" min="0" id="pg-%1$s" name="%1$s" value="%3$s"></div>', esc_attr( $k ), esc_html( $label ), esc_attr( $s[ $k ] ) );
	}

	private static function field_textarea( $k, $label, $s ) {
		printf( '<div class="pg-field"><label for="pg-%1$s">%2$s</label><textarea id="pg-%1$s" name="%1$s" rows="3">%3$s</textarea></div>', esc_attr( $k ), esc_html( $label ), esc_textarea( $s[ $k ] ) );
	}

	private static function field_secret( $k, $label, $s ) {
		$has = ! empty( $s[ $k ] );
		printf(
			'<div class="pg-field"><label for="pg-%1$s">%2$s</label><input type="password" autocomplete="new-password" id="pg-%1$s" name="%1$s" value="" placeholder="%3$s">%4$s</div>',
			esc_attr( $k ),
			esc_html( $label ),
			esc_attr( $has ? '•••••••• ' . __( 'saved — leave blank to keep', 'product-glide' ) : '' ),
			$has ? '<label class="pg-check"><input type="checkbox" name="clear_' . esc_attr( $k ) . '" value="1"> ' . esc_html__( 'Remove saved value', 'product-glide' ) . '</label>' : ''
		);
	}

	private static function field_check( $k, $label, $s ) {
		printf( '<div class="pg-field"><label class="pg-check"><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label></div>', esc_attr( $k ), checked( ! empty( $s[ $k ] ), true, false ), esc_html( $label ) );
	}

	private static function field_select( $k, $label, $s, $opts ) {
		echo '<div class="pg-field"><label for="pg-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label><select id="pg-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '">';
		foreach ( $opts as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( $s[ $k ], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></div>';
	}
}
