=== Bunseki Analytic ===
Contributors: Saguya
Tags: analytics, tracking, statistics, privacy, bot blocker
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.2.0
Requires PHP: 8.3
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

## 1.2.0 – 2026-07-07

### Fixed

* **endpoint.php – Architectural cleanup (critical):** Removed a leftover `SHORTINIT` / `wp-load.php` bootstrap block that was a remnant of the old direct-file architecture. Since tracking now runs exclusively through the WordPress REST API, WordPress is already fully loaded when the handler is invoked — manually re-loading it caused potential `$wpdb` conflicts and unpredictable behaviour on some server configurations.
* **endpoint.php – CORS origin check now supports subdomains:** The previous check compared the full `HTTP_ORIGIN` host against `$_SERVER['HTTP_HOST']` directly, which caused legitimate tracking requests from subdomains (e.g. `ai.vtubes.tokyo`) to be rejected with a `403 Forbidden` response. The check now compares the two trailing domain segments (base domain) of both origin and host, so all subdomains of the same root domain are correctly allowed.
* **endpoint.php – Input now read via WP REST API:** User input is now retrieved cleanly through `$request->get_param()` instead of accessing `$_POST` directly, which is the correct and secure pattern for WordPress REST API handlers.
* **endpoint.php – DNT / opt-out check added at handler level:** Do Not Track and the `bunseki_dnt` cookie are now respected directly inside the REST handler as an additional server-side safeguard, independent of the JavaScript check.
* **b-core.js – Endpoint scope bug fixed:** The `endpoint` variable was referenced inside the `bunseki.track()` closure before it was assigned a value. Although JavaScript `var` hoisting prevented a hard error, the value was `undefined` at definition time and relied on implicit closure behaviour. Replaced with a dedicated `getEndpoint()` helper that reads `window.bunsekiAjax.rest_url` at call time, making the behaviour explicit and reliable.
* **b-core.js – Heartbeat interval increased from 10 s to 30 s:** The session duration heartbeat was firing every 10 seconds, generating a continuous stream of `UPDATE` queries against the database. On sites with many concurrent visitors this produced significant unnecessary write load. Increased to 30 seconds — duration accuracy is unaffected for practical purposes.
* **b-core.js – DoNotTrack check centralised:** The DNT and opt-out cookie check was previously duplicated in multiple places throughout the script. Consolidated into a single early-exit check at the top of the load handler and a shared guard inside `bunseki.track()`.
* **bunseki-analytic.php – Bot detection called only once per request:** `Bunseki_Helper::detect_bot()` was previously invoked twice per request — once in `bunseki_firewall_check()` at `plugins_loaded` and again in `bunseki_live_bot_tracker()` at `shutdown`. Refactored into a shared `bunseki_insert_bot_hit()` function that both hooks call, ensuring the User-Agent string is parsed only once.
* **bunseki-analytic.php – Blocked bots now appear in bot statistics:** Bots that were blocked by the firewall (`die()`) never reached the `shutdown` hook and were therefore never recorded in the `bunseki_bots` table, making them invisible in the dashboard. `bunseki_insert_bot_hit()` is now called before the `die()` so blocked bots are correctly tracked.
* **common.php – Retention period comment corrected:** The inline comment on the garbage collection function still read "30 days" while the actual value had been updated to 3650 days (10 years). Comment updated to match the real behaviour.
* **install.php – Deactivation hook now removes all three cron jobs:** The deactivation hook previously only unscheduled `bunseki_daily_cleanup_event`. The weekly email report event (`bunseki_weekly_email_event`) and the auto-import event (`bunseki_auto_import_event`) were left behind as orphaned cron entries. All three are now cleared on deactivation.
* **cli.php – AVIF and JPEG XL extensions added to static asset ignore list:** The log parser's static file filter did not include `.avif` and `.jxl` extensions. These are now excluded alongside existing image formats, consistent with the output formats produced by the Henkan image conversion plugin.

### Changed

* Plugin version bumped to `1.2.0`.
* Plugin header updated: `Tested up to: 7.0`, `Requires PHP: 8.3`.

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
