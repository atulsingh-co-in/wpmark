<?php
/**
 * Clean up when WPMark is deleted from the Plugins screen.
 *
 * Removes WPMark's settings and its OAuth tables, which signs every AI app
 * out. Connection keys (Application Passwords) belong
 * to each person's WordPress account, not to WPMark, so they are left for
 * people to revoke from their profile.
 *
 * @package WPMark
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wpmark_settings' );
delete_option( 'wpmark_oauth_schema' );
wp_clear_scheduled_hook( 'wpmark_oauth_cleanup' );

// OAuth sign-ins and registered apps. The plugin is not loaded here, so the table names are spelled out.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own tables on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmark_oauth_tokens" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmark_oauth_clients" );
// phpcs:enable

delete_transient( 'wpmark_welcome' );
delete_transient( 'wpmark_connection_check' );
