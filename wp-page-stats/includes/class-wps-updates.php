<?php
defined('ABSPATH') || exit;

final class WPS_Updates {
    public const SLUG = 'wp-page-stats';
    public const CACHE_KEY = 'wps_github_release';
    private const REPOSITORY = 'https://github.com/Saeed-Tavoosi/wp-page-stats';
    private const RELEASE_API = 'https://api.github.com/repos/Saeed-Tavoosi/wp-page-stats/releases/latest';
    private const ASSET = 'wp-page-stats.zip';

    public static function plugin_update($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== plugin_basename(WPS_FILE) || ($plugin_data['UpdateURI'] ?? '') !== self::REPOSITORY) {
            return $update;
        }

        $release = self::latest_release();
        if (!$release) {
            return $update;
        }

        return array(
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => $plugin_data['RequiresPHP'] ?? '',
        );
    }

    public static function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $plugin = get_plugin_data(WPS_FILE, false, false);
        $release = self::latest_release();
        $changelog = self::local_changelog();
        $info = (object) array(
            'name' => esc_html($plugin['Name']),
            'slug' => self::SLUG,
            'version' => $plugin['Version'],
            'author' => $plugin['Author'],
            'homepage' => self::REPOSITORY,
            'requires' => $plugin['RequiresWP'],
            'requires_php' => $plugin['RequiresPHP'],
            'sections' => array('description' => wpautop(esc_html($plugin['Description']))),
        );

        if ($release && version_compare($release['version'], $info->version, '>')) {
            $info->version = $release['version'];
            $info->download_link = $release['package'];
            $info->last_updated = $release['published_at'];
            $changelog = '<h3>نسخهٔ ' . esc_html($release['version']) . '</h3>'
                . wpautop(esc_html($release['notes']))
                . '<p><a href="' . esc_url($release['url']) . '">جزئیات انتشار در GitHub</a></p>'
                . $changelog;
        }

        $info->sections['changelog'] = $changelog;
        return $info;
    }

    public static function clear_cache_on_manual_check() {
        if (!empty($_GET['force-check']) && current_user_can('update_plugins')) {
            delete_site_transient(self::CACHE_KEY);
        }
    }

    private static function latest_release() {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_safe_remote_get(self::RELEASE_API, array(
            'headers' => array('Accept' => 'application/vnd.github+json'),
            'user-agent' => 'WP Page Stats/' . WPS_VERSION,
            'timeout' => 5,
        ));
        $release = array();
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $release = self::parse_release(json_decode(wp_remote_retrieve_body($response), true));
        }

        set_site_transient(self::CACHE_KEY, $release, ($release ? 30 : 5) * MINUTE_IN_SECONDS);
        return $release;
    }

    private static function parse_release($data) {
        $tag = $data['tag_name'] ?? '';
        if (($data['draft'] ?? true) !== false || ($data['prerelease'] ?? true) !== false
            || !is_string($tag) || !preg_match('/\Av?(\d+\.\d+\.\d+)\z/', $tag, $matches)
            || !is_array($data['assets'] ?? null)) {
            return array();
        }

        $package = self::REPOSITORY . '/releases/download/' . $tag . '/' . self::ASSET;
        foreach ($data['assets'] as $asset) {
            if (($asset['name'] ?? '') === self::ASSET && ($asset['state'] ?? '') === 'uploaded'
                && ($asset['size'] ?? 0) > 0 && ($asset['browser_download_url'] ?? '') === $package) {
                return array(
                    'version' => $matches[1],
                    'package' => $package,
                    'url' => self::REPOSITORY . '/releases/tag/' . $tag,
                    'notes' => is_string($data['body'] ?? null) ? $data['body'] : '',
                    'published_at' => is_string($data['published_at'] ?? null) ? $data['published_at'] : '',
                );
            }
        }

        return array();
    }

    private static function local_changelog() {
        $readme = WPS_DIR . 'readme.txt';
        if (!is_readable($readme) || !preg_match('/^== Changelog ==[ \t]*\R(.*?)(?=^== [^\r\n]+ ==[ \t]*\r?$|\z)/ms', file_get_contents($readme), $matches)) {
            return '';
        }

        return wpautop(preg_replace('/^= ([^\r\n]+) =[ \t]*\r?$/m', '<h3>$1</h3>', esc_html($matches[1])));
    }
}
