[![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-8A2BE2.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.2-orange.svg)]()

# Bunseki Pro 📊

**High Scale Analytics (Stealth & Secure) for WordPress.**

Bunseki Pro is a custom-built, highly optimized analytics and tracking system for WordPress. It is designed to be extremely lightweight, privacy-focused, and capable of handling high traffic volumes without slowing down your server.

## ✨ Key Features

* **🚀 High-Performance JS-Endpoint:** Tracks real users via a standalone endpoint (`endpoint.php`) that bypasses the heavy WordPress core for maximum speed and accurate "time on page" metrics.
* **🤖 Real-Time Bot Tracker:** Captures bots and crawlers live via the WordPress `shutdown` hook. Eliminates the need to parse large server `access.log` files, making it 100% compatible with strict hosting environments (like Froxlor, custom `open_basedir`, or strict `0640` file permissions).
* **🔒 Privacy First (GDPR Ready):** Uses secure, salted daily hashing for user identification instead of storing raw IP addresses.
* **📈 Advanced Admin Dashboard:** A beautiful, native WordPress interface displaying live visitors, overall views, average duration, top referrers, top search terms (including 0-hit "lost chances"), and 404 bot tracking.
* **🛠️ Hybrid Log Parsing (Optional):** For traditional server setups, it still includes a robust, AJAX-chunk-based `.log` file importer that processes massive files without PHP timeouts.
* **✅ 100% WPCS Compliant:** Strictly adheres to the latest WordPress Coding Standards (Zero Errors / Zero Warnings).

## 📦 Installation

1. Download or clone this repository.
2. Upload the `bunseki-pro` folder to your `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Navigate to the **Bunseki** menu in your WordPress admin dashboard to view your statistics.

## ⚙️ Configuration & Usage

* **Out of the Box:** The plugin works immediately upon activation. Real visitors are tracked via the lightweight JavaScript core, while bots are tracked silently in the background via PHP.
* **Disable Live Bot Tracking:** If you prefer to manually parse your server access logs via the Importer tool, you can disable the live bot tracker to prevent double-counting. Simply set the database option `bunseki_disable_live_bots` to `1`.

## 📜 Changelog

### Version 1.0 - Official Release
* **[Major Architecture Update]** Introduced the Real-Time Bot Tracker for strict server environments.
* **[Optimization]** Completely decoupled the tracking endpoint from heavy WordPress loading cycles.
* **[Security]** Full code refactoring to achieve 100% compliance with WordPress Coding Standards.
* **[Fix]** Resolved complex caching issues for immediate dashboard data synchronization.
* Stable 1.0 Master Release.

---
*Developed with focus on performance and data sovereignty.*
