<?php
defined('ABSPATH') || exit;

class WPS_Geo {
    public static function directory() {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'wp-page-stats-geo';
    }

    public static function lookup($ip) {
        $packed = @inet_pton($ip);
        if ($packed === false) { return 'ZZ'; }
        $version = strlen($packed) === 4 ? 'v4' : 'v6';
        $generation = get_option('wps_geo_generation', '');
        $base = $generation && preg_match('/^[a-f0-9]{12}$/', $generation)
            ? self::directory() . '/' . $generation : WPS_DIR . 'data';
        $file = $base . '/' . $version . '.bin';
        $fp = @fopen($file, 'rb');
        if (!$fp) { return 'ZZ'; }
        $size = strlen($packed);
        $width = $size * 2 + 2;
        $low = 0;
        $high = intdiv((int) filesize($file), $width) - 1;
        $country = 'ZZ';
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            fseek($fp, $mid * $width);
            $record = fread($fp, $width);
            if (strlen($record) !== $width) { break; }
            if (strcmp($packed, substr($record, 0, $size)) < 0) { $high = $mid - 1; }
            elseif (strcmp($packed, substr($record, $size, $size)) > 0) { $low = $mid + 1; }
            else { $country = substr($record, -2); break; }
        }
        fclose($fp);
        return $country;
    }

    // Shared by the administrative updater and the offline package builder.
    public static function import($gzip, $directory) {
        if (!function_exists('gzopen')) { return new WP_Error('zlib', 'افزونهٔ zlib در PHP فعال نیست.'); }
        if (!wp_mkdir_p($directory)) { return new WP_Error('write', 'پوشهٔ پایگاه کشور قابل نوشتن نیست.'); }
        $in = @gzopen($gzip, 'rb');
        $v4 = @fopen($directory . '/v4.bin', 'wb');
        $v6 = @fopen($directory . '/v6.bin', 'wb');
        if (!$in || !$v4 || !$v6) {
            if ($in) { gzclose($in); } if ($v4) { fclose($v4); } if ($v6) { fclose($v6); }
            return new WP_Error('open', 'خواندن یا نوشتن فایل پایگاه کشور ممکن نیست.');
        }
        $count = 0; $error = false; $last = array(4 => null, 16 => null);
        while (!gzeof($in)) {
            $line = gzgets($in, 1024);
            if ($line === false) { if (!gzeof($in)) { $error = true; } break; }
            if (trim($line) === '') { continue; }
            $row = str_getcsv(trim($line), ',', '"', '');
            if (count($row) !== 3) { $error = true; break; }
            $start = @inet_pton($row[0]); $end = @inet_pton($row[1]); $cc = strtoupper($row[2]);
            if (!$start || !$end || strlen($start) !== strlen($end) || strcmp($start, $end) > 0 || !preg_match('/^[A-Z]{2}$/', $cc)) { $error = true; break; }
            $len = strlen($start);
            if ($last[$len] !== null && strcmp($start, $last[$len]) <= 0) { $error = true; break; }
            $last[$len] = $end;
            $record = $start . $end . $cc;
            if (fwrite($len === 4 ? $v4 : $v6, $record) !== strlen($record)) { $error = true; break; }
            $count++;
            if ($count > 2000000) { $error = true; break; }
        }
        gzclose($in); fclose($v4); fclose($v6);
        if ($error || $count < 1) { return new WP_Error('format', 'قالب یا ترتیب دادهٔ DB-IP معتبر نیست.'); }
        return $count;
    }

    public static function update() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        // A single fixed vendor endpoint; visitor addresses are never transmitted.
        $month = gmdate('Y-m');
        $tmp = download_url('https://download.db-ip.com/free/dbip-country-lite-' . $month . '.csv.gz', 45);
        if (is_wp_error($tmp)) { return $tmp; }
        if (filesize($tmp) > 30 * 1024 * 1024) { wp_delete_file($tmp); return new WP_Error('size', 'حجم فایل بیش از حد مجاز است.'); }
        $generation = substr(wp_generate_password(32, false), 0, 12);
        $generation = substr(hash('sha256', $generation . microtime(true)), 0, 12);
        $dir = self::directory() . '/' . $generation;
        $result = self::import($tmp, $dir);
        wp_delete_file($tmp);
        if (is_wp_error($result)) {
            wp_delete_file($dir . '/v4.bin'); wp_delete_file($dir . '/v6.bin');
            @rmdir($dir);
            return $result;
        }
        $old = get_option('wps_geo_generation', '');
        update_option('wps_geo_generation', $generation, false);
        update_option('wps_geo_updated', $month, false);
        if ($old && preg_match('/^[a-f0-9]{12}$/', $old)) {
            wp_delete_file(self::directory() . '/' . $old . '/v4.bin');
            wp_delete_file(self::directory() . '/' . $old . '/v6.bin');
            @rmdir(self::directory() . '/' . $old);
        }
        return $result;
    }

    public static function in_cidr($ip, $cidr) {
        $parts = explode('/', trim($cidr));
        $a = @inet_pton($ip); $b = @inet_pton($parts[0]);
        if (!$a || !$b || strlen($a) !== strlen($b)) { return false; }
        $bits = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : strlen($a) * 8;
        if ($bits === false || $bits < 0 || $bits > strlen($a) * 8) { return false; }
        $bytes = intdiv($bits, 8); $rest = $bits % 8;
        return substr($a, 0, $bytes) === substr($b, 0, $bytes)
            && (!$rest || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xff << (8 - $rest))) === 0);
    }

    public static function client_ip() {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $ranges = preg_split('/[\r\n,]+/', wps_settings()['trusted_proxies']);
        $trusted = function ($ip) use ($ranges) {
            foreach ($ranges as $range) { if ($range !== '' && self::in_cidr($ip, $range)) { return true; } }
            return false;
        };
        if ($trusted($remote)) {
            $chain = array_slice(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), -20);
            $chain[] = $remote;
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $candidate = trim($chain[$i]);
                if (!filter_var($candidate, FILTER_VALIDATE_IP)) { return $remote; }
                if (!$trusted($candidate)) { return $candidate; }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }
}

