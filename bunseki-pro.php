<?php
/**
 * Plugin Name: Bunseki Pro
 * Description: v1.0.1 - High Scale Analytics (Stealth & Secure).
 * Version: 1.0.1
 * Author: すずちゃん
 */

if (!defined('ABSPATH')) exit;

define('BUNSEKI_VERSION', '1.0.1');
define('BUNSEKI_PATH', plugin_dir_path(__FILE__));
define('BUNSEKI_URL', plugin_dir_url(__FILE__));

require_once BUNSEKI_PATH . 'inc/install.php';
require_once BUNSEKI_PATH . 'inc/common.php';

if (is_admin()) {
    require_once BUNSEKI_PATH . 'inc/admin.php';
}

if (defined('WP_CLI') && WP_CLI) {
    require_once BUNSEKI_PATH . 'inc/cli.php';
}

add_action('wp_enqueue_scripts', 'bunseki_enqueue_tracker');
function bunseki_enqueue_tracker() {
    if (is_user_logged_in()) return;
    wp_enqueue_script('bunseki-core', BUNSEKI_URL . 'js/b-core.js', [], BUNSEKI_VERSION, true);
    wp_localize_script('bunseki-core', 'bunseki_config', [
        'endpoint' => BUNSEKI_URL . 'inc/endpoint.php',
        'status'   => is_404() ? 404 : 200
    ]);
}

add_action('bunseki_daily_cleanup_event', 'bunseki_garbage_collection');
add_action('bunseki_auto_import_event', 'bunseki_auto_import_cron');

function bunseki_auto_import_cron() {
    $path = get_option('bunseki_auto_log_path');
    if (!$path || !file_exists($path)) return;
    if (!class_exists('Bunseki_CLI')) require_once BUNSEKI_PATH . 'inc/cli.php';
    $cli = new Bunseki_CLI();
    $cli->parse_log([$path], []);
}

// ========================================================================
// Live Bot Tracker (replaces the need to parse server logs)
// ========================================================================
add_action('shutdown', 'bunseki_live_bot_tracker');

function bunseki_live_bot_tracker() {
    // Ignore Cronjobs and WP-CLI to prevent loops or unwanted tracking
    if ( defined('DOING_CRON') && DOING_CRON ) return;
    if ( defined('WP_CLI') && WP_CLI ) return;

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
    if ( empty($ua) ) return;

    // Uses your existing bot detection from common.php
    $bot_name = Bunseki_Helper::detect_bot($ua);
    
    // If a bot is detected, insert it directly into the database
    if ( $bot_name ) {
        global $wpdb;
        $tbl_bot = $wpdb->prefix . 'bunseki_bots';
        
        $url    = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $date   = gmdate('Y-m-d'); // WPCS compliant
        $now    = current_time('mysql');
        $status = http_response_code() ?: 200;

        // Secure insert with duplicate key update
        $sql = "INSERT INTO $tbl_bot (date, bot_name, url, hits, status, last_seen) 
                VALUES (%s, %s, %s, %d, %d, %s) 
                ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s";
                
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( $sql, $date, $bot_name, $url, 1, $status, $now, $now ) );
    }
}	

// --- GitHub Updater (Plugin Update Checker) ---
add_action('init', function () {
    // Nur im Admin wirklich nötig, aber init ist ok
    if ( ! is_admin() ) {
        return;
    }

    $puc_path = TEGATAI_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists($puc_path) ) {
        return; // Library fehlt
    }

    require_once $puc_path;

    // Build checker
    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/Bunseki/',
        __FILE__,
        'Bunseki' // Plugin-Slug (sollte stabil sein)
    );

    // Wenn du Releases/Tags nutzt:
    $updateChecker->getVcsApi()->enableReleaseAssets();

    // Falls du (statt Releases) direkt den main-Branch “auslieferst”:
    // $updateChecker->setBranch('main');

    // Optional: falls dein Hauptplugin nicht im Repo-Root läge (bei dir liegt es im Root -> passt)
    // $updateChecker->setPluginFileName('tegatai-secure.php');
});
