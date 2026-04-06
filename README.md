=== Bunseki Analytic ===
Contributors: Saguya
Tags: analytics, tracking, statistics, privacy, bot blocker
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.1.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

High Scale Analytics (Stealth & Secure) for WordPress. Lightweight, privacy-focused, and packed with premium features.

== Description ==

Bunseki Analytic is a custom-built, highly optimized analytics and tracking system for WordPress. It is designed to be extremely lightweight, privacy-focused, and capable of handling high traffic volumes without slowing down your server. 

Say goodbye to bloated third-party scripts. Bunseki gives you full data sovereignty right inside your WordPress dashboard, featuring advanced bot protection, UTM campaign tracking, and custom event logging.

### ✨ Premium Features (All Included)

* **🚀 High-Performance Tracking:** Tracks real users via a standalone, ultra-fast endpoint (`endpoint.php`) that bypasses the heavy WordPress core for maximum speed.
* **🛡️ AI Scraper & Bot Firewall:** Stop AI training bots (GPTBot, ClaudeBot, ByteSpider) and aggressive scrapers from stealing your content. A built-in firewall returns a 403 Forbidden status to known bad bots to save massive server bandwidth.
* **🎯 Marketing & UTM Campaigns:** Easily track where your traffic comes from. Bunseki automatically detects `?utm_source=` parameters and groups them in a dedicated marketing dashboard.
* **🖱️ Auto Event & Download Tracking:** Automatically tracks clicks on outbound links and file downloads (.pdf, .zip, etc.) without any manual configuration. Includes a custom JS API `bunseki.track()` for your own events.
* **🔒 100% Privacy First (GDPR Ready):** Uses secure, salted daily hashing for user identification instead of storing raw IP addresses. Includes a handy `[bunseki_opt_out]` shortcode for your privacy policy page.
* **📊 Advanced Admin Dashboard:** A beautiful, native interface (with full **Dark Mode** support) displaying live visitors, average duration, top search terms, and more. Filter data dynamically by 30, 60, 90, 120 days, or "All Time".
* **📥 CSV Data Exports:** Download your raw tracking data with a single click for reporting or external analysis.
* **📧 Weekly Email Reports:** Get a quick summary of your weekly traffic and pageviews delivered straight to your admin inbox every week.
* **⚡ Dashboard Widget:** Keep an eye on your 7-day performance right from the main WordPress dashboard.

== Installation ==

1. Download or clone this repository.
2. Upload the `bunseki-analytic` folder to your `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Navigate to the **Bunseki** menu in your WordPress admin dashboard to view your statistics.

== FAQ ==

### How does the AI Bot Firewall work?
You can enable the firewall in the Bunseki "Settings" tab. Once active, Bunseki checks the User-Agent of every request. If an AI scraper or known bad bot is detected, it immediately terminates the connection, saving your server resources. Good bots (like Google and Bing) are strictly ignored and allowed to index your site.

### How do I make it GDPR compliant?
Bunseki does not store IP addresses or invasive cookies for tracking. To be 100% compliant, go to the "Settings" tab, copy the `[bunseki_opt_out]` shortcode, and paste it into your Privacy Policy page.

### How do I track custom events?
Downloads and outbound links are tracked automatically. For custom buttons, you can use our simple Javascript API in your theme: `bunseki.track('Event Name', 'Event Value');`

== Changelog ==

= 1.0.2 =
* **Feature:** Added Custom Event Tracking (Auto-tracks outbound links and file downloads).
* **Feature:** Added Marketing & UTM Campaign Dashboard.
* **Feature:** Added AI Scraper & Bad Bot Firewall (Toggle in settings).
* **Feature:** Added beautiful Dark Mode for the entire dashboard.
* **Feature:** Added WordPress Start Page Widget (Quick 7-day overview).
* **Feature:** Added CSV Data Export functionality.
* **Feature:** Added `[bunseki_opt_out]` shortcode for strict GDPR/DSGVO compliance.
* **Feature:** Added automated Weekly Email Traffic Reports.
* **Feature:** Added dynamic time-range filters (30, 60, 90, 120 days, All Time).
* **Optimization:** Dropped redundant `$top_events` cache bugs and improved WPCS compliance.

= 1.0.1 =
* **Update:** Rebranding to Bunseki Analytic.
* **Fix:** 100% strict compliance with WordPress Coding Standards (Security, Escaping, Sanitization).

= 1.0 =
* **[Major Architecture Update]** Introduced the Real-Time Bot Tracker for strict server environments.
* **[Optimization]** Completely decoupled the tracking endpoint from heavy WordPress loading cycles.
* Stable 1.0 Master Release.

---
*Developed with focus on performance and data sovereignty.*
