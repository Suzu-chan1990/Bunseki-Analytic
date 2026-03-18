<?php
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'bunseki_update_check');

function bunseki_update_check() {
    if (get_option('bunseki_version') !== BUNSEKI_VERSION) {
        bunseki_install_db();
        update_option('bunseki_version', BUNSEKI_VERSION);
    }
}

register_activation_hook(dirname(__DIR__) . '/bunseki-pro.php', 'bunseki_install_db');

// phpcs:ignore PluginCheck.CodeAnalysis.SettingSanitization.register_settingMissing
function bunseki_register_settings() { register_setting('bunseki_importer_group', 'bunseki_auto_log_path'); }
add_action('admin_init', 'bunseki_register_settings');
function bunseki_install_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $table_users = $wpdb->prefix . 'bunseki_log';
    
    // FIX: Syntax für dbDelta optimiert (Newlines wichtig!)
    $sql_users = "CREATE TABLE $table_users (
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
        KEY url (url)
    ) $charset_collate;";

    $table_bots = $wpdb->prefix . 'bunseki_bots';
    
    // FIX: Syntax für dbDelta optimiert
    $sql_bots = "CREATE TABLE $table_bots (
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

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_users);
    dbDelta($sql_bots);
    
    // Cronjob sicherstellen
    if (!wp_next_scheduled('bunseki_daily_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'bunseki_daily_cleanup_event');
    }
    if (!wp_next_scheduled('bunseki_auto_import_event')) {
        wp_schedule_event(time(), 'twicedaily', 'bunseki_auto_import_event');
    }
}

register_deactivation_hook(dirname(__DIR__) . '/bunseki-pro.php', 'bunseki_remove_schedule');
function bunseki_remove_schedule() {
    wp_clear_scheduled_hook('bunseki_daily_cleanup_event');
}
