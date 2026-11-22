<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/class-wps-uninstall.php';

WPS_Uninstall::run();
