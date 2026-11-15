<?php
/**
 * NexaCMS Logout Handler (admin/logout.php)
 */
require_once __DIR__ . '/inc.php';

// Lifecycle notifications: logout also abandons any pending auth challenge.
if (function_exists('do_action')) {
    do_action('nexa_auth_pending_cleared', ['user_id' => (int) ($_SESSION['user_id'] ?? 0), 'username' => (string) ($_SESSION['name'] ?? '')], 'logout');
    do_action('nexa_logout', (int) ($_SESSION['user_id'] ?? 0));
}

$authKey = $config['auth_key'] ?? '';
foreach (['di_rem_user' . $authKey, 'di_rem_sig' . $authKey, 'username' . $authKey, 'password' . $authKey] as $ck) {
    setcookie($ck, '', time() - 3600, '/');
}

$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}
session_destroy();

session_start();
$_SESSION['login_notice'] = 'You have been signed out successfully.';
header('Location: login.php');
exit;
