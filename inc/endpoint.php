<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'bunseki/v1', '/track', [
        'methods'  => 'POST',
        'callback' => 'bunseki_rest_track_handler',
        'permission_callback' => '__return_true'
    ] );
} );

function bunseki_rest_track_handler( WP_REST_Request $request ) {
    global $wpdb;
    
    // Globals simulieren, falls im alten Code direkt darauf zugegriffen wurde
    $params = $request->get_params();
    if(empty($_POST)) $_POST = $params;
if ( ! defined( 'ABSPATH' ) ) exit;
// phpcs:ignoreFile



// POST-only


// Payload limit (Schutz vor riesigen Requests)
if(isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 16384){ return new WP_REST_Response('Payload Too Large', 413); }

// Real IP (Sicher vor simplem CF-Connecting-IP Spoofing)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// File-based rate limit: 120 Hits / 60s pro IP (SHORTINIT-friendly)
$rl_file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bunseki_rl_' . md5($ip);
$now = time();
$window = 60;
$max_hits = 120;
$hits = 0;
$start_ts = $now;

$fh = @fopen($rl_file, 'c+');
if ($fh) {
    @flock($fh, LOCK_EX);
    $data = trim(stream_get_contents($fh));
    if ($data) {
        $parts = explode(',', $data, 2);
        if (count($parts) == 2) {
            $start_ts = (int)$parts[0];
            $hits = (int)$parts[1];
        }
    }
    if (($now - $start_ts) >= $window) {
        $start_ts = $now;
        $hits = 0;
    }
    $hits++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $start_ts . ',' . $hits);
    @flock($fh, LOCK_UN);
    fclose($fh);
}
if ($hits > $max_hits) {
    header("HTTP/1.1 429 Too Many Requests"); exit;
}

// CORS & Origin Schutz (Blockiert Fake-Traffic von externen Servern)
$host = $_SERVER['HTTP_HOST'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    $origin_host = parse_url($origin, PHP_URL_HOST);
    if ($origin_host !== $host && $origin_host !== 'www.' . $host && 'www.' . $origin_host !== $host) {
        header("HTTP/1.1 403 Forbidden"); exit;
    }
}
header("Access-Control-Allow-Origin: https://" . $host);
header("Access-Control-Allow-Methods: POST");

// Init WP via SHORTINIT (für maximale Performance)
define('SHORTINIT', true);
$wp_load = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
if (file_exists($wp_load)) require_once($wp_load); else exit;

global $wpdb; 
$table = $wpdb->prefix . 'bunseki_log';

// Input Cleaning
$url = substr(strip_tags($_POST['url'] ?? '/'), 0, 255);
$url = preg_replace('/[?#].*/','',$url);
$url = rtrim(preg_replace('#/+#', '/', $url), '/');
if (empty($url)) $url = '/';

$lang = substr(strip_tags($_POST['lang'] ?? 'en'), 0, 2);
if(!preg_match('/^[a-z]{2}$/', $lang)) $lang = 'ja';

$width = intval($_POST['width'] ?? 0);
$ttfb = intval($_POST['ttfb'] ?? 0);
$load = intval($_POST['load'] ?? 0);
$status = intval($_POST['status'] ?? 200);
$utm = substr(strip_tags($_POST['utm'] ?? ''), 0, 50);
$duration = intval($_POST['duration'] ?? 0);
$is_update = intval($_POST['is_update'] ?? 0);
$event_name = substr(strip_tags($_POST['event_name'] ?? ''), 0, 100);
$event_val = substr(strip_tags($_POST['event_val'] ?? ''), 0, 255);

// Dedupe Guard (HDD tuned): 30s Guard pro IP+URL (verhindert Spam in der DB)
$dedupe_key = md5($ip . '|' . $url);
$dedupe_file = sys_get_temp_dir() . '/bunseki_dedupe_' . $dedupe_key;
if(file_exists($dedupe_file) && time() - filemtime($dedupe_file) < 30 && !$is_update){
    http_response_code(204);
    exit;
}
if (!$is_update) @touch($dedupe_file);

// User Agent & Device Parsing
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$os = 'Unknown';
if (stripos($ua, 'windows')!==false)$os='Windows'; elseif(stripos($ua, 'android')!==false)$os='Android'; elseif(stripos($ua, 'iphone')!==false)$os='iOS'; elseif(stripos($ua, 'mac')!==false)$os='macOS'; elseif(stripos($ua, 'linux')!==false)$os='Linux';

$browser = 'Unknown';
if (stripos($ua, 'chrome')!==false)$browser='Chrome'; elseif(stripos($ua, 'firefox')!==false)$browser='Firefox'; elseif(stripos($ua, 'safari')!==false)$browser='Safari';

$device = ($width < 900 || $os == 'Android' || $os == 'iOS') ? 'Mobile' : 'Desktop';

// Secure Hashing (Täglich rotierendes Salt)
$salt = defined('NONCE_SALT') ? NONCE_SALT : 'BUNSEKI_SECURE_SALT';
$hash = md5($ip . $ua . gmdate('Y-m-d') . $salt);

if (!empty($event_name)) {
    $tbl_evt = $wpdb->prefix . 'bunseki_events';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert($tbl_evt, [
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        'time'=>gmdate('Y-m-d H:i:s'),
        'event_name'=>$event_name,
        'event_val'=>$event_val,
        'url'=>$url,
        'hash'=>$hash
    ]);
    return new WP_REST_Response('1', 200);
}

if ($is_update) {
    // Heartbeat: Verweildauer aktualisieren
    $wpdb->query($wpdb->prepare("UPDATE $table SET duration = %d WHERE hash = %s AND url = %s ORDER BY id DESC LIMIT 1", $duration, $hash, $url));
} else {
    // Neuer Seitenaufruf (Ref & Search gedroppt für DB-Performance)
    $wpdb->insert($table, array(
        'time'=>gmdate('Y-m-d H:i:s'),
        'url'=>$url,
        'referrer'=>'',
        'ref_domain'=>'Direct',
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
        'search_term'=>'',
        'search_results'=>1
    ));
}

header("HTTP/1.1 200 OK"); echo "1";

    return new WP_REST_Response('1', 200);
}
