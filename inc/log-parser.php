<?php
/**
 * Bunseki Analytic – Shared access-log parser
 *
 * Used by the WP-CLI importer, the admin AJAX importer and the auto-import cron,
 * so all three count bots and pageviews exactly the same way as live tracking.
 *
 * @package Bunseki_Analytic
 * @since   1.2.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bunseki_Log_Parser {

    /** Nginx / Apache "combined" log format; tolerates '-' as byte count and extra spaces. */
    const LINE_REGEX = '/^(\S+)\s+\S+\s+\S+\s+\[(.*?)\]\s+"(.*?)"\s+(\d{3})\s+(\S+)\s+"(.*?)"\s+"(.*?)"/';

    /** Static assets are never pageviews and never interesting bot targets. */
    const ASSET_REGEX = '/\.(css|js|mjs|map|json|jpe?g|png|gif|webp|avif|jxl|svg|ico|bmp|woff2?|ttf|eot|otf|mp4|webm|mp3|m4a|ogg|wav|br|gz)$/i';

    /** Rows per multi-row INSERT statement. */
    const INSERT_CHUNK = 500;

    /** @var array<string, array> Aggregated bot hits keyed by date|bot|url|status. */
    private array $bots = [];

    /** @var array<int, array> Human pageview rows. */
    private array $users = [];

    private string $site_host;

    /** Lines that matched the log format. */
    public int $parsed = 0;

    /** Human pageviews written to the database. */
    public int $imported_views = 0;

    /** Bot hits written to the database. */
    public int $imported_bots = 0;

    /** Last database error, if any. */
    public string $last_error = '';

    public function __construct() {
        $this->site_host = Bunseki_Helper::site_host();
    }

    /**
     * Parses one log line and queues it for insertion.
     *
     * @return bool True if the line matched the log format.
     */
    public function add_line( string $line ): bool {
        if ( ! preg_match( self::LINE_REGEX, $line, $m ) ) {
            return false;
        }
        $this->parsed++;

        // "10/Oct/2026:13:55:36 +0900" -> "10-Oct-2026 13:55:36 +0900" (strtotime cannot read the original)
        $date_str = (string) preg_replace( '/:/', ' ', str_replace( '/', '-', $m[2] ), 1 );
        $ts       = strtotime( $date_str );
        if ( false === $ts ) {
            return true;
        }

        $req       = explode( ' ', $m[3] );
        $method    = $req[0] ?? 'GET';
        $raw_url   = $req[1] ?? '/';
        $path_only = (string) wp_parse_url( $raw_url, PHP_URL_PATH );

        if ( preg_match( self::ASSET_REGEX, $path_only ) ) {
            return true;
        }

        $ip         = $m[1];
        $status     = (int) $m[4];
        $ref        = $m[6];
        $ua         = $m[7];
        $url        = Bunseki_Helper::normalize_path( $raw_url );
        $local_date = Bunseki_Helper::local_date( $ts );

        $bot = Bunseki_Helper::detect_bot( $ua );
        if ( $bot ) {
            $key = $local_date . '|' . $bot . '|' . $url . '|' . $status;
            if ( ! isset( $this->bots[ $key ] ) ) {
                $this->bots[ $key ] = [
                    'date'   => $local_date,
                    'bot'    => substr( $bot, 0, 50 ),
                    'url'    => $url,
                    'status' => $status,
                    'hits'   => 0,
                    'last'   => gmdate( 'Y-m-d H:i:s', $ts ),
                ];
            }
            $this->bots[ $key ]['hits']++;
            $this->bots[ $key ]['last'] = max( $this->bots[ $key ]['last'], gmdate( 'Y-m-d H:i:s', $ts ) );
            return true;
        }

        if ( ! Bunseki_Helper::is_countable_pageview( $method, $path_only, $status, $local_date ) ) {
            return true;
        }

        [ $referrer, $ref_domain ] = Bunseki_Helper::classify_referrer( $ref, $this->site_host );
        $ua_info = Bunseki_Helper::parse_ua( $ua );

        $this->users[] = [
            gmdate( 'Y-m-d H:i:s', $ts ),
            $url,
            $referrer,
            $ref_domain,
            Bunseki_Helper::visitor_hash( $ip, $ua, $local_date ),
            $ua_info['device'],
            $ua_info['os'],
            $ua_info['browser'],
            $status,
        ];

        return true;
    }

    /**
     * Number of queued rows (bot aggregates + human pageviews).
     */
    public function pending(): int {
        return count( $this->bots ) + count( $this->users );
    }

    /**
     * Writes all queued rows with multi-row INSERT statements and clears the queue.
     */
    public function flush(): void {
        global $wpdb;

        if ( ! empty( $this->bots ) ) {
            $tbl = $wpdb->prefix . 'bunseki_bots';
            foreach ( array_chunk( array_values( $this->bots ), self::INSERT_CHUNK ) as $chunk ) {
                $place = [];
                $args  = [];
                foreach ( $chunk as $r ) {
                    $place[] = '(%s, %s, %s, %d, %d, %s)';
                    array_push( $args, $r['date'], $r['bot'], $r['url'], $r['hits'], $r['status'], $r['last'] );
                    $this->imported_bots += $r['hits'];
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $sql = $wpdb->prepare(
                    "INSERT INTO $tbl (date, bot_name, url, hits, status, last_seen) VALUES " . implode( ',', $place ) . '
                     ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), last_seen = GREATEST(last_seen, VALUES(last_seen))',
                    $args
                );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
                if ( false === $wpdb->query( $sql ) ) {
                    $this->last_error = (string) $wpdb->last_error;
                }
            }
            $this->bots = [];
        }

        if ( ! empty( $this->users ) ) {
            $tbl = $wpdb->prefix . 'bunseki_log';
            foreach ( array_chunk( $this->users, self::INSERT_CHUNK ) as $chunk ) {
                $place = [];
                $args  = [];
                foreach ( $chunk as $r ) {
                    // lang is left empty: access logs carry no language information.
                    $place[] = "(%s, %s, %s, %s, %s, %s, %s, %s, '', %d)";
                    array_push( $args, ...$r );
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $sql = $wpdb->prepare(
                    "INSERT INTO $tbl (time, url, referrer, ref_domain, hash, device, os, browser, lang, status) VALUES " . implode( ',', $place ),
                    $args
                );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
                $res = $wpdb->query( $sql );
                if ( false === $res ) {
                    $this->last_error = (string) $wpdb->last_error;
                } else {
                    $this->imported_views += (int) $res;
                }
            }
            $this->users = [];
        }
    }
}
