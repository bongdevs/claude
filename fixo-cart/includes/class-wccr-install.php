<?php
/**
 * Handles plugin activation: DB table creation & default options.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Install {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::add_default_options();
		self::maybe_schedule_events();
		update_option( 'wccr_db_version', FIXO_CART_VERSION );
		flush_rewrite_rules(); // Defensive: makes sure REST routes resolve immediately after activation.
	}

	/**
	 * Create the carts + events tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$carts_table     = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$events_table    = $wpdb->prefix . FIXO_CART_TABLE_EVENTS;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Main abandoned cart record — one row per browser/session cart.
		$sql_carts = "CREATE TABLE {$carts_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			first_name VARCHAR(191) NULL,
			last_name VARCHAR(191) NULL,
			email VARCHAR(191) NULL,
			phone VARCHAR(60) NULL,
			billing_address_1 VARCHAR(191) NULL,
			billing_address_2 VARCHAR(191) NULL,
			billing_city VARCHAR(191) NULL,
			billing_state VARCHAR(100) NULL,
			billing_postcode VARCHAR(30) NULL,
			billing_country VARCHAR(10) NULL,
			billing_company VARCHAR(191) NULL,
			ship_to_different TINYINT(1) NOT NULL DEFAULT 0,
			shipping_first_name VARCHAR(191) NULL,
			shipping_last_name VARCHAR(191) NULL,
			shipping_address_1 VARCHAR(191) NULL,
			shipping_address_2 VARCHAR(191) NULL,
			shipping_city VARCHAR(191) NULL,
			shipping_state VARCHAR(100) NULL,
			shipping_postcode VARCHAR(30) NULL,
			shipping_country VARCHAR(10) NULL,
			order_note TEXT NULL,
			cart_contents LONGTEXT NULL,
			cart_total DECIMAL(19,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NULL,
			source_url TEXT NULL,
			landing_page TEXT NULL,
			referrer TEXT NULL,
			utm_source VARCHAR(191) NULL,
			utm_medium VARCHAR(191) NULL,
			utm_campaign VARCHAR(191) NULL,
			utm_term VARCHAR(191) NULL,
			utm_content VARCHAR(191) NULL,
			device_type VARCHAR(20) NULL,
			ip_address VARCHAR(100) NULL,
			country_code VARCHAR(10) NULL,
			tracking_data TEXT NULL,
			recovery_token VARCHAR(64) NULL,
			emails_sent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_email_sent_at DATETIME NULL,
			recovered_at DATETIME NULL,
			order_id BIGINT UNSIGNED NULL,
			conversion_type VARCHAR(30) NULL,
			converted_by_user_id BIGINT UNSIGNED NULL,
			risk_status VARCHAR(20) NOT NULL DEFAULT 'normal',
			duplicate_of BIGINT UNSIGNED NULL,
			admin_note TEXT NULL,
			consent TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_key (session_key),
			KEY status (status),
			KEY email (email(100)),
			KEY phone (phone),
			KEY status_updated (status,updated_at),
			KEY recovery_token (recovery_token),
			KEY order_id (order_id),
			KEY conversion_type (conversion_type),
			KEY risk_status (risk_status),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		// Lightweight event log per cart (opened recovery link, clicked email, phone contacted, etc.) — powers the activity timeline in the dashboard.
		$sql_events = "CREATE TABLE {$events_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cart_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(50) NOT NULL,
			event_data TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY cart_id (cart_id),
			KEY cart_event (cart_id,event_type),
			KEY event_type (event_type)
		) {$charset_collate};";

		dbDelta( $sql_carts );
		dbDelta( $sql_events );
	}

	/**
	 * Upgrade existing installations without requiring deactivation/reactivation.
	 * v2.1 introduces explicit conversion attribution so revenue only counts
	 * orders manually created from an abandoned cart by a store manager/admin.
	 */
	public static function maybe_upgrade() {
		global $wpdb;

		$current = get_option( 'wccr_db_version', '0' );
		// Compare against the running version (was hard-coded to 2.4.0, so future schema changes
		// would never run on sites that update by replacing files without re-activating).
		if ( version_compare( $current, FIXO_CART_VERSION, '>=' ) ) {
			return;
		}

		// dbDelta adds any missing columns/indexes safely (e.g. the new phone / status_updated indexes).
		self::create_tables();
		self::add_default_options();

		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! in_array( 'conversion_type', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD conversion_type VARCHAR(30) NULL AFTER order_id" );
		}
		if ( ! in_array( 'converted_by_user_id', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD converted_by_user_id BIGINT UNSIGNED NULL AFTER conversion_type" );
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! in_array( 'risk_status', $columns, true ) ) { $wpdb->query( "ALTER TABLE {$table} ADD risk_status VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER converted_by_user_id" ); }
		if ( ! in_array( 'duplicate_of', $columns, true ) ) { $wpdb->query( "ALTER TABLE {$table} ADD duplicate_of BIGINT UNSIGNED NULL AFTER risk_status" ); }
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$has_conversion_index = false;
		foreach ( $indexes as $index ) {
			if ( isset( $index['Key_name'] ) && 'conversion_type' === $index['Key_name'] ) {
				$has_conversion_index = true;
				break;
			}
		}
		if ( ! $has_conversion_index ) { $wpdb->query( "ALTER TABLE {$table} ADD KEY conversion_type (conversion_type)" ); }
		$has_risk_index = false;
		foreach ( $indexes as $index ) { if ( isset( $index['Key_name'] ) && 'risk_status' === $index['Key_name'] ) { $has_risk_index = true; break; } }
		if ( ! $has_risk_index ) { $wpdb->query( "ALTER TABLE {$table} ADD KEY risk_status (risk_status)" ); }

		// Backfill old records: dashboard/manual conversions have a dedicated event;
		// normal checkout conversions are explicitly customer_checkout.
		$wpdb->query(
			"UPDATE {$table} c
			 SET c.conversion_type = 'admin_recovery'
			 WHERE c.status = 'converted' AND c.order_id IS NOT NULL
			 AND EXISTS (
				SELECT 1 FROM {$wpdb->prefix}" . FIXO_CART_TABLE_EVENTS . " e
				WHERE e.cart_id = c.id AND e.event_type = 'converted_to_order'
			 )"
		);
		$wpdb->query(
			"UPDATE {$table} SET conversion_type = 'customer_checkout'
			 WHERE status = 'converted' AND order_id IS NOT NULL
			 AND (conversion_type IS NULL OR conversion_type = '')"
		);

		self::maybe_schedule_events();
		update_option( 'wccr_db_version', FIXO_CART_VERSION );
	}

	/**
	 * Sensible default settings on first install.
	 */
	/**
	 * Import the bot token / chat id from Woo Telegram Orders (merged into Fixo Cart) once.
	 */
	public static function import_legacy_telegram() {
		$token = trim( (string) get_option( 'wto_bot_token', '' ) );
		$chat  = trim( (string) get_option( 'wto_chat_id', '' ) );
		if ( ! $token || ! $chat || false !== get_option( 'wccr_telegram_bot_token', false ) ) {
			return;
		}
		update_option( 'wccr_telegram_bot_token', $token );
		update_option( 'wccr_telegram_chat_ids', $chat );
		update_option( 'wccr_notify_telegram_enabled', 'yes' );
	}

	public static function add_default_options() {
		self::import_legacy_telegram();
		$defaults = array(
			'capture_enabled'          => 'yes',
			'capture_scope'            => 'cart_checkout',
			'capture_guests'           => 'yes',
			'require_consent'          => 'no',
			'consent_text'             => __( 'I agree to be contacted about my order and cart via email or phone.', 'fixo-cart' ),
			'abandoned_after_minutes'  => 20,
			'exclude_admin_ip'         => 'yes',
			'emails_enabled'           => 'yes',
			'email_1_delay_minutes'    => 60,
			'email_1_subject'          => __( 'Did you forget something, {first_name}?', 'fixo-cart' ),
			'email_2_delay_minutes'    => 1440,
			'email_2_subject'          => __( 'Still thinking it over? Here\'s 10% off', 'fixo-cart' ),
			'email_2_enabled'          => 'yes',
			'email_2_coupon_enabled'   => 'yes',
			'email_2_coupon_percent'   => 10,
			'smart_coupon_enabled'     => 'yes',
			'smart_coupon_expiry_days' => 7,
			'smart_coupon_min_spend'   => 0,
			'smart_coupon_max_discount' => 0,
			'smart_coupon_prefix'      => 'RECOVER',
			'email_3_delay_minutes'    => 4320,
			'email_3_subject'          => __( 'Last chance — your cart expires soon', 'fixo-cart' ),
			'email_3_enabled'          => 'no',
			'data_retention_days'      => 90,
			'from_name'                => get_bloginfo( 'name' ),
			'from_email'               => get_option( 'admin_email' ),
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( 'wccr_' . $key, false ) ) {
				add_option( 'wccr_' . $key, $value );
			}
		}
	}

	/**
	 * Schedule the cron hooks if not already scheduled.
	 */
	public static function maybe_schedule_events() {
		if ( ! wp_next_scheduled( 'wccr_check_abandoned_carts' ) ) {
			wp_schedule_event( time() + 300, 'wccr_five_minutes', 'wccr_check_abandoned_carts' );
		}
		if ( ! wp_next_scheduled( 'wccr_cleanup_old_carts' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'wccr_cleanup_old_carts' );
		}
	}
}
