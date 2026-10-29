<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database tables and activation tasks.
 */
class PG_Install {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'product_glide_' . $name;
	}

	public static function activate() {
		self::create_tables();
		if ( ! get_option( 'pg_settings' ) ) {
			add_option( 'pg_settings', PG_Settings::defaults(), '', false );
		}
		$s = get_option( 'pg_settings', array() );
		if ( empty( $s['sf_webhook_token'] ) ) {
			$s['sf_webhook_token'] = wp_generate_password( 40, false, false );
			update_option( 'pg_settings', $s, false );
		}
		PG_App::add_role();
		update_option( 'pg_db_version', PG_DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'pg_db_version' ) !== PG_DB_VERSION ) {
			self::activate();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		$consign = self::table( 'consignments' );
		$fraud   = self::table( 'fraud_cache' );
		$costs   = self::table( 'costs' );
		$wa      = self::table( 'wa_templates' );

		dbDelta(
			"CREATE TABLE {$consign} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			provider varchar(20) NOT NULL DEFAULT 'steadfast',
			invoice varchar(100) NOT NULL DEFAULT '',
			consignment_id varchar(50) NOT NULL DEFAULT '',
			tracking_code varchar(100) NOT NULL DEFAULT '',
			courier_status varchar(60) NOT NULL DEFAULT '',
			last_synced_status varchar(60) NOT NULL DEFAULT '',
			cod_amount decimal(12,2) NOT NULL DEFAULT 0,
			cod_collected decimal(12,2) DEFAULT NULL,
			delivery_charge decimal(12,2) DEFAULT NULL,
			tracking_message text NULL,
			last_event_key varchar(120) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			final_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY consignment_id (consignment_id),
			KEY invoice (invoice),
			KEY created_at (created_at),
			KEY courier_status (courier_status)
			) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$fraud} (
			phone varchar(20) NOT NULL,
			delivery_ratio smallint(6) DEFAULT NULL,
			cancellation_ratio smallint(6) DEFAULT NULL,
			total_reports int(11) NOT NULL DEFAULT 0,
			volume_band varchar(20) NOT NULL DEFAULT '',
			payload longtext NULL,
			checked_at datetime NOT NULL,
			PRIMARY KEY  (phone)
			) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$costs} (
			product_id bigint(20) unsigned NOT NULL,
			cost decimal(12,2) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (product_id)
			) {$c};"
		);

		dbDelta(
			"CREATE TABLE {$wa} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trigger_status varchar(60) NOT NULL DEFAULT '',
			template_name varchar(190) NOT NULL DEFAULT '',
			language varchar(20) NOT NULL DEFAULT 'bn',
			variables varchar(255) NOT NULL DEFAULT '',
			has_header_text tinyint(1) NOT NULL DEFAULT 0,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY trigger_status (trigger_status)
			) {$c};"
		);

		$leads = self::table( 'leads' );
		dbDelta(
			"CREATE TABLE {$leads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_key varchar(80) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			source varchar(20) NOT NULL DEFAULT 'checkout',
			phone varchar(20) NOT NULL DEFAULT '',
			phone_key varchar(12) NOT NULL DEFAULT '',
			name varchar(150) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			address text NULL,
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(100) NOT NULL DEFAULT '',
			note text NULL,
			items longtext NULL,
			item_count int(11) NOT NULL DEFAULT 0,
			subtotal decimal(12,2) NOT NULL DEFAULT 0,
			discount decimal(12,2) NOT NULL DEFAULT 0,
			shipping decimal(12,2) NOT NULL DEFAULT 0,
			total decimal(12,2) NOT NULL DEFAULT 0,
			fields longtext NULL,
			staff_notes longtext NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			last_activity_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_key (session_key),
			KEY phone_key (phone_key),
			KEY status_activity (status,last_activity_at),
			KEY updated_at (updated_at)
			) {$c};"
		);

		$act = self::table( 'activity' );
		dbDelta(
			"CREATE TABLE {$act} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(20) NOT NULL DEFAULT '',
			status_to varchar(40) NOT NULL DEFAULT '',
			detail varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_time (user_id,created_at),
			KEY created_at (created_at),
			KEY order_id (order_id)
			) {$c};"
		);
	}
}
