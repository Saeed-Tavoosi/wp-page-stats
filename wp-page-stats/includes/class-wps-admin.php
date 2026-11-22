<?php
defined('ABSPATH') || exit;

class WPS_Admin {
    private $counts = null;
    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_filter('plugin_action_links_' . plugin_basename(WPS_FILE), array($this, 'plugin_action_links'));
        add_filter('network_admin_plugin_action_links_' . plugin_basename(WPS_FILE), array($this, 'plugin_action_links'));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        add_action('admin_post_wps_check_update', array($this, 'check_update'));
        add_action('wp_ajax_wps_check_update', array($this, 'check_update_ajax'));
        add_action('admin_footer-plugins.php', array($this, 'update_check_form'));
        add_action('admin_notices', array($this, 'update_check_notice'));
        add_action('network_admin_notices', array($this, 'update_check_notice'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_wps_settings', array($this, 'save_settings'));
        add_action('admin_post_wps_geo', array($this, 'update_geo'));
        add_action('admin_post_wps_export', array($this, 'export'));
        add_action('admin_post_wps_widget_period', array($this, 'save_widget_period'));
        add_action('wp_ajax_wps_find_page', array($this, 'find_page'));
        add_action('init', array($this, 'register_columns'), 30);
        add_action('add_meta_boxes', array($this, 'meta_boxes'));
        add_action('save_post', array($this, 'save_meta'));
        add_action('wp_dashboard_setup', array($this, 'dashboard_widget'));
    }
    public function plugin_action_links($links) {
        if (current_user_can('manage_options')) {
            $url = $this->report_url(array('tab' => 'settings'));
            $links['wps_settings'] = '<a href="' . esc_url($url) . '">تنظیمات</a>';
            $links['wps_woo_plus'] = '<a class="wps-partner-badge" href="' . esc_url('https://artiilus.com') . '" target="_blank" rel="noopener noreferrer" aria-label="وو پلاس (تب جدید)">وو پلاس</a>';
        }
        if (current_user_can('update_plugins')) {
            $links['wps_check_update'] = '<button type="submit" form="wps-update-check" class="button wps-update-button"><span class="wps-update-icon" aria-hidden="true"></span>بررسی به‌روزرسانی</button>';
        }
        return $links;
    }
    public function plugin_row_meta($meta, $file) {
        if ($file !== plugin_basename(WPS_FILE) || !current_user_can('install_plugins')) {
            return $meta;
        }
        $url = add_query_arg(array(
            'tab' => 'plugin-information',
            'plugin' => WPS_Updates::SLUG,
            'section' => 'changelog',
            'TB_iframe' => 'true',
            'width' => 600,
            'height' => 800,
        ), is_multisite() ? network_admin_url('plugin-install.php') : admin_url('plugin-install.php'));
        $link = '<a class="thickbox open-plugin-details-modal" href="' . esc_url($url) . '">تاریخچهٔ نسخه‌ها</a>';
        array_splice($meta, 2, 0, array($link));
        return $meta;
    }
    public function update_check_form() {
        if (!current_user_can('update_plugins')) { return; }
        // The plugin list already has a bulk-actions form, so use a separate form.
        echo '<form id="wps-update-check" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wps_check_update">';
        wp_nonce_field('wps_check_update', '_wpnonce', false);
        echo '</form>';
    }
    public function check_update() {
        if (!current_user_can('update_plugins')) {
            wp_die('دسترسی کافی نیست.', '', array('response' => 403));
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('درخواست نامعتبر.', '', array('response' => 405));
        }
        check_admin_referer('wps_check_update');
        $result = WPS_Updates::check_now();
        $status = is_wp_error($result) ? $result->get_error_code() : $result['status'];
        $screen = is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php');
        $redirect = add_query_arg(array(
            'wps_update_result' => $status,
            '_wpnonce' => wp_create_nonce('wps_update_result_' . $status),
        ), $screen);
        wp_safe_redirect($redirect, 303);
        exit;
    }
    public function check_update_ajax() {
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(array('message' => 'دسترسی بررسی به‌روزرسانی را ندارید.'), 403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(array('message' => 'درخواست بررسی نامعتبر است.'), 405);
        }
        if (!check_ajax_referer('wps_check_update', '_wpnonce', false)) {
            wp_send_json_error(array('message' => 'اعتبار فرم بررسی تمام شده است. صفحه را تازه کنید و دوباره امتحان کنید.'), 403);
        }

        $result = WPS_Updates::check_now();
        if (is_wp_error($result)) {
            $details = trim($result->get_error_message() . ' ' . $result->get_error_data());
            wp_send_json_error(array(
                'message' => $this->update_check_message($result->get_error_code())[1],
                'detail' => wps_cut(sanitize_text_field($details), 350),
            ), 502);
        }

        $result['message'] = $this->update_check_message($result['status'], $result['version'])[1];
        if ($result['status'] === 'available') {
            $plugin = plugin_basename(WPS_FILE);
            $result['upgrade_url'] = add_query_arg(array(
                'action' => 'upgrade-plugin',
                'plugin' => rawurlencode($plugin),
                '_wpnonce' => wp_create_nonce('upgrade-plugin_' . $plugin),
            ), is_multisite() ? network_admin_url('update.php') : admin_url('update.php'));
        }
        wp_send_json_success($result);
    }
    public function update_check_notice() {
        global $pagenow;
        if ($pagenow !== 'plugins.php' || !current_user_can('update_plugins')) { return; }
        $result = sanitize_key($_GET['wps_update_result'] ?? '');
        $message = $this->update_check_message($result);
        if (!$message || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'wps_update_result_' . $result)) {
            return;
        }
        echo '<div class="notice notice-' . esc_attr($message[0]) . ' is-dismissible"><p>' . esc_html($message[1]) . '</p></div>';
    }
    private function update_check_message($status, $version = '') {
        $messages = array(
            'available' => array('info', $version ? 'نسخهٔ ' . $version . ' در دسترس است.' : 'نسخهٔ جدید آمار صفحات در دسترس است. از لینک به‌روزرسانی در ردیف افزونه استفاده کنید.'),
            'current' => array('success', 'نسخهٔ ' . ($version ?: WPS_VERSION) . ' به‌روز است.'),
            'missing_release' => array('warning', 'انتشار پایدار آمار صفحات در گیت‌هاب پیدا نشد.'),
            'missing_release_asset' => array('warning', 'فایل نصب wp-page-stats.zip در آخرین انتشار پایدار گیت‌هاب پیدا نشد.'),
            'invalid_release' => array('warning', 'پاسخ گیت‌هاب برای تشخیص نسخه معتبر نبود.'),
            'github_limit' => array('warning', 'گیت‌هاب درخواست بررسی نسخه را نپذیرفت.'),
            'github_unreachable' => array('warning', 'ارتباط هاست با گیت‌هاب برای بررسی نسخه برقرار نشد.'),
        );
        return $messages[$status] ?? null;
    }
    private function guard($nonce) {
        if (!current_user_can('manage_options')) { wp_die('دسترسی کافی نیست.', '', array('response' => 403)); }
        check_admin_referer($nonce);
    }
    public function menu() {
        add_menu_page('آمار صفحات', 'آمار صفحات', 'manage_options', 'wp-page-stats', array($this, 'render'), plugins_url('assets/menu-icon.png', WPS_FILE), 65);
        add_submenu_page('wp-page-stats','گزارش بازدید','گزارش بازدید','manage_options','wp-page-stats',array($this,'render'));
        foreach (array('wps-settings'=>'تنظیمات و حریم خصوصی','wps-data'=>'مدیریت و پاکسازی داده‌ها') as $slug=>$label) {
            add_submenu_page('wp-page-stats',$label,$label,'manage_options',$slug,array($this,'render'));
        }
        $anchor = self::menu_positions()[wps_settings()['menu_position']][1] ?? '';
        if ($anchor !== '') {
            add_filter('custom_menu_order', '__return_true');
            add_filter('menu_order', static function ($items) use ($anchor) {
                if (!in_array($anchor, $items, true) || !in_array('wp-page-stats', $items, true)) {
                    return $items;
                }
                $items = array_values(array_diff($items, array('wp-page-stats')));
                array_splice($items, array_search($anchor, $items, true) + 1, 0, array('wp-page-stats'));
                return $items;
            }, 100);
        }
    }
    private static function menu_positions() {
        return array(
            'pages' => array('زیر برگه‌ها (پیش‌فرض)', 'edit.php?post_type=page'),
            'posts' => array('زیر نوشته‌ها', 'edit.php'),
            'plugins' => array('زیر افزونه‌ها', 'plugins.php'),
            'settings' => array('زیر تنظیمات وردپرس', 'options-general.php'),
        );
    }
    public function assets($hook) {
        if ($hook === 'plugins.php' || current_user_can('manage_options')) {
            wp_enqueue_style('wps-font', plugins_url('assets/font.css', WPS_FILE), array(), WPS_VERSION);
            if ($hook === 'index.php') {
                wp_enqueue_style('wps-widget', plugins_url('assets/widget.css', WPS_FILE), array('wps-font'), WPS_VERSION);
            }
        }
        if ($hook === 'plugins.php') {
            wp_enqueue_style('wps-plugin-list', plugins_url('assets/plugin-list.css', WPS_FILE), array('wps-font'), WPS_VERSION);
            if (current_user_can('update_plugins')) {
                wp_enqueue_script('wps-plugin-list', plugins_url('assets/plugin-list.js', WPS_FILE), array(), WPS_VERSION, array(
                    'in_footer' => true,
                    'strategy' => 'defer',
                ));
            }
        }
        if (in_array($hook,array('edit.php','post.php','post-new.php'),true)) {
            wp_enqueue_style('wps-checks', plugins_url('assets/checks.css',WPS_FILE),array(),WPS_VERSION);
        }
        if (!in_array($_GET['page'] ?? '',array('wp-page-stats','wps-settings','wps-data'),true)) { return; }
        wp_enqueue_style('wps-checks', plugins_url('assets/checks.css',WPS_FILE),array(),WPS_VERSION);
        wp_enqueue_script('wps-controls',plugins_url('assets/controls.js',WPS_FILE),array('wps-admin'),WPS_VERSION,true);
        wp_enqueue_style('wps-admin', plugins_url('assets/admin.css', WPS_FILE), array(), WPS_VERSION);
        wp_enqueue_script('wps-calendar', plugins_url('assets/calendar.js', WPS_FILE), array(), WPS_VERSION, true);
        wp_enqueue_script('wps-admin', plugins_url('assets/admin.js', WPS_FILE), array('wps-calendar'), WPS_VERSION, true);
        wp_add_inline_script('wps-admin', 'window.WPSAdmin=' . wp_json_encode(array('ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wps_find_page'))) . ';', 'before');
    }
    public function register_columns() {
        if (!current_user_can('manage_options')) { return; }
        foreach (get_post_types(array('public' => true)) as $type) {
            if ($type === 'attachment') { continue; }
            add_filter("manage_{$type}_posts_columns", array($this, 'columns'));
            add_action("manage_{$type}_posts_custom_column", array($this, 'column'), 10, 2);
        }
    }
    public function columns($columns) {
        $columns['wps_views'] = 'بازدید کل';
        $columns['wps_unique'] = 'بازدید یونیک';
        return $columns;
    }
    public function column($column, $id) {
        if (!in_array($column, array('wps_views', 'wps_unique'), true)) { return; }
        if ($this->counts === null) {
            global $wp_query;
            $ids = wp_list_pluck($wp_query->posts ?? array(), 'ID');
            $ids[] = $id;
            $this->counts = WPS_Store::post_counts($ids);
        }
        $count = $this->counts[$id][$column === 'wps_views' ? 'views' : 'visitors'] ?? 0;
        $s = wps_settings();
        $url = $this->report_url(array('post_id' => $id, 'from' => wp_date('Y-m-d', time() - (int) $s['retention'] * DAY_IN_SECONDS), 'to' => wp_date('Y-m-d')));
        echo '<a href="' . esc_url($url) . '" title="' . esc_attr('آمار موجود در دورهٔ نگهداری؛ کلیک برای جزئیات') . '">' . esc_html(number_format_i18n($count)) . '</a>';
    }
    public function meta_boxes() {
        if (!current_user_can('manage_options')) { return; }
        foreach (get_post_types(array('public' => true)) as $type) {
            if ($type !== 'attachment') { add_meta_box('wps-page', 'آمار این صفحه', array($this, 'meta_box'), $type, 'side'); }
        }
    }
    public function meta_box($post) {
        wp_nonce_field('wps_meta', 'wps_meta_nonce');
        $c = WPS_Store::post_counts(array($post->ID)); $c = $c[$post->ID] ?? array('views' => 0, 'visitors' => 0);
        echo '<p>کل: <strong>' . esc_html(number_format_i18n($c['views'])) . '</strong> · یونیک: <strong>' . esc_html(number_format_i18n($c['visitors'])) . '</strong></p>';
        echo '<p><label><input type="checkbox" name="wps_disabled" value="1" ' . checked(get_post_meta($post->ID, '_wps_disabled', true), '1', false) . '> آمارگیری این صفحه غیرفعال شود</label></p>';
        echo '<a href="' . esc_url($this->report_url(array('post_id' => $post->ID))) . '">گزارش اختصاصی و فیلترها ←</a><p class="description">اعداد مربوط به داده‌های نگهداری‌شده‌اند. تغییر تنظیم آمارگیری پس از ذخیره اعمال می‌شود.</p>';
    }
    public function save_meta($id) {
        if (!isset($_POST['wps_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wps_meta_nonce'])), 'wps_meta') || !current_user_can('manage_options') || !current_user_can('edit_post', $id) || wp_is_post_revision($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) { return; }
        if (!empty($_POST['wps_disabled'])) { update_post_meta($id, '_wps_disabled', '1'); } else { delete_post_meta($id, '_wps_disabled'); }
    }
    public function report_url($args = array()) { $slug = array('settings'=>'wps-settings','data'=>'wps-data')[$args['tab'] ?? ''] ?? 'wp-page-stats'; unset($args['tab']); return add_query_arg(array_merge(array('page'=>$slug),$args),admin_url('admin.php')); }
    public function find_page() {
        if (!current_user_can('manage_options')) { wp_send_json_error(null, 403); }
        check_ajax_referer('wps_find_page', 'nonce');
        $posts = get_posts(array('post_type' => array_values(get_post_types(array('public' => true))), 'post_status' => 'publish', 's' => sanitize_text_field(wp_unslash($_GET['q'] ?? '')), 'posts_per_page' => 20));
        wp_send_json_success(array_map(function ($p) { return array('id' => $p->ID, 'title' => get_the_title($p)); }, $posts));
    }
    public function save_settings() {
        $this->guard('wps_settings');
        $old = wps_settings(); $s = array();
        foreach (array('enabled','exclude_admin','respect_dnt','consent','delete_data','keep_forever') as $key) { $s[$key] = empty($_POST[$key]) ? 0 : 1; }
        $s['retention'] = min(365, max(7, absint($_POST['retention'] ?? 30)));
        $position = $_POST['menu_position'] ?? '';
        $s['menu_position'] = is_string($position) && isset(self::menu_positions()[$position]) ? $position : 'pages';
        $s['goals'] = WPS_Features::save_goals(wp_unslash($_POST['goals'] ?? array()));
        $s['excluded_paths'] = wps_cut(sanitize_textarea_field(wp_unslash($_POST['excluded_paths'] ?? '')), 4000);
        $s['trusted_proxies'] = wps_cut(sanitize_textarea_field(wp_unslash($_POST['trusted_proxies'] ?? '')), 4000);
        update_option('wps_settings', $s, false);
        if ($s['keep_forever']) {
            wp_clear_scheduled_hook('wps_cleanup');
            wp_clear_scheduled_hook('wps_cleanup_batch');
        }
        // Clear common page caches so changes to consent or enabled state can reach rendered scripts.
        if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); }
        do_action('wps_settings_updated', $s, $old);
        wp_safe_redirect($this->report_url(array('tab' => 'settings', 'saved' => 1))); exit;
    }
    public function update_geo() {
        $this->guard('wps_geo');
        $result = WPS_Geo::update();
        set_transient('wps_geo_notice_' . get_current_user_id(), is_wp_error($result) ? $result->get_error_message() : 'پایگاه کشور با موفقیت به‌روز شد: ' . number_format_i18n($result) . ' محدوده.', 120);
        wp_safe_redirect($this->report_url(array('tab' => 'settings'))); exit;
    }
    private function csv_cell($value) {
        $value = (string) $value;
        return preg_match('/^[\s\x00-\x1F]*[=+@\-]/u', $value) ? "'" . $value : $value;
    }
    public function export() {
        $this->guard('wps_export');
        $f = WPS_Store::filters(wp_unslash($_GET));
        $dimension = sanitize_key($_GET['dimension'] ?? 'page_key');
        if (!in_array($dimension, array('page_key','country','browser','device','member','referrer','local_day','utm_source','utm_medium','utm_campaign','goal','google_channel'), true)) { $dimension = 'page_key'; }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wp-page-stats-' . $dimension . '-' . $f['from'] . '.csv"');
        $fp = fopen('php://output', 'w'); fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array('بخش', 'عنوان', 'مسیر', $dimension==='goal' ? 'رویداد تبدیل' : 'بازدید کل', $dimension==='goal' ? 'مرورگرهای دارای تبدیل' : 'بازدید یونیک'), ',', '"', '');
        $offset = 0;
        do {
            $rows = WPS_Store::groups($f, $dimension, 500, $offset);
            foreach ($rows as $r) {
                $label = $dimension === 'member' ? ($r['label'] ? 'عضو' : 'مهمان') : $r['label'];
                if ($dimension === 'page_key') { $label = $r['post_id'] ?: $r['label']; }
                if ($dimension === 'goal') { $goal=WPS_Features::goal($label); $r['title']=$goal ? $goal['label'] : $label; }
                if ($dimension === 'google_channel') { $label = WPS_Acquisition::labels()[$label] ?? $label; }
                fputcsv($fp, array_map(array($this, 'csv_cell'), array($label, $r['title'] ?? '', $r['path'] ?? '', $r['views'], $r['visitors'])), ',', '"', '');
            }
            $offset += 500;
        } while (count($rows) === 500);
        fclose($fp); exit;
    }
    public function dashboard_widget() {
        if (current_user_can('manage_options')) { wp_add_dashboard_widget('wps_summary', 'آمار صفحات', array($this, 'widget')); }
    }
    public function save_widget_period() {
        $this->guard('wps_widget_period');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('درخواست نامعتبر.', '', array('response' => 405));
        }
        $period = $_POST['wps_period'] ?? '';
        if (!in_array($period, array('7', '30'), true)) {
            wp_die('بازهٔ انتخاب‌شده معتبر نیست.', '', array('response' => 400));
        }
        update_user_option(get_current_user_id(), 'wps_widget_period', (int) $period);
        wp_safe_redirect(admin_url('index.php') . '#wps_summary', 303);
        exit;
    }
    public function widget() {
        $period = (int) get_user_option('wps_widget_period') === 30 ? 30 : 7;
        $today = new DateTimeImmutable('today', wp_timezone());
        $from = $today->modify('-' . ($period - 1) . ' days');
        $range = array('from' => $from->format('Y-m-d'), 'to' => $today->format('Y-m-d'));
        $filters = WPS_Store::filters($range);
        $summary = WPS_Store::summary($filters);
        echo '<div class="wps-widget" dir="rtl"><form class="wps-widget-period" aria-label="بازهٔ آمار صفحات" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wps_widget_period">';
        wp_nonce_field('wps_widget_period', '_wpnonce', false);
        foreach (array(7 => '۷ روز اخیر', 30 => '۳۰ روز اخیر') as $days => $label) {
            echo '<button type="submit" name="wps_period" value="' . esc_attr($days) . '"' . ($period === $days ? ' aria-current="true"' : '') . '>' . esc_html($label) . '</button>';
        }
        echo '</form><dl class="wps-widget-metrics">';
        foreach (array('views' => 'بازدید کل', 'visitors' => 'بازدیدکنندهٔ یونیک', 'guests' => 'بازدید مهمان‌ها', 'members' => 'بازدید اعضا') as $key => $label) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html(number_format_i18n((int) ($summary[$key] ?? 0))) . '</dd></div>';
        }
        echo '</dl>';
        if (!empty($summary['views'])) {
            $daily = array_column(WPS_Store::groups($filters, 'local_day', $period), 'views', 'label');
            $maximum = max(array_merge(array(1), array_map('intval', $daily)));
            echo '<div class="wps-widget-trend"><strong>روند بازدید روزانه</strong><div class="wps-widget-bars" role="img" aria-label="نمودار بازدید روزانه. مقادیر دقیق در گزارش کامل موجود است.">';
            for ($date = $from; $date <= $today; $date = $date->modify('+1 day')) {
                $day = $date->format('Y-m-d');
                $views = (int) ($daily[$day] ?? 0);
                $height = (int) round(100 * $views / $maximum);
                echo '<span style="--wps-bar-height:' . esc_attr($height) . '%" title="' . esc_attr($day . ' · ' . number_format_i18n($views) . ' بازدید') . '"></span>';
            }
            echo '</div></div>';
            $top_pages = WPS_Store::groups($filters, 'page_key', 1);
            if ($top_pages) {
                $top = $top_pages[0];
                $title = $top['title'] ?: ($top['path'] ?: 'صفحهٔ اصلی');
                $page_filter = $top['post_id'] ? array('post_id' => $top['post_id']) : array('page_key' => $top['label']);
                echo '<div class="wps-widget-top"><div><span>پربازدیدترین صفحه</span><a href="' . esc_url($this->report_url($range + $page_filter)) . '">' . esc_html($title) . '</a></div>';
                echo '<strong>' . esc_html(number_format_i18n((int) $top['views'])) . ' بازدید</strong></div>';
            }
        } else {
            echo '<p class="wps-widget-empty">در این بازه هنوز بازدیدی ثبت نشده است.</p>';
        }
        echo '<a class="wps-widget-report" href="' . esc_url($this->report_url($range)) . '">گزارش کامل همین بازه <span aria-hidden="true">←</span></a></div>';
    }

    public function render() {
        if (!current_user_can('manage_options')) { return; }
        $settings = ($_GET['page'] ?? '') === 'wps-settings' || ($_GET['tab'] ?? '') === 'settings';
        $data = ($_GET['page'] ?? '') === 'wps-data' || ($_GET['tab'] ?? '') === 'data';
        echo '<div class="wrap wps" dir="rtl"><header class="wps-header"><h1>آمار صفحات</h1>';
        echo '<div class="wps-release"><span class="wps-badge">نسخهٔ ' . esc_html(WPS_VERSION) . '</span><span class="wps-developer">توسعه‌دهنده: سعید طاوسی</span></div></header>';
        echo '<nav class="wps-tabs"><a class="' . (!$settings && !$data ? 'active' : '') . '" href="' . esc_url($this->report_url()) . '">گزارش بازدید</a><a class="' . ($settings ? 'active' : '') . '" href="' . esc_url($this->report_url(array('tab' => 'settings'))) . '">تنظیمات و حریم خصوصی</a><a class="'.($data ? 'active' : '').'" href="'.esc_url($this->report_url(array('tab'=>'data'))).'">مدیریت و پاکسازی داده‌ها</a></nav>';
        if ($settings) { $this->settings_page(); } elseif ($data) { WPS_Data::render(); } else { $this->reports(); }
        echo '<footer class="wps-footer">تشخیص تقریبی کشور: <a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a> · بدون ذخیرهٔ IP خام · منطقهٔ زمانی: ' . esc_html(wp_timezone_string()) . '</footer></div>';
    }
    private function select($name, $label, $items, $value) {
        echo '<label>' . esc_html($label) . '<select name="' . esc_attr($name) . '"><option value="">همه</option>';
        foreach ($items as $key => $text) { echo '<option value="' . esc_attr($key) . '" ' . selected((string) $value, (string) $key, false) . '>' . esc_html($text) . '</option>'; }
        echo '</select></label>';
    }
    private function reports() {
        global $wpdb;
        $f = WPS_Store::filters(wp_unslash($_GET));
        $s = wps_settings();
        if (!$s['enabled']) { echo '<div class="notice notice-warning inline"><p>آمارگیری متوقف است. برای ثبت بازدیدهای جدید، آن را در تنظیمات فعال کنید.</p></div>'; }
        echo '<form class="wps-panel wps-filter" method="get"><input type="hidden" name="page" value="wp-page-stats">';
        WPS_Features::date_fields($f);
        echo '<div class="wps-page-picker"><label for="wps-page-search">صفحه / نوشته</label><input id="wps-page-search" type="search" placeholder="عنوان یا شناسهٔ صفحه…" autocomplete="off" aria-controls="wps-page-results" value="' . esc_attr($f['post_id'] ? get_the_title($f['post_id']) : '') . '"><span class="wps-search-spinner" hidden aria-hidden="true"></span><div id="wps-page-results"></div><span id="wps-page-status" class="screen-reader-text" role="status"></span><input type="hidden" name="post_id" id="wps-post-id" value="' . esc_attr($f['post_id']) . '"></div>';
        if ($f['page_key']) { echo '<input type="hidden" name="page_key" value="' . esc_attr($f['page_key']) . '"><span class="wps-badge">فیلتر یک مسیر فعال است</span>'; }
        echo '<details class="wps-more" '.(array_filter(array($f['country'],$f['browser'],$f['device'],$f['utm_source'],$f['utm_medium'],$f['utm_campaign'],$f['google_channel'])) || $f['member']!=='' ? 'open' : '').'><summary>فیلترهای مخاطب و کمپین</summary><div class="wps-grid">';
        $countries = $wpdb->get_col('SELECT DISTINCT country FROM ' . WPS_Store::table() . ' ORDER BY country');
        $country_items = array(); foreach ($countries as $cc) { $country_items[$cc] = $this->country_name($cc); }
        $this->select('country', 'کشور', $country_items, $f['country']);
        $this->select('browser', 'مرورگر', array_combine(WPS_Tracker::browsers(), WPS_Tracker::browsers()), $f['browser']);
        $this->select('member', 'وضعیت کاربر', array('0' => 'مهمان', '1' => 'عضو / واردشده'), $f['member']);
        $this->select('device', 'دستگاه', array('Desktop' => 'رایانه', 'Mobile' => 'موبایل', 'Tablet' => 'تبلت'), $f['device']);
        $this->select('google_channel', 'نوع ورودی گوگل', WPS_Acquisition::labels(), $f['google_channel']);
        foreach (array('utm_source'=>'منبع کمپین','utm_medium'=>'رسانهٔ کمپین','utm_campaign'=>'نام کمپین') as $name=>$label) {
            echo '<label>'.esc_html($label).'<input name="'.esc_attr($name).'" value="'.esc_attr($f[$name]).'" maxlength="80" dir="auto" placeholder="همه"></label>';
        }
        echo '</div></details><div class="wps-filter-actions"><button class="button button-primary">اعمال فیلتر</button><a class="button" href="' . esc_url($this->report_url()) . '">پاک کردن</a></div></form>';
        echo '<div class="wps-quick">بازهٔ سریع: ';
        foreach (array(1 => 'امروز', 7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز') as $days => $label) {
            echo '<a href="' . esc_url($this->report_url(array_merge($f, array('from' => wp_date('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS), 'to' => wp_date('Y-m-d'))))) . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';
        $summary = WPS_Store::summary($f);
        echo '<div class="wps-cards">';
        foreach (array('views' => array('بازدید کل', 'هر بار نمایش صفحه'), 'visitors' => array('بازدیدکنندهٔ یونیک', 'مرورگرهای متمایز در این بازه'), 'guests' => array('بازدید مهمان‌ها', 'بدون ورود به حساب'), 'members' => array('بازدید اعضا', 'با ورود به حساب')) as $key => $copy) {
            echo '<article class="wps-card"><span>' . esc_html($copy[0]) . '</span><strong>' . esc_html(number_format_i18n($summary[$key])) . '</strong><small>' . esc_html($copy[1]) . '</small></article>';
        }
        echo '</div>';
        if (!$summary['views']) { echo '<div class="wps-panel wps-empty"><h2>در این بازه هنوز بازدیدی ثبت نشده</h2><p>یک صفحهٔ عمومی را در مرورگر مهمان باز کنید یا فیلترها را تغییر دهید. بازدید مدیر به‌صورت پیش‌فرض شمرده نمی‌شود.</p></div>'; }
        $this->google_channels($f);
        $this->chart($f);
        WPS_Features::comparison($f,$summary);
        WPS_Features::conversions_report($f,$summary);
        $page = min(100000, max(1, absint($_GET['report_page'] ?? 1)));
        $rows = WPS_Store::groups($f, 'page_key', 26, ($page - 1) * 25);
        $next = count($rows) > 25; $rows = array_slice($rows, 0, 25);
        echo '<section class="wps-panel"><div class="wps-section-title"><h2>صفحه‌ها و نوشته‌ها</h2>' . $this->export_link($f, 'page_key') . '</div><div class="wps-table-scroll"><table class="wps-table"><thead><tr><th>صفحه</th><th>بازدید کل</th><th>یونیک</th><th>جزئیات</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $args = $f;
            if ($r['post_id']) { $args['post_id'] = $r['post_id']; $args['page_key'] = ''; }
            else { $args['page_key'] = $r['label']; $args['post_id'] = 0; }
            echo '<tr><td><strong>' . esc_html($r['title'] ?: $r['path']) . '</strong><small class="wps-path" dir="ltr">' . esc_html(rawurldecode($r['path'])) . '</small></td><td>' . esc_html(number_format_i18n($r['views'])) . '</td><td>' . esc_html(number_format_i18n($r['visitors'])) . '</td><td><a href="' . esc_url($this->report_url($args)) . '">گزارش صفحه ←</a></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="4">داده‌ای برای نمایش وجود ندارد.</td></tr>'; }
        echo '</tbody></table></div><div class="wps-pagination">';
        if ($page > 1) { echo '<a class="button" href="' . esc_url($this->report_url(array_merge($f, array('report_page' => $page - 1)))) . '">قبلی</a>'; }
        echo '<span>صفحهٔ ' . esc_html(number_format_i18n($page)) . '</span>';
        if ($next) { echo '<a class="button" href="' . esc_url($this->report_url(array_merge($f, array('report_page' => $page + 1)))) . '">بعدی</a>'; }
        echo '</div></section><div class="wps-breakdowns">';
        foreach (array('country' => 'کشورها', 'browser' => 'مرورگرها', 'member' => 'مهمان و عضو', 'device' => 'دستگاه‌ها', 'referrer' => 'سایت‌های ارجاع‌دهنده', 'utm_source'=>'منبع کمپین‌ها','utm_medium'=>'رسانهٔ کمپین‌ها','utm_campaign'=>'نام کمپین‌ها','goal'=>'تبدیل به تفکیک هدف') as $dim => $title) { $this->breakdown($f, $dim, $title); }
        echo '</div><div class="wps-note"><strong>این اعداد چه می‌گویند؟</strong><p>یونیکِ کل بازه از مرورگرهای متمایز محاسبه می‌شود؛ جمع یونیک روزها، صفحه‌ها یا دسته‌ها الزاماً برابر یونیک کل نیست. یک مرورگر ممکن است هم در گروه مهمان و هم عضو دیده شود. داده‌ها فقط از زمان نصب جمع می‌شوند. ' . ($s['keep_forever'] ? 'حذف خودکار خاموش است.' : 'مدت نگهداری: '.esc_html($s['retention']).' روز.') . ' ستون‌های فهرست نوشته/برگه، کل دادهٔ موجود را نشان می‌دهند.</p><p>جاوااسکریپت مسدودشده، رد رضایت، حذف کوکی و ابزارهای مسدودکننده می‌توانند بر آمار اثر بگذارند. کشور، محل تقریبی IP اتصال است و ممکن است با VPN تغییر کند. آرشیوهایی که فقط در پارامتر آدرس تفاوت دارند، یک مسیر حساب می‌شوند.</p></div>';
    }
    private function google_channels($filters) {
        $selection = $filters['google_channel'];
        $filters['google_channel'] = '';
        $rows = array_column(WPS_Store::groups($filters, 'google_channel', 3), null, 'label');
        echo '<section id="wps-google-acquisition" class="wps-panel"><div class="wps-section-title"><h2>تفکیک ورودی گوگل</h2>' . $this->export_link($filters, 'google_channel') . '</div>';
        echo '<div class="wps-google-channels">';
        foreach (WPS_Acquisition::labels() as $channel => $label) {
            $row = $rows[$channel] ?? array('views' => 0, 'visitors' => 0);
            $url = $this->report_url(array_merge($filters, array('google_channel' => $channel)));
            echo '<a class="wps-google-channel" href="' . esc_url($url) . '"' . ($selection === $channel ? ' aria-current="true"' : '') . '><strong>' . esc_html($label) . '</strong>';
            echo '<span><b>' . esc_html(number_format_i18n((int) $row['views'])) . '</b> بازدید · <b>' . esc_html(number_format_i18n((int) $row['visitors'])) . '</b> یونیک</span></a>';
        }
        echo '</div>';
        if ($selection !== '') {
            echo '<p><a href="' . esc_url($this->report_url($filters)) . '">برداشتن فیلتر نوع ورودی گوگل</a></p>';
        }
        echo '<p class="wps-muted">هر کارت را برای فیلتر گزارش انتخاب کنید. برای بازدیدهای قبلی هم از برچسب‌های ذخیره‌شده استفاده می‌شود. اگر تنها نشانه، دامنهٔ گوگل باشد، بازدید در «نوع نامشخص» می‌آید.</p></section>';
    }
    private function export_link($f, $dimension) {
        $url = add_query_arg(array_merge($f, array('action' => 'wps_export', 'dimension' => $dimension)), admin_url('admin-post.php'));
        return '<a class="wps-export" href="' . esc_url(wp_nonce_url($url, 'wps_export')) . '">دریافت CSV ↓</a>';
    }
    private function country_name($code) {
        if ($code === 'ZZ') { return 'نامشخص (ZZ)'; }
        $names = array('IR' => 'ایران', 'US' => 'آمریکا', 'DE' => 'آلمان', 'TR' => 'ترکیه', 'GB' => 'بریتانیا', 'FR' => 'فرانسه', 'CA' => 'کانادا', 'AE' => 'امارات', 'NL' => 'هلند', 'RU' => 'روسیه', 'CN' => 'چین', 'IN' => 'هند', 'IQ' => 'عراق', 'AF' => 'افغانستان');
        if (isset($names[$code])) { return $names[$code] . ' (' . $code . ')'; }
        if (class_exists('Locale')) { return Locale::getDisplayRegion('und_' . $code, 'fa') . ' (' . $code . ')'; }
        return $code;
    }
    private function breakdown($f, $dim, $title) {
        $rows = WPS_Store::groups($f, $dim, 25);
        echo '<section class="wps-panel"><div class="wps-section-title"><h2>' . esc_html($title) . '</h2>' . $this->export_link($f, $dim) . '</div>';
        if ($dim === 'utm_source') {
            echo '<p class="wps-muted">منبعِ برچسب‌خوردهٔ لینک (utm_source). برای آمار تبلیغات و جست‌وجو، <a href="#wps-google-acquisition">تفکیک ورودی گوگل</a> را ببینید.</p>';
        }
        echo '<div class="wps-table-scroll"><table class="wps-table"><thead><tr><th>عنوان</th><th>'.($dim==='goal' ? 'تبدیل' : 'کل').'</th><th>'.($dim==='goal' ? 'مرورگر' : 'یونیک').'</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $label = $r['label'];
            if ($dim === 'country') { $label = $this->country_name($label); }
            if ($dim === 'member') { $label = $label ? 'عضو / واردشده' : 'مهمان'; }
            if ($dim === 'device') { $label = array('Desktop' => 'رایانه', 'Mobile' => 'موبایل', 'Tablet' => 'تبلت')[$label] ?? $label; }
            if ($dim === 'referrer' && !$label) { $label = 'مستقیم / داخلی / نامشخص'; }
            if (strpos($dim,'utm_')===0 && !$label) { $label='بدون برچسب'; }
            if ($dim==='goal') { $goal=WPS_Features::goal($label); $label=$goal ? $goal['label'] : $label; }
            $label_html = esc_html($label);
            if (in_array($dim, array('country','browser','device','member','utm_source','utm_medium','utm_campaign'), true) && $r['label'] !== '') { $label_html = '<a href="' . esc_url($this->report_url(array_merge($f, array($dim => $r['label'])))) . '">' . $label_html . '</a>'; }
            echo '<tr><td>' . $label_html . '</td><td>' . esc_html(number_format_i18n($r['views'])) . '</td><td>' . esc_html(number_format_i18n($r['visitors'])) . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="3">هنوز داده‌ای نیست.</td></tr>'; }
        echo '</tbody></table></div><small class="wps-muted">حداکثر ۲۵ مورد؛ خروجی CSV شامل همهٔ مواردِ فیلترشده است.</small></section>';
    }
    private function chart($f) {
        $rows = WPS_Store::groups($f, 'local_day', 367); $map = array(); $max = 1;
        foreach ($rows as $r) { $map[$r['label']] = $r; $max = max($max, (int) $r['views']); }
        echo '<section class="wps-panel"><div class="wps-section-title"><h2>روند روزانه</h2>' . $this->export_link($f, 'local_day') . '</div><div class="wps-chart" role="img" aria-label="نمودار روزانه؛ مقادیر دقیق در جدول زیر آمده است">';
        $d = new DateTimeImmutable($f['from'], wp_timezone()); $end = new DateTimeImmutable($f['to'], wp_timezone()); $days = array();
        while ($d <= $end) {
            $key = $d->format('Y-m-d'); $r = $map[$key] ?? array('views' => 0, 'visitors' => 0); $days[$key] = $r;
            echo '<div class="wps-chart-day" title="' . esc_attr($key . ' · کل: ' . $r['views'] . ' · یونیک: ' . $r['visitors']) . '"><i style="height:' . esc_attr(100 * $r['views'] / $max) . '%"></i><b style="height:' . esc_attr(100 * $r['visitors'] / $max) . '%"></b></div>';
            $d = $d->modify('+1 day');
        }
        echo '</div><div class="wps-chart-labels"><span data-wps-date="' . esc_attr($f['from']) . '">' . esc_html($f['from']) . '</span><span class="wps-legend"><span><i class="wps-dot"></i>کل</span><span><i class="wps-dot secondary"></i>یونیک</span></span><span data-wps-date="' . esc_attr($f['to']) . '">' . esc_html($f['to']) . '</span></div><details class="wps-daily"><summary>مشاهدهٔ جدول روزانه و مقادیر دقیق</summary><div class="wps-daily-scroll"><table class="wps-table"><thead><tr><th>تاریخ (تقویم انتخابی)</th><th>کل</th><th>یونیک</th></tr></thead><tbody>';
        foreach ($days as $day => $r) { echo '<tr><td data-wps-date="' . esc_attr($day) . '">' . esc_html($day) . '</td><td>' . esc_html(number_format_i18n($r['views'])) . '</td><td>' . esc_html(number_format_i18n($r['visitors'])) . '</td></tr>'; }
        echo '</tbody></table></div></details><p class="wps-muted">روزهای بدون بازدید با صفر نمایش داده می‌شوند. نمودار بر اساس منطقهٔ زمانی سایت هنگام ثبت بازدید است.</p></section>';
    }
    private function settings_page() {
        $s = wps_settings();
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success inline"><p>تنظیمات ذخیره شد. کش صفحات سایت و CDN را هم پاک کنید.</p></div>'; }
        $notice = get_transient('wps_geo_notice_' . get_current_user_id());
        if ($notice) { echo '<div class="notice notice-info inline"><p>' . esc_html($notice) . '</p></div>'; delete_transient('wps_geo_notice_' . get_current_user_id()); }
        echo '<form class="wps-panel wps-settings" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wps_settings">';
        wp_nonce_field('wps_settings');
        echo '<label class="wps-field">جای منوی آمار صفحات در سایدبار<select name="menu_position">';
        foreach (self::menu_positions() as $value => $position) {
            echo '<option value="' . esc_attr($value) . '"' . selected($s['menu_position'], $value, false) . '>' . esc_html($position[0]) . '</option>';
        }
        echo '</select></label>';
        echo '<h2>ثبت بازدید و نگهداری داده</h2>';
        foreach (array('enabled' => 'آمارگیری فعال باشد', 'exclude_admin' => 'بازدید مدیران سایت شمرده نشود', 'respect_dnt' => 'رعایت Do Not Track و Global Privacy Control', 'consent' => 'ثبت بازدید فقط پس از رضایت صریح بازدیدکننده', 'delete_data' => 'هنگام حذف کامل افزونه، همهٔ داده‌ها و تنظیمات آن از وردپرس پاک شوند') as $key => $label) {
            echo '<label class="wps-check"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($s[$key], 1, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '<label class="wps-check"><input type="checkbox" name="keep_forever" value="1" '.checked($s['keep_forever'],1,false).'> نگهداری نامحدود (بدون حذف خودکار)</label>';
        echo '<label class="wps-field">مدت نگهداری (۷ تا ۳۶۵ روز)<input type="number" name="retention" min="7" max="365" value="' . esc_attr($s['retention']) . '"></label><p class="wps-muted">حذف داده‌های قدیمی با WP-Cron روزانه و به‌صورت دسته‌ای انجام می‌شود. در سایت کم‌ترافیک، اجرای آن به بازدید بعدی وابسته است. کاهش این عدد باعث حذف دائمی داده‌های قدیمی در اجرای پاکسازی می‌شود.</p>';
        echo '<label class="wps-field">مسیرهای مستثنا؛ هر پیشوند در یک خط<textarea name="excluded_paths" rows="4" dir="ltr" placeholder="/checkout/&#10;/my-account/">' . esc_textarea($s['excluded_paths']) . '</textarea></label><p class="wps-muted">مثلاً /account همهٔ مسیرهای شروع‌شونده با همین عبارت را حذف می‌کند. صفحات خصوصی، رمزدار، پیش‌نمایش، جست‌وجو و خطای ۴۰۴ از ابتدا ثبت نمی‌شوند.</p>';
        echo '<h2>سایت پشت CDN یا پراکسی</h2><label class="wps-field">IP یا CIDR پراکسی‌های مورد اعتماد؛ هر کدام در یک خط<textarea name="trusted_proxies" rows="4" dir="ltr" placeholder="203.0.113.10/32">' . esc_textarea($s['trusted_proxies']) . '</textarea></label><p class="wps-muted">در حالت عادی IP اتصال مستقیم مبناست. فقط اگر سایت پشت CDN یا پراکسی قرار دارد، محدوده‌های واقعی و رسمی آن را وارد کنید تا زنجیرهٔ X-Forwarded-For بررسی شود. محدودهٔ عمومی 0.0.0.0/0 یا ::/0 وارد نکنید؛ هدرهای جعلی می‌توانند کشور را اشتباه کنند.</p>';
        WPS_Features::settings();
        echo '<button class="button button-primary">ذخیرهٔ تنظیمات</button></form>';
        $version = get_option('wps_geo_updated', '');
        if (!$version && file_exists(WPS_DIR . 'data/version.txt')) { $version = trim(file_get_contents(WPS_DIR . 'data/version.txt')); }
        echo '<section class="wps-panel"><h2>پایگاه محلی کشورها</h2><p>نسخهٔ پایگاه: <strong>' . esc_html($version ?: 'نصب نشده؛ کشورها تا دریافت پایگاه نامشخص خواهند بود') . '</strong></p><p>تشخیص کشور روی هاست انجام می‌شود. به‌روزرسانی فقط فایل عمومی DB-IP Lite ماه جاری را دریافت می‌کند و IP بازدیدکننده را ارسال نمی‌کند. دقت مکان‌یابی تقریبی است.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wps_geo">';
        wp_nonce_field('wps_geo');
        echo '<button class="button">دریافت پایگاه ماه جاری از DB-IP</button></form></section><section class="wps-panel"><h2>ستون‌های فهرست نوشته‌ها و برگه‌ها</h2><p>در بالای فهرست نوشته‌ها یا برگه‌ها، «تنظیمات صفحه» را باز کنید و تیک «بازدید کل» و «بازدید یونیک» را جداگانه تغییر دهید. انتخاب برای هر کاربر و هر فهرست به‌صورت مستقل ذخیره می‌شود. ستون‌ها و گزارش‌ها فقط برای مدیر سایت قابل مشاهده‌اند.</p><h2>اتصال به ابزار رضایت</h2><p>اگر رضایت صریح فعال است، افزونه تا دریافت رضایت هیچ بازدیدی ثبت نمی‌کند. بنر رضایت را ابزار فعلی سایت نمایش دهد و بعد از موافقت، این رویداد را اجرا کند:</p><pre dir="ltr">document.dispatchEvent(new CustomEvent(&quot;wps:consent&quot;, {detail: true}));</pre><p>برای رد یا پس گرفتن رضایت از <code dir="ltr">window.WPPageStats?.consent(false)</code> استفاده کنید. کوکی شناسه حذف می‌شود و ثبت بعدی متوقف خواهد شد. پس گرفتن رضایت داده‌های آماری قبلاً ثبت‌شده را حذف نمی‌کند.</p></section>';
    }
}



