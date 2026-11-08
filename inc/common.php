<?php
/**
 * Bunseki Analytic – Shared helpers
 *
 * @package Bunseki_Analytic
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bunseki_Helper {

    /**
     * Detects known bots by user agent.
     *
     * @param string $ua User agent string.
     * @return string|false Bot name or false if no bot was detected.
     */
    public static function detect_bot( $ua ) {
        if ( empty( $ua ) ) return false;

        // As of 2026: search engines, SEO tools, AI/LLM scrapers, social fetchers, generic tools
        $bots = [
            // Search engines (tracked, never blocked)
            'Googlebot'            => 'Google',
            'bingbot'              => 'Bing',
            'Yandex'               => 'Yandex',
            'Baiduspider'          => 'Baidu',
            'DuckDuckBot'          => 'DuckDuckGo',

            // SEO tools
            'AhrefsBot'            => 'Ahrefs (SEO)',
            'MJ12bot'              => 'Majestic (SEO)',
            'SemrushBot'           => 'Semrush (SEO)',
            'DotBot'               => 'Moz (SEO)',
            'PetalBot'             => 'Petal',

            // AI & LLM scrapers – blocked when the firewall is enabled
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

            // Social & messenger preview fetchers
            'FacebookExternalHit'  => 'Facebook',
            'Twitterbot'           => 'Twitter',
            'Pinterest'            => 'Pinterest',
            'Discordbot'           => 'Discord',
            'TelegramBot'          => 'Telegram',
            'WhatsApp'             => 'WhatsApp',

            // Generic tools – blocked when the firewall is enabled
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
     * Derives OS, browser and device class from a user agent.
     *
     * Order matters: Edge/Opera/Samsung contain "Chrome", Chrome contains "Safari",
     * Android contains "Linux", iOS browsers use their own tokens (CriOS, FxiOS, EdgiOS).
     *
     * @param string $ua          User agent string.
     * @param int    $width       Viewport width from the tracker (0 = unknown, e.g. log import).
     * @param int    $touch       navigator.maxTouchPoints from the tracker (0 = unknown).
     * @return array{os:string, browser:string, device:string}
     */
    public static function parse_ua( string $ua, int $width = 0, int $touch = 0 ): array {
        $os = 'Unknown';
        if ( preg_match( '/iPhone|iPad|iPod/i', $ua ) )     $os = 'iOS';
        elseif ( stripos( $ua, 'Android' ) !== false )      $os = 'Android';
        elseif ( stripos( $ua, 'Windows' ) !== false )      $os = 'Windows';
        elseif ( stripos( $ua, 'CrOS' ) !== false )         $os = 'ChromeOS';
        elseif ( preg_match( '/Macintosh|Mac OS X/i', $ua ) ) $os = 'macOS';
        elseif ( stripos( $ua, 'Linux' ) !== false )        $os = 'Linux';

        $browser = 'Unknown';
        if ( preg_match( '#Edg(e|A|iOS)?/#', $ua ) )         $browser = 'Edge';
        elseif ( preg_match( '#OPR/|Opera|OPiOS/#', $ua ) )  $browser = 'Opera';
        elseif ( stripos( $ua, 'SamsungBrowser' ) !== false ) $browser = 'Samsung';
        elseif ( preg_match( '#Firefox/|FxiOS/#', $ua ) )    $browser = 'Firefox';
        elseif ( preg_match( '#Chrome/|CriOS/|Chromium/#', $ua ) ) $browser = 'Chrome';
        elseif ( stripos( $ua, 'Safari/' ) !== false )      $browser = 'Safari';

        // iPadOS 13+ sends a desktop macOS UA; only the touch points give it away.
        if ( $os === 'macOS' && $touch > 1 ) {
            $os = 'iOS';
            return [ 'os' => $os, 'browser' => $browser, 'device' => 'Tablet' ];
        }

        if ( preg_match( '/iPad|Tablet/i', $ua ) || ( $os === 'Android' && stripos( $ua, 'Mobile' ) === false ) ) {
            $device = 'Tablet';
        } elseif ( preg_match( '/Mobile|iPhone|iPod/i', $ua ) ) {
            $device = 'Mobile';
        } elseif ( $width > 0 && $width < 768 ) {
            $device = 'Mobile';
        } else {
            $device = 'Desktop';
        }

        return [ 'os' => $os, 'browser' => $browser, 'device' => $device ];
    }

    /**
     * Normalizes a request path the same way for live tracking and log imports:
     * strips query/fragment, collapses double slashes, removes the trailing slash.
     */
    public static function normalize_path( string $url ): string {
        $url = substr( wp_strip_all_tags( $url ), 0, 255 );
        $url = (string) preg_replace( '/[?#].*/s', '', $url );
        $url = rtrim( (string) preg_replace( '#/+#', '/', $url ), '/' );
        return $url === '' ? '/' : $url;
    }

    /**
     * Returns the registrable part of a host (last two labels), without "www.".
     */
    public static function base_host( string $host ): string {
        $host = (string) preg_replace( [ '/^www\./', '/:\d+$/' ], '', strtolower( trim( $host ) ) );
        return implode( '.', array_slice( explode( '.', $host ), -2 ) );
    }

    /**
     * Classifies a referrer URL.
     *
     * @param string $referrer_raw Full referrer URL ('' or '-' for none).
     * @param string $site_host    Host of the tracked site.
     * @return array{0:string,1:string} [ stored referrer URL, ref_domain ('Direct', 'Internal' or host) ]
     */
    public static function classify_referrer( string $referrer_raw, string $site_host ): array {
        $referrer_raw = trim( $referrer_raw );
        if ( $referrer_raw === '' || $referrer_raw === '-' ) {
            return [ '', 'Direct' ];
        }

        $ref_host = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $referrer_raw, PHP_URL_HOST ) ) );
        if ( $ref_host === '' ) {
            return [ '', 'Direct' ];
        }
        if ( self::base_host( $ref_host ) === self::base_host( $site_host ) ) {
            return [ '', 'Internal' ];
        }

        return [
            substr( esc_url_raw( (string) strtok( $referrer_raw, '?#' ) ), 0, 255 ),
            substr( $ref_host, 0, 100 ),
        ];
    }

    /**
     * Host of the site itself (used to tell internal from external referrers).
     */
    public static function site_host(): string {
        return (string) wp_parse_url( home_url(), PHP_URL_HOST );
    }

    /**
     * Daily visitor hash. Rotates at local midnight of the site's timezone.
     */
    public static function visitor_hash( string $ip, string $ua, string $local_date ): string {
        $salt = defined( 'NONCE_SALT' ) ? NONCE_SALT : 'BUNSEKI_SECURE_SALT';
        return md5( $ip . $ua . $local_date . $salt );
    }

    /**
     * Current UTC offset of the site timezone in seconds (used for SQL day grouping).
     */
    public static function tz_offset(): int {
        return (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now' ) );
    }

    /**
     * Local (site timezone) date for a Unix timestamp.
     */
    public static function local_date( ?int $ts = null ): string {
        return wp_date( 'Y-m-d', $ts ?? time() );
    }

    /**
     * UTC datetime string of local midnight of the given local date.
     */
    public static function local_day_start_utc( string $local_date ): string {
        $dt = new DateTimeImmutable( $local_date . ' 00:00:00', wp_timezone() );
        return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
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
     * True if a local day already has live-tracked rows (width > 0).
     * Log imports skip human rows for such days to avoid double counting.
     */
    public static function day_has_live_data( string $local_date ): bool {
        static $cache = [];
        if ( isset( $cache[ $local_date ] ) ) return $cache[ $local_date ];

        global $wpdb;
        $tbl   = $wpdb->prefix . 'bunseki_log';
        $start = self::local_day_start_utc( $local_date );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM $tbl WHERE time >= %s AND time < DATE_ADD(%s, INTERVAL 1 DAY) AND width > 0 LIMIT 1",
            $start, $start
        ) );
        return $cache[ $local_date ] = ( $found !== null );
    }

    /**
     * Decides whether a (non-bot) log line counts as a human pageview.
     */
    public static function is_countable_pageview( string $method, string $path, int $status, string $local_date ): bool {
        if ( strtoupper( $method ) !== 'GET' ) return false;
        if ( ! in_array( $status, [ 200, 404 ], true ) ) return false;
        if ( self::is_noise_path( $path ) ) return false;
        if ( self::day_has_live_data( $local_date ) ) return false;
        return true;
    }
}

/**
 * Records one bot hit (aggregated per local day/bot/url/status).
 * Used by the firewall (403) and the tracking endpoint (JS-executing bots).
 */
function bunseki_insert_bot_hit( $bot_name, $url = null, $status = 403 ) {
    global $wpdb;

    if ( null === $url ) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $url = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
    }
    $url = Bunseki_Helper::normalize_path( (string) $url );

    $tbl = $wpdb->prefix . 'bunseki_bots';
    $now = gmdate( 'Y-m-d H:i:s' );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $tbl (date, bot_name, url, hits, status, last_seen)
         VALUES (%s, %s, %s, 1, %d, %s)
         ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s",
        Bunseki_Helper::local_date(), substr( (string) $bot_name, 0, 50 ), $url, (int) $status, $now, $now
    ) );
}

/**
 * Deletes all dashboard caches (one transient per range).
 */
function bunseki_clear_dashboard_cache() {
    foreach ( [ '30', '60', '90', '120', 'all' ] as $r ) {
        delete_transient( 'bunseki_dashboard_stats_v7_' . $r );
        delete_transient( 'bunseki_dashboard_stats_v8_' . $r );
    }
    delete_transient( 'bunseki_health' );
}

/**
 * Garbage collection: deletes logs older than 10 years.
 * Runs daily via WP-Cron (bunseki_daily_cleanup_event).
 */
function bunseki_garbage_collection() {
    global $wpdb;

    $days    = 3650;
    $tbl_usr = $wpdb->prefix . 'bunseki_log';
    $tbl_bot = $wpdb->prefix . 'bunseki_bots';
    $tbl_evt = $wpdb->prefix . 'bunseki_events';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare( "DELETE FROM $tbl_usr WHERE time < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", $days ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare( "DELETE FROM $tbl_evt WHERE time < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", $days ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare( "DELETE FROM $tbl_bot WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)", $days ) );
}
