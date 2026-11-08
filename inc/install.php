<?php
/**
 * Bunseki Analytic – Database installation, migrations & settings registration
 *
 * @package Bunseki_Analytic
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Schema version; bump when bunseki_migrate_db() gets a new step. */
const BUNSEKI_DB_VERSION = 2;

add_action( 'plugins_loaded', 'bunseki_update_check' );
function bunseki_update_check() {
    if ( get_option( 'bunseki_version' ) !== BUNSEKI_VERSION ) {
        bunseki_install_db();
        update_option( 'bunseki_version', BUNSEKI_VERSION );
        bunseki_clear_dashboard_cache();
    }
}

register_activation_hook( BUNSEKI_FILE, 'bunseki_install_db' );

add_action( 'admin_init', 'bunseki_register_settings' );
function bunseki_register_settings() {
    // Each form gets its own option group: options.php resets every option of a group
    // that is missing from the submitted form, so shared groups wipe each other's values.
    register_setting( 'bunseki_settings_group', 'bunseki_block_bots', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ] );
    register_setting( 'bunseki_settings_group', 'bunseki_delete_on_uninstall', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ] );
    register_setting( 'bunseki_importer_group', 'bunseki_auto_log_path', [
        'type'              => 'string',
        'sanitize_callback' => 'bunseki_sanitize_log_path',
        'default'           => '',
    ] );
}

/**
 * Keeps the auto-import path a plain absolute file path.
 */
function bunseki_sanitize_log_path( $value ) {
    $value = trim( sanitize_text_field( (string) $value ) );
    if ( $value === '' ) return '';
    if ( str_contains( $value, '..' ) || str_contains( $value, '://' ) ) {
        add_settings_error( 'bunseki_auto_log_path', 'bunseki_bad_path', __( 'Invalid log path.', 'bunseki-analytic' ) );
        return (string) get_option( 'bunseki_auto_log_path', '' );
    }
    return $value;
}

function bunseki_install_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Main pageview table
    $table_users = $wpdb->prefix . 'bunseki_log';
    $sql_users   = "CREATE TABLE $table_users (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        url varchar(255) DEFAULT '' NOT NULL,
        referrer varchar(255) DEFAULT '' NOT NULL,
        ref_domain varchar(100) DEFAULT '' NOT NULL,
        utm_source varchar(50) DEFAULT '' NOT NULL,
        hash varchar(32) DEFAULT '' NOT NULL,
        device varchar(20) DEFAULT 'Desktop' NOT NULL,
        os varchar(50) DEFAULT 'Unknown' NOT NULL,
        browser varchar(50) DEFAULT 'Unknown' NOT NULL,
        lang varchar(5) DEFAULT 'en' NOT NULL,
        width int(5) DEFAULT 0 NOT NULL,
        load_time int(5) DEFAULT 0 NOT NULL,
        ttfb int(5) DEFAULT 0 NOT NULL,
        status int(3) DEFAULT 200 NOT NULL,
        duration int(10) DEFAULT 0 NOT NULL,
        search_term varchar(100) DEFAULT '' NOT NULL,
        search_results int(1) DEFAULT 1 NOT NULL,
        PRIMARY KEY  (id),
        KEY time (time),
        KEY url (url),
        KEY hash_url (hash,url)
    ) $charset_collate;";

    // Custom events table
    $table_events = $wpdb->prefix . 'bunseki_events';
    $sql_events   = "CREATE TABLE $table_events (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        event_name varchar(100) DEFAULT '' NOT NULL,
        event_val varchar(255) DEFAULT '' NOT NULL,
        url varchar(255) DEFAULT '' NOT NULL,
        hash varchar(32) DEFAULT '' NOT NULL,
        PRIMARY KEY  (id),
        KEY time (time)
    ) $charset_collate;";

    // Bot tracking table (one row per local day / bot / url / status)
    $table_bots = $wpdb->prefix . 'bunseki_bots';
    $sql_bots   = "CREATE TABLE $table_bots (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        date date DEFAULT '0000-00-00' NOT NULL,
        bot_name varchar(50) DEFAULT 'Unknown' NOT NULL,
        url varchar(255) DEFAULT '' NOT NULL,
        hits int(10) DEFAULT 1 NOT NULL,
        status int(3) DEFAULT 200 NOT NULL,
        last_seen datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY bot_day_url (date, bot_name, url, status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_users );
    dbDelta( $sql_bots );
    dbDelta( $sql_events );

    bunseki_migrate_db();

    // Register cron jobs
    if ( ! wp_next_scheduled( 'bunseki_weekly_email_event' ) ) {
        wp_schedule_event( time(), 'weekly', 'bunseki_weekly_email_event' );
    }
    if ( ! wp_next_scheduled( 'bunseki_daily_cleanup_event' ) ) {
        wp_schedule_event( time(), 'daily', 'bunseki_daily_cleanup_event' );
    }
    if ( ! wp_next_scheduled( 'bunseki_auto_import_event' ) ) {
        wp_schedule_event( time(), 'twicedaily', 'bunseki_auto_import_event' );
    }
}

/**
 * Versioned schema migrations that dbDelta cannot express.
 */
function bunseki_migrate_db() {
    global $wpdb;
    $from = (int) get_option( 'bunseki_db_version', 1 );
    if ( $from >= BUNSEKI_DB_VERSION ) return;

    $tbl = $wpdb->prefix . 'bunseki_log';

    if ( $from < 2 ) {
        // v2: idx_url duplicated the "url" key created by dbDelta – drop it.
        bunseki_drop_index( $tbl, 'idx_url' );

        // Secondary indexes for dashboard aggregation (previously added on admin_init).
        bunseki_ensure_index( $tbl, 'idx_ref', 'ref_domain' );
        bunseki_ensure_index( $tbl, 'idx_utm', 'utm_source' );
        bunseki_ensure_index( $tbl, 'idx_status', 'status' );
        bunseki_ensure_index( $tbl, 'idx_device', 'device' );

        // Options that no longer do anything.
        delete_option( 'bunseki_db_indexes_added_v1' );
        delete_option( 'bunseki_disable_live_bots' );
    }

    update_option( 'bunseki_db_version', BUNSEKI_DB_VERSION );
}

/**
 * True if the table has an index with this name.
 */
function bunseki_index_exists( string $table, string $name ): bool {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `$table` WHERE Key_name = %s", $name ) );
}

function bunseki_ensure_index( string $table, string $name, string $columns ): void {
    global $wpdb;
    if ( bunseki_index_exists( $table, $name ) ) return;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "ALTER TABLE `$table` ADD INDEX `$name` ($columns)" );
}

function bunseki_drop_index( string $table, string $name ): void {
    global $wpdb;
    if ( ! bunseki_index_exists( $table, $name ) ) return;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "ALTER TABLE `$table` DROP INDEX `$name`" );
}

register_deactivation_hook( BUNSEKI_FILE, 'bunseki_remove_schedule' );
function bunseki_remove_schedule() {
    wp_clear_scheduled_hook( 'bunseki_daily_cleanup_event' );
    wp_clear_scheduled_hook( 'bunseki_weekly_email_event' );
    wp_clear_scheduled_hook( 'bunseki_auto_import_event' );
}
