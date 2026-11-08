<?php
/**
 * Bunseki Analytic – WP-CLI commands
 *
 * @package Bunseki_Analytic
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bunseki_CLI {

    /**
     * Imports Nginx/Apache access logs into the Bunseki database.
     *
     * Plain files are read incrementally (the last read position is remembered);
     * .gz files are always read completely.
     *
     * ## OPTIONS
     *
     * <file>...
     * : One or more log files (.log or .gz).
     *
     * [--force]
     * : Ignore the remembered position and read plain files from the start.
     *
     * ## EXAMPLES
     *
     *     wp bunseki parse_log /var/log/nginx/access.log
     *     wp bunseki parse_log /var/log/nginx/access.log.2.gz --force
     *
     * @param array $args       Paths to log files.
     * @param array $assoc_args Optional flags: --force
     */
    public function parse_log( $args, $assoc_args ) {
        $is_cli = defined( 'WP_CLI' ) && WP_CLI;

        if ( empty( $args ) ) {
            $is_cli && WP_CLI::error( __( 'No file specified.', 'bunseki-analytic' ) );
            return;
        }

        foreach ( $args as $file ) {
            if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
                /* translators: %s: file path */
                $is_cli && WP_CLI::warning( sprintf( __( 'Skipping: File not found -> %s', 'bunseki-analytic' ), $file ) );
                continue;
            }

            $is_gzip    = ( substr( $file, -3 ) === '.gz' );
            $offset_key = 'bunseki_log_offset_' . md5( $file );
            $last_pos   = $is_gzip ? 0 : (int) get_option( $offset_key, 0 );

            if ( isset( $assoc_args['force'] ) ) $last_pos = 0;
            if ( ! $is_gzip && filesize( $file ) < $last_pos ) $last_pos = 0; // log was rotated

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            $handle = $is_gzip ? gzopen( $file, 'r' ) : fopen( $file, 'r' );
            if ( ! $handle ) {
                /* translators: %s: file path */
                $is_cli && WP_CLI::warning( sprintf( __( 'Could not open file: %s', 'bunseki-analytic' ), $file ) );
                continue;
            }
            if ( ! $is_gzip && $last_pos > 0 ) fseek( $handle, $last_pos );

            /* translators: 1: file path, 2: byte offset */
            $is_cli && WP_CLI::line( sprintf( __( 'Starting import of: %1$s (Offset: %2$s)', 'bunseki-analytic' ), $file, $last_pos ) );

            $parser = new Bunseki_Log_Parser();
            $lines  = 0;

            while ( ( $line = ( $is_gzip ? gzgets( $handle ) : fgets( $handle ) ) ) !== false ) {
                $lines++;
                $parser->add_line( $line );

                // Write every 2500 lines to keep memory flat.
                if ( ( $lines % 2500 ) === 0 ) {
                    $parser->flush();
                    /* translators: 1: lines read, 2: lines parsed */
                    $is_cli && WP_CLI::line( sprintf( __( '%1$d read | %2$d imported...', 'bunseki-analytic' ), $lines, $parser->parsed ) );
                }
            }
            $parser->flush();

            if ( $parser->last_error !== '' ) {
                $is_cli && WP_CLI::warning( __( 'DATABASE ERROR: ', 'bunseki-analytic' ) . $parser->last_error );
            }

            if ( $is_gzip ) {
                gzclose( $handle );
            } else {
                update_option( $offset_key, ftell( $handle ), false );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose( $handle );
            }

            /* translators: 1: file path, 2: lines read, 3: lines parsed */
            $is_cli && WP_CLI::success( sprintf(
                __( '  -> %1$s: %2$d read, %3$d imported.', 'bunseki-analytic' ),
                $file, $lines, $parser->parsed
            ) );
        }

        bunseki_clear_dashboard_cache();
        $is_cli && WP_CLI::success( __( '✅ All provided files successfully processed! Cache cleared.', 'bunseki-analytic' ) );
    }

    /**
     * Resets all Bunseki data (pageviews, events, bots, cache, import offsets).
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     */
    public function reset( $args = [], $assoc_args = [] ) {
        global $wpdb;

        WP_CLI::confirm( __( 'Delete ALL Bunseki statistics?', 'bunseki-analytic' ), $assoc_args );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bunseki_log" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bunseki_events" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bunseki_bots" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bunseki\\_log\\_offset\\_%'" );

        bunseki_clear_dashboard_cache();

        WP_CLI::success( __( '✅ Bunseki database & cache completely cleared! System ready for fresh import.', 'bunseki-analytic' ) );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'bunseki', 'Bunseki_CLI' );
}
