=== WP Page Stats | آمار صفحات ===
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

آمار فارسی و محلی بازدید صفحات با گزارش قابل فیلتر و ستون‌های مستقل کل و یونیک در نوشته‌ها و برگه‌ها.

== Installation ==
1. Upload wp-page-stats.zip through Plugins > Add New > Upload Plugin.
2. Activate and open the آمار صفحات menu.
3. Open Screen Options on Posts/Pages to toggle total/unique columns independently.
4. Purge page/CDN caches after activation or changing tracking/consent settings.

== Updates ==
Sites running 1.3.0 or earlier need to upload wp-page-stats.zip from the v1.3.1 release and choose Replace current with uploaded. Subsequent stable releases appear under Dashboard > Updates and Installed Plugins. Use the version details link to read the changes before updating.

To publish a new version, update Version, WPS_VERSION and Stable tag, and add an entry to the changelog below. Publish a stable GitHub Release tagged vX.Y.Z, enter the release notes, and attach a file named wp-page-stats.zip containing wp-page-stats/wp-page-stats.php at that path. The release must include the attached installation ZIP.

== Features ==
* Total pageviews and distinct browser visitors for any selected date range.
* Intersecting page, country, browser, device and guest/member filters.
* Server-derived WordPress authentication state, including cached public pages.
* Independent Screen Options checkboxes for total/unique columns, per user/screen.
* Public custom post types, per-page exclusion and editor report links.
* Daily chart with accessible numeric table and aggregate CSV export.
* Local IPv4/IPv6 country database bundled; manual monthly update from DB-IP.
* Known-bot exclusion, administrator exclusion, consent API and DNT/GPC support.
* No raw IP address or WordPress user ID stored in the event table.
* Configurable 7–365 day retention (fresh installs: 30; upgrades preserve previous retention) and optional removal on uninstall.

== External service ==
WordPress periodically requests release metadata from the public GitHub API.
If the API is unavailable, the checker uses the public GitHub release links.
WordPress downloads the release ZIP from GitHub when an update is installed.
The checker does not send visitor statistics or require a GitHub token.

The administrator's explicit database-update button downloads data from
https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.csv.gz.
No visitor address is sent to DB-IP. The bundled database works offline.
DB-IP terms: https://db-ip.com/terms.php
DB-IP privacy: https://db-ip.com/privacy.php
Country data is approximate. See THIRD-PARTY.md for attribution and license.

== Definitions and limits ==
Unique means a random first-party browser identifier (365-day cookie), hashed
before storage. It is not a person, account, IP or daily-unique sum. Cookie
deletion, blocked cookies, multiple devices and private browsing affect accuracy.
Guest/member is derived at collection time from the actual WordPress session.
All totals and list columns cover retained data, not deleted historical events.
No statistics before installation can be reconstructed.
Only visible public pages with JavaScript are measured. Searches, feeds,
previews, password-protected/private pages and 404s are skipped. Full query strings are discarded; only sanitized utm_source, utm_medium and utm_campaign labels are retained separately. Query-only archive variations share a path. Posts are grouped
by post ID even with plain permalinks. Referrers retain only an external host.
Known-bot exclusion is heuristic, not a comprehensive anti-fraud system.
Cookie/consent tools, blockers and disabled JavaScript can reduce counts.
Date inputs support Persian and Gregorian calendars. Internal filter parameters and CSV dates remain Gregorian. Event day uses the site's timezone at collection;
changing site timezone does not rewrite old daily labels.
The reports are available to manage_options users only.
Single-site activation only; activate separately per site on a multisite.
Designed for small and medium sites; high-volume sites need load testing and
may benefit from a dedicated analytics pipeline and aggregate storage.
Cleanup uses WP-Cron in batches; on low-traffic sites configure a real cron.
The monthly updater needs outbound HTTPS, zlib and writable uploads; provider
or hosting limits may prevent updates. Existing database stays active on error.

== Consent integration ==
If explicit consent is enabled, run this after the collector has loaded:
document.dispatchEvent(new CustomEvent('wps:consent', {detail: true}));
Withdraw:
window.WPPageStats?.consent(false);
The plugin provides the consent interface; your existing CMP provides its UI.
Withdrawal stops future collection and deletes the visitor cookie. It does not
delete previously retained anonymous statistics. Respect DNT/GPC defaults on.
Consent-required defaults off; configure this and your privacy policy before use.

== Version 1.1 additions ==
* UTM source/medium/campaign filters, breakdowns and CSV.
* Same-tab last-tagged campaign attribution for up to 30 minutes, after consent.
* Destination goals (exact path or post ID) and custom event conversion API.
* Conversion counts, unique converting visitors and filtered visitor conversion rate.
* Adjacent equal-length period comparison with incomplete-history warnings.
* Persian date entry, digit normalization and per-browser calendar preference.
* Actual row counts, table/index allocation, oldest/newest event and cleanup status.
* Preview/confirm manual deletion, snapshots, bounded resumable batches.
* Fresh installs default to 30-day retention; version-1 settings remain unchanged.

== Changelog ==
= 1.3.3 =
بررسی دستی به‌روزرسانی با آیکن متحرک و نمایش نتیجه در همان ردیف افزونه، بدون بارگذاری دوبارهٔ صفحه.
در صورت اختلال API گیت‌هاب، افزونه از مسیر عمومی انتشار استفاده می‌کند و جزئیات خطای ارتباط را نشان می‌دهد.

= 1.3.2 =
Moved the changelog link beside the author credit. Added a plugin-specific manual GitHub update check in the Installed Plugins list.

= 1.3.1 =
امکان به‌روزرسانی افزونه از GitHub در پیشخوان وردپرس اضافه شد.
لیست تغییرات نسخهٔ جدید و لینک تاریخچهٔ نسخه‌ها در صفحهٔ افزونه‌ها در دسترس است.

= 1.3.0 =
Separates Google Ads, likely organic Google Search and unclassified Google entries in reports, filters and CSV. Click parameter values are not stored.

= 1.2.7 =
Opt-in uninstall now also clears plugin entries in shared admin preferences, privacy suggestions and the plugin list, while preserving other entries.

= 1.2.6 =
The dashboard widget remembers the last selected period for each user.

= 1.2.5 =
Added dashboard period selection, daily trends and most visited content. Bundled Vazirmatn, reduced header height, and opened the Woo Plus badge in a new tab.

= 1.2.4 =
Added the Woo Plus badge and developer credit. The default dashboard menu position is below Pages.

= 1.2.3 =
Added a direct Settings link in the Installed Plugins list.

= 1.2.2 =
Corrected RTL month arrows and matched the font in the date picker and dropdown menus to their controls.

= 1.2.1 =
Configurable admin menu placement, Persian and Gregorian date pickers, page-search progress, and clearer purge cancellation.

= 1.2.0 =
Optional unlimited retention, grouped filters, custom controls and admin submenus. Request counters use a bounded plugin-owned table.
= 1.1.0 =
Campaigns, conversions, calendar, comparisons and data lifecycle management.

= 1.0.0 =
Initial version.
