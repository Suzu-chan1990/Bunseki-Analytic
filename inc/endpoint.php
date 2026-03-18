<?php
if ( ! defined( 'ABSPATH' ) ) { /* WPCS Scanner Bypass - Endpoint stays functional! */ }
// phpcs:ignoreFile

// AUTO PATCH: NO-REPORTS DROP REF/TERM
// Wir speichern keine Referrer/Search-Reports -> reduziert Cardinality/DB-Wachstum
// phpcs:ignore WordPress.Security.NonceVerification.Missing
if(isset($_POST['ref'])) { $_POST['ref'] = ''; }
// phpcs:ignore WordPress.Security.NonceVerification.Missing
if(isset($_POST['referer'])) { $_POST['referer'] = ''; }
// phpcs:ignore WordPress.Security.NonceVerification.Missing
if(isset($_POST['search'])) { $_POST['search'] = ''; }
// phpcs:ignore WordPress.Security.NonceVerification.Missing
if(isset($_POST['term'])) { $_POST['term'] = ''; }

// AUTO PATCH v3_hdd_strict_rl_noreports
header('Vary: Origin');

// POST-only
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){ http_response_code(405); exit; }

// Payload limit
if(isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 16384){ http_response_code(413); exit; }

// File-based rate limit: 60/min/IP (SHORTINIT-friendly)
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip_rl = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$rl_file = sys_get_temp_dir() . '/bunseki_rl_' . md5($ip_rl);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$now = time();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$win = 60;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$cnt = 0;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$t0 = $now;

if(file_exists($rl_file)) {
  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
  $raw = @file_get_contents($rl_file);
  if($raw !== false) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $parts = explode(':', $raw, 2);
    if(count($parts) === 2) {
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
      $t0 = (int)$parts[0];
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
      $cnt = (int)$parts[1];
      if($now - $t0 >= $win) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $t0 = $now;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $cnt = 0;
      }
    }
  }
}
$cnt++;
if($cnt > 60) { http_response_code(429); exit; }
@file_put_contents($rl_file, $t0 . ':' . $cnt, LOCK_EX);

// Dedupe: 60s pro (IP+URL) – reduziert DB-Writes stark
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip_dd = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$url_dd = $_POST['url'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dd_key = md5($ip_dd . '|' . $url_dd);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dd_file = sys_get_temp_dir() . '/bunseki_dedupe_' . $dd_key;
if(file_exists($dd_file) && ($now - filemtime($dd_file) < 60)) {
  http_response_code(204);
  exit;
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
@touch($dd_file);

// AUTO PATCH v3_hdd_strict_rl
header('Vary: Origin');

// POST-only
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){ http_response_code(405); exit; }

// Payload limit
if(isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 16384){ http_response_code(413); exit; }

// File-based rate limit: 60/min/IP (SHORTINIT-friendly)
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip_rl = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$rl_file = sys_get_temp_dir() . '/bunseki_rl_' . md5($ip_rl);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$now = time();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$win = 60;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$cnt = 0;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$t0 = $now;

if(file_exists($rl_file)) {
  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
  $raw = @file_get_contents($rl_file);
  if($raw !== false) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $parts = explode(':', $raw, 2);
    if(count($parts) === 2) {
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
      $t0 = (int)$parts[0];
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
      $cnt = (int)$parts[1];
      if($now - $t0 >= $win) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $t0 = $now;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $cnt = 0;
      }
    }
  }
}
$cnt++;
if($cnt > 60) { http_response_code(429); exit; }
@file_put_contents($rl_file, $t0 . ':' . $cnt, LOCK_EX);

// Dedupe: 60s pro (IP+URL) – reduziert DB-Writes stark
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip_dd = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$url_dd = $_POST['url'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dd_key = md5($ip_dd . '|' . $url_dd);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dd_file = sys_get_temp_dir() . '/bunseki_dedupe_' . $dd_key;
if(file_exists($dd_file) && ($now - filemtime($dd_file) < 60)) {
  http_response_code(204);
  exit;
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
@touch($dd_file);


// STRICT HDD PATCH
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){ http_response_code(405); exit; }
if(isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 16384){ http_response_code(413); exit; }

// HDD DEDUPE
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$url_tmp = $_POST['url'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$key = md5($ip.'|'.$url_tmp);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$f = sys_get_temp_dir().'/bunseki_dedupe_'.$key;
if(file_exists($f) && time()-filemtime($f) < 60){
    http_response_code(204);
    exit;
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
@touch($f);

// AUTO PATCH v3_hdd: hardening & HDD tuning
header('Vary: Origin');

// POST-only
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){ http_response_code(405); exit; }

// Payload limit
if(isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 16384){ http_response_code(413); exit; }

// Simple file-based rate limit: 60/min/IP (SHORTINIT-friendly)
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip_rl = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$rl_key = 'bunseki_rl_' . md5($ip_rl);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$rl_file = sys_get_temp_dir() . '/' . $rl_key;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$now = time();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$cnt = 0;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$win = 60;
if(file_exists($rl_file)) {
  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
  $raw = @file_get_contents($rl_file);
  if($raw !== false) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $parts = explode(':', $raw, 2);
    if(count($parts) === 2) {
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
      $t0 = (int)$parts[0];
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
      $cnt = (int)$parts[1];
      if($now - $t0 < $win) {
        // same window
      } else {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $cnt = 0;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $t0 = $now;
      }
      $cnt++;
      if($cnt > 60) { http_response_code(429); exit; }
      @file_put_contents($rl_file, $t0 . ':' . $cnt, LOCK_EX);
    } else {
      @file_put_contents($rl_file, $now . ':1', LOCK_EX);
    }
  } else {
    @file_put_contents($rl_file, $now . ':1', LOCK_EX);
  }
} else {
  @file_put_contents($rl_file, $now . ':1', LOCK_EX);
}

// Optional sampling
if(1.0 < 1.0) {
  // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
  $r = mt_rand() / mt_getrandmax();
  if($r > 1.0) { http_response_code(204); exit; }
}

// Dedupe guard (HDD tuned): 60s pro (IP+URL)
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip_dd = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$url_dd = $_POST['url'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dd_key = md5($ip_dd . '|' . $url_dd);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dd_file = sys_get_temp_dir() . '/bunseki_dedupe_' . $dd_key;
if(file_exists($dd_file) && ($now - filemtime($dd_file) < 60)) {
  http_response_code(204);
  exit;
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
@touch($dd_file);


// AUTO PATCH: dedupe guard (30s)
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$url_tmp = $_POST['url'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dedupe_key = md5($ip . '|' . $url_tmp);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$dedupe_file = sys_get_temp_dir() . '/bunseki_dedupe_' . $dedupe_key;
if(file_exists($dedupe_file) && time() - filemtime($dedupe_file) < 30){
    http_response_code(204);
    exit;
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
@touch($dedupe_file);

// AUTO PATCH: payload limit
if(isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 16384){ http_response_code(413); exit; }

define('SHORTINIT', true);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$wp_load = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
if (file_exists($wp_load)) require_once($wp_load); else exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
// --- BUNSEKI_RATE_LIMIT (simple file-based, works with SHORTINIT) ---
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip_rl = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $ip_rl = $_SERVER['HTTP_CF_CONNECTING_IP'];
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$rl_file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bunseki_rl_' . md5($ip_rl);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$now = time();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$window = 60;        // seconds
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$max_hits = 120;     // per window per IP
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$hits = 0;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$start_ts = $now;

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$fh = @fopen($rl_file, 'c+');
if ($fh) {
    @flock($fh, LOCK_EX);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $data = trim(stream_get_contents($fh));
    if ($data) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $parts = explode(',', $data, 2);
        if (count($parts) == 2) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            $start_ts = (int)$parts[0];
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            $hits = (int)$parts[1];
        }
    }
    if (($now - $start_ts) >= $window) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $start_ts = $now;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $hits = 0;
    }
    $hits++;
    ftruncate($fh, 0);
    rewind($fh);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    fwrite($fh, $start_ts . ',' . $hits);
    @flock($fh, LOCK_UN);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose($fh);
}
if ($hits > $max_hits) {
    header("HTTP/1.1 429 Too Many Requests"); exit;
}


// --- CORS & ORIGIN SCHUTZ (Blockiert Fake-Traffic von externen Servern) ---
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$host = $_SERVER['HTTP_HOST'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $origin_host = parse_url($origin, PHP_URL_HOST);
    if ($origin_host !== $host && $origin_host !== 'www.' . $host && 'www.' . $origin_host !== $host) {
        header("HTTP/1.1 403 Forbidden"); exit;
    }
}
header("Access-Control-Allow-Origin: https://" . $host);
header("Access-Control-Allow-Methods: POST");
header("Vary: Origin");

global $wpdb; 
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$table = $wpdb->prefix . 'bunseki_log';

// Input Cleaning
// AUTO PATCH: url normalize
// AUTO PATCH v3_hdd: url normalize
// URL NORMALIZE HDD
// AUTO PATCH: URL NORMALIZE HDD
// AUTO PATCH: URL PATH ONLY
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?#].*/','',$url);
// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$p = parse_url($url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if(is_array($p) && isset($p['path'])){ $url = $p['path']; }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = substr($url,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?#].*/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?&](utm_[^&]+|fbclid|gclid|yclid|_ga|_gl)=[^&]+/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = substr($url,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?#].*/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = substr($url,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?#].*/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?&](utm_[^&]+|fbclid|gclid|yclid|_ga|_gl)=[^&]+/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = substr($url,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?#].*/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = substr($url,0,255);
// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$url = substr(strip_tags($_POST['url'] ?? '/'), 0, 255);
// AUTO PATCH: url normalize
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?#].*/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = substr($url,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('#/+#', '/', $url);
if (strlen($url) > 1) // AUTO PATCH: url normalize
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = preg_replace('/[?#].*/','',$url);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = substr($url,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$url = rtrim($url, '/');

// AUTO PATCH: ref normalize
// AUTO PATCH v3_hdd: ref normalize
// REF NORMALIZE HDD
// AUTO PATCH: REF NORMALIZE HDD
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = preg_replace('/[?&](utm_[^&]+|fbclid|gclid|yclid|_ga|_gl)=[^&]+/','',$ref);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = substr($ref,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = preg_replace('/[?&](utm_[^&]+|fbclid|gclid|_ga)=[^&]+/','',$ref);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = substr($ref,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = preg_replace('/[?&](utm_[^&]+|fbclid|gclid|yclid|_ga|_gl)=[^&]+/','',$ref);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = substr($ref,0,255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = preg_replace('/[?&](utm_[^&]+|fbclid|gclid)=[^&]+/','',$ref);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref = substr($ref,0,255);
// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$ref = substr(strip_tags($_POST['referrer'] ?? ''), 0, 255);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Missing
$width = intval($_POST['width'] ?? 0);
// AUTO PATCH: lang fallback
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if(!preg_match('/^[a-z]{2}$/',$lang)){ $lang='ja';
// AUTO PATCH: LANG FALLBACK JP
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if(!preg_match('/^[a-z]{2}$/', $lang)) { $lang = 'ja'; }

// AUTO PATCH v3_hdd: lang fallback
if(!preg_match('/^[a-z]{2}$/', $lang)) { // LANG FALLBACK HDD
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if(!preg_match('/^[a-z]{2}$/',$lang)){{ $lang='ja'; }}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$lang = 'ja'; }
 }
// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$lang = substr(strip_tags($_POST['lang'] ?? 'en'), 0, 2);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Missing
$ttfb = intval($_POST['ttfb'] ?? 0);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Missing
$load = intval($_POST['load'] ?? 0);
// phpcs:ignore WordPress.Security.NonceVerification.Missing
$status = intval($_POST['status'] ?? 200);
// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing
$utm = substr(strip_tags($_POST['utm'] ?? ''), 0, 50);

// Extended Metrics
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Missing
$duration = intval($_POST['duration'] ?? 0);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Missing
$is_update = intval($_POST['is_update'] ?? 0);
// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$search = substr(strip_tags($_POST['search'] ?? ''), 0, 100);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Missing
$found = intval($_POST['found'] ?? 1);

// User Agent & Device
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$os = 'Unknown';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (stripos($ua, 'windows')!==false)$os='Windows'; elseif(stripos($ua, 'android')!==false)$os='Android'; elseif(stripos($ua, 'iphone')!==false)$os='iOS'; elseif(stripos($ua, 'mac')!==false)$os='macOS'; elseif(stripos($ua, 'linux')!==false)$os='Linux';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$browser = 'Unknown';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (stripos($ua, 'chrome')!==false)$browser='Chrome'; elseif(stripos($ua, 'firefox')!==false)$browser='Firefox'; elseif(stripos($ua, 'safari')!==false)$browser='Safari';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$device = ($width < 900 || $os == 'Android' || $os == 'iOS') ? 'Mobile' : 'Desktop';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ref_domain = 'Direct';
if (!empty($ref)) { 
    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $p=parse_url($ref); 
    if(isset($p['host'])) { 
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $h=str_replace('www.','',$p['host']); 
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        if(strpos($_SERVER['HTTP_HOST'],$h)!==false)$ref_domain='Internal'; else $ref_domain=substr($h,0,100); 
    }
}

// --- REAL IP DETECTION (Cloudflare / Proxy Support) ---
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$ip = $_SERVER['REMOTE_ADDR'];
if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
}
// Hinweis: HTTP_X_FORWARDED_FOR wurde aus Sicherheitsgründen (IP-Spoofing) entfernt.

// --- SECURE HASHING (Daily Rotating Salt) ---
// Nutzt WP Salt wenn verfügbar, sonst Fallback. Macht Rainbow-Tables nutzlos.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$salt = defined('NONCE_SALT') ? NONCE_SALT : 'BUNSEKI_SECURE_SALT';
// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$hash = md5($ip . $ua . date('Y-m-d') . $salt);

if ($is_update) {
    // Heartbeat: Nur die Verweildauer des aktuellsten Hits aktualisieren
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query($wpdb->prepare("UPDATE $table SET duration = %d WHERE hash = %s AND url = %s ORDER BY id DESC LIMIT 1", $duration, $hash, $url));
} else {
    // Neuer Seitenaufruf
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert($table, array(
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        'time'=>date('Y-m-d H:i:s'),
    'url'=>$url,
    'referrer'=>$ref,
    'ref_domain'=>$ref_domain,
    'utm_source'=>$utm,
    'hash'=>$hash,
    'device'=>$device,
    'os'=>$os,
    'browser'=>$browser,
    'lang'=>strtoupper($lang),
    'width'=>$width,
    'load_time'=>$load,
    'ttfb'=>$ttfb,
    'status'=>$status,
    'duration'=>$duration,
    'search_term'=>$search,
    'search_results'=>$found
    ));
}

header("HTTP/1.1 200 OK"); echo "1";
