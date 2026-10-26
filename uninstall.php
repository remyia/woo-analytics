<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Cron and cached reports are always removed. Settings and the spend ledger are removed only when
 * "Remove all plugin data on uninstall" was enabled under Pulse > Settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

wp_clear_scheduled_hook( 'woo_pulse_weekly_digest' );

// Cached reports.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_woo\\_pulse\\_%' OR option_name LIKE '\\_transient\\_timeout\\_woo\\_pulse\\_%'" );

$settings = get_option( 'woo_pulse_settings', array() );
if ( ! is_array( $settings ) || empty( $settings['remove_data_on_uninstall'] ) ) {
	return;
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woo_pulse_spend" );

delete_option( 'woo_pulse_settings' );
delete_option( 'woo_pulse_db_version' );
delete_option( 'woo_pulse_cache_version' );
delete_option( 'woo_pulse_cache_bumped_at' );

foreach ( wp_roles()->role_objects as $role ) {
	if ( $role->has_cap( 'view_woo_pulse' ) ) {
		$role->remove_cap( 'view_woo_pulse' );
	}
}
