<?php
if (!defined('ABSPATH')) exit;

class Bunseki_Helper {
    public static function detect_bot($ua) {
        if (empty($ua)) return false;
        
        // Erweiterte Liste für 2026 (AI Scraper & Big Tech)
        $bots = [
            // Search Engines
            'Googlebot' => 'Google', 
            'bingbot' => 'Bing', 
            'Yandex' => 'Yandex', 
            'Baiduspider' => 'Baidu',
            'DuckDuckBot' => 'DuckDuckGo',
            
            // SEO Tools
            'AhrefsBot' => 'Ahrefs (SEO)', 
            'MJ12bot' => 'Majestic (SEO)', 
            'SemrushBot' => 'Semrush (SEO)',
            'DotBot' => 'Moz (SEO)', 
            'PetalBot' => 'Petal', 
            
            // AI & LLM Scrapers (WICHTIG!)
            'GPTBot' => 'GPTBot (OpenAI)', 
            'ChatGPT-User' => 'ChatGPT', 
            'ClaudeBot' => 'Claude (Anthropic)', 
            'CCBot' => 'CommonCrawl (AI Data)', 
            'Applebot' => 'Applebot', 
            'Amazonbot' => 'Amazon', 
            'Diffbot' => 'Diffbot', 
            'Bytespider' => 'ByteSpider (TikTok)', 
            'ImagesiftBot' => 'Imagesift', 
            'Omgilibot' => 'Omgili',
            'PerplexityBot' => 'Perplexity',
            
            // Social & Tools
            'FacebookExternalHit' => 'Facebook', 
            'Twitterbot' => 'Twitter', 
            'Pinterest' => 'Pinterest',
            'Discordbot' => 'Discord',
            'TelegramBot' => 'Telegram',
            'WhatsApp' => 'WhatsApp',
            
            // Generic Tools
            'curl' => 'Tool/Curl', 
            'python' => 'Tool/Python', 
            'wget' => 'Tool/Wget',
            'Go-http-client' => 'Tool/Go'
        ];
        
        foreach ($bots as $key => $name) {
            if (stripos($ua, $key) !== false) return $name;
        }
        return false;
    }
}

// Garbage Collection: Löscht Daten älter als 30 Tage
function bunseki_garbage_collection() {
    global $wpdb;
    $days = 3650; // Vorhaltezeit auf 10 Jahre erhoeht (Gesamtstatistiken)
    
    // 1. User Logs bereinigen
    $tbl_usr = $wpdb->prefix . 'bunseki_log';
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare("DELETE FROM $tbl_usr WHERE time < DATE_SUB(NOW(), INTERVAL %d DAY)", $days) );
    
    // 2. Bot Logs bereinigen (optional länger halten, hier auch 30 Tage)
    $tbl_bot = $wpdb->prefix . 'bunseki_bots';
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( $wpdb->prepare("DELETE FROM $tbl_bot WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)", $days) );
    
    // Optional: Optimize Table einmal die Woche (kann bei riesigen DBs locken, daher vorsichtig)
    // $wpdb->query("OPTIMIZE TABLE $tbl_usr");
}
