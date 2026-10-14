<?php
/**
 * Scheduled tasks: mark carts abandoned, run the multi-step recovery email sequence,
 * and clean up old data per the retention setting.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Cron {

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_five_minute_schedule' ) );
		add_action( 'wccr_check_abandoned_carts', array( $this, 'process_abandoned_carts' ) );
		add_action( 'wccr_cleanup_old_carts', array( $this, 'cleanup_old_carts' ) );

		// Self-heal: re-schedule if the events disappeared (e.g. cron option reset, migration).
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( 'wccr_check_abandoned_carts' ) || ! wp_next_scheduled( 'wccr_cleanup_old_carts' ) ) {
			WCCR_Install::maybe_schedule_events();
		}
	}

	/**
	 * Fallback for sites where WP-Cron does not fire (DISABLE_WP_CRON without a real server cron,
	 * aggressive caching, low traffic). If the scheduled tick has not run for 10 minutes, run it
	 * now — called when the dashboard loads. Without this, carts stayed "active" forever and
	 * never became "abandoned".
	 */
	public static function run_if_stale() {
		$last = (int) get_option( 'wccr_last_cron_run', 0 );
		if ( $last > time() - 10 * MINUTE_IN_SECONDS ) {
			return;
		}
		if ( get_transient( 'wccr_cron_fallback_lock' ) ) {
			return;
		}
		set_transient( 'wccr_cron_fallback_lock', 1, 2 * MINUTE_IN_SECONDS );
		( new self() )->process_abandoned_carts();
	}

	public function add_five_minute_schedule( $schedules ) {
		$schedules['wccr_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (Fixo Cart)', 'fixo-cart' ),
		);
		return $schedules;
	}

	/**
	 * Main tick: (1) flip stale "active"/"recovered" carts to "abandoned", (2) send whichever step of
	 * the email sequence is due for already-abandoned carts.
	 */
	public function process_abandoned_carts() {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		update_option( 'wccr_last_cron_run', time(), false );

		$threshold_minutes = max( 5, (int) get_option( 'wccr_abandoned_after_minutes', 20 ) );
		// updated_at is stored in site-local time, so the cutoff must be site-local too.
		// (gmdate() here meant carts in Dhaka were only marked abandoned ~6 hours late, while
		// stores west of UTC marked carts abandoned while the shopper was still browsing.)
		$cutoff = WCCR_Cart::mysql_ago( $threshold_minutes * MINUTE_IN_SECONDS );

		// 1) Active carts with no activity past the threshold become "abandoned".
		//    "recovered" = customer opened the recovery link but still did not buy — it goes back to
		//    abandoned so the remaining sequence steps can continue (it used to be stuck forever).
		$newly_abandoned = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status IN ('active','recovered') AND order_id IS NULL AND updated_at <= %s AND cart_contents IS NOT NULL AND cart_contents <> '' AND cart_contents <> '[]' LIMIT 500",
				$cutoff
			)
		);
		if ( $newly_abandoned ) {
			$ids = implode( ',', array_map( 'absint', $newly_abandoned ) );
			$wpdb->query( "UPDATE {$table} SET status = 'abandoned' WHERE id IN ({$ids})" );
			foreach ( $newly_abandoned as $cart_id ) {
				$cart = WCCR_Cart::get( (int) $cart_id );
				// Alerts no longer require an email address — phone-only checkouts (very common in
				// Bangladesh) never produced a dashboard alert before.
				if ( $cart && 'bot' !== $cart['risk_status'] ) {
					$this->maybe_alert( $cart );
					/**
					 * A cart just became abandoned (Telegram / WhatsApp notifications listen here).
					 *
					 * @param array $cart Cart row.
					 */
					do_action( 'wccr_cart_abandoned', $cart );
				}
			}
		}

		if ( 'yes' !== get_option( 'wccr_emails_enabled', 'yes' ) ) {
			return;
		}
		$emails = new WCCR_Emails();

		// 2) Walk abandoned carts that still have an email step left.
		//    The old query ("ORDER BY updated_at ASC LIMIT 200" with no other filter) kept picking the
		//    same 200 oldest rows forever — once 200 finished/skipped carts existed, NEW carts never
		//    received an email. Now finished sequences and very old carts are excluded.
		$lookback = WCCR_Cart::mysql_ago( 30 * DAY_IN_SECONDS );
		$max_step = 1;
		foreach ( array( 3, 2 ) as $s ) {
			if ( 'yes' === get_option( "wccr_email_{$s}_enabled", 'no' ) ) { $max_step = $s; break; }
		}
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'abandoned' AND order_id IS NULL AND email IS NOT NULL AND email <> '' AND emails_sent < %d AND updated_at >= %s ORDER BY updated_at DESC LIMIT 300",
				$max_step,
				$lookback
			),
			ARRAY_A
		);

		foreach ( $candidates as $cart ) {
			WCCR_Cart::apply_risk_detection( (int) $cart['id'] );
			$cart = WCCR_Cart::get( (int) $cart['id'] );
			if ( ! $cart || ! $this->passes_smart_rules( $cart ) ) {
				continue;
			}
			// Stop emailing people who already bought (e.g. ordered from another device/browser,
			// or by phone) — previously they kept receiving "you forgot your cart" emails.
			if ( $this->customer_already_ordered( $cart ) ) {
				continue;
			}
			$emails->maybe_send_next_step( $cart );
		}
	}

	/**
	 * If a real order exists with the same billing email/phone placed after this cart was created,
	 * link it and mark the cart converted (customer checkout) so the sequence stops.
	 */
	private function customer_already_ordered( array $cart ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$created = WCCR_Cart::to_timestamp( $cart['created_at'] );
		$base    = array(
			'limit'        => 1,
			'return'       => 'ids',
			'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ),
			'date_created' => '>=' . ( $created ? $created : time() - DAY_IN_SECONDS ),
			'orderby'      => 'date',
			'order'        => 'DESC',
		);
		$orders = array();
		if ( ! empty( $cart['email'] ) ) {
			$orders = wc_get_orders( array_merge( $base, array( 'billing_email' => $cart['email'] ) ) );
		}
		if ( ! $orders && ! empty( $cart['phone'] ) ) {
			$orders = wc_get_orders( array_merge( $base, array( 'billing_phone' => $cart['phone'] ) ) );
		}
		if ( ! $orders ) {
			return false;
		}
		$order_id = (int) $orders[0];
		WCCR_Cart::update(
			$cart['id'],
			array(
				'status'          => 'converted',
				'order_id'        => $order_id,
				'conversion_type' => 'customer_checkout',
			),
			false
		);
		WCCR_Cart::log_event( $cart['id'], 'order_placed', array( 'order_id' => $order_id, 'matched_by' => 'email_or_phone' ) );
		return true;
	}

	/**
	 * Remove old, dead cart rows (never converted) past the configured retention window — GDPR friendly.
	 */
	private function passes_smart_rules( $cart ) {
		if ( 'yes' !== get_option( 'wccr_smart_rules_enabled', 'yes' ) ) return true;
		if ( 'yes' === get_option( 'wccr_smart_rules_skip_bots', 'yes' ) && 'bot' === $cart['risk_status'] ) return false;
		if ( 'yes' === get_option( 'wccr_smart_rules_skip_duplicates', 'yes' ) && 'duplicate' === $cart['risk_status'] ) return false;
		$min = (float) get_option( 'wccr_smart_rules_min_cart_total', 0 );
		if ( $min > 0 && (float) $cart['cart_total'] < $min ) return false;
		$max_emails = (int) get_option( 'wccr_smart_rules_max_recovery_emails', 3 );
		if ( $max_emails > 0 && (int) $cart['emails_sent'] >= $max_emails ) return false;
		$excluded = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'wccr_smart_rules_excluded_sources', '' ) ) ) );
		if ( $excluded ) {
			$haystack = strtolower( implode( ' ', array( $cart['utm_source'], $cart['utm_medium'], $cart['utm_campaign'], $cart['source_url'], $cart['referrer'] ) ) );
			foreach ( $excluded as $source ) { if ( false !== strpos( $haystack, strtolower( $source ) ) ) return false; }
		}
		return true;
	}

	private function maybe_alert( $cart ) {
		if ( 'yes' !== get_option( 'wccr_alerts_enabled', 'yes' ) ) return;
		if ( 'yes' === get_option( 'wccr_alert_high_value', 'yes' ) && (float) $cart['cart_total'] >= (float) get_option( 'wccr_alert_high_value_threshold', 2000 ) ) {
			$latest = WCCR_Cart::get_latest_event( $cart['id'], 'alert_high_value' );
			if ( ! $latest || WCCR_Cart::to_timestamp( $latest['created_at'] ) < time() - DAY_IN_SECONDS ) WCCR_Cart::log_event( $cart['id'], 'alert_high_value', array( 'message' => 'High-value abandoned cart', 'total' => (float) $cart['cart_total'] ) );
		}
		if ( 'yes' === get_option( 'wccr_alert_new_abandoned', 'yes' ) ) {
			$latest = WCCR_Cart::get_latest_event( $cart['id'], 'alert_abandoned' );
			if ( ! $latest ) WCCR_Cart::log_event( $cart['id'], 'alert_abandoned', array( 'message' => 'Cart became abandoned' ) );
		}
	}

	public function cleanup_old_carts() {
		global $wpdb;
		$table  = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$events = $wpdb->prefix . FIXO_CART_TABLE_EVENTS;
		$days   = (int) get_option( 'wccr_data_retention_days', 90 );

		if ( $days <= 0 ) {
			return; // 0 = keep forever.
		}

		$cutoff = WCCR_Cart::mysql_ago( $days * DAY_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('abandoned','emptied','active','recovered') AND order_id IS NULL AND updated_at <= %s",
				$cutoff
			)
		);

		// Events of deleted carts used to stay in the table forever (orphans).
		$wpdb->query( "DELETE e FROM {$events} e LEFT JOIN {$table} c ON c.id = e.cart_id WHERE c.id IS NULL" );
	}
}
