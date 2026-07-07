<?php
/**
 * Bunseki Analytic – WP-CLI Kommandos
 *
 * @package Bunseki_Analytic
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bunseki_CLI {

    /**
     * Importiert Nginx/Apache-Logdateien in die Bunseki-Datenbank.
     *
     * @param array $args       Pfade zu Logdateien (auch .gz).
     * @param array $assoc_args Optionale Flags: --force
     */
    public function parse_log( $args, $assoc_args ) {
        global $wpdb;

        if ( empty( $args ) ) {
            WP_CLI::error( __( 'No file specified.', 'bunseki-analytic' ) );
        }

        foreach ( $args as $file ) {
            if ( ! file_exists( $file ) ) {
                /* translators: %s: Dateipfad */
                WP_CLI::warning( sprintf( __( 'Skipping: File not found -> %s', 'bunseki-analytic' ), $file ) );
                continue;
            }

            $is_gzip    = ( substr( $file, -3 ) === '.gz' );
            $offset_key = 'bunseki_log_offset_' . md5( $file );
            $last_pos   = $is_gzip ? 0 : get_option( $offset_key, 0 );

            if ( isset( $assoc_args['force'] ) ) $last_pos = 0;
            if ( ! $is_gzip && filesize( $file ) < $last_pos ) $last_pos = 0;

            if ( $is_gzip ) {
                $handle = gzopen( $file, 'r' );
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                $handle = fopen( $file, 'r' );
                if ( $last_pos > 0 ) fseek( $handle, $last_pos );
            }

            if ( ! $handle ) {
                /* translators: %s: Dateipfad */
                WP_CLI::warning( sprintf( __( 'Could not open file: %s', 'bunseki-analytic' ), $file ) );
                continue;
            }

            // Regex: toleriert '-' bei Bytes und variable Leerzeichen
            $regex = '/^(\S+)\s+\S+\s+\S+\s+\[(.*?)\]\s+"(.*?)"\s+(\d{3})\s+(\S+)\s+"(.*?)"\s+"(.*?)"/';

            $batch_bots  = [];
            $batch_users = [];
            $lines       = 0;
            $parsed      = 0;
            $now         = current_time( 'mysql' );

            /* translators: 1: Dateipfad, 2: Offset */
            WP_CLI::line( sprintf( __( 'Starting import of: %1$s (Offset: %2$s)', 'bunseki-analytic' ), $file, $last_pos ) );

            while ( ( $line = ( $is_gzip ? gzgets( $handle ) : fgets( $handle ) ) ) !== false ) {
                $lines++;

                if ( preg_match( $regex, $line, $matches ) ) {
                    $parsed++;
                    $ip = $matches[1];

                    // PHP strtotime() Bug fix: Schrägstriche durch Bindestriche ersetzen
                    $clean_date = str_replace( '/', '-', $matches[2] );
                    $date_str   = preg_replace( '/:/', ' ', $clean_date, 1 );

                    $req = explode( ' ', $matches[3] );
                    $url = isset( $req[1] ) ? substr( $req[1], 0, 255 ) : '/';

                    // Statische Assets ignorieren
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
                    $path_only = (string) parse_url( $url, PHP_URL_PATH );
                    if ( preg_match( '/\.(css|js|jpg|jpeg|png|gif|webp|avif|jxl|svg|ico|woff|woff2|ttf|eot|mp4|webm|mp3)$/i', $path_only ) ) {
                        if ( ( $lines % 2500 ) === 0 ) {
                            $this->flush( $batch_bots, $batch_users, $now );
                            $batch_bots = $batch_users = [];
                            /* translators: 1: gelesen, 2: importiert */
                            WP_CLI::line( sprintf( __( '%1$d read | %2$d imported...', 'bunseki-analytic' ), $lines, $parsed ) );
                        }
                        continue;
                    }

                    $status = intval( $matches[4] );
                    $ua     = $matches[7];

                    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $time     = date( 'Y-m-d H:i:s', strtotime( $date_str ) );
                    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $log_date = date( 'Y-m-d', strtotime( $date_str ) );

                    $bot_name = Bunseki_Helper::detect_bot( $ua );

                    if ( $bot_name ) {
                        $key = $log_date . '|' . $bot_name . '|' . $url . '|' . $status;
                        if ( ! isset( $batch_bots[ $key ] ) ) {
                            $batch_bots[ $key ] = [
                                'hits'   => 0,
                                'date'   => $log_date,
                                'bot'    => $bot_name,
                                'url'    => $url,
                                'status' => $status,
                            ];
                        }
                        $batch_bots[ $key ]['hits']++;
                    } else {
                        $salt   = defined( 'NONCE_SALT' ) ? NONCE_SALT : 'BUNSEKI_SECURE_SALT';
                        $hash   = md5( $ip . $ua . $log_date . $salt );
                        $device = (
                            stripos( $ua, 'mobile' )  !== false ||
                            stripos( $ua, 'android' ) !== false ||
                            stripos( $ua, 'iphone' )  !== false
                        ) ? 'Mobile' : 'Desktop';

                        $batch_users[] = [
                            'time'     => $time,
                            'url'      => $url,
                            'referrer' => '',
                            'hash'     => $hash,
                            'device'   => $device,
                            'status'   => $status,
                        ];
                    }
                }

                // Chunking: Alle 2500 Zeilen in die DB schreiben (RAM-Schutz)
                if ( ( $lines % 2500 ) === 0 ) {
                    $this->flush( $batch_bots, $batch_users, $now );
                    $batch_bots = $batch_users = [];
                    /* translators: 1: gelesen, 2: importiert */
                    WP_CLI::line( sprintf( __( '%1$d read | %2$d imported...', 'bunseki-analytic' ), $lines, $parsed ) );
                }
            }

            // Letzten Rest schreiben
            if ( ! empty( $batch_bots ) || ! empty( $batch_users ) ) {
                $this->flush( $batch_bots, $batch_users, $now );
            }

            if ( ! $is_gzip ) {
                update_option( $offset_key, ftell( $handle ) );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose( $handle );
            } else {
                gzclose( $handle );
            }

            /* translators: 1: Dateipfad, 2: gelesen, 3: importiert */
            WP_CLI::success( sprintf(
                __( '  -> %1$s: %2$d read, %3$d imported.', 'bunseki-analytic' ),
                $file, $lines, $parsed
            ) );
        }

        delete_transient( 'bunseki_dashboard_stats_v3' );
        WP_CLI::success( __( '✅ All provided files successfully processed! Cache cleared.', 'bunseki-analytic' ) );
    }

    /**
     * Schreibt gesammelte Bot- und User-Daten in die Datenbank (Batch-Insert).
     *
     * @param array  $bots  Bot-Batch.
     * @param array  $users User-Batch.
     * @param string $now   Aktueller MySQL-Timestamp.
     */
    private function flush( $bots, $users, $now ) {
        global $wpdb;

        $tbl_bot = $wpdb->prefix . 'bunseki_bots';
        $tbl_usr = $wpdb->prefix . 'bunseki_log';

        if ( ! empty( $bots ) ) {
            foreach ( $bots as $row ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO $tbl_bot (date, bot_name, url, hits, status, last_seen)
                     VALUES (%s, %s, %s, %d, %d, %s)
                     ON DUPLICATE KEY UPDATE hits = hits + %d, last_seen = %s",
                    $row['date'], $row['bot'], $row['url'],
                    $row['hits'], $row['status'], $now,
                    $row['hits'], $now
                ) );
            }
        }

        if ( ! empty( $users ) ) {
            foreach ( $users as $u ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $result = $wpdb->insert( $tbl_usr, [
                    'time'     => $u['time'],
                    'url'      => $u['url'],
                    'referrer' => $u['referrer'],
                    'hash'     => $u['hash'],
                    'device'   => $u['device'],
                    'status'   => $u['status'],
                ] );

                if ( false === $result && ! empty( $wpdb->last_error ) ) {
                    WP_CLI::warning( __( 'DATABASE ERROR: ', 'bunseki-analytic' ) . $wpdb->last_error );
                }
            }
        }
    }

    /**
     * Setzt alle Bunseki-Daten zurück (Logs, Bots, Cache, Offsets).
     */
    public function reset() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bunseki_log" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bunseki_bots" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bunseki_log_offset_%'" );

        delete_transient( 'bunseki_dashboard_stats_v3' );

        WP_CLI::success( __( '✅ Bunseki database & cache completely cleared! System ready for fresh import.', 'bunseki-analytic' ) );
    }
}

WP_CLI::add_command( 'bunseki', 'Bunseki_CLI' );
