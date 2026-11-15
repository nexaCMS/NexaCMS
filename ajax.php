<?php
/**
 * NexaCMS AJAX Dispatcher (ajax.php)
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

require_once __DIR__ . '/inc.php';
load_active_plugins();
// Idle-timeout gate: a session that sat inactive past its limit gets a
// 403 JSON and NO action runs (Settings > Sessions & Login).
if (function_exists('nexa_session_enforce_idle')) nexa_session_enforce_idle('json');
// Session-assurance gate: a stale authenticated session (e.g. mandatory
// enrollment introduced after login) must verify before ANY AJAX action
// runs. Guests/public actions are unaffected.
if (function_exists('nexa_auth_enforce_session_assurance')) nexa_auth_enforce_session_assurance('json');
queajax();
do_action('ajax');
