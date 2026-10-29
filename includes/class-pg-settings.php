<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings (single option `pg_settings`).
 */
class PG_Settings {

	const SECRET_KEYS = array( 'sf_api_key', 'sf_secret_key', 'wa_token', 'or_key' );

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

		$text_keys = array( 'brand_name', 'logo_url', 'hotline', 'sf_base_url', 'sf_default_note', 'wa_phone_id', 'wa_waba_id', 'wa_lang', 'wa_api_version', 'or_model', 'inv_logo', 'inv_company', 'inv_website', 'inv_phone', 'inv_email', 'gh_repo' );
		foreach ( $text_keys as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$new[ $k ] = in_array( $k, array( 'logo_url', 'sf_base_url', 'inv_logo' ), true ) ? esc_url_raw( trim( $in[ $k ] ) ) : sanitize_text_field( $in[ $k ] );
			}
		}
		if ( isset( $in['inv_template'] ) ) {
			$tpl                 = sanitize_key( $in['inv_template'] );
			$new['inv_template'] = isset( PG_Invoice::templates()[ $tpl ] ) ? $tpl : 'classic';
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
		foreach ( array( 'items_per_slip', 'fraud_cache_hours', 'lead_minutes', 'lead_keep_days', 'block_max', 'block_hours' ) as $k ) {
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
		$section = isset( $in['pg_section'] ) ? sanitize_key( $in['pg_section'] ) : '';
		$checkbox_by_section = array(
			'steadfast' => array( 'sf_item_desc' ),
			'fraud'     => array( 'fraud_auto' ),
			'whatsapp'  => array( 'wa_auto' ),
			'leads'     => array( 'lead_enabled', 'guard_bd_phone', 'block_repeat' ),
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
			'fraud'     => __( 'Fraud Check', 'product-glide' ),
			'whatsapp'  => __( 'WhatsApp', 'product-glide' ),
			'ai'        => __( 'AI / OCR', 'product-glide' ),
			'profit'    => __( 'Profit', 'product-glide' ),
			'leads'     => __( 'Incomplete & Checkout', 'product-glide' ),
			'updates'   => __( 'Updates', 'product-glide' ),
		);
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
