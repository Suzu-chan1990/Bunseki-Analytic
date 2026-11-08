<?php
/**
 * Bunseki Analytic – Uninstall
 *
 * Runs when the plugin is deleted from the Plugins screen.
 * Statistics are only removed if "Delete all statistics when the plugin is deleted"
 * was enabled in the Bunseki settings; otherwise only scheduled events are cleared.
 *
 * @package Bunseki_Analytic
 * @since   1.2.3
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

/**
 * Removes scheduled events, and optionally all data, for the current site.
 */
function bunseki_uninstall_site(): void {
    global $wpdb;

    foreach ( [ 'bunseki_daily_cleanup_event', 'bunseki_weekly_email_event', 'bunseki_auto_import_event' ] as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }

    if ( ! (int) get_option( 'bunseki_delete_on_uninstall', 0 ) ) {
        return;
    }

    foreach ( [ 'bunseki_log', 'bunseki_events', 'bunseki_bots' ] as $t ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`" );
    }

    // Options, import offsets and transients (dashboard cache, health)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'bunseki\\_%'
            OR option_name LIKE '\\_transient\\_bunseki\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_bunseki\\_%'"
    );
}

if ( is_multisite() ) {
    foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $bunseki_site_id ) {
        switch_to_blog( (int) $bunseki_site_id );
        bunseki_uninstall_site();
        restore_current_blog();
    }
} else {
    bunseki_uninstall_site();
}
