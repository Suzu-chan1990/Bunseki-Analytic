<?php
/**
 * Plugin Name: Bunseki Analytic
 * Description: Self-hosted visitor statistics for WordPress: own tracking endpoint, bot statistics, optional AI bot blocker and access log import.
 * Version: 1.3.0
 * Author: Saguya
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.1
 * Tested up to: 7.2 
 * Requires PHP: 8.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BUNSEKI_VERSION', '1.3.0' );
define( 'BUNSEKI_FILE', __FILE__ );
define( 'BUNSEKI_PATH', plugin_dir_path( __FILE__ ) );
define( 'BUNSEKI_URL', plugin_dir_url( __FILE__ ) );

require_once BUNSEKI_PATH . 'inc/install.php';
require_once BUNSEKI_PATH . 'inc/common.php';
require_once BUNSEKI_PATH . 'inc/log-parser.php';
require_once BUNSEKI_PATH . 'inc/endpoint.php';
require_once BUNSEKI_PATH . 'inc/cli.php';

if ( is_admin() ) {
    require_once BUNSEKI_PATH . 'inc/admin.php';
}

// ========================================================================
// 🛡️ Firewall: block AI bots & scrapers (early, on plugins_loaded)
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
            // Record the blocked bot before exiting so it stays visible in the bot statistics.
            bunseki_insert_bot_hit( $bot );
            status_header( 403 );
            nocache_headers();
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'Access denied by Bunseki Firewall. Unauthorized AI scrapers are strictly prohibited.';
            exit;
        }
    }
}

// ========================================================================
// 📡 Live Bot Tracker
// ========================================================================
// Intentionally not attached to the shutdown hook. A DB write on every
// frontend request adds avoidable latency on high-traffic installations.
// Bots that are actually blocked by the firewall are still recorded.

// ========================================================================
// 📦 Frontend: enqueue tracker script
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

    // REST endpoint URL for b-core.js
    wp_localize_script( 'bunseki-core', 'bunsekiAjax', [
        'rest_url' => esc_url_raw( rest_url( 'bunseki/v1/track' ) ),
    ] );

    wp_localize_script( 'bunseki-core', 'bunseki_config', [
        'status' => is_404() ? 404 : 200,
    ] );
}

// ========================================================================
// 🚀 Cache & optimizer bypass (prevents delay/defer of b-core.js)
// ========================================================================

/**
 * Adds data attributes that tell common cache plugins
 * not to delay or minify b-core.js.
 *
 * @param string $tag    Script tag.
 * @param string $handle Script handle.
 * @param string $src    Script URL.
 * @return string Modified script tag.
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
 * Excludes b-core.js from delay/defer in PHP-based cache plugins.
 *
 * @param array|string $exclusions Existing exclusions.
 * @return array|string Extended exclusions.
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
    // Bunseki_CLI::parse_log() also works outside WP-CLI (all CLI output is guarded).
    $cli = new Bunseki_CLI();
    $cli->parse_log( [ $path ], [] );
}

// ========================================================================
// 📊 WordPress dashboard widget (7-day overview)
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

/**
 * Visitors (sum of daily unique hashes) and pageviews of the last 7 days.
 *
 * @return array{0:int,1:int} [ visitors, views ]
 */
function bunseki_last_7_days_totals(): array {
    global $wpdb;
    $tbl   = $wpdb->prefix . 'bunseki_log';
    $limit = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
    $off   = Bunseki_Helper::tz_offset();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT COUNT(*) AS c, COUNT(DISTINCT hash) AS u FROM $tbl WHERE time > %s GROUP BY DATE(DATE_ADD(time, INTERVAL %d SECOND))",
        $limit, $off
    ) );
    $v = 0; $p = 0;
    foreach ( (array) $rows as $r ) { $v += (int) $r->u; $p += (int) $r->c; }
    return [ $v, $p ];
}

function bunseki_render_dashboard_widget() {
    [ $v, $p ] = bunseki_last_7_days_totals();

    echo '<div style="display:flex;justify-content:space-around;text-align:center;padding:15px 0;">';
    echo '<div><strong style="font-size:28px;color:#06b6d4;">' . esc_html( number_format( (float) $v ) ) . '</strong><br><span style="color:#64748b;">' . esc_html__( 'Visitors', 'bunseki-analytic' ) . '</span></div>';
    echo '<div><strong style="font-size:28px;color:#8b5cf6;">' . esc_html( number_format( (float) $p ) ) . '</strong><br><span style="color:#64748b;">' . esc_html__( 'Views', 'bunseki-analytic' ) . '</span></div>';
    echo '</div>';
    echo '<hr style="border:0;border-top:1px solid #e2e8f0;margin:15px 0;">';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=bunseki-analytic' ) ) . '" class="button button-primary" style="width:100%;text-align:center;">' . esc_html__( 'Open Full Dashboard', 'bunseki-analytic' ) . '</a>';
}

// ========================================================================
// 📧 Weekly e-mail report
// ========================================================================
add_action( 'bunseki_weekly_email_event', 'bunseki_send_weekly_report' );
function bunseki_send_weekly_report() {
    $to = get_option( 'admin_email' );
    if ( ! $to ) return;

    [ $v, $p ] = bunseki_last_7_days_totals();

    /* translators: %s: number of visitors */
    $subject = sprintf(
        __( '📊 Bunseki: Your Weekly Traffic Report (%s Visitors)', 'bunseki-analytic' ),
        number_format( (float) $v )
    );

    $message = sprintf(
        /* translators: 1: number of visitors, 2: number of pageviews */
        __( "Hello!\n\nHere is your weekly traffic summary:\n\nVisitors: %1\$s\nPageviews: %2\$s\n\nLog in to your dashboard for more details.\n\nYour Bunseki Analytic Plugin", 'bunseki-analytic' ),
        number_format( (float) $v ),
        number_format( (float) $p )
    );

    wp_mail( $to, $subject, $message );
}

// ========================================================================
// 🔒 Opt-Out Shortcode
// ========================================================================
add_shortcode( 'bunseki_opt_out', 'bunseki_render_opt_out' );
function bunseki_render_opt_out() {
    $done = esc_js( __( 'Tracking disabled successfully.', 'bunseki-analytic' ) );
    return '<div class="bunseki-opt-out-wrap" style="padding:15px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin-top:0;">' . esc_html__( 'Click the button below to opt-out of all statistical tracking on this website.', 'bunseki-analytic' ) . '</p>
        <button type="button" style="padding:8px 16px;background:#334155;color:white;border:none;border-radius:4px;cursor:pointer;"
            onclick="document.cookie=\'bunseki_dnt=1; max-age=31536000; path=/; SameSite=Lax\'; this.disabled=true; this.nextElementSibling.textContent=\'' . $done . '\';">
            ' . esc_html__( 'Disable Tracking', 'bunseki-analytic' ) . '
        </button>
        <span class="bunseki-opt-out-status" role="status" style="margin-left:10px;font-weight:600;"></span>
    </div>';
}

// ========================================================================
// GitHub updates (Plugin Update Checker, bundled in lib/)
// ========================================================================
add_action( 'init', 'bunseki_init_update_checker' );
function bunseki_init_update_checker() {
    // Update checks only matter in the admin, for WP-CLI and for WP-Cron
    if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron() ) {
        return;
    }

    $puc_path = BUNSEKI_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
    if ( ! is_readable( $puc_path ) ) {
        return; // Library missing: no update checks, the plugin keeps working
    }
    require_once $puc_path;

    $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/Bunseki-Analytic/',
        BUNSEKI_FILE,
        'bunseki-analytic'
    );

    // Install the ZIP attached to the GitHub release (folder "bunseki-analytic" inside).
    // Without an attached ZIP, PUC falls back to the source code archive of the release tag.
    $checker->getVcsApi()->enableReleaseAssets();
}
