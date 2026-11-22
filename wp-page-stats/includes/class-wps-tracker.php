<?php
defined('ABSPATH') || exit;

class WPS_Tracker {
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_ajax_wps_track', array($this, 'collect'));
        add_action('wp_ajax_nopriv_wps_track', array($this, 'collect'));
        add_action('admin_init', array($this, 'privacy'));
    }

    public static function browsers() { return array('Chrome','Safari','Firefox','Edge','Opera','Samsung Internet','Other'); }
    public static function browser($ua) {
        foreach (array('SamsungBrowser' => 'Samsung Internet', 'Edg|Edge' => 'Edge', 'OPR|Opera' => 'Opera', 'Firefox|FxiOS' => 'Firefox', 'Chrome|CriOS' => 'Chrome', 'Safari' => 'Safari') as $pattern => $name) {
            if (preg_match('~' . $pattern . '~i', $ua)) { return $name; }
        }
        return 'Other';
    }
    public static function device($ua) {
        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua)) { return 'Tablet'; }
        return preg_match('/Mobile|iPhone|iPod|Android/i', $ua) ? 'Mobile' : 'Desktop';
    }
    public static function bot($ua) {
        return !$ua || preg_match('/bot|spider|crawl|slurp|headless|lighthouse|preview|facebookexternalhit|monitor|uptime|curl|wget|python-requests/i', $ua);
    }
    public static function excluded($path) {
        foreach (preg_split('/\r?\n/', wps_settings()['excluded_paths']) as $prefix) {
            $prefix = trim($prefix);
            if ($prefix !== '' && strpos($path, $prefix) === 0) { return true; }
        }
        return false;
    }
    public static function signature($post_id, $path, $title) {
        return hash_hmac('sha256', $post_id . '|' . $path . '|' . $title, wp_salt('auth'));
    }
    public function enqueue() {
        $s = wps_settings();
        if (!$s['enabled'] || is_admin() || is_feed() || is_preview() || is_404() || is_search() || is_trackback()) { return; }
        $post_id = is_singular() ? get_queried_object_id() : 0;
        if (is_front_page() && get_option('page_on_front')) { $post_id = (int) get_option('page_on_front'); }
        if ($post_id && (get_post_status($post_id) !== 'publish' || post_password_required($post_id) || get_post_meta($post_id, '_wps_disabled', true))) { return; }
        $path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $path = '/' . ltrim($path, '/');
        // Query strings are deliberately omitted (including emails, search text and tracking tokens).
        $path = wps_cut($path, 512);
        if (self::excluded($path)) { return; }
        $title = $post_id ? get_the_title($post_id) : wp_get_document_title();
        $title = wps_cut(wp_strip_all_tags($title), 255);
        $config = array('endpoint' => admin_url('admin-ajax.php'), 'postId' => $post_id,
            'path' => $path, 'title' => $title, 'signature' => self::signature($post_id, $path, $title),
            'consentRequired' => (bool) $s['consent'], 'respectDnt' => (bool) $s['respect_dnt'],
            'cookieName' => 'wps_visitor_' . get_current_blog_id(),
            'goals' => wps_settings()['goals'],
        );
        wp_enqueue_script('wps-tracker', plugins_url('assets/tracker.js', WPS_FILE), array(), WPS_VERSION . '.' . filemtime(WPS_DIR . 'assets/tracker.js'), true);
        wp_add_inline_script('wps-tracker', 'window.WPSConfig=' . wp_json_encode($config) . ';', 'before');
    }

    public function collect() {
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_send_json_error(null, 405); }
        foreach (array('post_id','path','title','signature','visitor','event','referrer','consent','event_type','goal','utm_source','utm_medium','utm_campaign','entry_referrer','entry_search','google_click_tag','entry_utm_source','entry_utm_medium') as $field) {
            if (isset($_POST[$field]) && !is_scalar($_POST[$field])) { wp_send_json_error(null, 400); }
        }
        $s = wps_settings();
        $ua = wps_cut(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''), 1024);
        if (!$s['enabled'] || self::bot($ua) || ($s['exclude_admin'] && current_user_can('manage_options'))) { wp_send_json_success(array('recorded' => false)); }
        if ($s['respect_dnt'] && (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1')) { wp_send_json_success(array('recorded' => false)); }
        if ($s['consent'] && ($_POST['consent'] ?? '') !== '1') { wp_send_json_error(null, 403); }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && $origin !== 'null') {
            $expected = wp_parse_url(home_url()); $actual = wp_parse_url($origin);
            if (!$actual || strtolower($actual['host'] ?? '') !== strtolower($expected['host'] ?? '') || ($actual['scheme'] ?? '') !== ($expected['scheme'] ?? '') || ($actual['port'] ?? null) !== ($expected['port'] ?? null)) { wp_send_json_error(null, 403); }
        } elseif ($origin === 'null') { wp_send_json_error(null, 403); }
        $post_id = absint($_POST['post_id'] ?? 0);
        $path = wp_unslash($_POST['path'] ?? ''); $title = wp_unslash($_POST['title'] ?? '');
        $sig = sanitize_text_field(wp_unslash($_POST['signature'] ?? ''));
        if (!is_string($path) || !is_string($title) || strlen($path) > 2048 || strlen($title) > 1020 || !hash_equals(self::signature($post_id, $path, $title), $sig)) { wp_send_json_error(null, 403); }
        if (self::excluded($path)) { wp_send_json_success(array('recorded' => false)); }
        $type = sanitize_key($_POST['event_type'] ?? 'pageview');
        if (!in_array($type,array('pageview','conversion'),true)) { wp_send_json_error(null,400); }
        $goal_id = '';
        if ($type === 'conversion') {
            $goal_id=sanitize_key($_POST['goal'] ?? ''); $goal=WPS_Features::goal($goal_id);
            if (!$goal || !WPS_Features::goal_matches($goal,$path,$post_id)) { wp_send_json_error(null,400); }
        }
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish' || $post->post_password || get_post_meta($post_id, '_wps_disabled', true)) { wp_send_json_success(array('recorded' => false)); }
        }
        $visitor = sanitize_text_field(wp_unslash($_POST['visitor'] ?? ''));
        $event = sanitize_text_field(wp_unslash($_POST['event'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $visitor) || !preg_match('/^[a-f0-9]{32}$/', $event)) { wp_send_json_error(null, 400); }
        $ip = WPS_Geo::client_ip();
        // Approximate per-address flood protection; only a short-lived salted digest is stored.
        global $wpdb;
        $minute = intdiv(time(), 60);
        $slot = hexdec(substr(hash_hmac('sha256', $ip . '|' . $minute, wp_salt('nonce')), 0, 4));
        $limits = $wpdb->prefix . 'wps_rate_limits';
        // Fixed slots cap disk usage. Unrelated addresses sharing a slot share its minute quota.
        $updated = $wpdb->query($wpdb->prepare("INSERT INTO $limits (slot,minute,hits) VALUES (%d,%d,1)
            ON DUPLICATE KEY UPDATE hits=IF(minute=VALUES(minute),LEAST(hits+1,241),1),minute=VALUES(minute)", $slot, $minute));
        if ($updated === false) { wp_send_json_error(null,503); }
        $hits = $wpdb->get_var($wpdb->prepare("SELECT hits FROM $limits WHERE slot=%d AND minute=%d",$slot,$minute));
        if ($hits > 240) { wp_send_json_error(null,429); }
        $hash = hash_hmac('sha256', $visitor, wp_salt('secure_auth'));
        global $wpdb;
        $recent = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WPS_Store::table() . ' WHERE visitor = %s AND occurred_at > %s', $hash, gmdate('Y-m-d H:i:s', time() - 60)));
        if ($recent >= 60) { wp_send_json_error(null, 429); }
        $referrer = wp_parse_url(wp_unslash($_POST['referrer'] ?? ''), PHP_URL_HOST);
        $own_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $referrer = $referrer && strtolower($referrer) !== strtolower($own_host) ? strtolower($referrer) : '';
        $channel = WPS_Acquisition::channel(
            WPS_Store::campaign_label(wp_unslash($_POST['entry_utm_source'] ?? $_POST['utm_source'] ?? '')),
            WPS_Store::campaign_label(wp_unslash($_POST['entry_utm_medium'] ?? $_POST['utm_medium'] ?? '')),
            wps_cut(wp_unslash($_POST['entry_referrer'] ?? $_POST['referrer'] ?? ''), 512),
            ($_POST['entry_search'] ?? '') === '1',
            ($_POST['google_click_tag'] ?? '') === '1'
        );
        $country = WPS_Geo::lookup($ip);
        $data = array(
            'event_key' => hash('sha256', $hash . '|' . $event . ($type === 'conversion' ? '|conversion|' . $goal_id : '')), 'occurred_at' => gmdate('Y-m-d H:i:s'),
            'local_day' => wp_date('Y-m-d'), 'visitor' => $hash,
            'page_key' => hash('sha256', $post_id ? 'post:' . $post_id : 'path:' . $path),
            'post_id' => $post_id, 'path' => wps_cut($path, 512), 'title' => wps_cut($title, 255),
            'country' => $country, 'browser' => self::browser($ua), 'device' => self::device($ua),
            'member' => is_user_logged_in() ? 1 : 0, 'referrer' => wps_cut(sanitize_text_field($referrer), 191),
            'event_type'=>$type, 'goal'=>$goal_id,
            'utm_source'=>WPS_Store::campaign_label(wp_unslash($_POST['utm_source'] ?? '')),
            'utm_medium'=>WPS_Store::campaign_label(wp_unslash($_POST['utm_medium'] ?? '')),
            'utm_campaign'=>WPS_Store::campaign_label(wp_unslash($_POST['utm_campaign'] ?? '')),
            'google_channel'=>$channel,
        );
        $columns = implode(',', array_keys($data));
        $values = implode(',', array_fill(0, count($data), '%s'));
        // Retries of the same navigation do not create another event.
        $result = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . WPS_Store::table() . " ($columns) VALUES ($values)", array_values($data)));
        if ($result === false) { wp_send_json_error(null, 503); }
        wp_send_json_success(array('recorded' => $result === 1));
    }

    public function privacy() {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('WP Page Stats', '<p>این سایت برای آمار بازدید از یک شناسهٔ تصادفی مرورگر با اعتبار ۳۶۵ روز استفاده می‌کند. زمان، مسیر عمومی صفحه بدون پارامترهای آدرس، کشور تقریبی، خانوادهٔ مرورگر، نوع دستگاه، مهمان/عضو بودن و فقط دامنهٔ ارجاع‌دهنده ثبت می‌شوند. در صورت وجود، سه برچسب کمپین UTM و رویداد هدف تبدیل هم ثبت می‌شوند. نوع ورودی گوگل از وجود برچسب تبلیغ، UTM و نشانهٔ سرچ مشخص می‌شود. مقدار شناسهٔ کلیک و متن جست‌وجو در آمار ذخیره نمی‌شوند. انتساب کمپین و نوع ورودی تا ۳۰ دقیقه در همان تب با sessionStorage نگهداری می‌شود؛ اطلاعات شخصی نباید داخل برچسب‌ها قرار بگیرد. IP خام، نام، ایمیل و شناسهٔ حساب در جدول آمار ذخیره نمی‌شود. IP برای تشخیص محلی کشور پردازش و برای محدودیت درخواست در انتخاب سبد شمارش دقیقه‌ای استفاده می‌شود. مدت نگهداری داده‌های این سایت، طبق تنظیم فعلی، ' . (wps_settings()['keep_forever'] ? 'نامحدود' : (int)wps_settings()['retention'].' روز') . ' است. پاک کردن داده‌های مرورگر یا تغییر دستگاه یک بازدیدکنندهٔ جدید می‌سازد. تنظیم رضایت و سیگنال‌های حریم خصوصی باید با سیاست سایت هماهنگ شود.</p>');
        }
    }
}

