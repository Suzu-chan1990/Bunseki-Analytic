=== Bunseki Analytic ===
Contributors: Saguya
Tags: analytics, statistics, tracking, privacy, bot blocker
Requires at least: 7.1
Tested up to: 7.2
Stable tag: 1.3.0
Requires PHP: 8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted visitor statistics: own tracking endpoint, bot statistics, optional AI bot blocker and access log import.

== Description ==

Visitor statistics for WordPress that run entirely on your own server. A small script sends one request per page view to an endpoint of the site; the data is stored in three tables of the WordPress database. No external service is involved.

It also counts bots separately, can block AI scrapers and scripted clients, and can import existing Nginx or Apache access logs.

## Requirements

* WordPress 7.1 or newer
* PHP 8.4 or newer
* MySQL or MariaDB


## Installation

1. Copy the plugin folder to `wp-content/plugins/bunseki-analytic`.
2. Activate it under **Plugins**.
3. Open **Bunseki** in the admin menu.

Tracking starts right away. Logged-in users are not tracked.


## How tracking works

* `js/b-core.js` is loaded on every front-end page for visitors who are not logged in. It sends one `POST` to `/wp-json/bunseki/v1/track` per page view, a heartbeat every 30 seconds while the tab is active, and a last request when the page is closed (for the time on page).
* The endpoint answers with `204` immediately and writes to the database afterwards (`fastcgi_finish_request()` where available).
* Requests are limited to 16 KB, to 120 per minute per IP and user agent, and to the site's own origin. The same page from the same visitor is counted once per 30 seconds.
* Common cache and optimization plugins are told not to delay or combine the script (WP Rocket, LiteSpeed Cache, Perfmatters, Flying Scripts and the `data-cfasync` / `data-noptimize` attributes).
* Visitors that send a bot user agent are counted as bot hits, not as visitors.
* The **Tracking health** card shows when the last live hit arrived and has a button to test the endpoint.

### What is stored

Per page view (`wp_bunseki_log`):

* path of the page (without query string), status code (200 or 404)
* referrer (domain, and the URL without query string for external referrers), `utm_source`
* a daily visitor hash: MD5 of IP address, user agent, local date and `NONCE_SALT`. It changes at midnight of the site's time zone, so visitors cannot be followed over several days.
* device class, operating system, browser, 2-letter browser language, viewport width
* load time, time to first byte, active time on the page
* site search term (`?s=`) and whether the search had results

Per custom event (`wp_bunseki_events`): event name, value (e.g. link target), page and daily hash.

Per bot (`wp_bunseki_bots`): one row per day, bot name, path and status code with a hit counter.

The IP address itself is not stored in the database. For the rate limit and the 30-second duplicate check, short-lived files named after an MD5 of IP and user agent are written to the PHP temp folder and removed regularly.

Rows older than 10 years are deleted by a daily WP-Cron job.

### Opt-out

* Visitors with **Do Not Track** enabled are not tracked.
* The shortcode `[bunseki_opt_out]` shows a button that sets the cookie `bunseki_dnt=1` for one year; from then on that browser is not tracked.

### Behind a proxy

Proxy headers (`CF-Connecting-IP`, `X-Real-IP`, `X-Forwarded-For`) are only used when the request comes from a private or local address (e.g. a local Nginx in front of PHP). To trust them in other setups, add to `wp-config.php`:

```php
define( 'BUNSEKI_TRUST_PROXY_HEADERS', true );
```


## Events

Clicks on links to other sites (event `Outbound Link`) and on downloads of `.pdf`, `.zip`, `.rar`, `.doc(x)` and `.xls(x)` files (event `Download`) are recorded automatically. Your own events can be sent from JavaScript:

```js
bunseki.track( 'Newsletter Signup', 'footer form' );
```

Links with `?utm_source=...` are grouped under **Marketing & UTM**.


## Bot blocker

**Settings → Block AI-Scrapers & Bad Bots** (off by default). When it is on, these user agents get a `403` before WordPress renders the page:

* AI and data crawlers: GPTBot, ChatGPT-User, ClaudeBot, CCBot, Diffbot, Bytespider, Omgilibot, PerplexityBot
* scripted clients: `curl`, `python`, `wget`, `Go-http-client`

Search engines (Google, Bing, Yandex, Baidu, DuckDuckGo), SEO tools and link preview fetchers (Facebook, X, Discord, Telegram, WhatsApp, Pinterest) are not blocked. Blocked requests still appear in the bot statistics.

Note: monitoring or uptime services that use `curl` or Python as user agent are blocked as well. Requests from the admin area and WP-CLI are never blocked.


## Access log import

**Bunseki → Log Importer** imports logs in the Nginx / Apache *combined* format.

* The import runs in chunks in the browser, so large files do not hit PHP time limits.
* Only files in the folder of the automatic import path (or inside the WordPress folder, if none is set) can be read.
* Days that already have live-tracked data only get the bot hits from the log, so visitors are not counted twice.
* Static files, admin, REST, cron, feeds and sitemaps are not counted as page views.
* **Automatic import:** enter a log path; WP-Cron imports it twice a day.

WP-CLI (also for `.gz` files). Run it as the PHP-FPM user of the site:

```bash
wp bunseki parse_log /var/log/nginx/access.log
wp bunseki parse_log /var/log/nginx/access.log.2.gz --force
wp bunseki reset            # deletes all statistics (asks first, --yes skips the question)
```


## Other features

* **Time ranges:** 30, 60, 90, 120 days or all data. Dashboard figures are cached for 15 minutes; **Refresh** recalculates them.
* **CSV export** of the selected range (UTF-8 with BOM for Excel; cell content that starts like a formula is neutralized).
* **Dashboard widget** with visitors and views of the last 7 days.
* **Weekly e-mail** with the same figures to the site's admin address (WP-Cron).
* **Dark mode** of the Bunseki pages follows the system setting.
* **Uninstall:** by default the statistics are kept when the plugin is deleted. **Settings → Delete all statistics when the plugin is deleted** removes the tables and options as well.
* Day boundaries follow the time zone set under **Settings → General**.


## Updates

Bunseki checks this GitHub repository for new releases (bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)) and shows them under **Plugins** like any other update. The check uses the public GitHub API; nothing else is sent.

---

== Changelog ==

= 1.3.0 =
* **New design:** the Bunseki pages use the same layout as Tegatai Secure: banner header with version badge, pill tabs, flat cards. Range, Refresh, CSV export and the live counter are in the header. The Log Importer has the same layout, a link back to the dashboard and a WP-CLI hint.
* **New:** plugin icon and banner in `assets/` (`icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`).
* **Fix:** the GitHub updater loaded its library via `TEGATAI_PATH`. Without Tegatai Secure active this constant does not exist, which ends in a fatal error ("Undefined constant") on every admin page; with Tegatai active, Tegatai's copy of the library was used. It now uses Bunseki's own `lib/plugin-update-checker`.
* **Updater:** also runs for WP-Cron and WP-CLI (background update checks), not only in the admin.
* **Docs:** README and readme.txt rewritten without marketing text, with screenshots, a list of the stored data and the bot blocker details. "Requires at least" corrected to 7.1.

= 1.2.3 =
* **Fix:** Saving the firewall settings no longer erases the auto-import log path (and vice versa). Both forms now use separate option groups.
* **Fix:** The automatic log import (cron) crashed with a fatal error outside WP-CLI. It now runs normally.
* **Fix:** CSV export works again. It now runs via `admin-post.php` (before any admin HTML is sent), is protected by a nonce, adds a UTF-8 BOM for Excel and neutralizes spreadsheet formulas.
* **Fix:** Removed the enqueue of the non-existent `js/chart.min.js`. The traffic chart is pure CSS, so the unused Chart.js files (about 270 KB) were removed as well.
* **Fix:** `b-core.js.br` contained an outdated tracker. Servers with `brotli_static` served the old script; the file is now rebuilt from the current source.
* **Fix:** Log-imported pageviews now get a proper source (Direct / Internal / referrer domain) instead of an empty entry in "Traffic Sources".
* **Fix:** JavaScript error in the log importer when an unknown error occurred.
* **Fix:** The firewall now answers blocked bots with a clean `403` via WordPress (`status_header`, `nocache_headers`).
* **Improvement:** One shared log parser for WP-CLI, admin import and cron, with multi-row `INSERT`s (much faster on large logs). URLs are normalized exactly like live tracking (no query strings, no double or trailing slashes), so one page no longer appears under several URLs.
* **Improvement:** Better browser/OS/device detection: Edge, Opera and Samsung Internet are no longer counted as Chrome, Chrome/Firefox on iPhone are detected correctly, iPads (including iPadOS desktop mode) count as tablets, ChromeOS is recognized.
* **Improvement:** Dashboard days, the daily visitor hash and bot statistics now follow the site timezone (Settings > General) instead of UTC.
* **Improvement:** Endpoint input is clamped to valid ranges (width, duration, status code, etc.).
* **Database:** Versioned migrations. The duplicate `idx_url` index is dropped; the secondary indexes are created once during the upgrade instead of on `admin_init`.
* **New:** `uninstall.php`. By default your statistics are kept when the plugin is deleted. Enable "Delete all statistics when the plugin is deleted" in the settings to remove tables and options.
* **New:** `wp bunseki reset` asks for confirmation (skip with `--yes`) and also clears custom events.
* **Removed:** Legacy "Bunseki Pro" files, the unused plugin-update-checker library and the non-functional "Disable Live Bot Tracker" option.

= 1.2.2 =
* **Fix:** REST tracking endpoint is loaded again, live tracking works.
* **Improvement:** Bots that execute JavaScript are counted as bots, deduplication per IP + user agent + URL, back/forward cache restores count as a new view.
* **New:** Tracking health card with endpoint self-test on the dashboard.

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
