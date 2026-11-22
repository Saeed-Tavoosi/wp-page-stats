<?php
defined('ABSPATH') || exit;

final class WPS_Acquisition {
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
        $google_source = in_array($source, array('google', 'googleads', 'google ads', 'google_ads', 'google-ads'), true);
        $paid_medium = in_array($medium, array('cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paid search', 'cpm', 'display', 'paidshopping', 'paid_shopping'), true);
        if ($has_click_tag || ($google_source && $paid_medium)) {
            return 'google_ads';
        }

        $host = strtolower(rtrim((string) wp_parse_url($referrer, PHP_URL_HOST), '.'));
        // Match complete domain names so names such as www.google.com.example do not qualify.
        $google_domains = 'com|ca|co\.uk|com\.au|de|fr|es|it|nl|ae|com\.tr|com\.sa|co\.in|co\.jp|co\.kr|com\.br|com\.mx';
        $search_host = (bool) preg_match('/^(?:www\.)?google\.(?:' . $google_domains . ')$/D', $host);
        $google_host = (bool) preg_match('/(?:^|\.)google\.(?:' . $google_domains . ')$/D', $host);
        $explicit_organic = $source === 'google' && $medium === 'organic';
        $search_referrer = $search_host && $search_path && ($source === '' || $source === 'google') && $medium === '';
        if ($explicit_organic || $search_referrer) {
            return 'google_organic';
        }
        if ($google_source || $google_host || in_array($host, array('googleadservices.com', 'www.googleadservices.com'), true)) {
            return 'google_unknown';
        }
        return '';
    }
}
