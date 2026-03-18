<?php
if (!defined('ABSPATH')) exit;

class Bunseki_CLI {
    
    public function parse_log( $args, $assoc_args ) {
        global $wpdb;
        if (empty($args)) WP_CLI::error("Keine Datei angegeben.");
        
        foreach ($args as $file) {
            if (!file_exists($file)) {
                WP_CLI::warning("Überspringe: Datei nicht gefunden -> $file");
                continue;
            }
            
            $is_gzip = (substr($file, -3) === '.gz');
            $offset_key = 'bunseki_log_offset_' . md5($file);
            $last_pos = ($is_gzip) ? 0 : get_option($offset_key, 0);
            if (isset($assoc_args['force'])) $last_pos = 0;
            if (!$is_gzip && filesize($file) < $last_pos) $last_pos = 0;
            if ($is_gzip) { $handle = gzopen($file, 'r'); } 
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            else { $handle = fopen($file, 'r'); if ($last_pos > 0) fseek($handle, $last_pos); }
            
            if (!$handle) {
                WP_CLI::warning("Konnte Datei nicht öffnen: $file");
                continue;
            }
            
            // Bulletproof Regex (toleriert '-' bei Bytes und variable Leerzeichen)
            $regex = '/^(\S+)\s+\S+\s+\S+\s+\[(.*?)\]\s+"(.*?)"\s+(\d{3})\s+(\S+)\s+"(.*?)"\s+"(.*?)"/';
            
            $batch_bots = [];
            $batch_users = [];
            $lines = 0;
            $parsed = 0;
            $now = current_time('mysql');
            
            WP_CLI::line("Starte Import von: $file (Offset: $last_pos)");

            while (($line = ($is_gzip ? gzgets($handle) : fgets($handle))) !== false) {
                $lines++;
                if (preg_match($regex, $line, $matches)) {
                    $parsed++;
                    $ip = $matches[1];
                    
                    // FIX: PHP strtotime() Bug beheben (Schrägstriche zwingen PHP ins US-Format)
                    $clean_date = str_replace('/', '-', $matches[2]); 
                    $date_str = preg_replace('/:/', ' ', $clean_date, 1); 
                    $req = explode(' ', $matches[3]);
                    $url = isset($req[1]) ? substr($req[1], 0, 255) : '/';
                    
                    // --- FIX: Statische Dateien ignorieren ---
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
                    $path_only = parse_url($url, PHP_URL_PATH);
                    if (preg_match('/\.(css|js|jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot|mp4|webm|mp3)$/i', (string)$path_only)) {
                        if (($lines % 2500) == 0) { $this->flush($batch_bots, $batch_users, $now); $batch_bots = []; $batch_users = []; WP_CLI::line("$lines gelesen | $parsed importiert..."); }
                        continue;
                    }

                    $status = intval($matches[4]);
                    $ref = $matches[6];
                    $ua = $matches[7];

                    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $time = date('Y-m-d H:i:s', strtotime($date_str));
                    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $log_date = date('Y-m-d', strtotime($date_str));
                    
                    $bot_name = Bunseki_Helper::detect_bot($ua);
                    if ($bot_name) {
                        $key = $log_date . '|' . $bot_name . '|' . $url . '|' . $status;
                        if (!isset($batch_bots[$key])) $batch_bots[$key] = ['hits'=>0, 'date'=>$log_date, 'bot'=>$bot_name, 'url'=>$url, 'status'=>$status];
                        $batch_bots[$key]['hits']++;
                    } else {
                        $salt = defined('NONCE_SALT') ? NONCE_SALT : 'BUNSEKI_SECURE_SALT';
                        $hash = md5($ip . $ua . $log_date . $salt);
                        $device = (stripos($ua, 'mobile')!==false || stripos($ua, 'android')!==false || stripos($ua, 'iphone')!==false) ? 'Mobile' : 'Desktop';
                        
                        $batch_users[] = [
                            'time' => $time,
                            'url' => $url,
                            'referrer' => ($ref !== '-') ? substr($ref, 0, 255) : '',
                            'hash' => $hash,
                            'device' => $device,
                            'status' => $status
                        ];
                    }
                }
                
                // Chunking: Alle 2500 Zeilen in die DB schreiben (RAM-Schutz)
                if (($lines % 2500) == 0) { 
                    $this->flush($batch_bots, $batch_users, $now); 
                    $batch_bots = []; $batch_users = []; 
                    WP_CLI::line("$lines gelesen | $parsed importiert...");
                }
            }
            
            // Letzten Rest schreiben
            if (!empty($batch_bots) || !empty($batch_users)) {
                $this->flush($batch_bots, $batch_users, $now);
            }
            
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            if (!$is_gzip) { update_option($offset_key, ftell($handle)); fclose($handle); } 
            else { gzclose($handle); }
            WP_CLI::success("  -> $file: $lines gelesen, $parsed importiert.");
        } // Ende der foreach-Schleife
        
        delete_transient('bunseki_dashboard_stats_v3');
        WP_CLI::success("✅ Alle übergebenen Dateien wurden erfolgreich verarbeitet! Cache geleert.");
    }
    
    private function flush($bots, $users, $now) {
        global $wpdb; 
        $tbl_bot = $wpdb->prefix . 'bunseki_bots';
        $tbl_usr = $wpdb->prefix . 'bunseki_log';

        if (!empty($bots)) {
            foreach ($bots as $row) {
                $sql = "INSERT INTO $tbl_bot (date, bot_name, url, hits, status, last_seen) VALUES (%s, %s, %s, %d, %d, %s) ON DUPLICATE KEY UPDATE hits = hits + %d, last_seen = %s";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare($sql, $row['date'], $row['bot'], $row['url'], $row['hits'], $row['status'], $now, $row['hits'], $now));
            }
        }
        if (!empty($users)) {
            foreach ($users as $u) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $result = $wpdb->insert($tbl_usr, [
                    'time' => $u['time'],
                    'url' => $u['url'],
                    'referrer' => $u['referrer'],
                    'hash' => $u['hash'],
                    'device' => $u['device'],
                    'status' => $u['status']
                ]);
                // Alarm schlagen, falls die DB den Insert blockiert
                if ($result === false && !empty($wpdb->last_error)) {
                    WP_CLI::error("DATENBANK FEHLER: " . $wpdb->last_error);
                }
            }
        }
    }

    public function reset() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bunseki_log");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}bunseki_bots");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bunseki_log_offset_%'");
        delete_transient('bunseki_dashboard_stats_v3');
        WP_CLI::success("✅ Bunseki Datenbank & Cache komplett geleert! Das System ist bereit für einen frischen Import.");
    }
}

WP_CLI::add_command('bunseki', 'Bunseki_CLI');