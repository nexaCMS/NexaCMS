<?php
/**
 * NexaCMS Bootstrap Loader (inc.php)
 */
if (!defined('NEXACMS_BOOTSTRAPPED')) {
    define('NEXACMS_BOOTSTRAPPED', true);
}

$include_dir = __DIR__;

if (!is_file(__DIR__ . '/config.php')) {
    copy(__DIR__ . '/config_.php', __DIR__ . '/config.php');
}
require __DIR__ . '/config.php';

$config['abs'] = __DIR__;
$is_subsite = (bool) ($config['is_subsite'] ?? false);

$dbDriver = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($config['database']['type'] ?? 'csv')));
$driverFile = __DIR__ . "/includes/{$dbDriver}-core.php";
if (!is_file($driverFile)) {
    $driverFile = __DIR__ . '/includes/csv-core.php';
}
require_once $driverFile;

$baseurl = [baseurl()];

// Subsites: resolve host/path/admin context before any settings are read.
if (function_exists('nexa_multisite_resolve')) nexa_multisite_resolve();

if (empty($config['installed']) && !defined('NEXACMS_INSTALLING') && !defined('DICIMES_INSTALLING')) {
    header('Location: ' . home_url('admin/install.php'));
    exit;
}

if (!empty($config['installed'])) {
    loadsettings();
    di_ensure_users_table();
    di_ensure_posts_seeded();
    di_ensure_menus_seeded();
    queplugins();
    updatepluginlist();
}
