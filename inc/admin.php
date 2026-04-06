<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// AUTO PATCH V2: Chart.js sauber einbinden
add_action( 'admin_enqueue_scripts', 'bunseki_enqueue_chart_js' );
function bunseki_enqueue_chart_js( $hook ) {
    if ( strpos( $hook, 'bunseki-analytic' ) !== false ) {
        wp_enqueue_script( 'chart-js', BUNSEKI_URL . 'js/chart.min.js', [], '4.0.0', true );
    }
}
// AUTO PATCH: wp_enqueue_style anstelle von hardcodiertem HTML
add_action( 'admin_enqueue_scripts', 'bunseki_enqueue_admin_assets' );
function bunseki_enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, 'bunseki-analytic' ) !== false ) {
        wp_enqueue_style( 'bunseki-admin-css', BUNSEKI_URL . 'css/admin.css', [], BUNSEKI_VERSION );
    }
}

// AUTO PATCH: global stats guards
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (!isset($stats_lang) || !is_array($stats_lang)) { $stats_lang = []; }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (!isset($stats_device) || !is_array($stats_device)) { $stats_device = []; }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (!isset($stats_ref) || !is_array($stats_ref)) { $stats_ref = []; }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (!isset($stats_search) || !is_array($stats_search)) { $stats_search = []; }
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (!isset($stats_pages) || !is_array($stats_pages)) { $stats_pages = []; }


// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (!isset($top_events) || !is_array($top_events)) { $top_events = []; }

// AUTO PATCH: stats_lang guard
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if (!isset($stats_lang) || !is_array($stats_lang)) { $stats_lang = []; }

// Option für den Live-Bot Tracker registrieren
add_action('admin_init', 'bunseki_register_live_bot_setting');
function bunseki_register_live_bot_setting() {
    register_setting('bunseki_importer_group', 'bunseki_disable_live_bots', ['type' => 'integer', 'sanitize_callback' => 'absint']);
}

add_action('admin_menu', 'bunseki_menu');
function bunseki_menu() {
    add_menu_page('Bunseki', 'Bunseki', 'manage_options', 'bunseki-analytic', 'bunseki_render_page', 'dashicons-chart-bar', 80);
    add_submenu_page('bunseki-analytic', __('Dashboard', 'bunseki-analytic'), __('Dashboard', 'bunseki-analytic'), 'manage_options', 'bunseki-analytic', 'bunseki_render_page');
    add_submenu_page('bunseki-analytic', __('Log Importer', 'bunseki-analytic'), __('Log Importer', 'bunseki-analytic'), 'manage_options', 'bunseki-importer', 'bunseki_render_importer'); // Disabled: Log Importer replaced by Live Bot Tracker
}

add_action('admin_enqueue_scripts', 'bunseki_styles');
function bunseki_styles($hook) {
    if($hook != 'toplevel_page_bunseki-analytic') return;
    
    // GARANTIERT RICHTIGER CSS PFAD OHNE "../"
    // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
    wp_enqueue_style('bunseki-css', plugin_dir_url(dirname(__FILE__)) . 'css/admin.css');
    
    // JS FÜR TABS MIT BACKTICKS (Keine PHP-String-Fehler mehr!)
    wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            $(".bun-tab").click(function() {
                var target = $(this).data("target");
                $(".bun-tab").removeClass("active");
                $(this).addClass("active");
                $(".bun-tab-content").removeClass("active").hide();
                $("#" + target).addClass("active").show();
                localStorage.setItem("bunseki_active_tab", target);
            });
            var savedTab = localStorage.getItem("bunseki_active_tab") || "tab-overview";
            $(`.bun-tab[data-target="${savedTab}"]`).click();
        });
    ');
}

function bunseki_render_page() {
    // --- CSV EXPORT FEATURE ---
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['bunseki_export']) && $_GET['bunseki_export'] == '1') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $range = (isset($_GET['range'])) ? $_GET['range'] : '30';
        $days = ($range === 'all') ? 3650 : (int)$range;
        $date_limit = gmdate('Y-m-d H:i:s', strtotime("-$days days"));
        global $wpdb;
        $tbl_usr = $wpdb->prefix . 'bunseki_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results("SELECT time, url, referrer, device, os, browser, lang, status, duration FROM $tbl_usr WHERE time > '$date_limit' ORDER BY id DESC LIMIT 50000", ARRAY_A);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bunseki_export_' . gmdate('Y-m-d') . '.csv"');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Time', 'URL', 'Referrer', 'Device', 'OS', 'Browser', 'Lang', 'Status', 'Duration (s)'));
        if ($results) { foreach ($results as $row) { fputcsv($output, $row); } }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }

    global $wpdb;
    
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $allowed_ranges = ['30', '60', '90', '120', 'all'];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $range = (isset($_GET['range']) && in_array($_GET['range'], $allowed_ranges)) ? $_GET['range'] : '30';
    $days = ($range === 'all') ? 3650 : (int)$range;
    
    $cache_key = 'bunseki_dashboard_stats_v7_' . $range;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $is_refresh = (isset($_GET['refresh']) && $_GET['refresh'] == 1);
    
    if ($is_refresh) delete_transient($cache_key);
    $data = get_transient($cache_key);
    
    if (false === $data || $is_refresh) {
        $tbl_usr = $wpdb->prefix . 'bunseki_log';
        $tbl_bot = $wpdb->prefix . 'bunseki_bots';
        
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $date_limit = gmdate('Y-m-d H:i:s', strtotime("-$days days"));
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        $date_limit_bot = gmdate('Y-m-d', strtotime("-$days days"));
        
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $chart_raw_usr = $wpdb->get_results("SELECT DATE(time) as d, COUNT(*) as c, COUNT(DISTINCT hash) as u FROM $tbl_usr WHERE time > '$date_limit' GROUP BY DATE(time)");
        
        $views = 0; $visitors = 0;
        foreach($chart_raw_usr as $r) { 
            $views += $r->c; 
            $visitors += $r->u; 
        }
        
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $bot_hits = $wpdb->get_var("SELECT SUM(hits) FROM $tbl_bot WHERE date > '$date_limit_bot'");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $avg_duration = $wpdb->get_var("SELECT AVG(duration) FROM $tbl_usr WHERE time > '$date_limit' AND duration > 0");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $chart_raw_bot = $wpdb->get_results("SELECT date as d, SUM(hits) as c FROM $tbl_bot WHERE date > '$date_limit_bot' GROUP BY date");
        
        $usr_map = []; foreach($chart_raw_usr as $r) { $usr_map[$r->d] = $r->c; }
        $bot_map = []; foreach($chart_raw_bot as $r) { $bot_map[$r->d] = $r->c; }

        $chart_data = [];
    $max_val = 10;
    $chart_days = ($days > 120) ? 120 : $days; // Cap visual chart at 120 days max to prevent browser lag
    for($i=$chart_days; $i>=0; $i--) {
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $d = gmdate('Y-m-d', strtotime("-$i days"));
            $u = isset($usr_map[$d]) ? $usr_map[$d] : 0;
            $b = isset($bot_map[$d]) ? $bot_map[$d] : 0;
            $total = $u + $b;
            if($total > $max_val) $max_val = $total;
            $chart_data[$d] = ['u'=>$u, 'b'=>$b];
        }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_pages = $wpdb->get_results("SELECT url, COUNT(*) as c FROM $tbl_usr WHERE time > '$date_limit' AND status = 200 AND url NOT LIKE '%//%' AND url != '' GROUP BY url ORDER BY c DESC LIMIT 10");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_duration = $wpdb->get_results("SELECT url, AVG(duration) as d, COUNT(*) as c FROM $tbl_usr WHERE time > '$date_limit' AND status = 200 AND duration > 10 AND url != '/' AND url NOT LIKE '%//%' GROUP BY url HAVING c > 1 ORDER BY d DESC LIMIT 5");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_refs = $wpdb->get_results("SELECT ref_domain, COUNT(*) as c FROM $tbl_usr WHERE time > '$date_limit' AND ref_domain != 'Internal' GROUP BY ref_domain ORDER BY c DESC LIMIT 10");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_search = $wpdb->get_results("SELECT search_term, COUNT(*) as c, MIN(search_results) as found FROM $tbl_usr WHERE time > '$date_limit' AND search_term != '' GROUP BY search_term ORDER BY c DESC LIMIT 10");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_bots = $wpdb->get_results("SELECT bot_name, SUM(hits) as h FROM $tbl_bot WHERE date > '$date_limit_bot' GROUP BY bot_name ORDER BY h DESC LIMIT 10");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats_device = $wpdb->get_results("SELECT device, COUNT(*) as c FROM $tbl_usr WHERE time > '$date_limit' GROUP BY device ORDER BY c DESC");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats_lang = $wpdb->get_results("SELECT lang, COUNT(*) as c FROM $tbl_usr WHERE time > '$date_limit' GROUP BY lang ORDER BY c DESC LIMIT 10");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $lost_chances = $wpdb->get_results("SELECT search_term, COUNT(*) as c FROM $tbl_usr WHERE time > '$date_limit' AND search_term != '' AND search_results = 0 GROUP BY search_term ORDER BY c DESC LIMIT 5");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $bot_404 = $wpdb->get_results("SELECT url, SUM(hits) as h FROM $tbl_bot WHERE date > '$date_limit_bot' AND status = 404 GROUP BY url ORDER BY h DESC LIMIT 5");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $tbl_evt = $wpdb->prefix . 'bunseki_events';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_events = $wpdb->get_results("SELECT event_name, event_val, COUNT(*) as c FROM $tbl_evt WHERE time > '$date_limit' GROUP BY event_name, event_val ORDER BY c DESC LIMIT 15");
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_utm = $wpdb->get_results("SELECT utm_source, COUNT(*) as c, COUNT(DISTINCT hash) as u FROM $tbl_usr WHERE time > '$date_limit' AND utm_source != '' GROUP BY utm_source ORDER BY c DESC LIMIT 15");

        $data = compact('views', 'visitors', 'bot_hits', 'avg_duration', 'chart_data', 'max_val', 'top_pages', 'top_duration', 'top_refs', 'top_search', 'top_bots', 'stats_device', 'bot_404', 'stats_lang', 'lost_chances', 'top_utm', 'top_events');
        set_transient($cache_key, $data, 900);
    } else {
        extract($data);
    }
    
    // Lokaler Guard, falls der Cache veraltet ist
    if (!isset($top_events) || !is_array($top_events)) { $top_events = []; }
    
    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    $two_hours_ago = date('Y-m-d H:i:s', strtotime("-2 hours"));
    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    $five_mins_ago = date('Y-m-d H:i:s', strtotime("-5 minutes"));
    
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $live = $wpdb->get_var("SELECT COUNT(DISTINCT hash) FROM " . $wpdb->prefix . "bunseki_log WHERE time > '$two_hours_ago' AND DATE_ADD(time, INTERVAL duration SECOND) > '$five_mins_ago'");
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $live_pages = $wpdb->get_results("SELECT url, COUNT(DISTINCT hash) as c FROM " . $wpdb->prefix . "bunseki_log WHERE time > '$two_hours_ago' AND DATE_ADD(time, INTERVAL duration SECOND) > '$five_mins_ago' AND status = 200 AND url NOT LIKE '%//%' AND url != '' GROUP BY url ORDER BY c DESC LIMIT 10");

    $mins = floor((float)$avg_duration / 60);
    $secs = (int)round((float)$avg_duration) % 60;
    $time_str = $mins . 'm ' . $secs . 's';
    ?>
    <div class="bun-wrap">
        <div class="bun-header">
            <h1 class="bun-title">📊 Bunseki <span class="bun-badge">v<?php echo esc_html(BUNSEKI_VERSION); ?></span></h1>
            <div style="display:flex; gap:10px; align-items:center;">
            <form method="GET" action="admin.php" style="margin:0;">
                <input type="hidden" name="page" value="bunseki-analytic">
                <select name="range" onchange="this.form.submit()" style="padding: 2px 24px 2px 8px; font-size: 13px; border-radius: 4px; border: 1px solid #cbd5e1; color: #334155; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <option value="30" <?php selected($range, '30'); ?>><?php esc_html_e('Last 30 Days', 'bunseki-analytic'); ?></option>
                    <option value="60" <?php selected($range, '60'); ?>><?php esc_html_e('Last 60 Days', 'bunseki-analytic'); ?></option>
                    <option value="90" <?php selected($range, '90'); ?>><?php esc_html_e('Last 90 Days', 'bunseki-analytic'); ?></option>
                    <option value="120" <?php selected($range, '120'); ?>><?php esc_html_e('Last 120 Days', 'bunseki-analytic'); ?></option>
                    <option value="all" <?php selected($range, 'all'); ?>><?php esc_html_e('All Time', 'bunseki-analytic'); ?></option>
                </select>
            </form>
            <a href="?page=bunseki-analytic&refresh=1&range=<?php echo esc_attr($range); ?>" class="page-title-action">🔄 <?php esc_html_e('Refresh', 'bunseki-analytic'); ?></a>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=bunseki-analytic&bunseki_export=1&range=" . $range ) ); ?>" class="page-title-action" target="_blank" style="background:#10b981; color:#fff; border-color:#059669;">📥 <?php esc_html_e('Export CSV', 'bunseki-analytic'); ?></a>
                <div class="bun-live <?php echo ($live > 0) ? 'active' : ''; ?>"><span class="dot"></span> <?php echo esc_html($live); ?> <?php esc_html_e('Live', 'bunseki-analytic'); ?></div>
            </div>
        </div>

        <div class="bun-tabs-nav">
            <div class="bun-tab active" data-target="tab-overview">🏠 <?php esc_html_e('Overview', 'bunseki-analytic'); ?></div>
            <div class="bun-tab" data-target="tab-content">🎬 <?php esc_html_e('Content & Engagement', 'bunseki-analytic'); ?></div>
            <div class="bun-tab" data-target="tab-marketing">🎯 <?php esc_html_e('Marketing & UTM', 'bunseki-analytic'); ?></div>
            <div class="bun-tab" data-target="tab-tech">📡 <?php esc_html_e('Acquisition & Tech', 'bunseki-analytic'); ?></div>
            <div class="bun-tab" data-target="tab-settings">⚙️ <?php esc_html_e('Settings', 'bunseki-analytic'); ?></div>
        </div>

        <div id="tab-overview" class="bun-tab-content active">
            <div class="bun-grid-kpi">
                <div class="bun-card kpi">
                    <div class="lbl"><?php echo esc_html(/* translators: %s: Time range */ sprintf(__('Visitors (%s)', 'bunseki-analytic'), $range === 'all' ? 'All' : $range.'d')); ?></div>
                    <div class="val"><?php echo number_format($visitors); ?></div>
                    <div class="sub"><?php echo number_format($views); ?> <?php esc_html_e('Views', 'bunseki-analytic'); ?></div>
                </div>
                <div class="bun-card kpi" style="border-left: 4px solid #8b5cf6;">
                    <div class="lbl"><?php esc_html_e('Avg. Time on Page', 'bunseki-analytic'); ?></div>
                    <div class="val" style="color:#8b5cf6;"><?php echo esc_html($time_str); ?></div>
                    <div class="sub"><?php esc_html_e('Active time in tab', 'bunseki-analytic'); ?></div>
                </div>
                <div class="bun-card kpi" style="border-left: 4px solid #0ea5e9;">
                    <div class="lbl"><?php esc_html_e('Bot Hits', 'bunseki-analytic'); ?></div>
                    <div class="val" style="color:#0ea5e9;"><?php echo number_format($bot_hits); ?></div>
                    <div class="sub"><?php esc_html_e('Scanner & AI', 'bunseki-analytic'); ?></div>
                </div>
            </div>

            <div class="bun-card chart-card" style="margin-bottom:25px;">
                <h3 style="display:flex; justify-content:space-between; align-items:center;">
                    <span>📈 <?php echo esc_html(/* translators: %s: Time range */ sprintf(__('Traffic Stream (%s)', 'bunseki-analytic'), $range === 'all' ? 'All' : $range.' Days')); ?></span>
                    <span style="font-size:13px; font-weight:normal; color:#64748b;">
                        <span style="color:#3b82f6;">■</span> <?php esc_html_e('Human Visitors', 'bunseki-analytic'); ?> &nbsp;&nbsp; 
                        <span style="color:#cbd5e1;">■</span> <?php esc_html_e('Bots & Crawlers', 'bunseki-analytic'); ?>
                    </span>
                </h3>
                <div class="bun-chart" style="height: 320px; padding-bottom: 5px;">
                    <?php foreach($chart_data as $date => $vals): 
                        $h_u = ($max_val > 0) ? round(($vals['u'] / $max_val) * 100) : 0;
                        $h_b = ($max_val > 0) ? round(($vals['b'] / $max_val) * 100) : 0;
                    ?>
                        <div style="flex:1; display:flex; flex-direction:column; height:100%;">
                            <div class="bar-stack" style="width:100%; flex:1;" title="<?php echo esc_attr($date . ' | User: ' . number_format($vals['u']) . ' | Bot: ' . number_format($vals['b'])); ?>">
                                <div class="b-bot" style="height:<?php echo esc_attr($h_b); ?>%"></div>
                                <div class="b-usr" style="height:<?php echo esc_attr($h_u); ?>%"></div>
                            </div>
                            <div style="text-align:center; font-size:10px; color:#94a3b8; margin-top:8px; font-weight:500;">
                                <?php echo esc_html(gmdate('d.m', strtotime($date))); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="tab-content" class="bun-tab-content">
            <div class="bun-grid-main">
                <div class="bun-stack">
                    <?php if($live > 0 && !empty($live_pages)): ?>
                    <div class="bun-card" style="border-left: 4px solid #ef4444; background: #fff5f5;">
                        <h3 style="color: #b91c1c; margin-top:0; border-bottom: 1px solid #fecaca; padding-bottom: 15px;"><span class="dot" style="display:inline-block; width:8px; height:8px; background:#ef4444; border-radius:50%; margin-right:8px; animation: pulse 2s infinite;"></span> <?php esc_html_e('Live Right Now', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php foreach($live_pages as $lp): ?>
                            <tr>
                                <td style="width:85%;"><a href="<?php echo esc_url($lp->url); ?>" target="_blank" class="trunc" style="color:#991b1b; font-weight:600;"><?php echo esc_html($lp->url); ?></a></td>
                                <td class="text-r" style="font-weight:bold; color: #ef4444; font-size:16px;"><?php echo esc_html($lp->c); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <?php endif; ?>

                    <div class="bun-card">
                        <h3>🔥 <?php esc_html_e('Top Content (by Clicks)', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php foreach($top_pages as $p): $pct = ($views > 0) ? round(($p->c / $views) * 100) : 0; ?>
                            <tr>
                                <td style="width:75%;">
                                    <a href="<?php echo esc_url($p->url); ?>" target="_blank" class="trunc"><?php echo esc_html($p->url); ?></a>
                                    <div class="minibar-bg" style="height:4px; margin-top:6px;"><div class="minibar-fill" style="width:<?php echo esc_attr($pct); ?>%; background:#3b82f6;"></div></div>
                                </td>
                                <td class="text-r"><?php echo number_format($p->c); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <div class="bun-card" style="border-left: 4px solid #8b5cf6;">
                        <h3 style="color: #6d28d9;">⏱️ <?php esc_html_e('Highest Time on Page (Top 5)', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php if(empty($top_duration)) echo '<tr><td style="color:#999;">' . esc_html__('Not enough data yet...', 'bunseki-analytic') . '</td></tr>'; ?>
                            <?php foreach($top_duration as $td): 
                                $dmins = floor($td->d / 60); $dsecs = round($td->d % 60);
                            ?>
                            <tr>
                                <td style="width:75%;"><a href="<?php echo esc_url($td->url); ?>" target="_blank" class="trunc"><?php echo esc_html($td->url); ?></a></td>
                                <td class="text-r" style="color:#8b5cf6; font-weight:bold;"><?php echo esc_html($dmins.'m '.$dsecs.'s'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                
                <div class="bun-stack">
                    <div class="bun-card">
                        <h3>🔍 <?php esc_html_e('Top Search Terms', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php if(empty($top_search)) echo '<tr><td style="color:#999;">' . esc_html__('No search data...', 'bunseki-analytic') . '</td></tr>'; ?>
                            <?php foreach($top_search as $s): ?>
                            <tr>
                                <td>
                                    <?php echo esc_html($s->search_term); ?>
                                    <?php if($s->found == 0) echo ' <span style="background:#fecaca; color:#b91c1c; font-size:10px; padding:2px 4px; border-radius:4px; margin-left:5px;">0 ' . esc_html__('Hits', 'bunseki-analytic') . '</span>'; ?>
                                </td>
                                <td class="text-r"><?php echo number_format($s->c); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <?php if(!empty($lost_chances)): ?>
                    <div class="bun-card" style="border-left: 4px solid #f97316; background: #fffcf9;">
                        <h3 style="color: #c2410c;">⚠️ <?php esc_html_e('Lost Chances (0 Hits)', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php foreach($lost_chances as $lc): ?>
                            <tr><td><strong><?php echo esc_html($lc->search_term); ?></strong></td><td class="text-r"><?php echo esc_html($lc->c); ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        
        <div id="tab-marketing" class="bun-tab-content">
            <div class="bun-card">
                <h3>🎯 <?php esc_html_e('Campaigns & Marketing (UTM Source)', 'bunseki-analytic'); ?></h3>
                <table class="bun-table">
                    <tr>
                        <th style="text-align:left; padding-bottom:10px;"><?php esc_html_e('Campaign / Source', 'bunseki-analytic'); ?></th>
                        <th class="text-r" style="padding-bottom:10px;"><?php esc_html_e('Visitors', 'bunseki-analytic'); ?></th>
                        <th class="text-r" style="padding-bottom:10px;"><?php esc_html_e('Views', 'bunseki-analytic'); ?></th>
                    </tr>
                    <?php if(empty($top_utm)) echo '<tr><td colspan="3" style="color:#999; padding-top:15px;">' . esc_html__('No UTM campaigns tracked yet. Add ?utm_source=newsletter to your links!', 'bunseki-analytic') . '</td></tr>'; ?>
                    <?php foreach($top_utm as $utm): ?>
                    <tr>
                        <td><strong><?php echo esc_html($utm->utm_source); ?></strong></td>
                        <td class="text-r" style="color:#06b6d4; font-weight:bold;"><?php echo number_format($utm->u); ?></td>
                        <td class="text-r"><?php echo number_format($utm->c); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            
            <div class="bun-card" style="margin-top: 20px;">
                <h3>🖱️ <?php esc_html_e('Custom Events & Downloads', 'bunseki-analytic'); ?></h3>
                <table class="bun-table">
                    <tr>
                        <th style="text-align:left; padding-bottom:10px;"><?php esc_html_e('Event Type', 'bunseki-analytic'); ?></th>
                        <th style="text-align:left; padding-bottom:10px;"><?php esc_html_e('Target / Value', 'bunseki-analytic'); ?></th>
                        <th class="text-r" style="padding-bottom:10px;"><?php esc_html_e('Triggered', 'bunseki-analytic'); ?></th>
                    </tr>
                    <?php if(empty($top_events)) echo '<tr><td colspan="3" style="color:#999; padding-top:15px;">' . esc_html__('No events tracked yet. Clicks on external links and downloads (.pdf, .zip) will appear here automatically!', 'bunseki-analytic') . '</td></tr>'; ?>
                    <?php foreach($top_events as $evt): ?>
                    <tr>
                        <td><strong><?php echo esc_html($evt->event_name); ?></strong></td>
                        <td class="trunc" style="max-width: 300px;" title="<?php echo esc_attr($evt->event_val); ?>"><?php echo esc_html($evt->event_val); ?></td>
                        <td class="text-r" style="color:#8b5cf6; font-weight:bold;"><?php echo number_format($evt->c); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        
        <div id="tab-tech" class="bun-tab-content">
            <div class="bun-grid-main">
                <div class="bun-stack">
                    <div class="bun-card">
                        <h3>🌍 <?php esc_html_e('Traffic Sources', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php foreach($top_refs as $r): $pct = ($views > 0) ? round(($r->c / $views) * 100) : 0; ?>
                            <tr>
                                <td style="width:75%;">
                                    <strong><?php echo esc_html($r->ref_domain); ?></strong>
                                    <div class="minibar-bg" style="height:4px; margin-top:6px;"><div class="minibar-fill" style="width:<?php echo esc_attr($pct); ?>%; background:#10b981;"></div></div>
                                </td>
                                <td class="text-r"><?php echo number_format($r->c); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <div class="bun-card">
                        <h3>🤖 <?php esc_html_e('Top Bots', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php foreach($top_bots as $b): $pct = ($bot_hits > 0) ? round(($b->h / $bot_hits) * 100) : 0; ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($b->bot_name); ?></strong>
                                    <div class="minibar-bg" style="height:4px; margin-top:6px;"><div class="minibar-fill" style="width:<?php echo esc_attr($pct); ?>%; background:#cbd5e1;"></div></div>
                                </td>
                                <td class="text-r"><?php echo number_format($b->h); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                
                <div class="bun-stack">
                    <div class="bun-card">
                        <h3>🌍 <?php esc_html_e('Top Languages', 'bunseki-analytic'); ?></h3><table class='bun-table'><?php foreach($stats_lang as $l): ?><tr><td><?php echo esc_html($l->lang); ?></td><td class='text-r'><?php echo esc_html($l->c); ?></td></tr><?php endforeach; ?></table><br><h3>📱 <?php esc_html_e('Devices', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php foreach($stats_device as $d): ?>
                            <tr><td><?php echo esc_html($d->device); ?></td><td class="text-r"><?php echo esc_html($d->c); ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    
                    <?php if(!empty($bot_404)): ?>
                    <div class="bun-card" style="border-left: 4px solid #ef4444; background:#fffafa;">
                        <h3 style="color:#b91c1c;">⚠️ <?php esc_html_e('Bot 404', 'bunseki-analytic'); ?></h3>
                        <table class="bun-table">
                            <?php foreach($bot_404 as $p): ?>
                            <tr><td class="trunc" style="color:#dc2626;"><?php echo esc_html($p->url); ?></td><td class="text-r"><?php echo esc_html($p->h); ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    
        <div id="tab-settings" class="bun-tab-content">
            <div class="bun-grid-main">
                <div class="bun-stack">
                    <div class="bun-card">
                        <h3>🛡️ <?php esc_html_e('Security & Firewall', 'bunseki-analytic'); ?></h3>
                        <form method="post" action="options.php">
                            <?php settings_fields('bunseki_importer_group'); ?>
                            
                            <label style="font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="hidden" name="bunseki_block_bots" value="0">
                                <input type="checkbox" name="bunseki_block_bots" value="1" <?php checked(get_option('bunseki_block_bots', '0'), '1'); ?>>
                                <?php esc_html_e('Block AI-Scrapers & Bad Bots', 'bunseki-analytic'); ?>
                            </label>
                            <p style="color: #64748b; font-size: 13px; margin-top: 5px;"><?php esc_html_e('When activated, Bunseki acts as a firewall. It instantly returns a 403 Forbidden status to known AI training bots (like GPTBot, ClaudeBot, ByteSpider) and aggressive generic scrapers. This protects your content and saves massive server bandwidth. Good bots (Google, Bing) are still allowed.', 'bunseki-analytic'); ?></p>
                            
                            <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">
                            <?php submit_button(__('Save Settings', 'bunseki-analytic')); ?>
                        </form>
                    </div>
                </div>
                
                <div class="bun-stack">
                    <div class="bun-card" style="border-left: 4px solid #06b6d4;">
                        <h3>🍪 <?php esc_html_e('GDPR / DSGVO Opt-Out', 'bunseki-analytic'); ?></h3>
                        <p style="color: #334155;"><?php esc_html_e('Bunseki is highly privacy-focused. However, to provide a legally compliant privacy policy, you can allow users to opt-out of tracking entirely.', 'bunseki-analytic'); ?></p>
                        <p style="color: #64748b; font-size: 13px;"><?php esc_html_e('Simply copy this shortcode and paste it anywhere on your Privacy Policy page:', 'bunseki-analytic'); ?></p>
                        <code style="display: block; background: #f1f5f9; padding: 12px; border-radius: 6px; font-size: 15px; user-select: all; font-weight: bold; color: #0f1720;">[bunseki_opt_out]</code>
                    </div>
                </div>
            </div>
        </div>
            </div>
    <?php
}

function bunseki_render_importer() {
    $nonce = wp_create_nonce('bunseki_import_log');
    ?>
    <div class="wrap" style="font-family: 'Inter', system-ui, sans-serif; max-width: 800px;">
        <h1 style="font-weight: 800; font-size: 26px; display:flex; align-items:center; gap:10px;">
            📁 Access Log Importer
        </h1>
        <p style="color: #64748b; font-size: 15px;"><?php esc_html_e('Import historical Apache or Nginx access logs (.log) directly into the Bunseki database. The system reads the file in resource-friendly chunks so that even gigantic logs will not crash your server.', 'bunseki-analytic'); ?></p>
        
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-top: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px;"><?php esc_html_e('Start Import', 'bunseki-analytic'); ?></h3>
            <p>
                <label for="log_path" style="font-weight: 600; color: #334155;"><?php esc_html_e('Absolute server path to the .log file:', 'bunseki-analytic'); ?></label><br>
                <input type="text" id="log_path" value="<?php echo esc_attr(ABSPATH . 'access.log'); ?>" style="width:100%; margin: 10px 0; padding: 8px; font-family: monospace; border: 1px solid #cbd5e1; border-radius: 6px;">
                <input type="hidden" id="bunseki_import_nonce" value="<?php echo esc_attr($nonce); ?>">
                <small style="color: #94a3b8;"><?php esc_html_e('Example: ', 'bunseki-analytic'); ?><code>/var/log/nginx/access.log</code> <?php esc_html_e( 'or', 'bunseki-analytic' ); ?> <code>/www/htdocs/w0123/logs/access.log</code></small>
            </p>
            
            <button id="btn-import" class="button button-primary button-large" onclick="startImport()" style="margin-top: 15px;"><?php esc_html_e('Read & Process Log', 'bunseki-analytic'); ?></button>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 2px dashed #e2e8f0;">
                <h3 style="font-size: 14px;"><?php esc_html_e('Automatic Background Import (Cron)', 'bunseki-analytic'); ?></h3>
                <form method="post" action="options.php">
                    <?php settings_fields('bunseki_importer_group'); ?>
                    <p><?php esc_html_e('Enter a path here so Bunseki automatically imports new logs every 12 hours.', 'bunseki-analytic'); ?></p>
                    <input type="text" name="bunseki_auto_log_path" value="<?php echo esc_attr(get_option('bunseki_auto_log_path')); ?>" style="width:100%; padding:8px;">
                    
                    <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                        <label style="font-weight: 600; color: #334155; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="hidden" name="bunseki_disable_live_bots" value="0">
                            <input type="checkbox" name="bunseki_disable_live_bots" value="1" <?php checked(get_option('bunseki_disable_live_bots', '0'), '1'); ?>>
                            <?php esc_html_e('Disable Live Bot Tracker', 'bunseki-analytic'); ?>
                        </label>
                        <p style="color: #64748b; font-size: 13px; margin-top: 5px; margin-bottom: 0;"><?php echo wp_kses_post(__('Check this box if you import server logs <strong>manually</strong> or via cron. This prevents bots from being counted twice (once live, once in the log).', 'bunseki-analytic')); ?></p>
                    </div>
                    <?php submit_button(__('Save Settings', 'bunseki-analytic')); ?>

                </form>
            </div>

            <div id="import-progress" style="display:none; margin-top: 25px;">
                <p id="import-status" style="font-weight:600; color:#0f1720;"><?php esc_html_e('Start...', 'bunseki-analytic'); ?></p>
                <div style="width:100%; height:12px; background:#e2e8f0; border-radius:99px; overflow:hidden;">
                    <div id="import-bar" style="height:100%; width:0%; background:#06b6d4;"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    var importOffset = 0;
    var totalParsed = 0;

    function startImport() {
        var file = document.getElementById('log_path').value;
        if(!file) { alert('<?php echo esc_js(__("Please enter a path!", "bunseki-analytic")); ?>'); return; }

        document.getElementById('btn-import').disabled = true;
        document.getElementById('import-progress').style.display = 'block';
        document.getElementById('import-status').innerText = '<?php echo esc_js(__("Reading file and processing first lines...", "bunseki-analytic")); ?>';

        importOffset = 0;
        totalParsed = 0;
        processChunk(file);
    }

    function processChunk(file) {
        var formData = new FormData();
        formData.append('action', 'bunseki_import_log');
        formData.append('file', file);
        formData.append('offset', importOffset);
        formData.append('nonce', document.getElementById('bunseki_import_nonce').value);

        fetch(ajaxurl, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data && data.success) {
                importOffset = data.data.offset;
                totalParsed += data.data.parsed;
                document.getElementById('import-status').innerText = totalParsed + ' <?php echo esc_js(__("lines parsed and imported...", "bunseki-analytic")); ?>';

                if(data.data.done) {
                    document.getElementById('import-bar').style.width = '100%';
                    document.getElementById('import-status').innerText = '✅ <?php echo esc_js(__("Import complete! (", "bunseki-analytic")); ?>' + totalParsed + ' <?php echo esc_js(__("lines)", "bunseki-analytic")); ?>';
                    document.getElementById('btn-import').disabled = false;
                } else {
                    // Optional simple progress animation (unknown total)
                    var w = Math.min(95, Math.floor((totalParsed % 100000) / 1000));
                    document.getElementById('import-bar').style.width = w + '%';
                    processChunk(file);
                }
            } else {
                var msg = (data && data.data) ? data.data : ((data && data.message) ? data.message : __('Unknown error', 'bunseki-analytic'));
                alert('<?php echo esc_js(__("Error: ", "bunseki-analytic")); ?>' + msg);
                document.getElementById('btn-import').disabled = false;
            }
        }).catch(err => {
            alert('<?php echo esc_js(__("Response could not be read (JSON). Check PHP error log / browser console.", "bunseki-analytic")); ?>');
            console.error(err);
            document.getElementById('btn-import').disabled = false;
        });
    }
    </script>
    <?php
}


// --- AJAX BATCH PROCESSOR ---
add_action('wp_ajax_bunseki_import_log', 'bunseki_ajax_import_log');
function bunseki_ajax_import_log() {
    if (!current_user_can('manage_options')) wp_send_json_error(__('Access denied.', 'bunseki-analytic'));

    // CSRF-Schutz
    check_ajax_referer('bunseki_import_log', 'nonce');
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $file = sanitize_text_field($_POST['file']);

    // Pfad-Whitelist: nur Dateien innerhalb des Auto-Log-Verzeichnisses zulassen
    $auto_path = get_option('bunseki_auto_log_path');
    $allowed_base = $auto_path ? realpath(dirname($auto_path)) : realpath(ABSPATH);
    $real_file = realpath($file);
    if (!$real_file || !$allowed_base || strncmp($real_file, $allowed_base . DIRECTORY_SEPARATOR, strlen($allowed_base) + 1) !== 0) {
        wp_send_json_error(__('Invalid file path.', 'bunseki-analytic'));
    }
    $file = $real_file;
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
    $offset = intval($_POST['offset']);
    
    if (!file_exists($file) || !is_readable($file)) {
        wp_send_json_error(__('File not found or server permissions (open_basedir) block reading.', 'bunseki-analytic'));
    }
    
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    $handle = fopen($file, 'r');
    if (!$handle) wp_send_json_error(__('Could not open file.', 'bunseki-analytic'));
    
    if ($offset > 0) fseek($handle, $offset);
    
    $lines = 0;
    $parsed = 0;
    $batch_bots = [];
    $batch_users = [];
    
    // Nginx / Apache Combined Log Format Regex
    $regex = '/^(\S+)\s+\S+\s+\S+\s+\[(.*?)\]\s+"(.*?)"\s+(\d{3})\s+(\S+)\s+"(.*?)"\s+"(.*?)"/';
    
    while (($line = fgets($handle)) !== false) {
        $lines++;
        if (preg_match($regex, $line, $matches)) {
            $ip = $matches[1];
            $clean_date = str_replace('/', '-', $matches[2]); 
            $date_str = preg_replace('/:/', ' ', $clean_date, 1); 
            $req = explode(' ', $matches[3]);
            $url = isset($req[1]) ? substr($req[1], 0, 255) : '/';
            
            // --- FIX: Statische Dateien ignorieren ---
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
            $path_only = parse_url($url, PHP_URL_PATH);
            if (preg_match('/\.(css|js|jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot|mp4|webm|mp3)$/i', (string)$path_only)) {
                if ($lines >= 2500) break;
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
                // Aggregiere Bots sofort (wie in der cli.php)
                $key = $log_date . '|' . $bot_name . '|' . $url . '|' . $status;
                if (!isset($batch_bots[$key])) $batch_bots[$key] = ['hits'=>0, 'date'=>$log_date, 'bot'=>$bot_name, 'url'=>$url, 'status'=>$status];
                $batch_bots[$key]['hits']++;
            } else {
                // Menschlicher User -> Hash generieren (DSGVO konform)
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
            $parsed++;
        }
        
        // Verarbeite exakt 2.500 Zeilen pro Durchlauf, um Timeouts zu vermeiden
        if ($lines >= 2500) break;
    }
    
    $new_offset = ftell($handle);
    $is_done = feof($handle);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose($handle);
    
    // Batch Insert in die Datenbank
    global $wpdb;
    $tbl_bot = $wpdb->prefix . 'bunseki_bots';
    $tbl_usr = $wpdb->prefix . 'bunseki_log';
    $now = current_time('mysql');
    
    if (!empty($batch_bots)) {
        foreach ($batch_bots as $row) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare("INSERT INTO $tbl_bot (date, bot_name, url, hits, status, last_seen) VALUES (%s, %s, %s, %d, %d, %s) ON DUPLICATE KEY UPDATE hits = hits + %d, last_seen = %s", $row['date'], $row['bot'], $row['url'], $row['hits'], $row['status'], $now, $row['hits'], $now));
        }
    }
    
    if (!empty($batch_users)) {
        foreach ($batch_users as $u) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert($tbl_usr, [
                'time' => $u['time'],
                'url' => $u['url'],
                'referrer' => $u['referrer'],
                'hash' => $u['hash'],
                'device' => $u['device'],
                'status' => $u['status']
            ]);
        }
    }
    
    if ($is_done) { delete_transient('bunseki_dashboard_stats_v6'); }
    wp_send_json_success(['offset' => $new_offset, 'done' => $is_done, 'parsed' => $parsed]);
}
