<?php
/**
 * Bunseki REST API Tracking Endpoint
 *
 * Registriert den REST-Route bunseki/v1/track.
 * Wird über wp_enqueue_scripts in bunseki-analytic.php geladen.
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
// REST Route registrieren
// ========================================================================
add_action( 'rest_api_init', function (): void {
    register_rest_route( 'bunseki/v1', '/track', [
        'methods'             => 'POST',
        'callback'            => 'bunseki_rest_track_handler',
        'permission_callback' => '__return_true',
    ] );
} );

/**
 * Verarbeitet einen eingehenden Tracking-Request.
 *
 * @param WP_REST_Request $request Der eingehende Request.
 * @return WP_REST_Response|void
 */
function bunseki_rest_track_handler( WP_REST_Request $request ) {
    global $wpdb;

    // ----------------------------------------------------------------
    // 1. Payload-Limit (Schutz vor überdimensionierten Requests)
    // ----------------------------------------------------------------
    $content_length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
    if ( $content_length > 16384 ) {
        return new WP_REST_Response( 'Payload Too Large', 413 );
    }

    // ----------------------------------------------------------------
    // 2. Reale IP ermitteln
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
        $origin_host = (string) parse_url( $origin, PHP_URL_HOST );

        $host_parts        = explode( '.', $host );
        $origin_host_parts = explode( '.', $origin_host );
        $base_host         = implode( '.', array_slice( $host_parts, -2 ) );
        $base_origin       = implode( '.', array_slice( $origin_host_parts, -2 ) );

        if ( $base_origin !== $base_host ) {
            return new WP_REST_Response( 'Forbidden', 403 );
        }
    }

    // ----------------------------------------------------------------
    // 4. File-basiertes Rate-Limiting: max. 120 Hits / 60s pro IP
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
    // 5. DNT & Opt-Out Cookie prüfen
    // ----------------------------------------------------------------
    $dnt = (string) ( $_SERVER['HTTP_DNT'] ?? '0' );
    if ( $dnt === '1' ) {
        return new WP_REST_Response( null, 204 );
    }
    if ( isset( $_COOKIE['bunseki_dnt'] ) && $_COOKIE['bunseki_dnt'] === '1' ) {
        return new WP_REST_Response( null, 204 );
    }

    // ----------------------------------------------------------------
    // 6. Input bereinigen
    // ----------------------------------------------------------------
    $url       = (string) ( $request->get_param( 'url' ) ?? '/' );
    $url       = substr( strip_tags( $url ), 0, 255 );
    $url       = (string) preg_replace( '/[?#].*/', '', $url );
    $url       = rtrim( (string) preg_replace( '#/+#', '/', $url ), '/' );
    if ( $url === '' ) $url = '/';

    $lang = (string) ( $request->get_param( 'lang' ) ?? 'ja' );
    $lang = substr( strip_tags( $lang ), 0, 2 );
    if ( ! preg_match( '/^[a-z]{2}$/', $lang ) ) $lang = 'ja';

    $width      = (int) ( $request->get_param( 'width' )      ?? 0 );
    $ttfb       = (int) ( $request->get_param( 'ttfb' )       ?? 0 );
    $load       = (int) ( $request->get_param( 'load' )       ?? 0 );
    $status     = (int) ( $request->get_param( 'status' )     ?? 200 );
    $duration   = (int) ( $request->get_param( 'duration' )   ?? 0 );
    $is_update  = (int) ( $request->get_param( 'is_update' )  ?? 0 );
    $utm        = substr( strip_tags( (string) ( $request->get_param( 'utm' )        ?? '' ) ), 0, 50 );
    $event_name = substr( strip_tags( (string) ( $request->get_param( 'event_name' ) ?? '' ) ), 0, 100 );
    $event_val  = substr( strip_tags( (string) ( $request->get_param( 'event_val' )  ?? '' ) ), 0, 255 );

    // Search term + "found results" flag (the tracker sends 'search' and 'found')
    $search_term    = substr( strip_tags( (string) ( $request->get_param( 'search' ) ?? '' ) ), 0, 100 );
    $search_results = ( $search_term !== '' && (string) $request->get_param( 'found' ) === '0' ) ? 0 : 1;

    // Referrer -> domain ('Direct', 'Internal' or external host)
    $referrer_raw = trim( (string) ( $request->get_param( 'referrer' ) ?? '' ) );
    $referrer     = '';
    $ref_domain   = 'Direct';
    if ( $referrer_raw !== '' ) {
        $ref_host = (string) preg_replace( '/^www\./', '', strtolower( (string) parse_url( $referrer_raw, PHP_URL_HOST ) ) );
        if ( $ref_host !== '' ) {
            $ref_base = implode( '.', array_slice( explode( '.', $ref_host ), -2 ) );
            if ( $ref_base === implode( '.', array_slice( explode( '.', $host ), -2 ) ) ) {
                $ref_domain = 'Internal';
            } else {
                $ref_domain = substr( $ref_host, 0, 100 );
                $referrer   = substr( esc_url_raw( (string) strtok( $referrer_raw, '?#' ) ), 0, 255 );
            }
        }
    }

    // ================================================================
    // 🚀 THE MAGIC TRICK: FASTCGI FINISH REQUEST
    // ================================================================
    // Sende HTTP 204 No Content Header sofort raus.
    status_header( 204 );
    header( 'Connection: close' );

    // Sag Nginx: "Alles okay, du kannst die Verbindung zum Browser schließen!"
    // Der Browser ist glücklich, Nginx wirft keine "recv() failed" Errors mehr,
    // und PHP schreibt die Daten im Anschluss in Ruhe in die Datenbank.
    if ( function_exists( 'fastcgi_finish_request' ) ) {
        fastcgi_finish_request();
    }

    // ----------------------------------------------------------------
    // 7. Dedupe-Guard: verhindert DB-Spam (30s Fenster pro IP+URL)
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
    // 8. User-Agent, Device, OS, Browser erkennen
    // ----------------------------------------------------------------
    $ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

    $os = 'Unknown';
    if      ( stripos( $ua, 'windows' ) !== false ) $os = 'Windows';
    elseif  ( stripos( $ua, 'android' ) !== false ) $os = 'Android';
    elseif  ( stripos( $ua, 'iphone' )  !== false ) $os = 'iOS';
    elseif  ( stripos( $ua, 'mac' )     !== false ) $os = 'macOS';
    elseif  ( stripos( $ua, 'linux' )   !== false ) $os = 'Linux';

    $browser = 'Unknown';
    if      ( stripos( $ua, 'chrome' )  !== false ) $browser = 'Chrome';
    elseif  ( stripos( $ua, 'firefox' ) !== false ) $browser = 'Firefox';
    elseif  ( stripos( $ua, 'safari' )  !== false ) $browser = 'Safari';

    $device = ( $width < 900 || $os === 'Android' || $os === 'iOS' ) ? 'Mobile' : 'Desktop';

    // ----------------------------------------------------------------
    // 9. Datenschutzkonformes Hashing
    // ----------------------------------------------------------------
    $salt = defined( 'NONCE_SALT' ) ? NONCE_SALT : 'BUNSEKI_SECURE_SALT';
    $hash = md5( $ip . $ua . gmdate( 'Y-m-d' ) . $salt );

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
    // 11. Seitenaufruf schreiben oder Verweildauer aktualisieren
    // ----------------------------------------------------------------
    $table = $wpdb->prefix . 'bunseki_log';

    if ( $is_update !== 0 ) {
        // Heartbeat: nur Verweildauer updaten
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET duration = %d, load_time = IF( %d > 0, %d, load_time ) WHERE hash = %s AND url = %s ORDER BY id DESC LIMIT 1",
                $duration, $load, $load, $hash, $url
            )
        );
    } else {
        // Neuer Seitenaufruf
        $wpdb->insert( $table, [
            'time'           => gmdate( 'Y-m-d H:i:s' ),
            'url'            => $url,
            'referrer'       => $referrer,
            'ref_domain'     => $ref_domain,
            'utm_source'     => $utm,
            'hash'           => $hash,
            'device'         => $device,
            'os'             => $os,
            'browser'        => $browser,
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

    // Wir steigen per exit aus, damit WordPress nicht versucht, 
    // nach dem fastcgi_finish_request() noch einmal Header zu generieren.
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
