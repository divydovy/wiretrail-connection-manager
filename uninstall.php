<?php
/**
 * Removes Connections Manager data when the plugin is deleted.
 *
 * @package ConnectionsManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}connections_manager_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}connections_manager_alerts" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'cmgr_enabled', 'cmgr_retention_days', 'cmgr_ignored_domains', 'cmgr_schema_version' ) as $cmgr_option ) {
	delete_option( $cmgr_option );
}

delete_transient( 'cmgr_activation_notice' );
wp_clear_scheduled_hook( 'cmgr_daily_retention_cleanup' );
