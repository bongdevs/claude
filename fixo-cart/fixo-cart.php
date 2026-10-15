<?php
/**
 * Plugin Name:       Fixo Cart
 * Plugin URI:        https://bongdevs.com/fixo-cart
 * Description:       Abandoned cart recovery and real-time store alerts for WooCommerce. Captures checkout leads as they type (name, phone, email, full address, cart and traffic source), recovers carts by email, WhatsApp, phone or one-click manual orders, and sends Telegram and WhatsApp notifications for new leads, new orders, order status changes, abandoned and high-value carts. Includes a live customer monitor, order dashboard, UTM, product and revenue analytics, customer segments, smart coupons and Meta CAPI / GA4 / webhook conversion tracking.
 * Version:           3.0.1
 * Author:            Jahid Hasan
 * Author URI:        https://bongdevs.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fixo-cart
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 5.0
 * WC tested up to:   9.5
 *
 * Fixo Cart is the successor of "BongDevs Cart Recovery for WooCommerce" and also replaces
 * "Woo Telegram Orders". Internal identifiers (wccr_* options, database tables, the wccr/v1
 * REST namespace) are kept unchanged so existing stores keep all their data after the upgrade.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'FIXO_CART_VERSION', '3.0.1' );
define( 'FIXO_CART_PLUGIN_FILE', __FILE__ );
define( 'FIXO_CART_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FIXO_CART_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FIXO_CART_TABLE_CARTS', 'wccr_carts' );
define( 'FIXO_CART_TABLE_EVENTS', 'wccr_cart_events' );

/**
 * Plugin basename of a legacy plugin, found from the file that declares one of its symbols
 * (folder names vary between installs, e.g. "woo-telegram-orders-3").
 *
 * @param string $kind 'cart' or 'telegram'.
 * @return string Basename, or '' when that plugin is not loaded.
 */
function fixo_cart_legacy_basename( $kind ) {
	try {
		if ( 'cart' === $kind && function_exists( 'wccr_init' ) ) {
			$file = ( new ReflectionFunction( 'wccr_init' ) )->getFileName();
		} elseif ( 'telegram' === $kind && class_exists( 'WTO_Plugin', false ) ) {
			$file = ( new ReflectionClass( 'WTO_Plugin' ) )->getFileName();
		} else {
			return '';
		}
	} catch ( ReflectionException $e ) {
		return '';
	}
	return $file ? plugin_basename( $file ) : '';
}

/**
 * The old cart recovery plugin declares the same classes. If it is still active, stop it from
 * booting (it would fatal on a class redeclaration) before its own plugins_loaded callback runs,
 * and deactivate it on the next admin request. Its data is shared, so nothing is lost.
 */
function fixo_cart_neutralize_legacy() {
	if ( function_exists( 'wccr_init' ) ) {
		remove_action( 'plugins_loaded', 'wccr_init' );
	}

	// Woo Telegram Orders sends its own Telegram message for every order. Fixo Cart does that now,
	// so drop its hook to avoid duplicate messages while both are active.
	if ( class_exists( 'WTO_Plugin' ) ) {
		global $wp_filter;
		if ( isset( $wp_filter['woocommerce_new_order'] ) ) {
			foreach ( $wp_filter['woocommerce_new_order']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof WTO_Plugin ) {
						remove_action( 'woocommerce_new_order', $cb['function'], $priority );
					}
				}
			}
		}
	}
}
add_action( 'plugins_loaded', 'fixo_cart_neutralize_legacy', 1 );

/**
 * Deactivate the replaced cart plugin and tell the admin about both legacy plugins.
 */
function fixo_cart_legacy_admin() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$legacy = fixo_cart_legacy_basename( 'cart' );
	if ( $legacy && is_plugin_active( $legacy ) ) {
		deactivate_plugins( $legacy, true );
		set_transient( 'fixo_cart_notice_legacy_cart', 1, HOUR_IN_SECONDS );
	}
}
add_action( 'admin_init', 'fixo_cart_legacy_admin' );

function fixo_cart_admin_notices() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( get_transient( 'fixo_cart_notice_legacy_cart' ) ) {
		delete_transient( 'fixo_cart_notice_legacy_cart' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Fixo Cart replaces "BongDevs Cart Recovery for WooCommerce", so the old plugin was deactivated. All carts, settings and history are kept. You can delete the old plugin.', 'fixo-cart' ) . '</p></div>';
	}
	$telegram = fixo_cart_legacy_basename( 'telegram' );
	if ( $telegram ) {
		$url = wp_nonce_url( admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $telegram ) ), 'deactivate-plugin_' . $telegram );
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Fixo Cart now includes Telegram order notifications and the order dashboard, and has imported your Woo Telegram Orders bot settings. Its duplicate messages are already switched off — you can deactivate Woo Telegram Orders.', 'fixo-cart' ) . ' <a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Deactivate Woo Telegram Orders', 'fixo-cart' ) . '</a></p></div>';
	}
}
add_action( 'admin_notices', 'fixo_cart_admin_notices' );

function fixo_cart_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'Fixo Cart requires WooCommerce to be installed and active.', 'fixo-cart' ) .
		'</p></div>';
}

/**
 * Load all plugin classes.
 */
function fixo_cart_load_includes() {
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-install.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-cart.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-capture.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-source-tracker.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-cron.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-emails.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-recovery.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-order.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-tracking.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-coupons.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-notifications.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-orders.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-rest-api.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-settings.php';
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-exporter.php';
	require_once FIXO_CART_PLUGIN_DIR . 'admin/class-wccr-admin.php';
}

/**
 * Bootstrap once all plugins are loaded (so we can check for WooCommerce).
 */
function fixo_cart_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'fixo_cart_woocommerce_missing_notice' );
		return;
	}
	if ( class_exists( 'WCCR_Cart' ) ) {
		return; // Another copy already loaded these classes.
	}

	load_plugin_textdomain( 'fixo-cart', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	fixo_cart_load_includes();

	// Lightweight schema/data migrations when an existing installation is upgraded.
	WCCR_Install::maybe_upgrade();

	// Core capture + tracking (storefront).
	new WCCR_Capture();
	new WCCR_Source_Tracker();
	new WCCR_Recovery();

	// Order attribution + Meta CAPI / GA4 / webhook events for recovered orders.
	new WCCR_Tracking();

	// Telegram + WhatsApp notifications (leads, orders, abandoned / high-value carts).
	new WCCR_Notifications();

	// Cron: abandonment, recovery email sequence, retention.
	new WCCR_Cron();

	// REST API for the React dashboard.
	new WCCR_REST_API();
	new WCCR_Orders();

	new WCCR_Settings();

	if ( is_admin() ) {
		new WCCR_Admin();
		new WCCR_Exporter();
	}
}
add_action( 'plugins_loaded', 'fixo_cart_init' );

/**
 * Activation — create DB tables + schedule cron.
 */
function fixo_cart_activate() {
	require_once FIXO_CART_PLUGIN_DIR . 'includes/class-wccr-install.php';
	WCCR_Install::activate();
}
register_activation_hook( __FILE__, 'fixo_cart_activate' );

/**
 * Deactivation — clear scheduled events (data is kept).
 */
function fixo_cart_deactivate() {
	wp_clear_scheduled_hook( 'wccr_check_abandoned_carts' );
	wp_clear_scheduled_hook( 'wccr_cleanup_old_carts' );
}
register_deactivation_hook( __FILE__, 'fixo_cart_deactivate' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
