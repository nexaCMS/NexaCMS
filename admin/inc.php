<?php
/**
 * NexaCMS Admin Bootstrap (admin/inc.php)
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$adminarea = true;
if (!defined('CURRENT_PAGE')) {
    define('CURRENT_PAGE', basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

require_once dirname(__DIR__) . '/inc.php';
load_active_plugins();
