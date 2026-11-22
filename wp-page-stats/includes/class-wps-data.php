<?php
defined('ABSPATH') || exit;

class WPS_Data {
    public function __construct() {
        add_action('admin_post_wps_purge_preview', array($this, 'preview'));
        add_action('admin_post_wps_purge_confirm', array($this, 'confirm'));
        add_action('admin_post_wps_purge_continue', array($this, 'continue_job'));
        add_action('wps_purge_batch', array(__CLASS__, 'work'));
        $job = get_option('wps_purge_job');
        if ($job && $job['status'] === 'running' && !wp_next_scheduled('wps_purge_batch')) {
            wp_schedule_single_event(time() + 60, 'wps_purge_batch');
        }
    }
    private static function guard($nonce) {
        if (!current_user_can('manage_options')) { wp_die('دسترسی کافی نیست.', '', array('response' => 403)); }
        check_admin_referer($nonce);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('درخواست نامعتبر.', '', array('response' => 405)); }
    }
    private static function url($args = array()) {
        return add_query_arg(array_merge(array('page'=>'wps-data'), $args), admin_url('admin.php'));
    }
    public static function condition($job) {
        global $wpdb;
        $where = $wpdb->prepare('id <= %d', $job['max_id']);
        if ($job['mode'] === 'older') { $where .= $wpdb->prepare(' AND occurred_at < %s', $job['cutoff']); }
        return $where;
    }
    public function preview() {
        self::guard('wps_purge_preview'); global $wpdb;
        $mode = isset($_POST['mode']) && $_POST['mode'] === 'all' ? 'all' : 'older';
        $days = max(1, min(365, absint($_POST['days'] ?? wps_settings()['retention'])));
        $token = bin2hex(random_bytes(16));
        $job = array('id'=>$token, 'mode'=>$mode, 'days'=>$days,
            'cutoff'=>gmdate('Y-m-d H:i:s', time()-$days*DAY_IN_SECONDS),
            'max_id'=>(int)$wpdb->get_var('SELECT MAX(id) FROM ' . WPS_Store::table()),
            'started_at'=>gmdate('Y-m-d H:i:s'), 'deleted'=>0, 'status'=>'running');
        $job['preview_count'] = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . WPS_Store::table() . ' WHERE ' . self::condition($job));
        set_transient('wps_purge_preview_' . get_current_user_id(), $job, 15*MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url(array('preview'=>$token))); exit;
    }
    public function confirm() {
        if (!current_user_can('manage_options')) { wp_die('دسترسی کافی نیست.', '', array('response'=>403)); }
        $job = get_transient('wps_purge_preview_' . get_current_user_id());
        if (!$job) { wp_die('پیش‌نمایش منقضی شده؛ دوباره پیش‌نمایش بگیرید.', '', array('response'=>400)); }
        self::guard('wps_purge_confirm_' . $job['id']);
        if (($_POST['confirm_delete'] ?? '') !== '1') { wp_die('تأیید حذف لازم است.', '', array('response'=>400)); }
        $old = get_option('wps_purge_job');
        if ($old && $old['status'] === 'running') { wp_die('یک پاکسازی در حال انجام است.', '', array('response'=>409)); }
        if ($old) { delete_option('wps_purge_job'); }
        if (!add_option('wps_purge_job', $job, '', false)) { wp_die('پاکسازی دیگری شروع شده است.', '', array('response'=>409)); }
        delete_transient('wps_purge_preview_' . get_current_user_id());
        self::work();
        wp_safe_redirect(self::url()); exit;
    }
    public function continue_job() {
        self::guard('wps_purge_continue');
        self::work(); wp_safe_redirect(self::url()); exit;
    }
    public static function work() {
        global $wpdb;
        $job = get_option('wps_purge_job');
        if (!$job || $job['status'] !== 'running') { return; }
        $lock = get_option('wps_purge_lock');
        if ($lock && $lock['until'] < time()) { delete_option('wps_purge_lock'); }
        $lock = array('until'=>time()+120, 'token'=>bin2hex(random_bytes(8)));
        if (!add_option('wps_purge_lock', $lock, '', false)) { return; }
        try {
            $where = self::condition($job); $deleted = 0;
            for ($i=0; $i<5; $i++) {
                $deleted = $wpdb->query('DELETE FROM ' . WPS_Store::table() . ' WHERE ' . $where . ' LIMIT 2000');
                if ($deleted === false) { $job['status']='failed'; break; }
                $job['deleted'] += $deleted;
                if ($deleted < 2000) { $job['status']='done'; break; }
            }
            if ($job['deleted'] > 0) {
                $floor = $job['mode'] === 'all' ? $job['started_at'] : $job['cutoff'];
                update_option('wps_history_floor', max(get_option('wps_history_floor',''), $floor), false);
            }
            $job['updated_at'] = gmdate('Y-m-d H:i:s');
            update_option('wps_purge_job', $job, false);
            if ($job['status'] === 'running' && !wp_next_scheduled('wps_purge_batch')) { wp_schedule_single_event(time()+60, 'wps_purge_batch'); }
        } finally {
            if (get_option('wps_purge_lock') === $lock) { delete_option('wps_purge_lock'); }
        }
    }
    public static function render() {
        $s = wps_settings(); $data = WPS_Store::storage_status(); $last = get_option('wps_cleanup_last');
        echo '<section class="wps-panel"><h2>وضعیت ذخیره‌سازی</h2><div class="wps-cards">';
        foreach (array('تعداد رکوردهای موجود'=>number_format_i18n($data['records']), 'حجم تخصیص‌یافتهٔ جدول و ایندکس‌ها'=>$data['bytes'] === null ? 'نامشخص' : size_format($data['bytes'],2), 'مدت نگهداری'=>($s['keep_forever'] ? 'نامحدود' : $s['retention'].' روز'), 'رکوردهای منقضیِ در انتظار حذف'=>number_format_i18n($data['expired'])) as $label=>$value) {
            echo '<article class="wps-card"><span>'.esc_html($label).'</span><strong>'.esc_html($value).'</strong></article>';
        }
        echo '</div><p>آمار و هدف‌ها در جدول <code dir="ltr">'.esc_html($data['table']).'</code> دیتابیس خودِ وردپرس نگهداری می‌شوند. هر بازدید یا تبدیل یک رکورد است؛ ستون یونیک با شناسه‌های هش‌شدهٔ متمایز محاسبه می‌شود.</p><p>اولین رکورد موجود: <b>'.esc_html($data['oldest'] ?: 'هنوز داده‌ای نیست').'</b> · آخرین رکورد: <b>'.esc_html($data['newest'] ?: '—').'</b> (UTC)</p><p>آخرین اجرای موفق پاکسازی خودکار: '.esc_html($last ? $last['at'].' UTC · حذف در آن اجرا: '.$last['deleted'] : 'هنوز اجرا نشده').'</p>';
        if (get_option('wps_cleanup_error')) { echo '<p class="wps-danger">اجرای آخر پاکسازی خطای دیتابیس داشته است؛ تنظیمات هاست و گزارش خطا را بررسی کنید.</p>'; }
        echo '<p>'.($s['keep_forever'] ? 'حذف خودکار خاموش است. آمار تا زمان پاکسازی دستی رشد می‌کند.' : 'حذف خودکار روزانه و دسته‌ای است.').' عدد حجم شامل فضای رزروشده برای داده و ایندکس‌هاست و اندازهٔ متن دو رکورد را نشان نمی‌دهد. اجرای به‌موقع به WP-Cron وابسته است؛ برای سایت کم‌ترافیک cron واقعی هاست را تنظیم کنید. حذف رکورد لزوماً فایل دیتابیس را فوراً کوچک نمی‌کند؛ MySQL معمولاً فضای خالی را برای رکوردهای بعدی استفاده می‌کند. فشرده‌سازی جدول روی هاست باید جداگانه بررسی شود.</p><p>نمونه: با روزی ۱۰۰۰ بازدید و نگهداری ۳۰ روز، حدود ۳۰ هزار رکورد بازدید خواهید داشت؛ رویدادهای تبدیل هم به این عدد اضافه می‌شوند. این برآورد تعداد است؛ حجم واقعی به طول متن‌ها، ایندکس‌ها و تنظیمات دیتابیس وابسته است.</p></section>';
        $job = get_option('wps_purge_job');
        if ($job) {
            $names=array('running'=>'در حال انجام','done'=>'پایان‌یافته','failed'=>'متوقف به علت خطای دیتابیس');
            echo '<section class="wps-panel"><h2>آخرین پاکسازی دستی: '.esc_html($names[$job['status']] ?? $job['status']).'</h2><p>حذف‌شده: <strong>'.esc_html(number_format_i18n($job['deleted'])).'</strong> · تعداد هنگام پیش‌نمایش: '.esc_html(number_format_i18n($job['preview_count'])).'</p>';
            if ($job['status']==='running') { echo '<p>ادامه با WP-Cron انجام می‌شود؛ می‌توانید دستهٔ بعدی را هم اجرا کنید.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wps_purge_continue">'; wp_nonce_field('wps_purge_continue'); echo '<button class="button">اجرای دستهٔ بعدی</button></form>'; }
            echo '</section>';
        }
        $preview=get_transient('wps_purge_preview_'.get_current_user_id());
        if ($preview && isset($_GET['preview']) && is_string($_GET['preview']) && hash_equals($preview['id'], $_GET['preview'])) {
            echo '<section class="wps-panel wps-delete-preview"><h2>پیش‌نمایش حذف؛ هنوز چیزی پاک نشده</h2><p><strong>'.esc_html(number_format_i18n($preview['preview_count'])).'</strong> رکورد مشمول است. '.($preview['mode']==='all' ? 'همهٔ رکوردهای موجود تا لحظهٔ پیش‌نمایش' : 'رکوردهای قدیمی‌تر از '.esc_html($preview['cutoff']).' UTC').'.</p><p>بازدیدهای بعد از پیش‌نمایش حذف نمی‌شوند. تعداد واقعی ممکن است به علت پاکسازی خودکار کمتر شود. این کار آمار بازدید، کمپین و تبدیلِ مشمول را حذف می‌کند و برگشت‌پذیر نیست. قبل از آن، CSV گزارش یا بکاپ دیتابیس بگیرید.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wps_purge_confirm">';
            wp_nonce_field('wps_purge_confirm_'.$preview['id']);
            echo '<label class="wps-check"><input required type="checkbox" name="confirm_delete" value="1"> حذف دائمیِ داده‌های پیش‌نمایش را تأیید می‌کنم.</label>';
            echo '<div class="wps-delete-actions"><a class="button" href="'.esc_url(self::url()).'">انصراف</a><button type="submit" class="button wps-danger">حذف همین داده‌ها</button></div></form></section>';
        }
        echo '<section class="wps-panel"><h2>پاکسازی دستی</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wps_purge_preview">'; wp_nonce_field('wps_purge_preview');
        echo '<label class="wps-check"><input type="radio" name="mode" value="older" checked> حذف رکوردهای قدیمی‌تر از <input aria-label="سن رکوردها به روز" type="number" name="days" min="1" max="365" value="'.esc_attr($s['retention']).'"> روز</label><label class="wps-check"><input type="radio" name="mode" value="all"> پاک کردن تمام آمار فعلی و شروع شمارش از نو</label><button class="button">محاسبه و پیش‌نمایش حذف</button></form></section><section class="wps-panel"><h2>بعد از حذف یا غیرفعال‌سازی افزونه</h2><p>غیرفعال‌سازی، داده‌ها را نگه می‌دارد و ثبت/پاکسازی زمان‌بندی‌شده را متوقف می‌کند. حذف کامل افزونه هم پیش‌فرض داده‌ها را نگه می‌دارد. اگر در تنظیمات «هنگام حذف کامل افزونه…» را روشن کرده باشید، هنگام حذف، جدول‌های آمار، تنظیمات از جمله بازهٔ ویجت، وضعیت نمایش ابزارک و ستون‌های افزونه و فایل‌های تشخیص کشور پاک می‌شوند. کوکی‌های مرورگر بازدیدکننده، نسخه‌های پشتیبان، لاگ‌های هاست و متن سیاست حریم خصوصی که قبلاً منتشر کرده‌اید جداگانه مدیریت می‌شوند.</p></section>';
    }
}
