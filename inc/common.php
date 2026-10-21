<?php
/**
 * Bunseki Analytic – Gemeinsame Hilfsfunktionen
 *
 * @package Bunseki_Analytic
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bunseki_Helper {

    /**
     * Erkennt bekannte Bots anhand des User-Agents.
     *
     * @param string $ua User-Agent-String.
     * @return string|false Bot-Name oder false wenn kein Bot erkannt.
     */
    public static function detect_bot( $ua ) {
        if ( empty( $ua ) ) return false;

        // Stand 2026: Search Engines, SEO-Tools, AI/LLM-Scraper, Social, Generic Tools
        $bots = [
            // Search Engines (werden NICHT geblockt, nur getrackt)
            'Googlebot'            => 'Google',
            'bingbot'              => 'Bing',
            'Yandex'               => 'Yandex',
            'Baiduspider'          => 'Baidu',
            'DuckDuckBot'          => 'DuckDuckGo',

            // SEO Tools
            'AhrefsBot'            => 'Ahrefs (SEO)',
            'MJ12bot'              => 'Majestic (SEO)',
            'SemrushBot'           => 'Semrush (SEO)',
            'DotBot'               => 'Moz (SEO)',
            'PetalBot'             => 'Petal',

            // AI & LLM Scraper – werden bei aktivierter Firewall geblockt
            'GPTBot'               => 'GPTBot (OpenAI)',
            'ChatGPT-User'         => 'ChatGPT (OpenAI)',
            'ClaudeBot'            => 'Claude (Anthropic)',
            'CCBot'                => 'CommonCrawl (AI Data)',
            'Applebot'             => 'Applebot',
            'Amazonbot'            => 'Amazon',
            'Diffbot'              => 'Diffbot (AI Data)',
            'Bytespider'           => 'ByteSpider (TikTok)',
            'ImagesiftBot'         => 'Imagesift',
            'Omgilibot'            => 'Omgili',
            'PerplexityBot'        => 'Perplexity',

            // Social & Messenger (Vorschau-Fetcher)
            'FacebookExternalHit'  => 'Facebook',
            'Twitterbot'           => 'Twitter',
            'Pinterest'            => 'Pinterest',
            'Discordbot'           => 'Discord',
            'TelegramBot'          => 'Telegram',
            'WhatsApp'             => 'WhatsApp',

            // Generic Tools – werden bei aktivierter Firewall geblockt
            'curl'                 => 'Tool/Curl',
            'python'               => 'Tool/Python',
            'wget'                 => 'Tool/Wget',
            'Go-http-client'       => 'Tool/Go',
        ];

        foreach ( $bots as $key => $name ) {
            if ( stripos( $ua, $key ) !== false ) return $name;
        }

        return false;
    }

    /**
     * Returns the real client IP.
     * Proxy headers are only trusted if the direct peer is a private/reserved address
     * (local nginx/proxy) or BUNSEKI_TRUST_PROXY_HEADERS is defined as true.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $trusted = ( defined( 'BUNSEKI_TRUST_PROXY_HEADERS' ) && BUNSEKI_TRUST_PROXY_HEADERS )
            || ! filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

        if ( $trusted ) {
            foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ] as $h ) {
                if ( empty( $_SERVER[ $h ] ) ) continue;
                $candidate = trim( explode( ',', (string) $_SERVER[ $h ] )[0] );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
            }
        }
        return $remote;
    }

    /**
     * True for URLs that are never real pageviews (API, cron, feeds, etc.).
     */
    public static function is_noise_path( string $path ): bool {
        return (bool) preg_match(
            '#^/(wp-json|wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|robots\.txt|favicon|\.well-known|feed)|admin-ajax\.php|/feed/?$|sitemap[^/]*\.xml$#i',
            $path
        );
    }

    /**
     * True if a day already has live-tracked rows (width > 0).
     * Log imports skip human rows for such days to avoid double counting.
     */
    public static function day_has_live_data( string $date ): bool {
        static $cache = [];
        if ( isset( $cache[ $date ] ) ) return $cache[ $date ];

        global $wpdb;
        $tbl = $wpdb->prefix . 'bunseki_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM $tbl WHERE time >= %s AND time < DATE_ADD(%s, INTERVAL 1 DAY) AND width > 0 LIMIT 1",
            $date . ' 00:00:00', $date . ' 00:00:00'
        ) );
        return $cache[ $date ] = ( $found !== null );
    }

    /**
     * Decides whether a (non-bot) log line counts as a human pageview.
     */
    public static function is_countable_pageview( string $method, string $path, int $status, string $log_date ): bool {
        if ( strtoupper( $method ) !== 'GET' ) return false;
        if ( ! in_array( $status, [ 200, 404 ], true ) ) return false;
        if ( self::is_noise_path( $path ) ) return false;
        if ( self::day_has_live_data( $log_date ) ) return false;
        return true;
    }
}

/**
 * Records one bot hit (aggregated per day/bot/url/status).
 * Used by the firewall (403) and the tracking endpoint (JS-executing bots).
 */
function bunseki_insert_bot_hit( $bot_name, $url = null, $status = 403 ) {
    global $wpdb;

    if ( null === $url ) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $url = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
    }
    $url = substr( (string) preg_replace( '/[?#].*/', '', (string) $url ), 0, 255 );
    if ( $url === '' ) $url = '/';

    $tbl = $wpdb->prefix . 'bunseki_bots';
    $now = gmdate( 'Y-m-d H:i:s' );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $tbl (date, bot_name, url, hits, status, last_seen)
         VALUES (%s, %s, %s, 1, %d, %s)
         ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s",
        gmdate( 'Y-m-d' ), substr( (string) $bot_name, 0, 50 ), $url, (int) $status, $now, $now
    ) );
}

/**
 * Deletes all dashboard caches (one transient per range).
 */
function bunseki_clear_dashboard_cache() {
    foreach ( [ '30', '60', '90', '120', 'all' ] as $r ) {
        delete_transient( 'bunseki_dashboard_stats_v7_' . $r );
    }
}

/**
 * Garbage Collection: Löscht Logs die älter als 10 Jahre sind.
 * Wird täglich via WP-Cron ausgeführt (bunseki_daily_cleanup_event).
 *
 * Vorhaltezeit: 3650 Tage (10 Jahre) für Gesamtstatistiken.
 */
function bunseki_garbage_collection() {
    global $wpdb;

    $days    = 3650;
    $tbl_usr = $wpdb->prefix . 'bunseki_log';
    $tbl_bot = $wpdb->prefix . 'bunseki_bots';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        $wpdb->prepare( "DELETE FROM $tbl_usr WHERE time < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        $wpdb->prepare( "DELETE FROM $tbl_bot WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)", $days )
    );
}
