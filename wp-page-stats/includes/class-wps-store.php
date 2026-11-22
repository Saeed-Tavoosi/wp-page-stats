<?php
defined('ABSPATH') || exit;

class WPS_Store {
    public static function table() { global $wpdb; return $wpdb->prefix . 'wps_events'; }

    public static function install() {
        global $wpdb;
        $previous = get_option('wps_schema', '');
        // Version 1.0 used an implicit 90-day default. An upgrade must not shorten it.
        if ($previous === '1.0.0') {
            $saved = get_option('wps_settings', array());
            if (!isset($saved['retention'])) { $saved['retention'] = 90; update_option('wps_settings', $saved, false); }
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_key char(64) NOT NULL,
            occurred_at datetime NOT NULL,
            local_day date NOT NULL,
            visitor char(64) NOT NULL,
            page_key char(64) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            path varchar(512) NOT NULL,
            title varchar(255) NOT NULL DEFAULT '',
            country char(2) NOT NULL DEFAULT 'ZZ',
            browser varchar(32) NOT NULL DEFAULT 'Other',
            device varchar(16) NOT NULL DEFAULT 'Desktop',
            member tinyint(1) NOT NULL DEFAULT 0,
            referrer varchar(191) NOT NULL DEFAULT '',
            event_type varchar(12) NOT NULL DEFAULT 'pageview',
            goal varchar(40) NOT NULL DEFAULT '',
            utm_source varchar(80) NOT NULL DEFAULT '',
            utm_medium varchar(80) NOT NULL DEFAULT '',
            utm_campaign varchar(80) NOT NULL DEFAULT '',
            google_channel varchar(16) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY occurred_at (occurred_at),
            KEY post_time (post_id,occurred_at),
            KEY page_time (page_key,occurred_at),
            KEY visitor_time (visitor,occurred_at),
            KEY local_day (local_day),
            KEY goal_time (goal,occurred_at)
        ) $charset;");
        $limits = $wpdb->prefix . 'wps_rate_limits';
        dbDelta("CREATE TABLE $limits (
            slot smallint unsigned NOT NULL,
            minute bigint unsigned NOT NULL,
            hits int unsigned NOT NULL,
            PRIMARY KEY  (slot)
        ) $charset;");
        update_option('wps_schema', WPS_VERSION, false);
        add_option('wps_installed_at', gmdate('Y-m-d H:i:s'), '', false);
        add_option('wps_google_channels_started_at', gmdate('Y-m-d H:i:s'), '', false);
        if (!wps_settings()['keep_forever'] && !wp_next_scheduled('wps_cleanup')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wps_cleanup'); }
    }

    public static function cleanup() {
        global $wpdb;
        if (wps_settings()['keep_forever']) { return 0; }
        $cutoff = self::retention_cutoff();
        $total = 0;
        // Small batches avoid long table locks on shared hosting.
        for ($i = 0; $i < 10; $i++) {
            $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE occurred_at < %s LIMIT 2000', $cutoff));
            if ($deleted === false) { update_option('wps_cleanup_error', gmdate('Y-m-d H:i:s'), false); return false; }
            $total += $deleted;
            if ($deleted < 2000) { break; }
        }
        if ($deleted >= 2000 && !wp_next_scheduled('wps_cleanup_batch')) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'wps_cleanup_batch');
        }
        update_option('wps_cleanup_last', array('at' => gmdate('Y-m-d H:i:s'), 'deleted' => $total), false);
        if ($total) { update_option('wps_history_floor', max($cutoff, get_option('wps_history_floor','')), false); }
        delete_option('wps_cleanup_error');
        return $total;
    }

    public static function retention_cutoff() {
        if (wps_settings()['keep_forever']) { return '1000-01-01 00:00:00'; }
        return gmdate('Y-m-d H:i:s', time() - min(365, max(7, (int) wps_settings()['retention'])) * DAY_IN_SECONDS);
    }
    public static function campaign_label($value) {
        if (!is_scalar($value)) { return ''; }
        $value = wps_cut(trim(sanitize_text_field((string) $value)), 80);
        return preg_match('/^[\p{L}\p{N} ._\-]*$/u', $value) ? $value : '';
    }
    public static function storage_status() {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS records, MIN(occurred_at) AS oldest, MAX(occurred_at) AS newest, COALESCE(SUM(occurred_at < %s),0) AS expired FROM $t", self::retention_cutoff()), ARRAY_A);
        $meta = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $t), ARRAY_A);
        $row['bytes'] = $meta ? (int) $meta['Data_length'] + (int) $meta['Index_length'] : null;
        $row['table'] = $t;
        return $row;
    }
    public static function previous_filters($f) {
        $from = new DateTimeImmutable($f['from'], wp_timezone());
        $to = new DateTimeImmutable($f['to'], wp_timezone());
        $days = (int) $from->diff($to)->days + 1;
        $f['to'] = $from->modify('-1 day')->format('Y-m-d');
        $f['from'] = $from->modify('-' . $days . ' days')->format('Y-m-d');
        return $f;
    }
    public static function data_coverage($f) {
        global $wpdb;
        $first = $wpdb->get_var('SELECT MIN(occurred_at) FROM ' . self::table());
        $installed = get_option('wps_installed_at', '');
        $start = $first && $installed ? min($first, $installed) : ($first ?: $installed);
        $start = max($start ?: gmdate('Y-m-d H:i:s'), self::retention_cutoff(), get_option('wps_history_floor', ''));
        if (!empty($f['google_channel'])) {
            $start = max($start, get_option('wps_google_channels_started_at', ''));
        }
        $requested = (new DateTimeImmutable($f['from'], wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return $requested >= $start;
    }

    public static function filters($input) {
        foreach ($input as $key => $value) { if (!is_scalar($value)) { $input[$key] = ''; } }
        $today = new DateTimeImmutable('today', wp_timezone());
        $from = isset($input['from']) ? sanitize_text_field($input['from']) : $today->modify('-29 days')->format('Y-m-d');
        $to = isset($input['to']) ? sanitize_text_field($input['to']) : $today->format('Y-m-d');
        foreach (array('from' => $from, 'to' => $to) as $key => $value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
            if (!$date || $date->format('Y-m-d') !== $value) { ${$key} = $today->format('Y-m-d'); }
        }
        if ($from > $to) { $swap = $from; $from = $to; $to = $swap; }
        $earliest = (new DateTimeImmutable($to, wp_timezone()))->modify('-365 days')->format('Y-m-d');
        if ($from < $earliest) { $from = $earliest; }
        $country = strtoupper(sanitize_text_field($input['country'] ?? ''));
        return array(
            'from' => $from, 'to' => $to,
            'post_id' => absint($input['post_id'] ?? 0),
            'page_key' => preg_match('/^[a-f0-9]{64}$/', $input['page_key'] ?? '') ? $input['page_key'] : '',
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
            'browser' => in_array($input['browser'] ?? '', WPS_Tracker::browsers(), true) ? $input['browser'] : '',
            'device' => in_array($input['device'] ?? '', array('Desktop', 'Mobile', 'Tablet'), true) ? $input['device'] : '',
            'member' => in_array((string) ($input['member'] ?? ''), array('0', '1'), true) ? (string) $input['member'] : '',
            'utm_source' => self::campaign_label($input['utm_source'] ?? ''),
            'utm_medium' => self::campaign_label($input['utm_medium'] ?? ''),
            'utm_campaign' => self::campaign_label($input['utm_campaign'] ?? ''),
            'google_channel' => isset(WPS_Acquisition::labels()[$input['google_channel'] ?? '']) ? $input['google_channel'] : '',
        );
    }

    public static function where($f) {
        global $wpdb;
        $start = (new DateTimeImmutable($f['from'], wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable($f['to'], wp_timezone()))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $sql = $wpdb->prepare('occurred_at >= %s AND occurred_at < %s', $start, $end);
        foreach (array('post_id', 'page_key', 'country', 'browser', 'device', 'member','utm_source','utm_medium','utm_campaign') as $key) {
            if (($f[$key] ?? '') !== '' && ($key !== 'post_id' || $f[$key] > 0)) {
                $sql .= $wpdb->prepare(" AND $key = %s", $f[$key]);
            }
        }
        if (!empty($f['google_channel'])) {
            $sql .= $wpdb->prepare(' AND (' . WPS_Acquisition::report_channel_sql() . ') = %s', $f['google_channel']);
        }
        return $sql;
    }

    public static function summary($f) {
        global $wpdb;
        return $wpdb->get_row('SELECT COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors,
            COALESCE(SUM(member = 0),0) AS guests, COALESCE(SUM(member = 1),0) AS members
            FROM ' . self::table() . " WHERE event_type = 'pageview' AND " . self::where($f), ARRAY_A);
    }

    public static function groups($f, $dimension, $limit = 25, $offset = 0) {
        global $wpdb;
        if (!in_array($dimension, array('page_key','country','browser','device','member','referrer','local_day','utm_source','utm_medium','utm_campaign','goal','google_channel'), true)) { return array(); }
        $extra = $dimension === 'page_key' ? ', MAX(path) AS path, MAX(title) AS title, MAX(post_id) AS post_id' : '';
        $order = $dimension === 'local_day' ? 'label ASC' : 'views DESC, label ASC';
        $type = $dimension === 'goal' ? 'conversion' : 'pageview';
        $where = self::where($f);
        $group = $dimension === 'google_channel' ? WPS_Acquisition::report_channel_sql() : $dimension;
        $having = $dimension === 'google_channel' ? " HAVING label <> ''" : '';
        return $wpdb->get_results($wpdb->prepare("SELECT $group AS label, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors $extra FROM " . self::table() . " WHERE event_type = %s AND " . $where . " GROUP BY label$having ORDER BY $order LIMIT %d OFFSET %d", $type, $limit, $offset), ARRAY_A);
    }

    public static function conversions($f) {
        global $wpdb;
        $t = self::table(); $where = self::where($f);
        $r = $wpdb->get_row("SELECT COUNT(*) AS conversions, COUNT(DISTINCT visitor) AS conversion_visitors FROM $t WHERE event_type = 'conversion' AND $where", ARRAY_A);
        $r['converters'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor) FROM $t WHERE event_type = 'conversion' AND $where AND visitor IN (SELECT visitor FROM $t WHERE event_type = 'pageview' AND $where)");
        return $r;
    }

    public static function post_counts($ids) {
        global $wpdb;
        $ids = array_filter(array_map('absint', $ids));
        if (!$ids) { return array(); }
        $sql = 'SELECT post_id, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM ' . self::table() . " WHERE event_type = 'pageview' AND post_id IN (" . implode(',', $ids) . ') GROUP BY post_id';
        $out = array();
        foreach ($wpdb->get_results($sql, ARRAY_A) as $row) { $out[(int) $row['post_id']] = $row; }
        return $out;
    }
}

