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
        if (!$release || is_wp_error($release)) {
            return $update;
        }

        return self::update_data($release, $plugin_data['RequiresPHP'] ?? '');
    }

    private static function update_data($release, $requires_php) {
        return array(
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => $requires_php,
        );
    }

    public static function check_now() {
        $release = self::latest_release(true);
        if (is_wp_error($release)) {
            return $release->get_error_code();
        }

        $plugin = plugin_basename(WPS_FILE);
        $header = get_plugin_data(WPS_FILE, false, false);
        $update = (object) self::update_data($release, $header['RequiresPHP']);
        $update->plugin = $plugin;
        $update->id = self::REPOSITORY;
        $update->new_version = $release['version'];

        $state = get_site_transient('update_plugins');
        if (!is_object($state)) {
            $state = new stdClass();
        }
        $state->response = (array) ($state->response ?? array());
        $state->no_update = (array) ($state->no_update ?? array());
        $state->checked = (array) ($state->checked ?? array());
        unset($state->response[$plugin], $state->no_update[$plugin]);
        $state->checked[$plugin] = WPS_VERSION;
        $available = version_compare($release['version'], WPS_VERSION, '>');
        if ($available) {
            $state->response[$plugin] = $update;
        } else {
            $state->no_update[$plugin] = $update;
        }
        set_site_transient('update_plugins', $state);
        return $available ? 'available' : 'current';
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

        if ($release && !is_wp_error($release) && version_compare($release['version'], $info->version, '>')) {
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

    private static function latest_release($fresh = false) {
        if (!$fresh) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
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

        if ($release || !$fresh) {
            set_site_transient(self::CACHE_KEY, $release, ($release ? 30 : 5) * MINUTE_IN_SECONDS);
            return $release;
        }
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if ($code === 200 || $code === 404) {
            return new WP_Error('missing_release');
        }
        if ($code === 403 || $code === 429) {
            return new WP_Error('github_limit');
        }
        return new WP_Error('github_unreachable');
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
