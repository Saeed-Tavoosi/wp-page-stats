<?php
defined('ABSPATH') || exit;

final class WPS_Acquisition {
    private const GOOGLE_SOURCES = array('google', 'googleads', 'google ads', 'google_ads', 'google-ads');
    private const PAID_MEDIA = array('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paid search', 'cpm', 'display', 'paidshopping', 'paid_shopping');
    private const GOOGLE_DOMAINS = 'com|ca|co[.]uk|com[.]au|de|fr|es|it|nl|ae|com[.]tr|com[.]sa|co[.]in|co[.]jp|co[.]kr|com[.]br|com[.]mx';
    private const GOOGLE_HOST = '(^|[.])google[.](' . self::GOOGLE_DOMAINS . ')$';
    private const AD_HOSTS = array('googleadservices.com', 'www.googleadservices.com');

    public static function labels() {
        return array(
            'google_ads' => 'تبلیغات گوگل (Google Ads)',
            'google_organic' => 'سرچ ارگانیک گوگل (محتمل)',
            'google_unknown' => 'ورودی گوگل با نوع نامشخص',
        );
    }

    public static function channel($source, $medium, $referrer, $search_path, $has_click_tag) {
        $source = strtolower($source);
        $medium = strtolower($medium);
        $google_source = in_array($source, self::GOOGLE_SOURCES, true);
        $paid_medium = in_array($medium, self::PAID_MEDIA, true);
        if ($has_click_tag || ($google_source && $paid_medium)) {
            return 'google_ads';
        }

        $host = strtolower(rtrim((string) wp_parse_url($referrer, PHP_URL_HOST), '.'));
        // Match complete domain names so names such as www.google.com.example do not qualify.
        $search_host = (bool) preg_match('/^(?:www[.])?google[.](?:' . self::GOOGLE_DOMAINS . ')$/D', $host);
        $google_host = (bool) preg_match('/' . self::GOOGLE_HOST . '/D', $host);
        $explicit_organic = $source === 'google' && $medium === 'organic';
        $search_referrer = $search_host && $search_path && ($source === '' || $source === 'google') && $medium === '';
        if ($explicit_organic || $search_referrer) {
            return 'google_organic';
        }
        if ($google_source || $google_host || in_array($host, self::AD_HOSTS, true)) {
            return 'google_unknown';
        }
        return '';
    }

    public static function report_channel_sql() {
        $sources = "'" . implode("', '", self::GOOGLE_SOURCES) . "'";
        $paid = "'" . implode("', '", self::PAID_MEDIA) . "'";
        $ad_hosts = "'" . implode("', '", self::AD_HOSTS) . "'";
        $google_source = "LOWER(utm_source) IN ($sources)";
        $host = "TRIM(TRAILING '.' FROM LOWER(referrer))";
        // Historical rows retain campaign labels and the host, without a click tag or search path.
        return "CASE
            WHEN google_channel <> '' THEN google_channel
            WHEN $google_source AND LOWER(utm_medium) IN ($paid) THEN 'google_ads'
            WHEN LOWER(utm_source) = 'google' AND LOWER(utm_medium) = 'organic' THEN 'google_organic'
            WHEN $google_source OR $host REGEXP '" . self::GOOGLE_HOST . "' OR $host IN ($ad_hosts) THEN 'google_unknown'
            ELSE '' END";
    }
}
