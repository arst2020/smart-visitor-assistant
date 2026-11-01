<?php
/**
 * Remove all plugin data on uninstall.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sva_index" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sva_logs" );  // phpcs:ignore
foreach ( array( 'sva_settings', 'sva_profile', 'sva_scan_state', 'sva_db_version' ) as $opt ) {
	delete_option( $opt );
}
wp_clear_scheduled_hook( 'sva_weekly_scan' );
wp_unschedule_hook( 'sva_cron_scan' );
