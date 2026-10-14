<?php
/**
 * Fires only when the plugin is deleted from wp-admin (not on deactivate).
 * Removing data is opt-in via a setting so people don't lose their leads by accident.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Safety switch: only wipe data if the store owner explicitly opted in via Settings
// (add a "wccr_delete_data_on_uninstall" = 'yes' option from the dashboard if you want this).
if ( 'yes' !== get_option( 'wccr_delete_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wccr_carts" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wccr_cart_events" );

$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wccr_%'" );
foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'wccr_check_abandoned_carts' );
wp_clear_scheduled_hook( 'wccr_cleanup_old_carts' );
wp_unschedule_hook( 'wccr_send_notification' );
wp_unschedule_hook( 'wccr_lead_notification' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'fixo-cart' );
}
