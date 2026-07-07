<?php
/**
 * Bunseki REST API Tracking Endpoint
 *
 * Registriert den REST-Route bunseki/v1/track.
 * Wird über wp_enqueue_scripts in bunseki-analytic.php geladen.
 * WordPress ist zu diesem Zeitpunkt bereits vollständig initialisiert –
 * kein SHORTINIT, kein manuelles wp-load.php nötig.
 *
 * @package Bunseki_Analytic
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ========================================================================
// REST Route registrieren
// ========================================================================
add_action( 'rest_api_init', function () {
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
 * @return WP_REST_Response
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
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // ----------------------------------------------------------------
    // 3. CORS Origin-Check (FIX: unterstützt jetzt Subdomains korrekt)
    //
    //    Beispiel: Anfrage kommt von ai.vtubes.tokyo, $host ist ai.vtubes.tokyo
    //    -> Origin-Host muss mit $host ODER einer seiner Parent-Domains matchen.
    //    Kein Origin-Header (z.B. sendBeacon) wird immer durchgelassen.
    // ----------------------------------------------------------------
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ( ! empty( $origin ) ) {
        $origin_host = (string) parse_url( $origin, PHP_URL_HOST );

        // Extrahiere die zwei letzten Domain-Segmente (z.B. "vtubes.tokyo")
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
    $rl_file  = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'bunseki_rl_' . md5( $ip );
    $now      = time();
    $window   = 60;
    $max_hits = 120;
    $hits     = 0;
    $start_ts = $now;

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $fh = @fopen( $rl_file, 'c+' );
    if ( $fh ) {
        @flock( $fh, LOCK_EX );
        $data = trim( (string) stream_get_contents( $fh ) );
        if ( $data ) {
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
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $fh );
    }

    if ( $hits > $max_hits ) {
        return new WP_REST_Response( 'Too Many Requests', 429 );
    }

    // ----------------------------------------------------------------
    // 5. DNT & Opt-Out Cookie prüfen
    // ----------------------------------------------------------------
    $dnt = $_SERVER['HTTP_DNT'] ?? '0';
    if ( $dnt === '1' ) {
        return new WP_REST_Response( 'ok', 200 );
    }
    if ( isset( $_COOKIE['bunseki_dnt'] ) && $_COOKIE['bunseki_dnt'] === '1' ) {
        return new WP_REST_Response( 'ok', 200 );
    }

    // ----------------------------------------------------------------
    // 6. Input bereinigen (aus Request-Params, sauber via WP REST API)
    // ----------------------------------------------------------------
    $url       = $request->get_param( 'url' )        ?? '/';
    $url       = substr( strip_tags( $url ), 0, 255 );
    $url       = (string) preg_replace( '/[?#].*/', '', $url );
    $url       = rtrim( (string) preg_replace( '#/+#', '/', $url ), '/' );
    if ( empty( $url ) ) $url = '/';

    $lang = $request->get_param( 'lang' ) ?? 'ja';
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

    // ----------------------------------------------------------------
    // 7. Dedupe-Guard: verhindert DB-Spam (30s Fenster pro IP+URL)
    //    Heartbeat-Updates (is_update=1) werden immer durchgelassen.
    // ----------------------------------------------------------------
    $dedupe_key  = md5( $ip . '|' . $url );
    $dedupe_file = sys_get_temp_dir() . '/bunseki_dedupe_' . $dedupe_key;

    if ( ! $is_update ) {
        if ( file_exists( $dedupe_file ) && ( time() - filemtime( $dedupe_file ) ) < 30 ) {
            return new WP_REST_Response( 'ok', 204 );
        }
        @touch( $dedupe_file );
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
    // 9. Datenschutzkonformes Hashing (täglich rotierendes Salt)
    // ----------------------------------------------------------------
    $salt = defined( 'NONCE_SALT' ) ? NONCE_SALT : 'BUNSEKI_SECURE_SALT';
    $hash = md5( $ip . $ua . gmdate( 'Y-m-d' ) . $salt );

    // ----------------------------------------------------------------
    // 10. Custom Events
    // ----------------------------------------------------------------
    if ( ! empty( $event_name ) ) {
        $tbl_evt = $wpdb->prefix . 'bunseki_events';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert( $tbl_evt, [
            'time'       => gmdate( 'Y-m-d H:i:s' ),
            'event_name' => $event_name,
            'event_val'  => $event_val,
            'url'        => $url,
            'hash'       => $hash,
        ] );
        return new WP_REST_Response( '1', 200 );
    }

    // ----------------------------------------------------------------
    // 11. Seitenaufruf schreiben oder Verweildauer aktualisieren
    // ----------------------------------------------------------------
    $table = $wpdb->prefix . 'bunseki_log';

    if ( $is_update ) {
        // Heartbeat: nur Verweildauer updaten
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET duration = %d WHERE hash = %s AND url = %s ORDER BY id DESC LIMIT 1",
                $duration, $hash, $url
            )
        );
    } else {
        // Neuer Seitenaufruf
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert( $table, [
            'time'           => gmdate( 'Y-m-d H:i:s' ),
            'url'            => $url,
            'referrer'       => '',
            'ref_domain'     => 'Direct',
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
            'search_term'    => '',
            'search_results' => 1,
        ] );
    }

    return new WP_REST_Response( '1', 200 );
}
