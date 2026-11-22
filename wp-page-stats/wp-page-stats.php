<?php
/**
 * Plugin Name: آمار صفحات | WP Page Stats
 * Plugin URI: https://github.com/Saeed-Tavoosi/wp-page-stats
 * Description: آمار بازدید کل و یونیک با تفکیک صفحه، کشور، مرورگر، دستگاه و مهمان/عضو؛ همراه ستون‌های نوشته و برگه.
 * Version: 1.3.6
 * Update URI: https://github.com/Saeed-Tavoosi/wp-page-stats
 * Requires at least: 6.4
 * Author: سعید طاوسی
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: wp-page-stats
 */
defined('ABSPATH') || exit;
define('WPS_VERSION', '1.3.6');
define('WPS_FILE', __FILE__);
define('WPS_DIR', plugin_dir_path(__FILE__));
require_once WPS_DIR . 'includes/class-wps-store.php';
require_once WPS_DIR . 'includes/class-wps-acquisition.php';
require_once WPS_DIR . 'includes/class-wps-geo.php';
require_once WPS_DIR . 'includes/class-wps-tracker.php';
require_once WPS_DIR . 'includes/class-wps-admin.php';
require_once WPS_DIR . 'includes/class-wps-data.php';
require_once WPS_DIR . 'includes/class-wps-features.php';
require_once WPS_DIR . 'includes/class-wps-updates.php';

add_filter('update_plugins_github.com', array('WPS_Updates', 'plugin_update'), 10, 3);
add_filter('plugins_api', array('WPS_Updates', 'plugin_information'), 10, 3);
add_action('load-update-core.php', array('WPS_Updates', 'clear_cache_on_manual_check'));

function wps_settings() {
    $settings = wp_parse_args(get_option('wps_settings', array()), array(
        'keep_forever' => 0, 'enabled' => 1, 'retention' => 30, 'exclude_admin' => 1,
        'menu_position' => 'pages',
        'respect_dnt' => 1, 'consent' => 0, 'delete_data' => 0,
        'excluded_paths' => '', 'trusted_proxies' => '', 'goals' => array(),
    ));
    if ($settings['menu_position'] === 'default') {
        $settings['menu_position'] = 'pages';
    }
    return $settings;
}
function wps_cut($value, $length) {
    if (function_exists('mb_substr')) { return mb_substr($value, 0, $length, 'UTF-8'); }
    return preg_match('/^.{0,' . (int) $length . '}/us', $value, $m) ? $m[0] : substr($value, 0, $length);
}
register_activation_hook(__FILE__, function ($network_wide) {
    if (is_multisite() && $network_wide) { wp_die('این نسخه باید برای هر سایت جداگانه فعال شود؛ فعال‌سازی شبکه‌ای پشتیبانی نمی‌شود.'); }
    WPS_Store::install();
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('wps_cleanup'); wp_clear_scheduled_hook('wps_cleanup_batch'); wp_clear_scheduled_hook('wps_purge_batch'); });
add_action('wps_cleanup', array('WPS_Store', 'cleanup'));
add_action('wps_cleanup_batch', array('WPS_Store', 'cleanup'));
add_action('plugins_loaded', function () {
    if (get_option('wps_schema') !== WPS_VERSION) { WPS_Store::install(); }
    if (!wps_settings()['keep_forever'] && !wp_next_scheduled('wps_cleanup')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wps_cleanup'); }
    new WPS_Tracker();
    new WPS_Data();
    if (is_admin()) { new WPS_Admin(); }
});
