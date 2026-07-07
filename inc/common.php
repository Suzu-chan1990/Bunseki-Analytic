<?php
/**
 * Bunseki Analytic – Gemeinsame Hilfsfunktionen
 *
 * @package Bunseki_Analytic
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bunseki_Helper {

    /**
     * Erkennt bekannte Bots anhand des User-Agents.
     *
     * @param string $ua User-Agent-String.
     * @return string|false Bot-Name oder false wenn kein Bot erkannt.
     */
    public static function detect_bot( $ua ) {
        if ( empty( $ua ) ) return false;

        // Stand 2026: Search Engines, SEO-Tools, AI/LLM-Scraper, Social, Generic Tools
        $bots = [
            // Search Engines (werden NICHT geblockt, nur getrackt)
            'Googlebot'            => 'Google',
            'bingbot'              => 'Bing',
            'Yandex'               => 'Yandex',
            'Baiduspider'          => 'Baidu',
            'DuckDuckBot'          => 'DuckDuckGo',

            // SEO Tools
            'AhrefsBot'            => 'Ahrefs (SEO)',
            'MJ12bot'              => 'Majestic (SEO)',
            'SemrushBot'           => 'Semrush (SEO)',
            'DotBot'               => 'Moz (SEO)',
            'PetalBot'             => 'Petal',

            // AI & LLM Scraper – werden bei aktivierter Firewall geblockt
            'GPTBot'               => 'GPTBot (OpenAI)',
            'ChatGPT-User'         => 'ChatGPT (OpenAI)',
            'ClaudeBot'            => 'Claude (Anthropic)',
            'CCBot'                => 'CommonCrawl (AI Data)',
            'Applebot'             => 'Applebot',
            'Amazonbot'            => 'Amazon',
            'Diffbot'              => 'Diffbot (AI Data)',
            'Bytespider'           => 'ByteSpider (TikTok)',
            'ImagesiftBot'         => 'Imagesift',
            'Omgilibot'            => 'Omgili',
            'PerplexityBot'        => 'Perplexity',

            // Social & Messenger (Vorschau-Fetcher)
            'FacebookExternalHit'  => 'Facebook',
            'Twitterbot'           => 'Twitter',
            'Pinterest'            => 'Pinterest',
            'Discordbot'           => 'Discord',
            'TelegramBot'          => 'Telegram',
            'WhatsApp'             => 'WhatsApp',

            // Generic Tools – werden bei aktivierter Firewall geblockt
            'curl'                 => 'Tool/Curl',
            'python'               => 'Tool/Python',
            'wget'                 => 'Tool/Wget',
            'Go-http-client'       => 'Tool/Go',
        ];

        foreach ( $bots as $key => $name ) {
            if ( stripos( $ua, $key ) !== false ) return $name;
        }

        return false;
    }
}

/**
 * Garbage Collection: Löscht Logs die älter als 10 Jahre sind.
 * Wird täglich via WP-Cron ausgeführt (bunseki_daily_cleanup_event).
 *
 * Vorhaltezeit: 3650 Tage (10 Jahre) für Gesamtstatistiken.
 */
function bunseki_garbage_collection() {
    global $wpdb;

    $days    = 3650;
    $tbl_usr = $wpdb->prefix . 'bunseki_log';
    $tbl_bot = $wpdb->prefix . 'bunseki_bots';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        $wpdb->prepare( "DELETE FROM $tbl_usr WHERE time < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        $wpdb->prepare( "DELETE FROM $tbl_bot WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)", $days )
    );
}
