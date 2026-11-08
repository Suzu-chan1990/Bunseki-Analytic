<?php
/**
 * Bunseki REST API Tracking Endpoint
 *
 * Registers the REST route bunseki/v1/track (loaded from bunseki-analytic.php).
 * (PHP 8.5 Optimized & Nginx Error-Free)
 *
 * @package Bunseki_Analytic
 * @since   1.2.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ========================================================================
// Register REST route
// ========================================================================
add_action( 'rest_api_init', function (): void {
    register_rest_route( 'bunseki/v1', '/track', [
        'methods'             => 'POST',
        'callback'            => 'bunseki_rest_track_handler',
        'permission_callback' => '__return_true',
    ] );
} );

/**
 * Handles an incoming tracking request.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|void
 */
function bunseki_rest_track_handler( WP_REST_Request $request ) {
    global $wpdb;

    // ----------------------------------------------------------------
    // 1. Payload limit (protects against oversized requests)
    // ----------------------------------------------------------------
    $content_length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
    if ( $content_length > 16384 ) {
        return new WP_REST_Response( 'Payload Too Large', 413 );
    }

    // ----------------------------------------------------------------
    // 2. Determine the real client IP
    // ----------------------------------------------------------------
    $ip     = Bunseki_Helper::get_client_ip();
    $ua_raw = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

    // Self-test from the dashboard: answer immediately, store nothing.
    if ( (string) $request->get_param( 'selftest' ) === '1' ) {
        return new WP_REST_Response( null, 204 );
    }

    // Occasional cleanup of old rate-limit / dedupe temp files.
    if ( mt_rand( 1, 200 ) === 1 ) {
        bunseki_gc_temp_files();
    }

    // ----------------------------------------------------------------
    // 3. CORS Origin-Check
    // ----------------------------------------------------------------
    $host   = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
    $origin = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );

    if ( $origin !== '' ) {
        $base_origin = Bunseki_Helper::base_host( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
        $base_host   = Bunseki_Helper::base_host( $host );

        if ( $base_origin !== $base_host ) {
            return new WP_REST_Response( 'Forbidden', 403 );
        }
    }

    // ----------------------------------------------------------------
    // 4. File-based rate limiting: max. 120 hits / 60 s per IP + user agent
    // ----------------------------------------------------------------
    $rl_file  = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'bunseki_rl_' . md5( $ip . '|' . $ua_raw );
    $now      = time();
    $window   = 60;
    $max_hits = 120;
    $hits     = 0;
    $start_ts = $now;

    $fh = @fopen( $rl_file, 'c+' );
    if ( $fh ) {
        @flock( $fh, LOCK_EX );
        $data = trim( (string) stream_get_contents( $fh ) );
        if ( $data !== '' ) {
            $parts = explode( ',', $data, 2 );
            if ( count( $parts ) === 2 ) {
                $start_ts = (int) $parts[0];
                $hits     = (int) $parts[1];
            }
        }
        if ( ( $now - $start_ts ) >= $window ) {
            $start_ts = $now;
            $hits     = 0;
        }
        $hits++;
        ftruncate( $fh, 0 );
        rewind( $fh );
        fwrite( $fh, $start_ts . ',' . $hits );
        @flock( $fh, LOCK_UN );
        fclose( $fh );
    }

    if ( $hits > $max_hits ) {
        return new WP_REST_Response( 'Too Many Requests', 429 );
    }

    // ----------------------------------------------------------------
    // 5. Respect DNT header and opt-out cookie
    // ----------------------------------------------------------------
    $dnt = (string) ( $_SERVER['HTTP_DNT'] ?? '0' );
    if ( $dnt === '1' ) {
        return new WP_REST_Response( null, 204 );
    }
    if ( isset( $_COOKIE['bunseki_dnt'] ) && $_COOKIE['bunseki_dnt'] === '1' ) {
        return new WP_REST_Response( null, 204 );
    }

    // ----------------------------------------------------------------
    // 6. Sanitize input (integers are clamped to their column ranges)
    // ----------------------------------------------------------------
    $url = Bunseki_Helper::normalize_path( (string) ( $request->get_param( 'url' ) ?? '/' ) );

    $lang = (string) ( $request->get_param( 'lang' ) ?? 'ja' );
    $lang = strtolower( substr( wp_strip_all_tags( $lang ), 0, 2 ) );
    if ( ! preg_match( '/^[a-z]{2}$/', $lang ) ) $lang = 'ja';

    $clamp      = static fn( $v, int $max ): int => max( 0, min( $max, (int) $v ) );
    $width      = $clamp( $request->get_param( 'width' ),    20000 );
    $touch      = $clamp( $request->get_param( 'touch' ),    20 );
    $ttfb       = $clamp( $request->get_param( 'ttfb' ),     99999 );
    $load       = $clamp( $request->get_param( 'load' ),     99999 );
    $duration   = $clamp( $request->get_param( 'duration' ), 86400 );
    $status     = (int) ( $request->get_param( 'status' ) ?? 200 );
    if ( $status < 100 || $status > 599 ) $status = 200;
    $is_update  = (int) ( $request->get_param( 'is_update' )  ?? 0 );
    $utm        = substr( wp_strip_all_tags( (string) ( $request->get_param( 'utm' )        ?? '' ) ), 0, 50 );
    $event_name = substr( wp_strip_all_tags( (string) ( $request->get_param( 'event_name' ) ?? '' ) ), 0, 100 );
    $event_val  = substr( wp_strip_all_tags( (string) ( $request->get_param( 'event_val' )  ?? '' ) ), 0, 255 );

    // Search term + "found results" flag (the tracker sends 'search' and 'found')
    $search_term    = substr( wp_strip_all_tags( (string) ( $request->get_param( 'search' ) ?? '' ) ), 0, 100 );
    $search_results = ( $search_term !== '' && (string) $request->get_param( 'found' ) === '0' ) ? 0 : 1;

    // Referrer -> domain ('Direct', 'Internal' or external host)
    [ $referrer, $ref_domain ] = Bunseki_Helper::classify_referrer(
        (string) ( $request->get_param( 'referrer' ) ?? '' ),
        $host !== '' ? $host : Bunseki_Helper::site_host()
    );

    // ================================================================
    // 🚀 THE MAGIC TRICK: FASTCGI FINISH REQUEST
    // ================================================================
    // Send the 204 No Content response immediately.
    status_header( 204 );
    header( 'Connection: close' );

    // Let PHP-FPM close the connection to nginx/the browser now. The browser gets its answer
    // instantly, nginx logs no "recv() failed" errors, and the DB write happens afterwards.
    if ( function_exists( 'fastcgi_finish_request' ) ) {
        fastcgi_finish_request();
    }

    // ----------------------------------------------------------------
    // 7. Bot routing and dedupe guard (30 s window per IP + UA + URL)
    // ----------------------------------------------------------------
    // Bots that execute JS: count them as bots, not as humans
    $bot = Bunseki_Helper::detect_bot( $ua_raw );
    if ( $bot ) {
        if ( $is_update === 0 && $event_name === '' ) {
            bunseki_insert_bot_hit( $bot, $url, $status );
        }
        exit;
    }

    // Key includes the user agent so visitors behind one IP (mobile, campus) do not hide each other.
    // Custom events are never deduplicated (several clicks on one page are legitimate).
    if ( $is_update === 0 && $event_name === '' && bunseki_seen_recently( md5( $ip . '|' . $ua_raw . '|' . $url ), 30 ) ) {
        exit;
    }

    // ----------------------------------------------------------------
    // 8. Detect device, OS and browser
    // ----------------------------------------------------------------
    $ua_info = Bunseki_Helper::parse_ua( $ua_raw, $width, $touch );

    // ----------------------------------------------------------------
    // 9. Privacy-friendly daily hash (rotates at local midnight)
    // ----------------------------------------------------------------
    $hash = Bunseki_Helper::visitor_hash( $ip, $ua_raw, Bunseki_Helper::local_date() );

    // ----------------------------------------------------------------
    // 10. Custom Events
    // ----------------------------------------------------------------
    if ( $event_name !== '' ) {
        $tbl_evt = $wpdb->prefix . 'bunseki_events';
        $wpdb->insert( $tbl_evt, [
            'time'       => gmdate( 'Y-m-d H:i:s' ),
            'event_name' => $event_name,
            'event_val'  => $event_val,
            'url'        => $url,
            'hash'       => $hash,
        ] );
        exit; // DB-Arbeit fertig, wir steigen leise aus
    }

    // ----------------------------------------------------------------
    // 11. Insert pageview or update time on page
    // ----------------------------------------------------------------
    $table = $wpdb->prefix . 'bunseki_log';

    if ( $is_update !== 0 ) {
        // Heartbeat: only update duration (and late load time)
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET duration = %d, load_time = IF( %d > 0, %d, load_time ) WHERE hash = %s AND url = %s ORDER BY id DESC LIMIT 1",
                $duration, $load, $load, $hash, $url
            )
        );
    } else {
        // New pageview
        $wpdb->insert( $table, [
            'time'           => gmdate( 'Y-m-d H:i:s' ),
            'url'            => $url,
            'referrer'       => $referrer,
            'ref_domain'     => $ref_domain,
            'utm_source'     => $utm,
            'hash'           => $hash,
            'device'         => $ua_info['device'],
            'os'             => $ua_info['os'],
            'browser'        => $ua_info['browser'],
            'lang'           => strtoupper( $lang ),
            'width'          => $width,
            'load_time'      => $load,
            'ttfb'           => $ttfb,
            'status'         => $status,
            'duration'       => $duration,
            'search_term'    => $search_term,
            'search_results' => $search_results,
        ] );
    }

    // Exit so WordPress does not try to send headers again after fastcgi_finish_request().
    exit;
}

/**
 * Returns true if the key was seen within $ttl seconds (and marks it as seen otherwise).
 * Uses the object cache when a persistent one exists, temp files otherwise.
 */
function bunseki_seen_recently( string $key, int $ttl ): bool {
    if ( wp_using_ext_object_cache() ) {
        // wp_cache_add() fails if the key already exists -> already seen.
        return ! wp_cache_add( 'dd_' . $key, 1, 'bunseki', $ttl );
    }
    $file = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'bunseki_dedupe_' . $key;
    if ( file_exists( $file ) && ( time() - (int) @filemtime( $file ) ) < $ttl ) {
        return true;
    }
    @touch( $file );
    return false;
}

/**
 * Deletes rate-limit and dedupe temp files older than 5 minutes.
 */
function bunseki_gc_temp_files(): void {
    $files = glob( rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'bunseki_*' );
    if ( ! $files ) return;
    $limit = time() - 300;
    foreach ( $files as $f ) {
        if ( is_file( $f ) && (int) @filemtime( $f ) < $limit ) {
            @unlink( $f );
        }
    }
}
