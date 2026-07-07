<?php
/**
 * Plugin Name: Bunseki Analytic
 * Description: High Scale Analytics (Stealth & Secure).
 * Version: 1.2.0
 * Author: Saguya
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BUNSEKI_VERSION', '1.2.0' );
define( 'BUNSEKI_PATH', plugin_dir_path( __FILE__ ) );
define( 'BUNSEKI_URL', plugin_dir_url( __FILE__ ) );

require_once BUNSEKI_PATH . 'inc/install.php';
require_once BUNSEKI_PATH . 'inc/common.php';

if ( is_admin() ) {
    require_once BUNSEKI_PATH . 'inc/admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once BUNSEKI_PATH . 'inc/cli.php';
}

// ========================================================================
// 🛡️ Firewall: AI-Bots & Scraper blockieren (plugins_loaded, früh)
// ========================================================================
add_action( 'plugins_loaded', 'bunseki_firewall_check' );
function bunseki_firewall_check() {
    if ( ! get_option( 'bunseki_block_bots', 0 ) ) return;
    if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) return;

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if ( empty( $ua ) ) return;

    $bot = Bunseki_Helper::detect_bot( $ua );

    if ( $bot ) {
        $is_blocked = (
            strpos( $bot, '(AI Data)' )   !== false ||
            strpos( $bot, '(OpenAI)' )    !== false ||
            strpos( $bot, '(Anthropic)' ) !== false ||
            strpos( $bot, 'Tool/' )       !== false ||
            strpos( $bot, 'ByteSpider' )  !== false ||
            strpos( $bot, 'Omgili' )      !== false ||
            strpos( $bot, 'Perplexity' )  !== false
        );

        if ( $is_blocked ) {
            // FIX: Geblockte Bots werden VOR dem die() noch getrackt,
            // damit sie in der Bot-Statistik sichtbar bleiben.
            bunseki_insert_bot_hit( $bot );
            header( 'HTTP/1.1 403 Forbidden' );
            die( 'Access denied by Bunseki Firewall. Unauthorized AI scrapers are strictly prohibited.' );
        }
    }
}

// ========================================================================
// 📡 Live Bot Tracker (shutdown hook für alle nicht-geblockten Bots)
// ========================================================================
add_action( 'shutdown', 'bunseki_live_bot_tracker' );
function bunseki_live_bot_tracker() {
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
    if ( defined( 'WP_CLI' ) && WP_CLI ) return;

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if ( empty( $ua ) ) return;

    $bot_name = Bunseki_Helper::detect_bot( $ua );
    if ( $bot_name ) {
        bunseki_insert_bot_hit( $bot_name );
    }
}

/**
 * Zentraler Bot-Insert (wird von Firewall & Live-Tracker genutzt).
 * FIX: detect_bot() wird jetzt nur noch einmal pro Request aufgerufen.
 *
 * @param string $bot_name Erkannter Bot-Name aus Bunseki_Helper::detect_bot().
 */
function bunseki_insert_bot_hit( $bot_name ) {
    global $wpdb;
    $tbl_bot = $wpdb->prefix . 'bunseki_bots';
    $url     = isset( $_SERVER['REQUEST_URI'] )
        ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
        : '/';
    $date    = gmdate( 'Y-m-d' );
    $now     = current_time( 'mysql' );
    $status  = http_response_code() ?: 200;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO $tbl_bot (date, bot_name, url, hits, status, last_seen)
             VALUES (%s, %s, %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s",
            $date, $bot_name, $url, 1, $status, $now, $now
        )
    );
}

// ========================================================================
// 📦 Frontend: Tracker-Script einbinden
// ========================================================================
add_action( 'wp_enqueue_scripts', 'bunseki_enqueue_tracker' );
function bunseki_enqueue_tracker() {
    if ( is_user_logged_in() ) return;

    wp_enqueue_script(
        'bunseki-core',
        BUNSEKI_URL . 'js/b-core.js',
        [],
        BUNSEKI_VERSION,
        true
    );

    // REST-Endpunkt URL für b-core.js
    wp_localize_script( 'bunseki-core', 'bunsekiAjax', [
        'rest_url' => esc_url_raw( rest_url( 'bunseki/v1/track' ) ),
    ] );

    wp_localize_script( 'bunseki-core', 'bunseki_config', [
        'status' => is_404() ? 404 : 200,
    ] );
}

// ========================================================================
// 🚀 Cache & Optimizer Bypass (verhindert Delay/Defer von b-core.js)
// ========================================================================

/**
 * Fügt data-Attribute hinzu, die gängige Cache-Plugins anweisen,
 * b-core.js nicht zu verzögern oder zu minifizieren.
 *
 * @param string $tag    Der Script-Tag.
 * @param string $handle Das Script-Handle.
 * @param string $src    Die Script-URL.
 * @return string Modifizierter Script-Tag.
 */
function bunseki_universal_cache_bypass( $tag, $handle, $src ) {
    if ( 'bunseki-core' === $handle || false !== strpos( (string) $src, 'b-core.js' ) ) {
        $tag = str_replace(
            '<script ',
            '<script data-cfasync="false" data-noptimize="1" data-no-minify="1" data-wpfc-render="false" ',
            $tag
        );
    }
    return $tag;
}
add_filter( 'script_loader_tag', 'bunseki_universal_cache_bypass', 10, 3 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
add_filter( 'rocket_delay_js_exclusions',    'bunseki_exclude_js_php' );
add_filter( 'rocket_exclude_js',             'bunseki_exclude_js_php' );
add_filter( 'litespeed_optm_js_defer_exc',   'bunseki_exclude_js_php' );
add_filter( 'litespeed_optm_js_delay_exc',   'bunseki_exclude_js_php' );
add_filter( 'perfmatters_delay_js_exclusions','bunseki_exclude_js_php' );
add_filter( 'flying_scripts_exclude_keywords','bunseki_exclude_js_php' );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Schließt b-core.js von Delay/Defer in PHP-basierten Cache-Plugins aus.
 *
 * @param array|string $exclusions Bestehende Ausschlüsse.
 * @return array|string Erweiterte Ausschlüsse.
 */
function bunseki_exclude_js_php( $exclusions ) {
    if ( is_array( $exclusions ) ) {
        $exclusions[] = 'b-core.js';
        return $exclusions;
    }
    return $exclusions . ', b-core.js';
}

// ========================================================================
// 🔁 Cron: Auto-Import & Cleanup
// ========================================================================
add_action( 'bunseki_daily_cleanup_event',  'bunseki_garbage_collection' );
add_action( 'bunseki_auto_import_event',    'bunseki_auto_import_cron' );

function bunseki_auto_import_cron() {
    $path = get_option( 'bunseki_auto_log_path' );
    if ( ! $path || ! file_exists( $path ) ) return;
    if ( ! class_exists( 'Bunseki_CLI' ) ) {
        require_once BUNSEKI_PATH . 'inc/cli.php';
    }
    $cli = new Bunseki_CLI();
    $cli->parse_log( [ $path ], [] );
}

// ========================================================================
// 📊 WordPress Dashboard Widget (7-Tage-Übersicht)
// ========================================================================
add_action( 'wp_dashboard_setup', 'bunseki_dashboard_widget' );
function bunseki_dashboard_widget() {
    if ( current_user_can( 'manage_options' ) ) {
        wp_add_dashboard_widget(
            'bunseki_dash_widget',
            '📊 Bunseki Analytic (7 Days)',
            'bunseki_render_dashboard_widget'
        );
    }
}

function bunseki_render_dashboard_widget() {
    global $wpdb;
    $tbl   = $wpdb->prefix . 'bunseki_log';
    $limit = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $v = $wpdb->get_var( "SELECT COUNT(DISTINCT hash) FROM $tbl WHERE time > '$limit'" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $p = $wpdb->get_var( "SELECT COUNT(*) FROM $tbl WHERE time > '$limit'" );

    echo '<div style="display:flex;justify-content:space-around;text-align:center;padding:15px 0;">';
    echo '<div><strong style="font-size:28px;color:#06b6d4;">' . esc_html( number_format( (float) $v ) ) . '</strong><br><span style="color:#64748b;">' . esc_html__( 'Visitors', 'bunseki-analytic' ) . '</span></div>';
    echo '<div><strong style="font-size:28px;color:#8b5cf6;">' . esc_html( number_format( (float) $p ) ) . '</strong><br><span style="color:#64748b;">' . esc_html__( 'Views', 'bunseki-analytic' ) . '</span></div>';
    echo '</div>';
    echo '<hr style="border:0;border-top:1px solid #e2e8f0;margin:15px 0;">';
    echo '<a href="admin.php?page=bunseki-analytic" class="button button-primary" style="width:100%;text-align:center;">' . esc_html__( 'Open Full Dashboard', 'bunseki-analytic' ) . '</a>';
}

// ========================================================================
// 📧 Wöchentlicher E-Mail-Report
// ========================================================================
add_action( 'bunseki_weekly_email_event', 'bunseki_send_weekly_report' );
function bunseki_send_weekly_report() {
    $to = get_option( 'admin_email' );
    if ( ! $to ) return;

    global $wpdb;
    $tbl   = $wpdb->prefix . 'bunseki_log';
    $limit = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $v = $wpdb->get_var( "SELECT COUNT(DISTINCT hash) FROM $tbl WHERE time > '$limit'" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $p = $wpdb->get_var( "SELECT COUNT(*) FROM $tbl WHERE time > '$limit'" );

    /* translators: %s: Besucherzahl */
    $subject = sprintf(
        __( '📊 Bunseki: Your Weekly Traffic Report (%s Visitors)', 'bunseki-analytic' ),
        number_format( (float) $v )
    );

    $message = sprintf(
        /* translators: 1: Besucherzahl, 2: Seitenaufrufe */
        __( "Hello!\n\nHere is your weekly traffic summary:\n\nVisitors: %1\$s\nPageviews: %2\$s\n\nLog in to your dashboard for more details.\n\nYour Bunseki Analytic Plugin", 'bunseki-analytic' ),
        number_format( (float) $v ),
        number_format( (float) $p )
    );

    wp_mail( $to, $subject, $message );
}

// ========================================================================
// ⚡ DB-Indizes einmalig anlegen (schnellere Aggregation)
// ========================================================================
add_action( 'admin_init', 'bunseki_add_db_indexes_once' );
function bunseki_add_db_indexes_once() {
    if ( get_option( 'bunseki_db_indexes_added_v1' ) === '1' ) return;

    global $wpdb;
    $tbl = $wpdb->prefix . 'bunseki_log';

    $wpdb->suppress_errors( true );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query( "ALTER TABLE $tbl ADD INDEX idx_url    (url(191))" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query( "ALTER TABLE $tbl ADD INDEX idx_ref    (ref_domain(100))" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query( "ALTER TABLE $tbl ADD INDEX idx_utm    (utm_source(50))" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query( "ALTER TABLE $tbl ADD INDEX idx_status (status)" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query( "ALTER TABLE $tbl ADD INDEX idx_device (device)" );
    $wpdb->suppress_errors( false );

    update_option( 'bunseki_db_indexes_added_v1', '1' );
}

// ========================================================================
// 🔒 Opt-Out Shortcode
// ========================================================================
add_shortcode( 'bunseki_opt_out', 'bunseki_render_opt_out' );
function bunseki_render_opt_out() {
    return '<div class="bunseki-opt-out-wrap" style="padding:15px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin-top:0;">' . esc_html__( 'Click the button below to opt-out of all statistical tracking on this website.', 'bunseki-analytic' ) . '</p>
        <button style="padding:8px 16px;background:#334155;color:white;border:none;border-radius:4px;cursor:pointer;"
            onclick="document.cookie=\'bunseki_dnt=1; max-age=31536000; path=/\'; alert(\'' . esc_js( __( 'Tracking disabled successfully.', 'bunseki-analytic' ) ) . '\');">
            ' . esc_html__( 'Disable Tracking', 'bunseki-analytic' ) . '
        </button>
    </div>';
}
