<?php
/**
 * Clean up when WPMark is deleted from the Plugins screen.
 *
 * Removes WPMark's settings and its OAuth tables, which signs every AI app
 * out. Drafts that AI apps created are the site's content and stay. Connection keys (Application Passwords) belong
 * to each person's WordPress account, not to WPMark, so they are left for
 * people to revoke from their profile.
 *
 * @package WPMark
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wpmark_settings' );
delete_option( 'wpmark_oauth_schema' );
delete_option( 'wpmark_connection_log' );
wp_clear_scheduled_hook( 'wpmark_oauth_cleanup' );

// OAuth sign-ins and registered apps. The plugin is not loaded here, so the table names are spelled out.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own tables on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmark_oauth_tokens" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpmark_oauth_clients" );
// phpcs:enable

// Drafts AI apps created stay: they are the site's content. Only WPMark's marker on them goes.
// Custom-designed pages keep their CSS and layout (_wpmark_css, _wpmark_layout), so they look right again if WPMark is reinstalled.
delete_post_meta_by_key( '_wpmark_draft' );

delete_transient( 'wpmark_welcome' );
delete_transient( 'wpmark_connection_check' );
