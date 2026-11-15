<?php
/**
 * NexaCMS Admin Auth Guard (admin/includes/auth_validate.php)
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
if (!isset($config)) {
    require_once dirname(__DIR__) . '/inc.php';
}

$authSessionKey = 'user_logged_in' . ($config['auth_key'] ?? '');
if (empty($_SESSION[$authSessionKey])) {
    header('Location: login.php');
    exit;
}

// Idle timeout (Settings > Sessions & Login): a session with no
// activity past its effective limit is signed out BEFORE anything
// privileged runs; a live session counts this request as activity.
if (function_exists('nexa_session_enforce_idle')) {
    nexa_session_enforce_idle('redirect');
}

// Authentication challenges: a provider may veto this existing session
// (e.g. mandatory enrollment introduced after login, stale assurance,
// or a required provider that has disappeared). The shared assurance
// gate re-challenges or invalidates the session — it is NOT trusted
// until it (re)verifies.
if (function_exists('nexa_auth_enforce_session_assurance')) {
    nexa_auth_enforce_session_assurance('redirect');
}

if (empty($_SESSION['display-name'])) {
    $acct = !empty($_SESSION['name']) ? di_find_user_by_login((string) $_SESSION['name']) : null;
    $_SESSION['display-name'] = $acct ? di_user_display_name($acct) : ($_SESSION['name'] ?? 'Administrator');
}
if (empty($_SESSION['admin_type'])) {
    $_SESSION['admin_type'] = 'super_admin';
}
