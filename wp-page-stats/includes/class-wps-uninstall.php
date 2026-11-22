<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

final class WPS_Uninstall {
    private const OPTIONS = array(
        'wps_settings', 'wps_schema', 'wps_geo_generation', 'wps_geo_updated',
        'wps_installed_at', 'wps_history_floor', 'wps_cleanup_last', 'wps_cleanup_error',
        'wps_purge_job', 'wps_purge_lock', 'wps_google_channels_started_at',
    );
    private const USER_TRANSIENTS = array('wps_purge_preview_', 'wps_geo_notice_');
    private const WIDGET_IDS = array('wps_summary', 'wps-page');
    private const COLUMN_IDS = array('wps_views', 'wps_unique');

    public static function run() {
        if (!is_multisite()) {
            if (self::site()) {
                self::registration_preferences();
            }
            return;
        }

        // Individual sites decide whether to retain their data when the shared files are removed.
        $cleaned = false;
        for ($offset = 0; ; $offset += 100) {
            $sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC'));
            foreach ($sites as $site_id) {
                switch_to_blog((int) $site_id);
                try {
                    if (self::site()) {
                        $cleaned = true;
                    }
                } finally {
                    restore_current_blog();
                }
            }
            if (count($sites) < 100) {
                break;
            }
        }
        if ($cleaned) {
            self::registration_preferences();
        }
    }

    private static function site() {
        global $wpdb;
        $settings = get_option('wps_settings', array());
        if (empty($settings['delete_data'])) {
            return false;
        }

        foreach (array('wps_cleanup', 'wps_cleanup_batch', 'wps_purge_batch') as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        foreach (array('wps_events', 'wps_rate_limits') as $table) {
            if (false === $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table)) {
                wp_die('حذف جدول‌های آمار کامل نشد. دسترسی دیتابیس را بررسی و دوباره حذف کنید.');
            }
        }
        delete_post_meta_by_key('_wps_disabled');
        delete_metadata('user', 0, $wpdb->get_blog_prefix() . 'wps_widget_period', '', true);
        self::screen_preferences();
        self::privacy_suggestions();
        self::transients();
        self::geo_files();

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        return true;
    }

    private static function registration_preferences() {
        $plugin = plugin_basename(dirname(__DIR__) . '/wp-page-stats.php');
        $recent = (array) get_site_option('recently_activated', array());
        if (isset($recent[$plugin])) {
            unset($recent[$plugin]);
            update_site_option('recently_activated', $recent);
        }
        $auto_updates = (array) get_site_option('auto_update_plugins', array());
        if (in_array($plugin, $auto_updates, true)) {
            update_site_option('auto_update_plugins', array_values(array_diff($auto_updates, array($plugin))));
        }
    }

    private static function screen_preferences() {
        global $wpdb;
        $prefixed = $wpdb->get_blog_prefix();
        $last = 0;
        $widget_like = '%' . $wpdb->esc_like('wps_summary') . '%';
        $box_like = '%' . $wpdb->esc_like('wps-page') . '%';
        $views_like = '%' . $wpdb->esc_like('wps_views') . '%';
        $unique_like = '%' . $wpdb->esc_like('wps_unique') . '%';
        // WordPress stores multiple widgets/columns in shared arrays. Remove only our identifiers.
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE umeta_id > %d AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
                 AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s)
                 ORDER BY umeta_id LIMIT 250",
                $last, $widget_like, $box_like, $views_like, $unique_like,
                '%' . $wpdb->esc_like('closedpostboxes_') . '%',
                '%' . $wpdb->esc_like('metaboxhidden_') . '%',
                '%' . $wpdb->esc_like('meta-box-order_') . '%', '%manage%columnshidden'
            ));
            foreach ($rows as $row) {
                $last = (int) $row->umeta_id;
                $key = $row->meta_key;
                if (strpos($key, $prefixed) === 0) {
                    $key = substr($key, strlen($prefixed));
                }
                $value = maybe_unserialize($row->meta_value);
                if (!is_array($value)) {
                    continue;
                }
                $updated = $value;
                if (preg_match('/^manageedit(?:-[a-z0-9_-]+)?columnshidden$/D', $key)) {
                    $updated = self::remove_ids($value, self::COLUMN_IDS);
                } elseif (preg_match('/^(closedpostboxes|metaboxhidden)_[a-z0-9_-]+$/D', $key)) {
                    $updated = self::remove_ids($value, self::WIDGET_IDS);
                } elseif (preg_match('/^meta-box-order_[a-z0-9_-]+$/D', $key)) {
                    foreach ($value as $column => $boxes) {
                        if (is_string($boxes)) {
                            $updated[$column] = implode(',', self::remove_ids(explode(',', $boxes), self::WIDGET_IDS));
                        }
                    }
                }
                if ($updated !== $value) {
                    update_metadata_by_mid('user', $last, $updated);
                }
            }
        } while (count($rows) === 250);
    }

    private static function remove_ids($values, $ids) {
        $remaining = array_filter($values, static function ($item) use ($ids) {
            return !in_array($item, $ids, true);
        });
        return count($values) === count($remaining) ? $values : array_values($remaining);
    }

    private static function privacy_suggestions() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            '_wp_suggested_privacy_policy_content',
            '%' . $wpdb->esc_like('WP Page Stats') . '%'
        ));
        foreach ($rows as $row) {
            $content = maybe_unserialize($row->meta_value);
            if (is_array($content) && ($content['plugin_name'] ?? '') === 'WP Page Stats') {
                delete_metadata_by_mid('post', (int) $row->meta_id);
            }
        }
    }

    private static function transients() {
        global $wpdb;
        foreach (array_merge(self::USER_TRANSIENTS, array('wps_rl_')) as $prefix) {
            $last = 0;
            do {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_id > %d
                     AND (option_name LIKE %s OR option_name LIKE %s) ORDER BY option_id LIMIT 250",
                    $last, $wpdb->esc_like('_transient_' . $prefix) . '%',
                    $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
                ));
                foreach ($rows as $row) {
                    $last = (int) $row->option_id;
                    delete_option($row->option_name);
                }
            } while (count($rows) === 250);
        }

        if (wp_using_ext_object_cache()) {
            $last = 0;
            do {
                // Cache-backed transients have no database row to discover. Their keys use user IDs.
                $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT 250", $last));
                foreach ($ids as $id) {
                    $last = (int) $id;
                    foreach (self::USER_TRANSIENTS as $prefix) {
                        delete_transient($prefix . $last);
                    }
                }
            } while (count($ids) === 250);
        }
    }

    private static function geo_files() {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir'])) {
            return;
        }
        $root = trailingslashit($uploads['basedir']) . 'wp-page-stats-geo';
        if (is_link($root) || !is_dir($root)) {
            return;
        }
        foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $directory) {
            if (is_link($directory) || !preg_match('/^[a-f0-9]{12}$/D', basename($directory))) {
                continue;
            }
            foreach (array('v4.bin', 'v6.bin') as $file) {
                $path = $directory . '/' . $file;
                if (is_file($path) || is_link($path)) {
                    wp_delete_file($path);
                    clearstatcache(true, $path);
                }
                if (is_file($path) || is_link($path)) {
                    wp_die('پاک کردن فایل‌های تشخیص کشور کامل نشد. دسترسی پوشهٔ بارگذاری را بررسی و دوباره حذف کنید.');
                }
            }
            @rmdir($directory);
        }
        @rmdir($root);
    }
}
