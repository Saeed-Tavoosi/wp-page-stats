<?php
defined('ABSPATH') || exit;

class WPS_Features {
    public static function save_goals($input) {
        $out=array(); $ids=array();
        foreach (array_slice(is_array($input) ? $input : array(),0,20) as $g) {
            if (!is_array($g)) { continue; }
            foreach ($g as $key=>$value) { if (!is_scalar($value)) { $g[$key]=''; } }
            $label=wps_cut(sanitize_text_field($g['label'] ?? ''),80);
            if ($label==='') { continue; }
            $id=sanitize_key($g['id'] ?? '');
            if (!$id) { $id='g_'.substr(hash('sha256',wp_generate_uuid4()),0,16); }
            $id=substr($id,0,40);
            if (isset($ids[$id])) { continue; }
            $type=($g['type'] ?? '')==='event' ? 'event' : 'destination';
            $path=wps_cut(sanitize_text_field($g['path'] ?? ''),512);
            $post_id=absint($g['post_id'] ?? 0);
            if ($type==='destination' && !$post_id && (substr($path,0,1)!=='/' || strpos($path,'?')!==false || strpos($path,'#')!==false)) { continue; }
            $out[]=array('id'=>$id,'label'=>$label,'type'=>$type,'post_id'=>$type==='destination' ? $post_id : 0,'path'=>$type==='destination' ? $path : ''); $ids[$id]=true;
        }
        return $out;
    }
    public static function goal($id) {
        foreach (wps_settings()['goals'] as $g) { if ($g['id']===$id) { return $g; } }
        return null;
    }
    public static function goal_matches($goal,$path,$post_id=0) {
        if ($goal['type']==='event') { return true; }
        if (!empty($goal['post_id'])) { return (int)$goal['post_id']===(int)$post_id; }
        return rtrim(rawurldecode($goal['path']),'/')===rtrim(rawurldecode($path),'/');
    }
    public static function date_fields($f) {
        echo '<label>تقویم<select id="wps-calendar" data-today="'.esc_attr(wp_date('Y-m-d')).'"><option value="gregorian">میلادی</option><option value="persian">شمسی</option></select></label>';
        foreach (array('from'=>'از تاریخ','to'=>'تا تاریخ') as $key=>$label) {
            echo '<div class="wps-date-field"><label for="wps-date-'.esc_attr($key).'">'.esc_html($label).'</label>';
            echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($f[$key]).'">';
            echo '<input required type="date" id="wps-date-'.esc_attr($key).'" data-wps-date-input="'.esc_attr($key).'" value="'.esc_attr($f[$key]).'">';
            echo '<button type="button" class="wps-date-toggle" aria-label="'.esc_attr('انتخاب '.$label).'" aria-haspopup="dialog" aria-expanded="false" hidden></button></div>';
        }
    }
    public static function comparison($f,$summary) {
        $previous=WPS_Store::previous_filters($f); $old=WPS_Store::summary($previous);
        $complete=WPS_Store::data_coverage($previous) && WPS_Store::data_coverage($f);
        echo '<section class="wps-panel"><h2>مقایسه با بازهٔ قبل</h2><p>بازهٔ قبل: <span data-wps-date="'.esc_attr($previous['from']).'">'.esc_html($previous['from']).'</span> تا <span data-wps-date="'.esc_attr($previous['to']).'">'.esc_html($previous['to']).'</span> · همان فیلترها، همان تعداد روز</p><table class="wps-table"><thead><tr><th>معیار</th><th>این بازه</th><th>بازهٔ قبل</th><th>تغییر</th></tr></thead><tbody>';
        foreach (array('views'=>'بازدید کل','visitors'=>'بازدید یونیک','guests'=>'بازدید مهمان','members'=>'بازدید عضو') as $key=>$label) {
            $change='—';
            if ($complete && (int)$old[$key]>0) { $percent=100*((int)$summary[$key]-(int)$old[$key])/(int)$old[$key]; $change=($percent>0 ? '+' : '').number_format_i18n($percent,1).'٪'; }
            elseif ($complete && (int)$summary[$key]===0) { $change='۰٪'; }
            echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html(number_format_i18n($summary[$key])).'</td><td>'.esc_html(number_format_i18n($old[$key])).'</td><td dir="ltr">'.esc_html($change).'</td></tr>';
        }
        echo '</tbody></table><p class="wps-muted">'.($complete ? 'اگر مقدار قبلی صفر باشد، درصد رشد تعریف نمی‌شود.' : 'پوشش زمانی این مقایسه کامل نیست؛ شروع آمارگیری یا حذف داده‌های قدیمی داخل یکی از بازه‌ها قرار دارد. اعداد، دادهٔ موجودند و درصد تغییر نمایش داده نمی‌شود.').'</p></section>';
    }
    public static function conversions_report($f,$summary) {
        $c=WPS_Store::conversions($f);
        $rate=(int)$summary['visitors'] ? number_format_i18n(100*$c['converters']/(int)$summary['visitors'],1).'٪' : '—';
        echo '<section class="wps-panel"><h2>هدف‌ها و تبدیل</h2><p>رویداد تبدیل: <strong>'.esc_html(number_format_i18n($c['conversions'])).'</strong> · بازدیدکنندگانِ تبدیل‌شده با بازدید در همین فیلتر: <strong>'.esc_html(number_format_i18n($c['converters'])).'</strong> · نرخ تبدیل این بازدیدکنندگان: <strong>'.esc_html($rate).'</strong></p><p class="wps-muted">نرخ = مرورگرهایی که هم بازدید و هم تبدیل با همین فیلتر و در همین بازه دارند ÷ یونیکِ بازدیدها. هر هدف در هر بار نمایش صفحه یک‌بار شمرده می‌شود. تبدیل از رویداد مرورگر یا رسیدن به صفحهٔ هدف می‌آید؛ تأیید پرداخت یا فروش نیست.</p></section>';
    }
    public static function settings() {
        $goals=wps_settings()['goals'];
        if (!$goals) { $goals=array(array('id'=>'','label'=>'','type'=>'destination','path'=>'','post_id'=>0)); }
        echo '<h2>هدف‌های تبدیل</h2><p>حداکثر ۲۰ هدف. برای شروع، مسیر یک صفحهٔ تشکر یا موفقیت را تعریف کنید. اگر شناسهٔ صفحهٔ مقصد را وارد کنید، به‌جای مسیر ملاک قرار می‌گیرد؛ برای پیوندهای یکتای ساده از شناسه استفاده کنید. هدف‌های بدون عنوان ذخیره نمی‌شوند. تغییر نام، شناسه و آمار قبلی هدف را حفظ می‌کند؛ حذف هدف، رویدادهای قبلی را پاک نمی‌کند.</p><div id="wps-goals">';
        foreach ($goals as $i=>$goal) { self::goal_row($i,$goal); }
        echo '</div><button class="button" type="button" id="wps-add-goal">افزودن هدف</button><p class="wps-muted">نوع «رویداد سفارشی» با اجرای <code dir="ltr">window.WPPageStats?.convert("goal_id")</code> بعد از موفقیت عمل ثبت می‌شود. شناسهٔ هر هدف پس از ذخیره در ردیف آن دیده می‌شود. فرم‌ها یا پرداخت‌ها خودکار متصل نمی‌شوند.</p>';
    }
    private static function goal_row($i,$g) {
        echo '<div class="wps-goal-row"><input type="hidden" data-goal="id" name="goals['.(int)$i.'][id]" value="'.esc_attr($g['id']).'"><label>عنوان هدف<input data-goal="label" name="goals['.(int)$i.'][label]" maxlength="80" value="'.esc_attr($g['label']).'" placeholder="مثلاً ارسال موفق فرم"></label><label>روش ثبت<select data-goal="type" name="goals['.(int)$i.'][type]"><option value="destination" '.selected($g['type'],'destination',false).'>رسیدن به صفحهٔ مقصد</option><option value="event" '.selected($g['type'],'event',false).'>رویداد سفارشی</option></select></label><label>مسیر دقیق مقصد<input data-goal="path" name="goals['.(int)$i.'][path]" value="'.esc_attr($g['path']).'" placeholder="/thank-you/" dir="ltr"></label><label>شناسهٔ صفحهٔ مقصد (اختیاری)<input type="number" min="0" data-goal="post_id" name="goals['.(int)$i.'][post_id]" value="'.esc_attr($g['post_id'] ?? 0).'"></label><button type="button" class="button wps-remove-goal">حذف ردیف</button><small class="wps-goal-id" dir="ltr">'.esc_html($g['id']).'</small></div>';
    }
}
