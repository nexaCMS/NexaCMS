<?php
/**
 * NexaCMS Unified Standalone Core (includes/common-core.php)
 *
 * Merges UPCMS + DiCimes into one standalone, WordPress-free, multi-database PHP 8.4 CMS
 * sharing CSV, SQLite, MySQL, PostgreSQL, JSON, and PHP Array backends.
 */

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/shortcodes.php';

$GLOBALS['hooks'] = $GLOBALS['hooks'] ?? [
    'up_pre'      => [],
    'pre'         => [],
    'up_loaded'   => [],
    'up_head'     => [],
    'up_body'     => [],
    'up_post'     => [],
    'up_footer'   => [],
    'up_admin'    => [],
    'di_admin'    => [],
    'nexa_admin'  => [],
    'ajax'        => [],
    'post_saved'  => [],
];
$GLOBALS['di_filters']    = $GLOBALS['di_filters']    ?? [];
$GLOBALS['di_post_types'] = $GLOBALS['di_post_types'] ?? [
    'post' => ['label' => 'Posts', 'singular' => 'Post', 'public' => true],
    'page' => ['label' => 'Pages', 'singular' => 'Page', 'public' => true],
];

/* ==========================================================================
 * 1. PROXY-AWARE URL, PATH & ESCAPING HELPERS
 * ========================================================================== */

function nexa_detect_scheme(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function nexa_detect_host(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        return $_SERVER['HTTP_HOST'];
    }
    $name = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $port = (string) ($_SERVER['SERVER_PORT'] ?? '');
    if ($port !== '' && $port !== '80' && $port !== '443' && !str_contains($name, ':')) {
        return "{$name}:{$port}";
    }
    return $name;
}

function url(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return nexa_detect_scheme() . '://' . nexa_detect_host() . $uri;
}

function baseurl(): string {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(dirname($script), '/.');
    foreach (['/admin/install', '/admin', '/admin/install', '/di-admin', '/up-admin'] as $suffix) {
        if (str_ends_with($dir, $suffix)) {
            $dir = substr($dir, 0, -strlen($suffix));
            break;
        }
    }
    return nexa_detect_scheme() . '://' . nexa_detect_host() . ($dir !== '' ? $dir : '') . '/';
}

function home_url(string $path = ''): string {
    $base = rtrim(baseurl(), '/');
    // Subsites: point content/page links at the subsite host or path prefix,
    // but never prefix admin, theme/plugin asset or upload URLs.
    $sitePath = trim((string) ($GLOBALS['nexa_site_url_prefix'] ?? ''), '/');
    $siteHost = trim((string) ($GLOBALS['nexa_site_url_host'] ?? ''));
    // Per-subsite admin area: while inside /<subsite>/admin (or on a subsite
    // frontend), admin links must lead to THAT site's admin, never the
    // network-central /admin, so you always know which site you are editing.
    $adminPrefix = trim((string) ($GLOBALS['nexa_site_admin_prefix'] ?? ''), '/');
    $cleanCheck = ltrim((string) $path, '/');
    if (($cleanCheck === 'admin' || str_starts_with($cleanCheck, 'admin/') || str_starts_with($cleanCheck, 'admin?'))) {
        $rest = trim(substr($cleanCheck, 5), '/');
        if ($adminPrefix !== '') {
            return nexa_preview_link($base . '/' . $adminPrefix . '/admin' . ($rest !== '' ? '/' . $rest : '/'));
        }
        if ($sitePath !== '' && empty($GLOBALS['adminarea'])) {
            return nexa_preview_link($base . '/' . $sitePath . '/admin' . ($rest !== '' ? '/' . $rest : '/'));
        }
    }
    if ($siteHost !== '') {
        $parts = parse_url($base);
        if (!empty($parts['scheme'])) {
            $base = $parts['scheme'] . '://' . $siteHost . (isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '');
        }
    }
    $cleanPath = ltrim((string) $path, '/');
    $isAsset = $cleanPath === 'admin' || str_starts_with($cleanPath, 'admin/') || $cleanPath === 'content'
        || str_starts_with($cleanPath, 'content/');
    if ($sitePath !== '' && !$isAsset) {
        if ($path === '' || $path === '/') return nexa_preview_link($base . '/' . $sitePath . '/');
        if (str_starts_with((string) $path, '?')) return nexa_preview_link($base . '/' . $sitePath . '/' . $path);
        return nexa_preview_link($base . '/' . $sitePath . '/' . $cleanPath);
    }
    if ($path === '' || $path === '/') {
        return nexa_preview_link($base . '/');
    }
    return nexa_preview_link($base . '/' . ltrim($path, '/'));
}

function admin_url(string $path = ''): string {
    $base = rtrim(home_url('admin'), '/');
    return $path !== '' ? ($base . '/' . ltrim($path, '/')) : $base . '/';
}

function content_url(string $path = ''): string {
    $base = rtrim(home_url('content'), '/');
    return $path !== '' ? ($base . '/' . ltrim($path, '/')) : $base . '/';
}

function generateRandomString(int $length = 16, string $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-'): string {
    $max = strlen($characters) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $characters[random_int(0, $max)];
    }
    return $out;
}

function esc_html($text): string {
    return htmlspecialchars((string) ($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr($text): string {
    return htmlspecialchars((string) ($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_textarea($text): string {
    return htmlspecialchars((string) ($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url($url): string {
    $url = trim((string) ($url ?? ''));
    if ($url === '') return '';
    if (preg_match('/^\s*javascript:/i', $url)) return '#';
    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_html_e($text): void {
    echo esc_html($text);
}

function sanitize_html_class(string $class, string $fallback = ''): string {
    $clean = preg_replace('/[^A-Za-z0-9_\-]/', '', $class);
    return ($clean !== '' && $clean !== null) ? $clean : $fallback;
}

function __($text, $domain = null): string {
    return (string) $text;
}

function _e($text, $domain = null, bool $echo = true): string {
    if ($echo) echo (string) $text;
    return (string) $text;
}

function db_prepare_input($string): string {
    return trim((string) ($string ?? ''));
}

function successMessage(string $str): string {
    return '<div class="alert alert-success alert-dismissable" style="margin:10px 0;"><a href="#" class="close" data-dismiss="alert">&times;</a>' . $str . '</div>';
}

function errorMessage(string $str): string {
    return '<div class="alert alert-danger alert-dismissable" style="margin:10px 0;"><a href="#" class="close" data-dismiss="alert">&times;</a>' . $str . '</div>';
}

/* ==========================================================================
 * 2. CSRF & SESSION SECURITY
 * ========================================================================== */

function nexa_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (empty($_SESSION['nexa_csrf_token'])) {
        $_SESSION['nexa_csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['nexa_csrf_token'];
}

function nexa_csrf_field(): string {
    return '<input type="hidden" name="nexa_csrf_token" value="' . esc_attr(nexa_csrf_token()) . '" />';
}

function nexa_verify_csrf(?string $token = null): bool {
    $token = $token ?? ($_POST['nexa_csrf_token'] ?? $_POST['di_csrf_token'] ?? $_GET['nexa_csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['nexa_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['nexa_csrf_token'], (string) $token);
}

// Aliases for UPCMS & DiCimes compatibility
function di_csrf_token(): string { return nexa_csrf_token(); }
function di_csrf_field(): string { return nexa_csrf_field(); }
function di_verify_csrf(?string $t = null): bool { return nexa_verify_csrf($t); }
function up_csrf_token(): string { return nexa_csrf_token(); }
function up_csrf_field(): string { return nexa_csrf_field(); }
function up_verify_csrf(?string $t = null): bool { return nexa_verify_csrf($t); }

/* ==========================================================================
 * 3. HOOK & FILTER ENGINE
 * ========================================================================== */

function next_index(array $array, int $index = 0): int {
    $n = $index;
    while (isset($array[$n])) {
        $n++;
    }
    return $n;
}

function di_callback_id($callback): string {
    if (is_string($callback)) return $callback;
    if (is_array($callback) && count($callback) === 2) {
        $cls = is_object($callback[0]) ? spl_object_hash($callback[0]) : (string) $callback[0];
        return $cls . '::' . $callback[1];
    }
    if (is_object($callback)) return spl_object_hash($callback);
    return md5(serialize($callback));
}

function addHook(string $cat, $fun, $args = [], int $index = 0): void {
    global $hooks, $hooked;
    $cat = mb_strtolower(trim($cat));
    if (!isset($hooks[$cat]) || !is_array($hooks[$cat])) {
        $hooks[$cat] = [];
    }
    $n = next_index($hooks[$cat], (int) $index);
    $cbKey = di_callback_id($fun);
    $hooked[$cat][$cbKey] = $n;
    $hooks[$cat][$n] = ['function' => $fun, 'args' => $args];
    ksort($hooks[$cat]);
}

function add_action(string $cat, $fun, $args = [], int $index = 0): void {
    addHook($cat, $fun, $args, $index);
}

function removeHook(string $cat, $fun): void {
    global $hooks, $hooked;
    $cat = mb_strtolower(trim($cat));
    $cbKey = di_callback_id($fun);
    if (isset($hooked[$cat][$cbKey])) {
        $idx = $hooked[$cat][$cbKey];
        unset($hooks[$cat][$idx], $hooked[$cat][$cbKey]);
    }
}

function remove_action(string $cat, $fun): void {
    removeHook($cat, $fun);
}

function doHook(string $cat, ...$runtimeArgs): bool {
    global $hooks;
    $cat = mb_strtolower(trim($cat));
    if (empty($hooks[$cat]) || !is_array($hooks[$cat])) {
        return false;
    }
    ksort($hooks[$cat]);
    foreach ($hooks[$cat] as $entry) {
        $fn = $entry['function'] ?? null;
        if (!is_callable($fn)) continue;
        $staticArgs = $entry['args'] ?? [];
        if (is_array($staticArgs) && !empty($staticArgs)) {
            call_user_func_array($fn, $staticArgs);
        } elseif (!empty($runtimeArgs)) {
            call_user_func_array($fn, $runtimeArgs);
        } else {
            call_user_func($fn);
        }
    }
    return true;
}

function do_action(string $cat, ...$runtimeArgs): bool {
    return doHook($cat, ...$runtimeArgs);
}

function add_filter(string $tag, callable $callback, int $priority = 10): void {
    global $di_filters;
    $tag = mb_strtolower(trim($tag));
    if (!isset($di_filters[$tag])) $di_filters[$tag] = [];
    $n = next_index($di_filters[$tag], $priority);
    $di_filters[$tag][$n] = $callback;
    ksort($di_filters[$tag]);
}

function apply_filters(string $tag, $value, ...$extraArgs) {
    global $di_filters;
    $tag = mb_strtolower(trim($tag));
    if (empty($di_filters[$tag])) return $value;
    foreach ($di_filters[$tag] as $cb) {
        if (is_callable($cb)) {
            $value = call_user_func_array($cb, array_merge([$value], $extraArgs));
        }
    }
    return $value;
}

/* ==========================================================================
 * 4. USERS, AUTHENTICATION & ROLES
 * ========================================================================== */

function di_user_fields(): array {
    return [
        'user', 'pass', 'displayname', 'display-name', 'displayed-name',
        'fullname', 'full-name', 'fname', 'mname', 'lname', 'avatar',
        'email', 'dob', 'url', 'register', 'activationkey', 'status',
        'role', 'gender', 'address', 'state', 'phone', 'date_of_birth',
        'user_name', 'created_at',
    ];
}

function di_ensure_users_table(): void {
    // SECURITY: never seed a default account. The only user created at
    // install time is the single super admin from the setup wizard
    // (admin/install.php). Earlier versions created admin/password here —
    // a known-credential backdoor on every fresh install.
    if (!table_exists('users')) {
        create_table('users', di_user_fields());
    } else {
        ensure_fields('users', ['displayname', 'display-name', 'fname', 'mname', 'lname', 'gender', 'role', 'email', 'idle_timeout_minutes', 'pass_reset_hash', 'pass_reset_expires', 'pass_reset_last']);
    }
}

function di_user_display_name(array $u): string {
    foreach (['display-name', 'displayname', 'displayed-name', 'full-name', 'fullname'] as $k) {
        if (!empty(trim((string) ($u[$k] ?? '')))) {
            return trim((string) $u[$k]);
        }
    }
    $full = trim(($u['fname'] ?? ($u['f_name'] ?? '')) . ' ' . ($u['lname'] ?? ($u['l_name'] ?? '')));
    if ($full !== '') return $full;
    return !empty($u['user']) ? (string) $u['user'] : 'Administrator';
}

function di_find_user_by_login(string $login): ?array {
    di_ensure_users_table();
    $loginLower = strtolower(trim($login));
    if ($loginLower === '') return null;
    $all = get_table('users');
    foreach ($all['data'] as $row) {
        if (strtolower(trim((string) ($row['user'] ?? ''))) === $loginLower ||
            strtolower(trim((string) ($row['email'] ?? ''))) === $loginLower) {
            return $row;
        }
    }
    return null;
}

/**
 * Password validator ONLY. A true result means the password matched —
 * it does NOT mean a login is complete: admin/authenticate.php passes
 * the account through nexa_auth_attempt(), where registered challenge
 * providers (see §4b) may still require verification/enrollment before
 * nexa_auth_finalize_login() grants the session. The legacy
 * 'nexa_login_succeeded' hook fired here is therefore a password-check
 * notification; 'nexa_login_completed' is the true post-challenge hook.
 */
function checkpass($user, $pass = null, $rem = false, bool $err = true): bool {
    global $error, $logged_in;
    if (is_array($user)) {
        $pass = $user['pass'] ?? '';
        $rem  = $user['rem']  ?? false;
        $err  = $user['err']  ?? true;
        $user = $user['user'] ?? '';
    }
    $user = trim((string) $user);
    $pass = (string) ($pass ?? '');

    $account = di_find_user_by_login($user);
    if ($account === null) {
        do_action('nexa_login_failed', $user);
        if ($err) $error .= '<span style="color:red;">' . esc_html($user) . ' is not a valid username or email!</span><br />';
        return $logged_in = false;
    }

    $storedHash = (string) ($account['pass'] ?? '');
    $valid = false;
    if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$argon2')) {
        $valid = password_verify($pass, $storedHash);
    } elseif (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
        if (hash_equals(strtolower($storedHash), md5($pass))) {
            $valid = true;
            update_table('users', [
                'id'   => $account['id'],
                'pass' => password_hash($pass, PASSWORD_DEFAULT),
            ], 'update');
        }
    }

    if ($valid) {
        do_action('nexa_login_succeeded', $account['user']);
        if ($err) $error .= '<span style="color:green;">Login successful!</span><br />';
        return $logged_in = true;
    }
    do_action('nexa_login_failed', $user);
    if ($err) $error .= '<span style="color:red;">Incorrect password!</span><br />';
    return $logged_in = false;
}

function getuserrole(string $user) {
    $account = di_find_user_by_login($user);
    return $account ? ($account['role'] ?: 'subscriber') : false;
}

function get_all_users(): array {
    di_ensure_users_table();
    $t = get_table('users');
    $set = [];
    foreach ($t['data'] as $row) {
        if (!empty($row['user'])) $set[$row['user']] = $row;
        if (isset($row['id'])) $set[$row['id']] = $row;
    }
    return $set;
}

function get_user($key) {
    $all = get_all_users();
    return $all[$key] ?? false;
}

/* ==========================================================================
 * 4c. PASSWORD POLICY (Settings > Password Policy)
 * One site-wide policy, enforced centrally EVERY time a password is set:
 * create_user() (registration, Add User, installer, plugins), Edit User,
 * Profile password change and subsite-admin creation. Existing passwords
 * are never re-checked at login — the policy applies when a password is
 * created or changed. Settings are per-site (each subsite has its own).
 * ========================================================================== */

function nexa_password_policy(): array {
    $min = (int) get_setting('pw_min_length', '8');
    return [
        'min_length'     => max(4, min(64, $min ?: 8)),
        'require_upper'  => (string) get_setting('pw_require_upper', '0') === '1',
        'require_lower'  => (string) get_setting('pw_require_lower', '0') === '1',
        'require_digit'  => (string) get_setting('pw_require_digit', '0') === '1',
        'require_symbol' => (string) get_setting('pw_require_symbol', '0') === '1',
        'no_username'    => (string) get_setting('pw_no_username', '1') === '1',
        'forbid_common'  => (string) get_setting('pw_forbid_common', '0') === '1',
    ];
}

/** Validate a candidate password. Returns a list of problems ([] = OK). */
function nexa_password_validate(string $password, string $username = '', ?array $policy = null): array {
    $policy ??= nexa_password_policy();
    $errors = [];
    if (mb_strlen($password) < (int) $policy['min_length']) {
        $errors[] = 'Password must be at least ' . (int) $policy['min_length'] . ' characters long.';
    }
    if ($policy['require_upper'] && !preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain an uppercase letter.';
    if ($policy['require_lower'] && !preg_match('/[a-z]/', $password)) $errors[] = 'Password must contain a lowercase letter.';
    if ($policy['require_digit'] && !preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain a number.';
    if ($policy['require_symbol'] && !preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password must contain a symbol (e.g. ! @ # $ %).';
    if ($policy['no_username'] && $username !== '' && mb_strlen($username) >= 3
        && stripos($password, $username) !== false) {
        $errors[] = 'Password must not contain the username.';
    }
    if ($policy['forbid_common']) {
        $common = ['password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwerty123', 'qwertyuiop', 'letmein123', 'admin1234', 'changeme', 'iloveyou1', 'welcome123', 'football1', 'baseball1', 'dragon123', 'master123', 'sunshine1', 'princess1', 'superman1', 'trustno1'];
        if (in_array(strtolower($password), $common, true)) $errors[] = 'That password is too common — please choose a less predictable one.';
    }
    return $errors;
}

/** Problems from the most recent failed create_user() password check. */
function nexa_password_last_errors(): array {
    return (array) ($GLOBALS['nexa_password_errors'] ?? []);
}

/** One-line human summary of the active policy, for forms and hints. */
function nexa_password_policy_summary(): string {
    $p = nexa_password_policy();
    $bits = ['at least ' . (int) $p['min_length'] . ' characters'];
    if ($p['require_upper']) $bits[] = 'an uppercase letter';
    if ($p['require_lower']) $bits[] = 'a lowercase letter';
    if ($p['require_digit']) $bits[] = 'a number';
    if ($p['require_symbol']) $bits[] = 'a symbol';
    $s = 'Passwords must contain ' . implode(', ', $bits) . '.';
    if ($p['no_username']) $s .= ' Your username must not appear in the password.';
    if ($p['forbid_common']) $s .= ' Common passwords are not allowed.';
    return $s;
}

/* ==========================================================================
 * 4d. SESSION IDLE TIMEOUT + PASSWORD UPGRADE AT SIGN-IN
 * Idle timeout (Settings > Sessions & Login): an authenticated session
 * with no activity for the effective limit is signed out. The limit is
 * the site default, overridable per user (Profile / Edit User) when the
 * admin allows it, capped by the admin maximum; "Never" only when the
 * admin allows that too. Activity is tracked server-side in the session
 * (nexa_last_activity) and enforced by the admin guard, ajax.php (JSON
 * mode) and member entry points — a stale session can do nothing.
 *
 * Password upgrade: existing passwords are never compared against the
 * policy from their hash. At a PASSWORD sign-in the plaintext is at hand,
 * so when it no longer meets the current policy (and the admin left
 * pw_prompt_on_login on) the login pauses in a pre-auth pending state —
 * exactly like an auth challenge, it grants NOTHING — until the user
 * chooses a compliant password. Only then does the normal login
 * lifecycle (challenges, finalize) continue. Remember-me sign-ins carry
 * no plaintext and cannot be checked; they are never blocked by this.
 * ========================================================================== */

function nexa_session_idle_settings(): array {
    $def = (int) get_setting('sess_idle_minutes', '30');
    $max = (int) get_setting('sess_idle_max_minutes', '1440');
    return [
        'enabled'       => (string) get_setting('sess_idle_enabled', '1') === '1',
        'default_minutes' => max(1, min(10080, $def ?: 30)),
        'allow_user'    => (string) get_setting('sess_idle_allow_user', '1') === '1',
        'allow_never'   => (string) get_setting('sess_idle_allow_never', '0') === '1',
        'max_minutes'   => max(1, min(10080, $max ?: 1440)),
    ];
}

/** Effective idle limit in minutes for the current user (0 = never). */
function nexa_session_idle_limit_minutes(?array $account = null): int {
    $s = nexa_session_idle_settings();
    if (!$s['enabled']) return 0;
    $limit = (int) $s['default_minutes'];
    if ($s['allow_user']) {
        $pref = $account['idle_timeout_minutes'] ?? ($_SESSION['nexa_idle_pref'] ?? '');
        if ($pref !== '' && $pref !== null) {
            $p = (int) $pref;
            if ($p === 0) {
                if ($s['allow_never']) return 0;
            } elseif ($p > 0) {
                $limit = min($p, (int) $s['max_minutes']);
            }
        }
    }
    return $limit;
}

/** Preset choices offered for a personal idle timeout. */
function nexa_session_idle_presets(): array {
    return [5, 10, 15, 30, 60, 120, 240, 480, 720, 1440];
}

function nexa_session_touch_activity(bool $force = false): void {
    if (!is_user_logged_in()) return;
    $now = time();
    if ($force || empty($_SESSION['nexa_last_activity']) || ($now - (int) $_SESSION['nexa_last_activity']) >= 30) {
        $_SESSION['nexa_last_activity'] = $now;
    }
}

function nexa_session_idle_expired(): bool {
    if (!is_user_logged_in()) return false;
    $limit = nexa_session_idle_limit_minutes();
    if ($limit <= 0) return false;
    $last = (int) ($_SESSION['nexa_last_activity'] ?? 0);
    if ($last <= 0) { $_SESSION['nexa_last_activity'] = time(); return false; }
    return (time() - $last) > $limit * 60;
}

/** Remove every trace of an authenticated session (session itself lives). */
function nexa_session_clear_auth_state(): void {
    $authKey = (string) ($GLOBALS['config']['auth_key'] ?? '');
    unset($_SESSION['user_logged_in' . $authKey], $_SESSION['user_id'], $_SESSION['name'],
        $_SESSION['admin_type'], $_SESSION['display-name'], $_SESSION['nexa_auth_assurance'],
        $_SESSION['nexa_last_activity'], $_SESSION['nexa_idle_pref'], $_SESSION['nexa_pw_upgrade_required']);
    if (function_exists('nexa_auth_clear_pending')) nexa_auth_clear_pending();
    if (function_exists('nexa_pwchange_clear')) nexa_pwchange_clear();
}

/**
 * Idle gate for cookie-session entry points, mirroring
 * nexa_auth_enforce_session_assurance(): 'redirect' (admin pages) sends
 * the browser to sign in with a notice; 'json' (AJAX/APIs) answers 403
 * {"code":"session_idle_timeout"} and nothing executes. Live sessions
 * pass through and count as activity.
 */
function nexa_session_enforce_idle(string $mode = 'redirect'): void {
    if (!is_user_logged_in()) return;
    if (!nexa_session_idle_expired()) { nexa_session_touch_activity(); return; }
    $limit = nexa_session_idle_limit_minutes();
    $ctx = ['user_id' => get_current_user_id(), 'username' => (string) ($_SESSION['name'] ?? ''), 'limit_minutes' => $limit];
    do_action('nexa_session_idle_timeout', $ctx);
    do_action('nexa_auth_pending_cleared', $ctx, 'idle_timeout');
    do_action('nexa_logout', (int) $ctx['user_id']);
    nexa_session_clear_auth_state();
    if ($mode === 'json') {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Your session timed out due to inactivity. Please sign in again.', 'code' => 'session_idle_timeout']);
        exit;
    }
    $_SESSION['login_notice'] = 'You were signed out after ' . $limit . ' minutes of inactivity.';
    header('Location: login.php');
    exit;
}

/* ----- password upgrade at sign-in (pre-auth pending, grants NOTHING) ----- */

function nexa_pwchange_pending(): ?array {
    $p = $_SESSION['nexa_pwchange_pending'] ?? null;
    if (!is_array($p)) return null;
    if ((int) ($p['expires'] ?? 0) < time()) { unset($_SESSION['nexa_pwchange_pending']); return null; }
    return $p;
}

function nexa_pwchange_clear(): void {
    unset($_SESSION['nexa_pwchange_pending']);
}

/**
 * Pause a password sign-in whose password fails the CURRENT policy: no
 * login flag, user id or role is granted; a session-bound, expiring
 * transaction sends the browser to the core password-update route. The
 * plaintext password is never stored or logged. Never returns.
 */
function nexa_pwchange_begin(array $account, array $opts, array $errors): void {
    $authKey = (string) ($GLOBALS['config']['auth_key'] ?? '');
    unset($_SESSION['user_logged_in' . $authKey], $_SESSION['user_id'], $_SESSION['admin_type']);
    if (function_exists('nexa_auth_clear_pending')) nexa_auth_clear_pending();
    session_regenerate_id(true);
    $ctx = nexa_auth_context($account, $opts);
    $_SESSION['nexa_pwchange_pending'] = [
        'id'         => bin2hex(random_bytes(16)),
        'user_id'    => $ctx['user_id'],
        'username'   => $ctx['username'],
        'role'       => $ctx['role'],
        'realm'      => $ctx['realm'],
        'site_db_site' => (int) ($opts['site_db_site'] ?? 0),
        'pending_site_admin' => (int) ($opts['pending_site_admin'] ?? 0),
        'site_id'    => $ctx['site_id'],
        'remember'   => $ctx['remember'],
        'errors'     => array_values(array_map('strval', $errors)),
        'created'    => time(),
        'expires'    => time() + 600, // 10 minutes to complete the update
    ];
    do_action('nexa_password_change_required', $ctx, $_SESSION['nexa_pwchange_pending']);
    header('Location: password-change-required.php');
    exit;
}

/**
 * Validate + store a new password for the pending account, in ITS realm
 * (mirrors nexa_auth_refetch_account's storage handling). Returns the
 * fresh live account row (with the new hash), or null when the account
 * is gone/replaced or the password still fails policy.
 */
function nexa_pwchange_complete(array $pending, string $newPassword): ?array {
    $username = (string) ($pending['username'] ?? '');
    $expectId = (int) ($pending['user_id'] ?? 0);
    if ($username === '' || $expectId <= 0) return null;
    if (nexa_password_validate($newPassword, $username)) return null;
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $apply = static function () use ($username, $expectId, $hash): ?array {
        $acct = di_find_user_by_login($username);
        if ($acct === null || (int) ($acct['id'] ?? 0) !== $expectId
            || strcasecmp((string) ($acct['user'] ?? ''), $username) !== 0) return null;
        update_table('users', ['id' => $expectId, 'pass' => $hash], 'update');
        return di_find_user_by_login($username);
    };
    $realm = (string) ($pending['realm'] ?? 'main');
    if (str_starts_with($realm, 'site:')) {
        $siteId = (int) substr($realm, 5);
        $site = null;
        if (function_exists('nexa_network_call')) {
            $site = nexa_network_call(static function () use ($siteId) {
                if (function_exists('nexa_multisite_sites')) foreach (nexa_multisite_sites() as $s) if ((int) $s['id'] === $siteId) return $s;
                return null;
            });
        }
        if (!$site || !function_exists('nexa_multisite_apply_storage')) return null;
        $saved = $GLOBALS['config']['database'] ?? null;
        nexa_multisite_apply_storage($site);
        $acct = $apply();
        if ($saved !== null) $GLOBALS['config']['database'] = $saved;
        return $acct;
    }
    if (function_exists('nexa_network_call')) return nexa_network_call($apply);
    return $apply();
}

/* ==========================================================================
 * 4e. FORGOT PASSWORD (emailed reset links)
 * Request -> a single-use, expiring token is generated; only its SHA-256
 * hash is stored on the account (pass_reset_hash / pass_reset_expires /
 * pass_reset_last). The plaintext token exists ONLY in the emailed link.
 * Responses are identical whether or not the account exists (no user
 * enumeration), requests are throttled per account, a new request
 * invalidates the previous link, and the reset password must satisfy the
 * current password policy. Changing the password also invalidates every
 * remember-me cookie (their HMAC is keyed to the old password hash).
 * Mail transport: nexa_mail() when the SMTP plugin provides it, else
 * PHP mail(); plugins may hook nexa_password_reset_requested (audit,
 * alternate delivery) and nexa_password_reset_completed.
 * ========================================================================== */

function nexa_password_reset_settings(): array {
    $mins = (int) get_setting('pw_reset_expire_minutes', '60');
    return [
        'enabled' => (string) get_setting('pw_reset_enabled', '1') === '1',
        'expire_minutes' => max(5, min(1440, $mins ?: 60)),
    ];
}

/** Locate an account across realms. Returns [account, realm] or null. */
function nexa_password_reset_find(string $login): ?array {
    $login = trim($login);
    if ($login === '') return null;
    // Current storage first (covers same-site sign-ins), then the main
    // realm, then an intended subsite's own database.
    $direct = di_find_user_by_login($login);
    if ($direct !== null) {
        $realm = 'main';
        $site = function_exists('nexa_current_site') ? nexa_current_site() : null;
        if ($site && (string) ($site['storage'] ?? '') === 'separate' && function_exists('nexa_auth_refetch_account')) {
            // Confirm which realm the direct hit belongs to: if the main
            // realm has the same login, the direct hit is ambiguous —
            // prefer the realm whose storage this request is running in.
            $realm = 'site:' . (int) $site['id'];
        }
        return [$direct, $realm];
    }
    if (function_exists('nexa_auth_refetch_account')) {
        $main = nexa_auth_refetch_account(['realm' => 'main', 'username' => $login]);
        if ($main !== null) return [$main, 'main'];
        $siteId = (int) ($_SESSION['nexa_pending_site_admin'] ?? 0);
        if ($siteId <= 0 && function_exists('nexa_current_site')) {
            $cur = nexa_current_site();
            if ($cur) $siteId = (int) $cur['id'];
        }
        if ($siteId > 0) {
            $siteAcct = nexa_auth_refetch_account(['realm' => 'site:' . $siteId, 'username' => $login]);
            if ($siteAcct !== null) return [$siteAcct, 'site:' . $siteId];
        }
    }
    return null;
}

/** Run an account update inside the account's own storage realm. */
function nexa_password_reset_update(string $realm, int $userId, array $fields): bool {
    $apply = static function () use ($userId, $fields): bool {
        update_table('users', ['id' => $userId] + $fields, 'update');
        return true;
    };
    if (str_starts_with($realm, 'site:')) {
        $siteId = (int) substr($realm, 5);
        $site = null;
        if (function_exists('nexa_network_call')) {
            $site = nexa_network_call(static function () use ($siteId) {
                if (function_exists('nexa_multisite_sites')) foreach (nexa_multisite_sites() as $s) if ((int) $s['id'] === $siteId) return $s;
                return null;
            });
        }
        if (!$site || !function_exists('nexa_multisite_apply_storage')) return false;
        $saved = $GLOBALS['config']['database'] ?? null;
        nexa_multisite_apply_storage($site);
        $ok = $apply();
        if ($saved !== null) $GLOBALS['config']['database'] = $saved;
        return $ok;
    }
    if (function_exists('nexa_network_call')) return (bool) nexa_network_call($apply);
    return $apply();
}

/**
 * Handle a reset request. ALWAYS returns the same generic outcome so the
 * response cannot reveal whether an account exists. Side effects (token
 * + email) happen only for a real account with an email address, and at
 * most once per minute per account.
 */
function nexa_password_reset_request(string $login): void {
    $cfg = nexa_password_reset_settings();
    if (!$cfg['enabled']) return;
    $found = nexa_password_reset_find($login);
    if ($found === null) return;
    [$account, $realm] = $found;
    $email = trim((string) ($account['email'] ?? ''));
    if ($email === '' || (int) ($account['id'] ?? 0) <= 0) return;
    if (time() - (int) ($account['pass_reset_last'] ?? 0) < 60) return; // throttle
    $token = bin2hex(random_bytes(32));
    nexa_password_reset_update($realm, (int) $account['id'], [
        'pass_reset_hash'    => hash('sha256', $token),
        'pass_reset_expires' => (string) (time() + $cfg['expire_minutes'] * 60),
        'pass_reset_last'    => (string) time(),
    ]);
    $link = home_url('admin/reset-password.php') . '?user=' . urlencode((string) $account['user']) . '&token=' . $token;
    $siteName = (string) get_bloginfo('name');
    $subject = 'Password reset for ' . $siteName;
    $body = "Hello " . (string) $account['user'] . ",\n\n"
        . "A password reset was requested for your account at {$siteName}.\n"
        . "Open this link within {$cfg['expire_minutes']} minutes to choose a new password:\n\n{$link}\n\n"
        . "If you did not request this, you can ignore this email — your password will not change.\n";
    if (function_exists('nexa_mail')) {
        nexa_mail($email, $subject, $body);
    } else {
        $from = (string) (get_setting('smtp_from', '') ?: get_setting('anim_email', 'no-reply@localhost'));
        @mail($email, $subject, $body, "From: {$siteName} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8");
    }
    do_action('nexa_password_reset_requested', [
        'user_id' => (int) $account['id'], 'username' => (string) $account['user'],
        'realm' => $realm, 'email' => $email,
    ], $link);
}

/**
 * Validate a reset link. Returns [account, realm] when the token matches
 * (constant-time) and has not expired, else null.
 */
function nexa_password_reset_validate(string $username, string $token): ?array {
    if ($username === '' || $token === '') return null;
    $found = nexa_password_reset_find($username);
    if ($found === null) return null;
    [$account, $realm] = $found;
    $stored = (string) ($account['pass_reset_hash'] ?? '');
    if ($stored === '' || (int) ($account['pass_reset_expires'] ?? 0) < time()) return null;
    if (!hash_equals($stored, hash('sha256', $token))) return null;
    return [$account, $realm];
}

/**
 * Complete a reset: policy-validate, store the new hash, burn the token.
 * Returns a list of policy problems ([] = success).
 */
function nexa_password_reset_complete(array $account, string $realm, string $newPassword): array {
    $errors = nexa_password_validate($newPassword, (string) ($account['user'] ?? ''));
    if ($errors) return $errors;
    nexa_password_reset_update($realm, (int) $account['id'], [
        'pass' => password_hash($newPassword, PASSWORD_DEFAULT),
        'pass_reset_hash' => '', 'pass_reset_expires' => '0',
    ]);
    do_action('nexa_password_reset_completed', [
        'user_id' => (int) $account['id'], 'username' => (string) ($account['user'] ?? ''), 'realm' => $realm,
    ]);
    return [];
}

function create_user(array $args) {
    global $settings;
    di_ensure_users_table();
    if (empty($args['user']) && !empty($args['email'])) {
        $args['user'] = $args['email'];
    }
    if (empty($args['user']) || empty($args['pass'])) {
        return false;
    }
    if (di_find_user_by_login((string) $args['user']) !== null || (!empty($args['email']) && di_find_user_by_login((string) $args['email']) !== null)) {
        return false;
    }
    $defaults = [
        'displayname'  => $args['display-name'] ?? $args['displayname'] ?? $args['user'],
        'display-name' => $args['display-name'] ?? $args['displayname'] ?? $args['user'],
        'fullname'     => $args['fullname'] ?? $args['user'],
        'register'     => date('Y-m-d H:i:s'),
        'status'       => '1',
        'role'         => $settings['default_role']['value'] ?? 'subscriber',
    ];
    $merged = array_merge($defaults, $args);
    // Password policy: enforced for every plaintext password set through
    // here (pre-hashed values cannot be validated and are left alone).
    $GLOBALS['nexa_password_errors'] = [];
    if (!str_starts_with((string) $merged['pass'], '$2y$') && !str_starts_with((string) $merged['pass'], '$argon2')) {
        $errors = nexa_password_validate((string) $merged['pass'], (string) ($merged['user'] ?? ''));
        if ($errors) {
            $GLOBALS['nexa_password_errors'] = $errors;
            return false;
        }
        $merged['pass'] = password_hash((string) $merged['pass'], PASSWORD_DEFAULT);
    }
    return update_table('users', $merged, 'insert');
}

function adduser(array $args): bool {
    return (bool) create_user($args);
}

function is_user_logged_in(): bool {
    global $config;
    return !empty($_SESSION['user_logged_in' . ($config['auth_key'] ?? '')]);
}

function get_current_user_id(): int {
    return (int) ($_SESSION['user_id'] ?? 0);
}

function current_user_can(string $capability = 'read'): bool {
    if (!is_user_logged_in()) return false;
    $role = $_SESSION['admin_type'] ?? 'subscriber';
    if (in_array($role, ['super_admin', 'admin'], true)) return true;
    if (in_array($role, ['editor', 'author'], true) && in_array($capability, ['read', 'edit_posts'], true)) return true;
    return $capability === 'read';
}

/* ==========================================================================
 * 4b. AUTHENTICATION CHALLENGE PROVIDERS (generic MFA/enrollment contract)
 * --------------------------------------------------------------------------
 * Core owns the login lifecycle; plugins may register ONE OR MORE
 * challenge providers (e.g. a 2FA plugin) without forking login:
 *
 *   nexa_register_auth_challenge_provider('my-2fa', [
 *       'decide' => callable(array $ctx): array,   // REQUIRED
 *       'render' => callable(array $ctx, array $pending): void, // challenge UI
 *       'verify' => callable(array $ctx, array $pending, array $post): array,
 *       'session_check' => callable(array $ctx, ?array $assurance): bool, // optional
 *   ]);
 *
 * decide() returns ['action' => 'allow'] |
 *   ['action' => 'challenge', 'mode' => 'verify'|'enroll'] |
 *   ['action' => 'deny', 'message' => '...'].
 * verify() returns ['ok' => true] | ['ok' => false, 'error' => '...'].
 * A provider that throws fails CLOSED (login denied). Plugins keep all
 * factor data (TOTP secrets, email codes, recovery codes, audit logs) in
 * their own storage — core never sees codes or password hashes.
 *
 * Lifecycle: password/remember validation -> nexa_auth_attempt() ->
 * providers decide -> either nexa_auth_finalize_login() (the ONLY place
 * an authenticated session is granted; fires nexa_login_completed) or a
 * session-bound, expiring pending challenge completed/cancelled via
 * admin/auth-challenge.php. While pending, NO login flag, user id or
 * role exists, so admin/AJAX/protected APIs stay inaccessible.
 *
 * Fail-closed availability: a plugin whose policy marks accounts as
 * requiring a challenge should call nexa_auth_set_required_providers()
 * with its id; if that provider is later missing/disabled, logins are
 * DENIED instead of silently downgrading. Deliberate owner recovery:
 * remove that provider id from the `nexa_auth_required_providers`
 * setting directly in the site's settings store (database/FTP access),
 * which is an auditable server-side action — there is no web bypass.
 * ========================================================================== */

function nexa_auth_challenge_providers(): array {
    return (array) ($GLOBALS['nexa_auth_challenge_providers'] ?? []);
}

function nexa_register_auth_challenge_provider(string $id, array $provider): void {
    $id = preg_replace('/[^a-z0-9\-_]/', '', strtolower($id)) ?? '';
    if ($id === '' || !is_callable($provider['decide'] ?? null)) return;
    $GLOBALS['nexa_auth_challenge_providers'][$id] = $provider;
}

function nexa_auth_required_providers(): array {
    $list = json_decode((string) get_setting('nexa_auth_required_providers', '[]'), true);
    return is_array($list) ? array_values(array_map('strval', $list)) : [];
}

function nexa_auth_set_required_providers(array $ids): void {
    set_setting('nexa_auth_required_providers', json_encode(array_values(array_unique(array_map('strval', $ids)))), 'Auth Required Providers');
}

/** Server-trusted challenge context. Never contains passwords or hashes. */
function nexa_auth_context(array $account, array $opts = []): array {
    return [
        'user_id'  => (int) ($account['id'] ?? 0),
        'username' => (string) ($account['user'] ?? ''),
        'role'     => (string) ($account['role'] ?? ''),
        'realm'    => (string) ($opts['realm'] ?? 'main'), // 'main' or 'site:<site_id>' (subsite own DB)
        'site_id'  => (int) ($opts['site_id'] ?? $opts['pending_site_admin'] ?? 0), // intended destination site, 0 = main
        'method'   => (string) ($opts['method'] ?? 'password'), // password|remember|session
        'remember' => !empty($opts['remember']),
        'return'   => 'index.php',
    ];
}

/** Id of a policy-required provider that is not currently loaded, if any. */
function nexa_auth_missing_required_provider(): ?string {
    $providers = nexa_auth_challenge_providers();
    foreach (nexa_auth_required_providers() as $reqId) {
        if (!isset($providers[$reqId])) return $reqId;
    }
    return null;
}

/**
 * Evaluate EVERY registered provider (multi-provider contract):
 * providers are consulted in registration order; the first deny wins;
 * EVERY provider that asks for a challenge must complete it, in order,
 * before login is finalized. Returns ['action'=>'allow'] |
 * ['action'=>'deny',...] | ['action'=>'challenge','provider'=>first,
 * 'providers'=>[ids in order],'modes'=>[id=>mode]].
 */
function nexa_auth_decide_all(array $ctx): array {
    $providers = nexa_auth_challenge_providers();
    // Fail closed when a policy-required provider is not loaded.
    $missing = nexa_auth_missing_required_provider();
    if ($missing !== null) {
        return ['action' => 'deny', 'provider' => $missing, 'message' => 'A required sign-in verification service is unavailable. Please contact the site administrator.'];
    }
    $challengeIds = []; $modes = [];
    foreach ($providers as $id => $provider) {
        try {
            $decision = ($provider['decide'])($ctx);
        } catch (Throwable $e) {
            return ['action' => 'deny', 'provider' => $id, 'message' => 'Sign-in verification failed. Please contact the site administrator.'];
        }
        if (!is_array($decision)) continue;
        $action = (string) ($decision['action'] ?? 'allow');
        if ($action === 'deny') return ['action' => 'deny', 'provider' => $id, 'message' => (string) ($decision['message'] ?? 'Sign-in was denied.')];
        if ($action === 'challenge') {
            $challengeIds[] = $id;
            $modes[$id] = (string) ($decision['mode'] ?? 'verify') === 'enroll' ? 'enroll' : 'verify';
        }
    }
    if ($challengeIds) {
        return ['action' => 'challenge', 'provider' => $challengeIds[0], 'providers' => $challengeIds, 'modes' => $modes];
    }
    return ['action' => 'allow'];
}

/** Single-decision convenience wrapper (first challenge, if any). */
function nexa_auth_decide(array $ctx): array {
    $all = nexa_auth_decide_all($ctx);
    if ($all['action'] === 'challenge') {
        return ['action' => 'challenge', 'provider' => $all['provider'], 'mode' => $all['modes'][$all['provider']] ?? 'verify', 'providers' => $all['providers'], 'modes' => $all['modes']];
    }
    return $all;
}

function nexa_auth_pending(): ?array {
    $p = $_SESSION['nexa_auth_pending'] ?? null;
    if (!is_array($p)) return null;
    if ((int) ($p['expires'] ?? 0) < time()) { unset($_SESSION['nexa_auth_pending']); return null; }
    return $p;
}

function nexa_auth_clear_pending(): void {
    unset($_SESSION['nexa_auth_pending']);
}

/**
 * Entry point after a password or remember-me cookie has been VALIDATED
 * and the account (and its storage realm) resolved. Runs the challenge
 * decision, then either finalizes the login or starts a pending
 * challenge. Never returns (redirects in every branch).
 */
function nexa_auth_attempt(array $account, array $opts = []): void {
    $ctx = nexa_auth_context($account, $opts);
    $decision = nexa_auth_decide($ctx);

    if ($decision['action'] === 'deny') {
        nexa_auth_clear_pending();
        do_action('nexa_auth_challenge_denied', $ctx, $decision);
        $_SESSION['login_failure'] = (string) ($decision['message'] ?? 'Sign-in was denied.');
        header('Location: login.php');
        exit;
    }

    if ($decision['action'] === 'challenge') {
        nexa_auth_begin_challenge($account, $opts, $decision);
    }

    nexa_auth_finalize_login($account, $opts + ['challenge_provider' => '']);
}

/**
 * Start a pending challenge: grants NOTHING (no login flag, user id or
 * role), binds an expiring random transaction to this session and sends
 * the browser to the core challenge route. Never returns.
 */
function nexa_auth_begin_challenge(array $account, array $opts, array $decision): void {
    $ctx = nexa_auth_context($account, $opts);
    $authKey = (string) ($GLOBALS['config']['auth_key'] ?? '');
    unset($_SESSION['user_logged_in' . $authKey], $_SESSION['user_id'], $_SESSION['admin_type']);
    session_regenerate_id(true);
    $providerIds = (array) ($decision['providers'] ?? [ (string) ($decision['provider'] ?? '') ]);
    $modes = (array) ($decision['modes'] ?? []);
    $first = (string) ($decision['provider'] ?? ($providerIds[0] ?? ''));
    $_SESSION['nexa_auth_pending'] = [
        'id'         => bin2hex(random_bytes(16)),
        'provider'   => $first,
        'mode'       => (string) ($modes[$first] ?? $decision['mode'] ?? 'verify'),
        'queue'      => array_values(array_filter($providerIds, static fn($p) => $p !== $first)),
        'modes'      => $modes,
        'completed'  => [],
        'user_id'    => $ctx['user_id'],
        'username'   => $ctx['username'],
        'role'       => $ctx['role'],
        'realm'      => $ctx['realm'],
        'site_id'    => $ctx['site_id'],
        'site_db_site' => (int) ($opts['site_db_site'] ?? 0),
        'pending_site_admin' => (int) ($opts['pending_site_admin'] ?? 0),
        'method'     => $ctx['method'],
        'remember'   => $ctx['remember'],
        'return'     => 'index.php',
        'created'    => time(),
        'expires'    => time() + 600, // 10 minutes to complete the challenge
    ];
    do_action('nexa_auth_challenge_started', $ctx, $_SESSION['nexa_auth_pending']);
    header('Location: auth-challenge.php');
    exit;
}

/**
 * THE login finalization: session rotation, authenticated session/role,
 * multisite context, remember-me issuance, nexa_login_completed hook and
 * final redirect. Only core calls this, and only after every required
 * challenge has been verified (or none was required).
 */
function nexa_auth_finalize_login(array $account, array $opts = []): void {
    global $config;
    $authKey   = (string) ($config['auth_key'] ?? '');
    $secretKey = (string) (($config['secret_key'] ?? '') ?: 'dicimes-default-secret');
    $sessFlag  = 'user_logged_in' . $authKey;

    nexa_auth_clear_pending();
    session_regenerate_id(true);

    $_SESSION[$sessFlag]      = true;
    $_SESSION['user_id']      = $account['id'] ?? '1';
    $_SESSION['name']         = $account['user'] ?? '';
    $_SESSION['admin_type']   = ($account['role'] ?? '') !== '' ? $account['role'] : 'super_admin';
    $_SESSION['display-name'] = di_user_display_name($account);
    $completedProviders = (array) ($opts['challenge_providers'] ?? []);
    if (!$completedProviders && (string) ($opts['challenge_provider'] ?? '') !== '') $completedProviders = [(string) $opts['challenge_provider']];
    $_SESSION['nexa_auth_assurance'] = [
        'provider'  => $completedProviders ? (string) end($completedProviders) : '',
        'providers' => array_values(array_map('strval', $completedProviders)),
        'method'    => (string) ($opts['method'] ?? 'password'),
        'at'        => time(),
    ];
    // Idle timeout: signing in counts as activity; cache the personal
    // preference (Profile / Edit User keep it fresh in the session too).
    $_SESSION['nexa_last_activity'] = time();
    $_SESSION['nexa_idle_pref'] = (string) ($account['idle_timeout_minutes'] ?? '');
    if (function_exists('nexa_pwchange_clear')) nexa_pwchange_clear();

    // Multisite context: assigned subsite admins are pinned to their site;
    // unassigned main-site admins are network admins.
    unset($_SESSION['nexa_admin_site_id'], $_SESSION['nexa_network_admin'], $_SESSION['nexa_site_db_user']);
    $siteDbSite = (int) ($opts['site_db_site'] ?? 0);
    $pendingSiteAdmin = (int) ($opts['pending_site_admin'] ?? 0);
    if ($siteDbSite > 0) {
        $_SESSION['nexa_admin_site_id'] = $siteDbSite;
        $_SESSION['nexa_site_db_user'] = $siteDbSite;
    } elseif (function_exists('nexa_multisite_ensure_registry')) {
        $assignedSite = 0;
        if (function_exists('nexa_network_call')) {
            $uid = (int) ($account['id'] ?? 0);
            $assignedSite = (int) nexa_network_call(static function () use ($uid) {
                try { foreach (get_table('network_site_users')['data'] as $r) if ((int) ($r['user_id'] ?? 0) === $uid) return (int) $r['site_id']; } catch (Throwable $e) {}
                return 0;
            });
        }
        if ($assignedSite > 0) $_SESSION['nexa_admin_site_id'] = $assignedSite;
        elseif (in_array((string) $_SESSION['admin_type'], ['super_admin', 'admin'], true)) $_SESSION['nexa_network_admin'] = 1;
        if ($pendingSiteAdmin > 0 && ($assignedSite === $pendingSiteAdmin || !empty($_SESSION['nexa_network_admin']))) {
            $_SESSION['nexa_admin_site_id'] = $pendingSiteAdmin;
        }
    }

    if (!empty($opts['remember']) && !empty($account['pass'])) {
        $sig = hash_hmac('sha256', ($account['id'] ?? '') . '|' . ($account['pass'] ?? ''), $secretKey);
        $exp = time() + (86400 * 30);
        setcookie('di_rem_user' . $authKey, (string) ($account['user'] ?? ''), $exp, '/', '', false, true);
        setcookie('di_rem_sig'  . $authKey, $sig, $exp, '/', '', false, true);
    }

    do_action('nexa_login_completed', nexa_auth_context($account, $opts));
    header('Location: index.php');
    exit;
}

/** Re-fetch the pending account from its own storage realm (for finalize). */
function nexa_auth_refetch_account(array $pending): ?array {
    $username = (string) ($pending['username'] ?? '');
    if ($username === '') return null;
    $realm = (string) ($pending['realm'] ?? 'main');
    if (str_starts_with($realm, 'site:')) {
        $siteId = (int) substr($realm, 5);
        $site = null;
        if (function_exists('nexa_network_call')) {
            $site = nexa_network_call(static function () use ($siteId) {
                if (function_exists('nexa_multisite_sites')) foreach (nexa_multisite_sites() as $s) if ((int) $s['id'] === $siteId) return $s;
                return null;
            });
        }
        if ($site && function_exists('nexa_multisite_apply_storage')) {
            $saved = $GLOBALS['config']['database'] ?? null;
            nexa_multisite_apply_storage($site);
            $acct = di_find_user_by_login($username);
            if ($saved !== null) $GLOBALS['config']['database'] = $saved;
            return $acct;
        }
        return null;
    }
    if (function_exists('nexa_network_call')) {
        return nexa_network_call(static fn() => di_find_user_by_login($username));
    }
    return di_find_user_by_login($username);
}

/**
 * Mid-session assurance: providers may veto an existing session (e.g. a
 * mandatory-enrollment policy was introduced after login). Returns the
 * challenge decision when the session must (re)verify, else null.
 */
function nexa_auth_session_challenge(): ?array {
    if (!is_user_logged_in()) return null;
    // Fail closed FIRST: a policy-required provider that has disappeared
    // invalidates existing sessions too — never downgrade to another
    // provider's say-so, and never convert this into a fresh challenge.
    $missing = nexa_auth_missing_required_provider();
    if ($missing !== null) {
        return ['action' => 'deny', 'provider' => $missing, 'message' => 'A required sign-in verification service is unavailable. Please contact the site administrator.'];
    }
    $providers = nexa_auth_challenge_providers();
    if (!$providers) return null;
    $ctx = [
        'user_id'  => get_current_user_id(),
        'username' => (string) ($_SESSION['name'] ?? ''),
        'role'     => (string) ($_SESSION['admin_type'] ?? ''),
        'realm'    => !empty($_SESSION['nexa_site_db_user']) ? 'site:' . (int) $_SESSION['nexa_site_db_user'] : 'main',
        'site_id'  => (int) ($_SESSION['nexa_admin_site_id'] ?? 0),
        'method'   => 'session',
        'remember' => false,
        'return'   => 'index.php',
    ];
    $assurance = (array) ($_SESSION['nexa_auth_assurance'] ?? []);
    foreach ($providers as $id => $provider) {
        if (!is_callable($provider['session_check'] ?? null)) continue;
        try { $ok = ($provider['session_check'])($ctx, $assurance); } catch (Throwable $e) { $ok = false; }
        if ($ok) continue;
        // The vetoing provider goes first; any other providers that also
        // require a challenge follow in order (multi-provider contract).
        $all = nexa_auth_decide_all($ctx);
        if ($all['action'] === 'deny') return $all;
        $ids = [$id]; $modes = [$id => ($all['modes'][$id] ?? 'verify')];
        if ($all['action'] === 'challenge') {
            foreach ((array) $all['providers'] as $pid) if ($pid !== $id) { $ids[] = $pid; $modes[$pid] = $all['modes'][$pid] ?? 'verify'; }
        }
        return ['action' => 'challenge', 'provider' => $id, 'mode' => $modes[$id], 'providers' => $ids, 'modes' => $modes];
    }
    return null;
}

/**
 * Shared session-assurance gate for every cookie-session protected
 * entry point (call AFTER plugins are loaded, BEFORE privileged work):
 *  - 'redirect' mode (admin pages): re-challenge through the standard
 *    lifecycle, or invalidate the session on a fail-closed denial.
 *  - 'json' mode (AJAX/API): deny safely with a 403 JSON body; the
 *    browser completes verification via the admin challenge route.
 * Guests and sessions that satisfy assurance pass through untouched, so
 * public endpoints stay public. Plugins with authenticated endpoints
 * should call nexa_auth_enforce_session_assurance('json') first.
 */
function nexa_auth_enforce_session_assurance(string $mode = 'redirect'): void {
    if (!is_user_logged_in() || !function_exists('nexa_auth_session_challenge')) return;
    $need = nexa_auth_session_challenge();
    if (!$need) return;

    if ((string) ($need['action'] ?? '') === 'deny') {
        // Fail closed: invalidate the session outright (owner recovery
        // stays server-side, as with fresh logins).
        $authKey = (string) ($GLOBALS['config']['auth_key'] ?? '');
        unset($_SESSION['user_logged_in' . $authKey], $_SESSION['user_id'], $_SESSION['admin_type'], $_SESSION['nexa_auth_assurance']);
        nexa_auth_clear_pending();
        do_action('nexa_auth_session_invalidated', $need);
        if ($mode === 'json') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => (string) ($need['message'] ?? 'Sign-in verification is unavailable.'), 'code' => 'auth_required_provider_unavailable']);
            exit;
        }
        $_SESSION['login_failure'] = (string) ($need['message'] ?? 'Sign-in verification is unavailable.');
        header('Location: login.php');
        exit;
    }

    if ($mode === 'json') {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Additional sign-in verification is required.', 'code' => 'auth_challenge_required', 'challenge_url' => home_url('admin/auth-challenge.php')]);
        exit;
    }

    $realm = !empty($_SESSION['nexa_site_db_user']) ? 'site:' . (int) $_SESSION['nexa_site_db_user'] : 'main';
    $acct = function_exists('nexa_auth_refetch_account') ? nexa_auth_refetch_account(['realm' => $realm, 'username' => (string) ($_SESSION['name'] ?? '')]) : null;
    if ($acct !== null && (int) ($acct['id'] ?? 0) === get_current_user_id() && function_exists('nexa_auth_begin_challenge')) {
        nexa_auth_begin_challenge($acct, [
            'realm'        => $realm,
            'site_db_site' => (int) ($_SESSION['nexa_site_db_user'] ?? 0),
            'site_id'      => (int) ($_SESSION['nexa_admin_site_id'] ?? 0),
            'pending_site_admin' => (int) ($_SESSION['nexa_admin_site_id'] ?? 0),
            'method'       => 'session',
            'remember'     => false,
        ], $need);
    }
    $authKey = (string) ($GLOBALS['config']['auth_key'] ?? '');
    unset($_SESSION['user_logged_in' . $authKey], $_SESSION['user_id'], $_SESSION['admin_type'], $_SESSION['nexa_auth_assurance']);
    $_SESSION['login_failure'] = 'Your session needs additional verification. Please sign in again.';
    header('Location: login.php');
    exit;
}

/* ==========================================================================
 * 5. SETTINGS ENGINE
 * ========================================================================== */

function loadsettings(): array {
    global $settings;
    $fields = [
        'setting', 'display', 'displayname', 'displayed-name', 'value',
        'description', 'lastchanged', 'prevvalue', 'default', 'setby', 'autoload', 'alternate',
    ];

    if (!table_exists('settings') || count_records('settings') === 0) {
        create_table('settings', $fields);
        $now = date('Y-m-d H:i:s');
        $defaults = [
            ['setting' => 'siteurl',                       'display' => 'Site URL',                     'value' => baseurl(),               'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'home',                          'display' => 'Home URL',                     'value' => baseurl(),               'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'blogname',                      'display' => 'Site Name',                    'value' => 'NexaCMS',               'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'blogdescription',               'display' => 'Tagline',                      'value' => 'Unified Polyglot Multi-Database PHP CMS', 'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'users_can_register',            'display' => 'Anyone Can Register',          'value' => '0',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'anim_email',                    'display' => 'Admin Email',                  'value' => 'admin@localhost',       'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'start_of_week',                 'display' => 'Week Starts On',               'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'posts_per_page',                'display' => 'Posts Per Page',               'value' => '10',                    'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'posts_per_rss',                 'display' => 'Syndication Feeds Show Recent','value' => '10',                    'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'date_format',                   'display' => 'Date Format',                  'value' => 'Y-m-d',                 'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'time_format',                   'display' => 'Time Format',                  'value' => 'g:i a',                 'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'blog_charset',                  'display' => 'Site Charset',                 'value' => 'UTF-8',                 'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'gmt_offset',                    'display' => 'GMT Offset',                   'value' => '-5',                    'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'template',                      'display' => 'Current Theme',                'value' => 'multi',                 'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'default_role',                  'display' => 'New User Default Role',        'value' => 'subscriber',            'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'show_on_front',                 'display' => 'Front Page Displays',          'value' => 'posts',                 'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'page_for_posts',                'display' => 'Posts Page ID',                'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'page_on_front',                 'display' => 'Front Page ID',                'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'permalink_structure',           'display' => 'Permalink Structure',          'value' => '/%postname%/',          'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'default_comment_status',        'display' => 'Default Comment Status',       'value' => 'open',                  'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'require_name_email',            'display' => 'Require Comment Name & Email', 'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'comment_registation',           'display' => 'Users Must Log In to Comment', 'value' => '0',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'thread_comments',               'display' => 'Enable Threaded Comments',     'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'show_avatars',                  'display' => 'Show Avatars',                 'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'thumbnail_size_w',              'display' => 'Thumbnail Width',              'value' => '150',                   'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'thumbnail_size_h',              'display' => 'Thumbnail Height',             'value' => '150',                   'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'medium_size_w',                 'display' => 'Medium Width',                 'value' => '300',                   'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'medium_size_h',                 'display' => 'Medium Height',                'value' => '300',                   'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'large_size_w',                  'display' => 'Large Width',                  'value' => '1024',                  'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'large_size_h',                  'display' => 'Large Height',                 'value' => '1024',                  'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'uploads_use_yearmonth_folders', 'display' => 'Year/Month Upload Folders',    'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'mailserver_url',                'display' => 'Mail Server URL',              'value' => 'mail.example.com',      'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'mailserver_port',               'display' => 'Mail Server Port',             'value' => '587',                   'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'mailserver_login',              'display' => 'Mail Login',                   'value' => 'login@example.com',     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'mailserver_pass',               'display' => 'Mail Password',                'value' => '',                      'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'default_category',              'display' => 'Default Post Category',        'value' => 'General',               'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
            ['setting' => 'multi_slider',                  'display' => 'Show Theme Hero Slider',       'value' => '1',                     'setby' => 'Setup', 'autoload' => '1', 'lastchanged' => $now],
        ];
        multi_update_table('settings', $defaults, 'insert');
    }

    $t = get_table('settings');
    $settings = [];
    foreach ($t['data'] as $row) {
        if (!empty($row['setting'])) {
            if (empty($row['display']) && !empty($row['displayname'])) {
                $row['display'] = $row['displayname'];
            }
            $settings[$row['setting']] = $row;
        }
    }
    return $settings;
}

function get_setting(string $key, $default = '') {
    global $settings;
    if (empty($settings)) loadsettings();
    return $settings[$key]['value'] ?? $default;
}

function get_option(string $key, $default = '') {
    return get_setting($key, $default);
}

function get_settings(string $setting) {
    global $active_plugins;
    if ($setting === 'active_plugins') {
        $ret = [];
        foreach ((array) $active_plugins as $group) {
            foreach ((array) $group as $plugin) {
                if (!empty($plugin['name']) && !in_array($plugin['name'], $ret, true)) {
                    $ret[] = $plugin['name'];
                }
            }
        }
        return $ret;
    }
    return get_setting($setting, false);
}

function set_setting(string $key, $value, ?string $displayLabel = null): bool {
    global $settings;
    if (empty($settings)) loadsettings();
    $valStr = is_scalar($value) || $value === null ? (string) ($value ?? '') : maybe_serialize($value);
    $args = [
        'setting'     => $key,
        'value'       => $valStr,
        'lastchanged' => date('Y-m-d H:i:s'),
        'setby'       => $_SESSION['display-name'] ?? 'Admin',
    ];
    if (isset($settings[$key]['id'])) {
        $args['id']        = $settings[$key]['id'];
        $args['prevvalue'] = $settings[$key]['value'] ?? '';
        update_table('settings', $args, 'update');
    } else {
        $args['display']  = $displayLabel ?? ucwords(str_replace('_', ' ', $key));
        $args['autoload'] = '1';
        $args['id']       = update_table('settings', $args, 'insert');
    }
    $settings[$key] = array_merge($settings[$key] ?? [], $args);
    do_action('nexa_setting_updated', $key, $valStr);
    return true;
}

function update_option(string $option_name, $newvalue): bool {
    if ($option_name === 'active_plugins') return true;
    return set_setting($option_name, $newvalue);
}

function update_settings(array $updates, $allowedKeys = null): void {
    $skip = ['action', 'option_page', 'page_options', 'Update', 'submit', 'nexa_csrf_token', 'di_csrf_token'];
    foreach ($updates as $key => $val) {
        if (in_array($key, $skip, true)) continue;
        if (is_array($allowedKeys) && !empty($allowedKeys) && !in_array($key, $allowedKeys, true)) continue;
        if ((string) get_setting($key, '__missing__') !== (string) $val) {
            set_setting($key, $val);
        }
    }
    $_SESSION['success'] = 'Settings saved successfully!';
}

/* ==========================================================================
 * 6. STAGE-SCOPED PLUGIN SYSTEM (content/plugins)
 * ========================================================================== */

function nexa_plugins_dir(): string {
    global $config, $include_dir;
    $root = $include_dir ?? ($config['abs'] ?? dirname(__DIR__));
    return rtrim(str_replace('\\', '/', $root), '/') . '/content/plugins';
}
function di_plugins_dir(): string { return nexa_plugins_dir(); }
function up_plugins_dir(): string { return nexa_plugins_dir(); }

/**
 * Per-subsite plugin copies live in content/plugins-<siteslug>/<plugin>/.
 * The extra folder keeps the exact same depth as content/plugins/<plugin>,
 * so plugins that find the install root via dirname(__DIR__, 3) keep
 * working unchanged. A site copy shadows the shared network copy for that
 * subsite only — that is how a subsite can run (and pay for) its own
 * plugin version while every other site keeps the network one.
 */
function nexa_site_plugins_dir(?array $site = null): string {
    global $config, $include_dir;
    $root = rtrim(str_replace('\\', '/', (string) ($include_dir ?? ($config['abs'] ?? dirname(__DIR__)))), '/');
    $site ??= function_exists('nexa_current_site') ? nexa_current_site() : null;
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($site['slug'] ?? ''))) ?: 'site';
    return $root . '/content/plugins-' . $slug;
}

function nexa_site_plugin_slugs(?array $site = null): array {
    $dir = nexa_site_plugins_dir($site);
    $out = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e !== '.' && $e !== '..' && is_dir($dir . '/' . $e) && is_file($dir . '/' . $e . '/plugin.json')) $out[] = $e;
        }
    }
    sort($out);
    return $out;
}

function nexa_site_has_plugin_copy(string $pluginName, ?array $site = null): bool {
    return is_dir(nexa_site_plugins_dir($site) . '/' . basename($pluginName));
}

function nexa_resolve_plugin_path(string $pluginName): string {
    $name = basename($pluginName);
    $site = function_exists('nexa_current_site') ? nexa_current_site() : null;
    if ($site && nexa_site_has_plugin_copy($name, $site)) {
        return nexa_site_plugins_dir($site) . '/' . $name;
    }
    return nexa_plugins_dir() . '/' . $name;
}
function di_resolve_plugin_path(string $pluginName): string { return nexa_resolve_plugin_path($pluginName); }

/**
 * Per-subsite theme copies live in content/themes-<siteslug>/<theme>/.
 * Only packages uploaded FROM a subsite land there; themes installed on
 * the main site stay in the shared content/themes/ folder for everyone.
 * A site copy shadows the shared copy of the same slug inside that
 * subsite only, so a subsite can run its own theme version while every
 * other site keeps the shared one.
 */
function nexa_themes_dir(): string {
    global $config, $include_dir;
    $root = $include_dir ?? ($config['abs'] ?? dirname(__DIR__));
    return rtrim(str_replace('\\', '/', $root), '/') . '/content/themes';
}

function nexa_site_themes_dir(?array $site = null): string {
    global $config, $include_dir;
    $root = rtrim(str_replace('\\', '/', (string) ($include_dir ?? ($config['abs'] ?? dirname(__DIR__)))), '/');
    $site ??= function_exists('nexa_current_site') ? nexa_current_site() : null;
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($site['slug'] ?? ''))) ?: 'site';
    return $root . '/content/themes-' . $slug;
}

function nexa_site_theme_slugs(?array $site = null): array {
    $dir = nexa_site_themes_dir($site);
    $out = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e !== '.' && $e !== '..' && is_dir($dir . '/' . $e) && is_file($dir . '/' . $e . '/index.php') && is_file($dir . '/' . $e . '/style.css')) $out[] = $e;
        }
    }
    sort($out);
    return $out;
}

function nexa_site_has_theme_copy(string $themeName, ?array $site = null): bool {
    return is_dir(nexa_site_themes_dir($site) . '/' . basename($themeName));
}

/** Absolute path (no trailing slash) of the theme copy in effect: the current subsite's own copy when it has one, else the shared network copy. */
function nexa_resolve_theme_path(string $themeName, ?array $site = null): string {
    $name = basename($themeName);
    $site ??= function_exists('nexa_current_site') ? nexa_current_site() : null;
    if ($site && nexa_site_has_theme_copy($name, $site)) {
        return nexa_site_themes_dir($site) . '/' . $name;
    }
    return nexa_themes_dir() . '/' . $name;
}

/** Site-root-relative path of the effective theme copy, for building asset URLs. */
function nexa_resolve_theme_rel(string $themeName, ?array $site = null): string {
    $name = basename($themeName);
    $site ??= function_exists('nexa_current_site') ? nexa_current_site() : null;
    if ($site && nexa_site_has_theme_copy($name, $site)) {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($site['slug'] ?? ''))) ?: 'site';
        return 'content/themes-' . $slug . '/' . $name;
    }
    return 'content/themes/' . $name;
}

/** Theme slugs usable in the current context: shared themes plus the current subsite's own copies (site copy wins on a slug clash). */
function nexa_available_theme_slugs(?array $site = null): array {
    $site ??= function_exists('nexa_current_site') ? nexa_current_site() : null;
    $out = [];
    $shared = nexa_themes_dir();
    if (is_dir($shared)) {
        foreach (scandir($shared) ?: [] as $e) {
            if ($e !== '.' && $e !== '..' && is_dir($shared . '/' . $e) && is_file($shared . '/' . $e . '/index.php')) $out[] = $e;
        }
    }
    foreach (nexa_site_theme_slugs($site) as $e) if (!in_array($e, $out, true)) $out[] = $e;
    sort($out);
    return $out;
}

function nexa_read_theme_manifest(string $themeDir, string $fallbackName): array {
    $meta = ['name' => $fallbackName, 'version' => '', 'author' => '', 'description' => ''];
    $jsonFile = rtrim($themeDir, '/') . '/theme.json';
    if (is_file($jsonFile)) {
        $parsed = json_decode((string) file_get_contents($jsonFile), true);
        if (is_array($parsed)) {
            foreach (['name', 'version', 'author', 'description'] as $k) if (!empty($parsed[$k])) $meta[$k] = (string) $parsed[$k];
        }
    }
    $css = (string) @file_get_contents(rtrim($themeDir, '/') . '/style.css');
    if ($css !== '') {
        if ($meta['name'] === $fallbackName && preg_match('/Theme Name:\s*(.+)/i', $css, $m)) $meta['name'] = trim($m[1]);
        if ($meta['version'] === '' && preg_match('/Version:\s*([0-9][^\s*]*)/i', $css, $v)) $meta['version'] = trim($v[1]);
        if ($meta['author'] === '' && preg_match('/Author:\s*(.+)/i', $css, $a)) $meta['author'] = trim($a[1]);
        if ($meta['description'] === '' && preg_match('/Description:\s*(.+)/i', $css, $d)) $meta['description'] = trim($d[1]);
    }
    return $meta;
}

function di_read_plugin_manifest(string $pluginDir, string $fallbackName): array {
    $meta = [
        'displayed-name' => $fallbackName,
        'version'        => '1.0.0',
        'author'         => 'NexaCMS',
        'description'    => 'Stage-scoped NexaCMS plugin.',
        'admin_page'     => '',
        'category'       => 'Utilities',
        'default_stages' => [],
    ];
    $jsonFile = rtrim($pluginDir, '/') . '/plugin.json';
    if (is_file($jsonFile)) {
        $parsed = json_decode((string) file_get_contents($jsonFile), true);
        if (is_array($parsed)) {
            $meta['displayed-name'] = $parsed['name'] ?? $fallbackName;
            $meta['version']        = $parsed['version'] ?? '1.0.0';
            $meta['author']         = $parsed['author'] ?? 'NexaCMS';
            $meta['description']    = $parsed['description'] ?? '';
            $meta['admin_page']     = $parsed['admin_page'] ?? '';
            $meta['category']       = $parsed['category'] ?? 'Utilities';
            $meta['default_stages'] = is_array($parsed['default_stages'] ?? null) ? $parsed['default_stages'] : [];
        }
    }
    return $meta;
}

function queplugins(): void {
    global $active_plugins, $adminarea;
    $fields = [
        'name', 'location', 'version', 'author', 'displayed-name', 'description',
        'lastchanged', 'prevvalue', 'default', 'setby', 'autoload', 'alternate',
        'pre', 'head', 'body', 'post',
    ];
    if (!table_exists('plugins')) {
        create_table('plugins', $fields);
    }
    $active_plugins = ['pre' => [], 'head' => [], 'body' => [], 'post' => [], 'admin' => []];
    $t = get_table('plugins');
    foreach ($t['data'] as $row) {
        if (empty($row['name'])) continue;
        $row['location'] = nexa_resolve_plugin_path($row['name']);
        if (!is_dir($row['location'])) continue;

        $anyActive = false;
        foreach (['pre', 'head', 'body', 'post'] as $stage) {
            if (($row[$stage] ?? '0') == '1') {
                $active_plugins[$stage][] = $row;
                $anyActive = true;
            }
        }
        if ($anyActive && !empty($adminarea)) {
            $active_plugins['admin'][] = $row;
        }
    }
    // Subsite governance: network allow-list + mandatory plugins.
    $site = function_exists('nexa_current_site') ? nexa_current_site() : null;
    if ($site) {
        $allowed = nexa_multisite_csv_list($site['allowed_plugins'] ?? '');
        $mandatory = nexa_multisite_csv_list($site['mandatory_plugins'] ?? '');
        if ($allowed) {
            $keep = array_merge($allowed, $mandatory);
            // A package installed into this subsite's own folder got
            // there by being uploaded from this subsite (directly, or
            // via network-admin approval of its request) — it belongs
            // to this site, so it is always usable here.
            foreach (nexa_site_plugin_slugs($site) as $sitePkg) {
                if (!in_array($sitePkg, $keep, true)) $keep[] = $sitePkg;
            }
            foreach ($active_plugins as $stage => $list) {
                $active_plugins[$stage] = array_values(array_filter($list, static fn($r) => in_array((string) ($r['name'] ?? ''), $keep, true)));
            }
        }
        foreach ($mandatory as $slugP) {
            $dir = nexa_resolve_plugin_path($slugP);
            if (!is_dir($dir)) continue;
            $already = false;
            foreach (['pre', 'head', 'body', 'post'] as $st) foreach ((array) $active_plugins[$st] as $r) if (($r['name'] ?? '') === $slugP) { $already = true; break 2; }
            if ($already) continue;
            $row = null;
            foreach ($t['data'] as $r) if (($r['name'] ?? '') === $slugP) { $row = $r; break; }
            if ($row === null) {
                $meta = di_read_plugin_manifest($dir, $slugP);
                $row = ['name' => $slugP, 'location' => 'content/plugins/' . $slugP, 'version' => $meta['version'] ?? '1.0.0'];
                $stages = $meta['default_stages'] ?? [];
            } else {
                $stages = di_read_plugin_manifest($dir, $slugP)['default_stages'] ?? [];
            }
            if (!$stages) $stages = ['pre', 'head', 'body', 'post'];
            $row['location'] = $dir;
            foreach ($stages as $st) if (isset($active_plugins[$st])) $active_plugins[$st][] = $row;
            if (!empty($adminarea)) $active_plugins['admin'][] = $row;
        }
    }
}

function updatepluginlist(): void {
    global $allplugins;
    $allplugins = [];
    $t = get_table('plugins');
    $existing = [];
    foreach ($t['data'] as $row) {
        if (!empty($row['name'])) $existing[$row['name']] = $row;
    }
    $dir = nexa_plugins_dir();
    if (!is_dir($dir)) return;

    // Candidate packages: the shared network folder, shadowed by this
    // subsite's own copies (content/plugins-<slug>/) when inside one.
    $candidates = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (is_dir($dir . '/' . $entry)) $candidates[$entry] = [$dir . '/' . $entry, 'content/plugins/' . $entry];
    }
    $msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;
    if ($msSite) {
        $siteDir = nexa_site_plugins_dir($msSite);
        if (is_dir($siteDir)) {
            foreach (scandir($siteDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (is_dir($siteDir . '/' . $entry)) $candidates[$entry] = [$siteDir . '/' . $entry, 'content/plugins-' . (string) $msSite['slug'] . '/' . $entry];
            }
        }
    }

    $newRows = [];
    foreach ($candidates as $entry => [$pDir, $loc]) {
        if (!is_file($pDir . '/functions.php')) continue;
        $allplugins[] = $entry;
        $meta = di_read_plugin_manifest($pDir, $entry);
        if (!isset($existing[$entry])) {
            $defStages = $meta['default_stages'];
            $newRows[] = [
                'name'           => $entry,
                'location'       => $loc,
                'displayed-name' => $meta['displayed-name'],
                'version'        => $meta['version'],
                'author'         => $meta['author'],
                'description'    => $meta['description'],
                'pre'            => in_array('pre', $defStages, true) ? '1' : '0',
                'head'           => in_array('head', $defStages, true) ? '1' : '0',
                'body'           => in_array('body', $defStages, true) ? '1' : '0',
                'post'           => in_array('post', $defStages, true) ? '1' : '0',
                'setby'          => 'Auto Discovery',
            ];
        } elseif ((string) ($existing[$entry]['version'] ?? '') !== (string) $meta['version'] || (string) ($existing[$entry]['location'] ?? '') !== $loc) {
            // Package changed under us (installed/updated/pinned): keep the row truthful.
            update_table('plugins', ['id' => (int) $existing[$entry]['id'], 'location' => $loc, 'version' => $meta['version'], 'displayed-name' => $meta['displayed-name'], 'description' => $meta['description'], 'author' => $meta['author']], 'update');
        }
    }
    if (!empty($newRows)) {
        multi_update_table('plugins', $newRows, 'insert');
        queplugins();
    }
}

function load_active_plugins(): void {
    global $active_plugins, $adminarea;
    $loadedFn = [];
    $loadStage = static function (string $stage, string $stageFile) use (&$active_plugins, &$loadedFn): void {
        foreach ((array) ($active_plugins[$stage] ?? []) as $plugin) {
            $pDir = nexa_resolve_plugin_path($plugin['name']);
            // Snapshot admin hooks/menus so we can enforce the admin-hook rule per plugin
            $isAdminStage = ($stageFile === 'admin.php');
            $hooksBefore = array_keys($GLOBALS['hooked']['nexa_admin'] ?? []);
            $menuBefore  = array_keys($GLOBALS['menu_index']['dashboard'] ?? []);
            $fnFile = $pDir . '/functions.php';
            if (!is_file($fnFile)) continue;
            if (!isset($loadedFn[$plugin['name']])) {
                include_once $fnFile;
                $loadedFn[$plugin['name']] = true;
            }
            $target = $pDir . '/' . $stageFile;
            if (is_file($target)) {
                include_once $target;
            }
            if ($isAdminStage) {
                $hooksAfter = array_keys($GLOBALS['hooked']['nexa_admin'] ?? []);
                $menuAfter  = array_keys($GLOBALS['menu_index']['dashboard'] ?? []);
                if (empty(array_diff($hooksAfter, $hooksBefore))) {
                    $GLOBALS['nexa_plugins_no_admin_hook'][] = $plugin['name'];
                }
                if (empty(array_diff($menuAfter, $menuBefore))) {
                    $GLOBALS['nexa_plugins_no_admin_menu'][] = $plugin['name'];
                }
            }
        }
    };
    if (!empty($adminarea)) $loadStage('admin', 'admin.php');
    $loadStage('pre', 'pre.php');
    $loadStage('head', 'head.php');
    $loadStage('body', 'body.php');
    $loadStage('post', 'post.php');
    if (!empty($adminarea)) nexa_enforce_plugin_admin_menus();
}

/**
 * ADMIN-HOOK RULE (enforced): every ENABLED plugin must appear in the admin.
 * Well-behaved plugins register their own menu + 'nexa_admin' renderer in
 * admin.php. If a plugin forgets, NexaCMS auto-adds a menu item and an
 * info screen so an enabled plugin is NEVER invisible in the admin area.
 */
function nexa_enforce_plugin_admin_menus(): void {
    global $active_plugins, $menu_index;
    foreach ((array) ($active_plugins['admin'] ?? []) as $i => $plugin) {
        $slug = (string) ($plugin['name'] ?? '');
        if ($slug === '') continue;
        if (in_array($slug, $GLOBALS['nexa_plugins_no_admin_menu'] ?? [], true) || empty($menu_index['dashboard'][$slug])) {
            $meta = di_read_plugin_manifest(nexa_resolve_plugin_path($slug), $slug);
            add_menu_item('dashboard', [
                'name'  => $slug,
                'class' => (($_GET['plugin_page'] ?? '') === $slug) ? 'active' : '',
                'icon'  => 'fa fa-plug fa-fw',
                'label' => ' ' . ($meta['displayed-name'] ?? $slug),
                'link'  => 'admin.php?plugin_page=' . urlencode($slug),
            ], 80 + $i);
        }
    }
    add_action('nexa_admin', 'nexa_plugin_info_render', [], 999);
}

function nexa_plugin_info_render(): void {
    $slug = (string) ($_GET['plugin_page'] ?? '');
    if ($slug === '' || !in_array($slug, $GLOBALS['nexa_plugins_no_admin_hook'] ?? [], true)) return;
    $meta = di_read_plugin_manifest(nexa_resolve_plugin_path($slug), $slug);
    $rows = get_result('plugins', 1, ['*'], ['search' => ['name']], 1, [$slug], true);
    $row  = $rows[0] ?? [];
    echo '<h2><i class="fa fa-plug"></i> ' . esc_html($meta['displayed-name'] ?? $slug) . '</h2>';
    echo '<p>' . esc_html($meta['description'] ?? '') . '</p>';
    echo '<table class="table table-striped" style="max-width:640px"><tr><th>Folder</th><td><code>content/plugins/' . esc_html($slug) . '</code></td></tr>'
       . '<tr><th>Version</th><td>' . esc_html($meta['version'] ?? '1.0.0') . '</td></tr>'
       . '<tr><th>Author</th><td>' . esc_html($meta['author'] ?? 'NexaCMS') . '</td></tr>'
       . '<tr><th>Stages ON</th><td>';
    $on = [];
    foreach (['pre', 'head', 'body', 'post'] as $st) if (($row[$st] ?? '0') == '1') $on[] = ucfirst($st);
    echo esc_html($on ? implode(', ', $on) : 'None') . '</td></tr></table>';
    echo '<div class="alert alert-info">This plugin did not register its own admin screen, so NexaCMS created this page automatically. '
       . 'Plugin authors: hook <code>nexa_admin</code> in <code>admin.php</code> and call <code>add_menu_item()</code> — see <code>PLUGIN_DEVELOPMENT.md</code>.</div>';
}

/* ==========================================================================
 * 6b. ADMIN THEMES (admin/themes/{slug}/theme.json + theme.css)
 * ========================================================================== */

function nexa_admin_themes(): array {
    global $config;
    $root = rtrim((string) ($config['abs'] ?? dirname(__DIR__)), '/') . '/admin/themes';
    $themes = [];
    if (is_dir($root)) {
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $dir = $root . '/' . $entry;
            if (!is_dir($dir) || !is_file($dir . '/theme.css')) continue;
            $meta = ['name' => ucfirst($entry), 'description' => '', 'swatch' => '#337ab7'];
            if (is_file($dir . '/theme.json')) {
                $j = json_decode((string) file_get_contents($dir . '/theme.json'), true);
                if (is_array($j)) $meta = array_merge($meta, array_intersect_key($j, $meta));
            }
            $themes[$entry] = $meta;
        }
    }
    if (!isset($themes['default'])) $themes = ['default' => ['name' => 'Default (SB Admin 2)', 'description' => 'Built-in light admin.', 'swatch' => '#337ab7']] + $themes;
    return $themes;
}

function nexa_get_admin_theme(): string {
    $themes = nexa_admin_themes();
    $slug = basename((string) get_setting('admin_theme', 'default'));
    return isset($themes[$slug]) ? $slug : 'default';
}

function load_plugins(): void {
    load_active_plugins();
}

function get_plugins(): array {
    global $allplugins;
    return is_array($allplugins) ? $allplugins : [];
}

function actplugs(): array {
    global $active_plugins;
    $ret = [];
    foreach ((array) $active_plugins as $stage => $plugins) {
        foreach ((array) $plugins as $plugin) {
            if (!empty($plugin['name'])) {
                $ret[$stage][$plugin['name']] = $plugin;
            }
        }
    }
    return $ret;
}

function activateplugin(?string $plugin = null, ?string $opt = null): void {
    $plugin = $plugin ?? ($_GET['plugin'] ?? '');
    $opt = $opt ?? ($_GET['opt'] ?? 'all');
    if ($plugin === '') return;
    $rows = get_result('plugins', 1, ['id', 'name'], ['search' => ['name']], 1, [$plugin], true);
    if (empty($rows)) return;
    $id = $rows[0]['id'];
    if (in_array($opt, ['pre', 'head', 'body', 'post'], true)) {
        update_table('plugins', ['id' => $id, $opt => '1'], 'update');
    } elseif ($opt === 'all') {
        update_table('plugins', ['id' => $id, 'pre' => '1', 'head' => '1', 'body' => '1', 'post' => '1'], 'update');
    }
    queplugins();
}

function deactivateplugin(?string $plugin = null, ?string $opt = null): void {
    $plugin = $plugin ?? ($_GET['plugin'] ?? '');
    $opt = $opt ?? ($_GET['opt'] ?? 'all');
    if ($plugin === '') return;
    $rows = get_result('plugins', 1, ['id', 'name'], ['search' => ['name']], 1, [$plugin], true);
    if (empty($rows)) return;
    $id = $rows[0]['id'];
    if (in_array($opt, ['pre', 'head', 'body', 'post'], true)) {
        update_table('plugins', ['id' => $id, $opt => '0'], 'update');
    } elseif ($opt === 'all') {
        update_table('plugins', ['id' => $id, 'pre' => '0', 'head' => '0', 'body' => '0', 'post' => '0'], 'update');
    }
    queplugins();
}

/* ==========================================================================
 * 7. ADMIN SIDEBAR & FRONTEND NAVIGATION MENUS
 * ========================================================================== */

function add_menu_item(string $m, array $args, int $index = 0, ?string $parent = null, ?string $child = null): void {
    global $menu, $menu_index, $menu_ul;
    if (!isset($menu[$m])) $menu[$m] = [];

    if ($parent !== null && $child === null) {
        $parentIdx = $menu_index[$m][$parent] ?? null;
        if ($parentIdx !== null) {
            $subItems = $menu[$m][$parentIdx]['submenu']['item'] ?? [];
            $n = next_index($subItems, $index);
            $menu[$m][$parentIdx]['submenu']['item'][$n] = $args;
            if (isset($args['name'])) {
                $menu_index[$m . '_sub'][$parent][$args['name']] = $n;
            }
        }
    } elseif ($parent !== null && $child !== null) {
        $parentIdx = $menu_index[$m][$parent] ?? null;
        $childIdx  = $menu_index[$m . '_sub'][$parent][$child] ?? null;
        if ($parentIdx !== null && $childIdx !== null) {
            $subSub = $menu[$m][$parentIdx]['submenu']['item'][$childIdx]['subsubmenu']['item'] ?? [];
            $n = next_index($subSub, $index);
            $menu[$m][$parentIdx]['submenu']['item'][$childIdx]['subsubmenu']['item'][$n] = $args;
        }
    } else {
        $n = next_index($menu[$m], $index);
        $menu[$m][$n] = $args;
        if (isset($args['name'])) {
            $menu_index[$m][$args['name']] = $n;
            if (isset($args['submenu']['item']) && is_array($args['submenu']['item'])) {
                foreach ($args['submenu']['item'] as $i => $v) {
                    $subKey = $v['name'] ?? ($v['label'] ?? null);
                    if ($subKey !== null) $menu_index[$m . '_sub'][$args['name']][$subKey] = $i;
                }
            }
        }
    }
    foreach (['ulclass', 'ulopen', 'ulclose', 'ulid'] as $ulProp) {
        if (isset($args[$ulProp])) $menu_ul[$m][$ulProp] = $args[$ulProp];
    }
}

function display_menu(string $m): void {
    global $menu, $menu_ul;
    if (empty($menu[$m]) || !is_array($menu[$m])) return;
    ksort($menu[$m]);
    $ulCfg = $menu_ul[$m] ?? [];
    $out = ($ulCfg['ulopen'] ?? '') !== '' ? $ulCfg['ulopen'] . "\n" : '';
    $out .= '<ul';
    if (!empty($ulCfg['ulclass'])) $out .= ' class="' . esc_attr($ulCfg['ulclass']) . '"';
    if (!empty($ulCfg['ulid']))    $out .= ' id="' . esc_attr($ulCfg['ulid']) . '"';
    $out .= ">\n";

    foreach ($menu[$m] as $item) {
        $itemClass = $item['class'] ?? '';
        $hasLink   = isset($item['link']);
        $out .= !empty($item['open']) ? $item['open'] . "\n" : '';
        $out .= '<li' . ($itemClass !== '' ? " class='" . esc_attr($itemClass) . "'" : '') . ">\n";
        if ($itemClass === 'dropdown' && !$hasLink) {
            $out .= '<a class="dropdown-toggle" data-toggle="dropdown" href="#">';
        } elseif ($hasLink) {
            $out .= '<a href="' . esc_attr($item['link']) . '">';
        }
        if (!empty($item['icon']))  $out .= '<i class="' . esc_attr($item['icon']) . '"></i>';
        if (!empty($item['icon2'])) $out .= '<i class="' . esc_attr($item['icon2']) . '"></i>';
        $out .= $item['label'] ?? '';
        if (($itemClass === 'dropdown' && !$hasLink) || $hasLink) $out .= "</a>\n";
        if ($itemClass === 'dropdown' && $hasLink) {
            $out .= '<a class="dropdown-toggle" data-toggle="dropdown" href="#"><span class="fa arrow"></span></a>';
        }

        if (!empty($item['submenu']['item']) && is_array($item['submenu']['item'])) {
            ksort($item['submenu']['item']);
            $subClass = $item['submenu']['class'] ?? '';
            $out .= '<ul' . ($subClass !== '' ? ' class="' . esc_attr($subClass) . '"' : '') . ">\n";
            foreach ($item['submenu']['item'] as $sub) {
                $sClass = $sub['class'] ?? '';
                $out .= '<li' . ($sClass !== '' ? ' class="' . esc_attr($sClass) . '"' : '') . '>';
                if (isset($sub['link'])) $out .= '<a href="' . esc_attr($sub['link']) . '">';
                if (!empty($sub['icon'])) $out .= '<i class="' . esc_attr($sub['icon']) . '"></i>';
                $out .= $sub['label'] ?? '';
                if (isset($sub['link'])) $out .= '</a>';
                $out .= "</li>\n";
            }
            $out .= "</ul>\n";
        }
        $out .= "</li>\n";
    }
    $out .= "</ul>\n";
    if (!empty($ulCfg['ulclose'])) $out .= $ulCfg['ulclose'] . "\n";
    echo $out;
}

function buildMenu($parent, array $menu, string $sp = "\t", array $a = []): string {
    if (!isset($menu['parent_menus'][$parent])) return '';
    $html = !isset($a['items_wrap']) ? "<ul class=\"nav nav-tabs\" role=\"tablist\">\n" : '';
    $currentUrl = url();
    foreach ($menu['parent_menus'][$parent] as $menu_id) {
        $item  = $menu['menus'][$menu_id] ?? [];
        $link  = $item['link'] ?? '#';
        $title = $item['title'] ?? '';
        $label = $item['label'] ?? '';
        $linkHost = parse_url((string) $link, PHP_URL_HOST);
        if (function_exists('nexa_pretty_permalinks') && nexa_pretty_permalinks() && preg_match('/^\?p=(\d+)$/', trim((string) $link), $mm)) {
            $href = post_url($mm[1]); // upgrade legacy ?p=N menu links to the pretty structure
        } elseif (in_array($linkHost, ['localhost', '127.0.0.1'], true) && !in_array(parse_url(baseurl(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
            $href = rtrim(baseurl(), '/') . (parse_url((string) $link, PHP_URL_PATH) ?: '/'); // repair localhost-seeded absolute links
        } else {
            $href = (str_starts_with($link, 'http') || str_starts_with($link, '?') || str_starts_with($link, '/')) ? $link : home_url($link);
        }
        $href = nexa_preview_link($href);
        $active = ($href === $currentUrl || home_url($link) === $currentUrl) ? ' class="active"' : '';

        $html .= $sp . "<li><a href='" . esc_attr($href) . "' title='" . esc_attr($title) . "'{$active}>" . esc_html($label) . '</a>';
        if (isset($menu['parent_menus'][$menu_id]) && (($a['depth'] ?? 0) === 0 || ($a['depth'] ?? 0) > 1)) {
            $nextDepth = ($a['depth'] ?? 0) > 0 ? ($a['depth'] - 1) : 0;
            $html .= buildMenu($menu_id, $menu, "\t\t" . $sp, ['depth' => $nextDepth]);
        }
        $html .= "</li>\n";
    }
    if (!isset($a['items_wrap'])) $html .= "</ul>\n";
    return $html;
}

function di_ensure_menus_seeded(): void {
    if (!table_exists('menus')) {
        create_table('menus', ['name', 'displayed-text', 'created_at', 'updated_at', 'created_by', 'function']);
    }
    if (!table_exists('menu_items')) {
        create_table('menu_items', ['label', 'link', 'parent', 'sort', 'created_at', 'updated_at', 'class', 'menu', 'depth', 'open', 'title', 'order']);
    }
    if (count_records('menus') === 0) {
        update_table('menus', [
            'id'             => '1',
            'name'           => 'primary',
            'displayed-text' => 'Primary Navigation',
            'created_at'     => date('Y-m-d H:i:s'),
            'created_by'     => 'NexaCMS',
            'function'       => 'primary',
        ], 'insert');
    }
    if (count_records('menu_items') === 0) {
        $menus = get_table('menus')['data'];
        $menuId = $menus[0]['id'] ?? '1';
        multi_update_table('menu_items', [
            ['label' => 'Home',       'link' => home_url(),          'parent' => '0', 'menu' => (string) $menuId, 'order' => '1', 'title' => 'Home'],
            ['label' => 'About',      'link' => '?p=2',              'parent' => '0', 'menu' => (string) $menuId, 'order' => '2', 'title' => 'About NexaCMS'],
            ['label' => 'Shop',       'link' => '?p=4',              'parent' => '0', 'menu' => (string) $menuId, 'order' => '3', 'title' => 'Storefront'],
            ['label' => 'Gallery',    'link' => '?p=5',              'parent' => '0', 'menu' => (string) $menuId, 'order' => '4', 'title' => 'Media Gallery'],
            ['label' => 'Contact',    'link' => '?p=6',              'parent' => '0', 'menu' => (string) $menuId, 'order' => '5', 'title' => 'Contact Us'],
            ['label' => 'Admin',      'link' => home_url('admin/'),  'parent' => '0', 'menu' => (string) $menuId, 'order' => '6', 'title' => 'Admin Dashboard'],
        ], 'insert');
    }
}

function nexa_nav_menu(array $args = []) {
    static $menu_id_slugs = [];
    di_ensure_menus_seeded();

    $defaults = [
        'menu'            => '',
        'container'       => 'div',
        'container_class' => '',
        'container_id'    => '',
        'menu_class'      => 'menu',
        'menu_id'         => '',
        'echo'            => false,
        'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>',
        'depth'           => 0,
        'theme_location'  => 'primary',
    ];
    $a = array_merge($defaults, $args);
    $loc = $a['theme_location'] !== '' ? $a['theme_location'] : 'primary';

    $menusTable = get_table('menus');
    $targetMenu = null;
    if ((string) $a['menu'] !== '') {
        // Explicit menu wins: match by slug/name, or by numeric menu id.
        foreach ($menusTable['data'] as $row) {
            if (($row['name'] ?? '') === $a['menu'] || (is_numeric($a['menu']) && (string) ($row['id'] ?? '') === (string) $a['menu'])) {
                $targetMenu = $row;
                break;
            }
        }
    }
    if ($targetMenu === null) {
        foreach ($menusTable['data'] as $row) {
            if (($row['function'] ?? '') === $loc) {
                $targetMenu = $row;
                break;
            }
        }
    }
    if ($targetMenu === null && !empty($menusTable['data'])) {
        $targetMenu = $menusTable['data'][0];
    }

    $itemsTable = get_table('menu_items', 'order ASC');
    $menuTree = ['menus' => [], 'parent_menus' => []];
    foreach ($itemsTable['data'] as $row) {
        if ((string) ($row['menu'] ?? '') === (string) $targetMenu['id']) {
            $parentId = (($row['parent'] ?? '') === '' || $row['parent'] === null) ? '0' : (string) $row['parent'];
            $menuTree['menus'][$row['id']] = $row;
            $menuTree['parent_menus'][$parentId][] = $row['id'];
        }
    }
    if (empty($menuTree['menus'])) {
        // FALLBACK: no menu items exist yet - synthesize Home + published pages
        // so themes ALWAYS have a working top navigation bar.
        $menuTree = ['menus' => [], 'parent_menus' => []];
        $fid = 1;
        $addItem = static function (string $label, string $link, string $title) use (&$menuTree, &$fid): void {
            $id = 'fb' . ($fid++);
            $menuTree['menus'][$id] = ['id' => $id, 'label' => $label, 'link' => $link, 'title' => $title, 'parent' => '0'];
            $menuTree['parent_menus']['0'][] = $id;
        };
        $addItem('Home', home_url(), (string) get_bloginfo('name'));
        try {
            $postsTable = get_table('posts', 'id ASC');
            foreach (($postsTable['data'] ?? []) as $row) {
                if (($row['post_status'] ?? '') !== 'published' || ($row['post_type'] ?? '') !== 'page') continue;
                $addItem((string) ($row['post_title'] ?? ('Page ' . $row['id'])), '?p=' . $row['id'], (string) ($row['post_title'] ?? ''));
            }
        } catch (Throwable $e) { /* menu fallback is best-effort */ }
        if (($a['theme_location'] ?? 'primary') === 'primary') {
            $addItem('Admin', home_url('admin/'), 'Admin Dashboard');
        }
        $targetMenu = $targetMenu ?? ['name' => $loc];
    }

    $innerItems = "\n" . buildMenu('0', $menuTree, "\t", $a) . "\n";
    $wrapId = !empty($a['menu_id']) ? $a['menu_id'] : ('menu-' . ($targetMenu['name'] ?? 'primary'));
    while (in_array($wrapId, $menu_id_slugs, true)) {
        $wrapId .= '-1';
    }
    $menu_id_slugs[] = $wrapId;

    $ulHtml = sprintf($a['items_wrap'], esc_attr($wrapId), esc_attr($a['menu_class']), $innerItems) . "\n";
    $out = $ulHtml;
    if (is_string($a['container']) && in_array($a['container'], ['div', 'nav'], true)) {
        $idAttr = !empty($a['container_id']) ? ' id="' . esc_attr($a['container_id']) . '"' : '';
        $clsAttr = !empty($a['container_class']) ? ' class="' . esc_attr($a['container_class']) . '"' : '';
        $out = sprintf('<%1$s%2$s%3$s>' . "\n%4\$s</%1\$s>\n", esc_attr($a['container']), $idAttr, $clsAttr, $ulHtml);
    }
    if (!empty($a['echo'])) echo $out;
    return $out;
}
function up_nav_menu(array $args = []) { return nexa_nav_menu($args); }

/* ---- Per-page sidebar layout + [menu] shortcode --------------------------------
 * Every post/page has a "post_layout" field (Admin > edit page > "Sidebar on
 * This Page"): '' = theme default, or right / left / none to override the
 * theme's sidebar setting for that one page only. Themes resolve the final
 * position through nexa_sidebar_position(). */

function nexa_current_singular_post(): ?array {
    static $resolved = false;
    static $found = null;
    if ($resolved) return $found;
    $resolved = true;
    $id = 0;
    if (!empty($GLOBALS['post']['id']) && is_numeric($GLOBALS['post']['id'])) {
        $id = (int) $GLOBALS['post']['id'];
    } elseif (!empty($_GET['p']) && is_numeric($_GET['p'])) {
        $id = (int) $_GET['p']; // pretty-permalink shim populates ?p as well
    }
    if ($id > 0) {
        try {
            $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$id], true);
            if (!empty($rows[0])) $found = $rows[0];
        } catch (Throwable $e) { /* layout lookup is best-effort */ }
    }
    return $found;
}

function nexa_sidebar_position(string $fallback = 'right'): string {
    $post = nexa_current_singular_post();
    if ($post !== null) {
        $layout = strtolower(trim((string) ($post['post_layout'] ?? '')));
        if (in_array($layout, ['right', 'left', 'none'], true)) return $layout;
    }
    return function_exists('theme_setting') ? theme_setting('sidebar', $fallback) : $fallback;
}

/** One-time CSS for [menu] shortcode styles (injected with the first menu on the page). */
function nexa_menu_css(): string {
    $accent = function_exists('theme_setting') ? theme_setting('accent_color', '') : '';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#7c3aed';
    return '<style>'
        . '.nexa-menu{margin:12px 0}.nexa-menu ul{list-style:none;margin:0;padding:0}'
        . '.nexa-menu a{text-decoration:none;color:inherit}.nexa-menu a.active{font-weight:700}'
        . '.nexa-menu-h>ul{display:flex;flex-wrap:wrap;gap:2px}.nexa-menu-h li{position:relative}'
        . '.nexa-menu-h li>a{display:block;padding:8px 14px;border-radius:6px}.nexa-menu-h li>a:hover{background:rgba(0,0,0,.06)}'
        . '.nexa-menu-h li ul{display:none;position:absolute;left:0;top:100%;background:#fff;color:#222;min-width:200px;box-shadow:0 10px 28px rgba(0,0,0,.18);border-radius:8px;padding:6px 0;z-index:60}'
        . '.nexa-menu-h li:hover>ul{display:block}.nexa-menu-h li li>a{border-radius:0;padding:7px 14px}.nexa-menu-h li li ul{left:100%;top:0}'
        . '.nexa-menu-v li>a{display:block;padding:7px 10px;border-bottom:1px solid rgba(0,0,0,.08)}.nexa-menu-v li ul{padding-left:18px}'
        . '.nexa-menu-pills>ul{display:flex;flex-wrap:wrap;gap:6px}.nexa-menu-pills li>a{display:block;padding:7px 16px;border-radius:999px;background:rgba(0,0,0,.06)}'
        . '.nexa-menu-pills li>a:hover,.nexa-menu-pills a.active{background:' . $accent . ';color:#fff}.nexa-menu-pills li ul{padding-left:18px;margin-top:6px}'
        . '.nexa-menu-simple ul{list-style:disc;padding-left:22px}.nexa-menu-simple li ul{list-style:circle}'
        . '</style>';
}

/**
 * Render any menu anywhere. $opts: menu (id or slug/name), location
 * (theme location), style (horizontal|vertical|pills|simple), level
 * (depth limit: 1 = top level only, 0 = all levels), class (extra CSS class).
 */
function nexa_menu(array $opts = []): string {
    static $cssDone = false;
    $style = strtolower(trim((string) ($opts['style'] ?? 'horizontal')));
    $style = ['nav' => 'horizontal', 'bar' => 'horizontal', 'list' => 'vertical'][$style] ?? $style;
    if (!in_array($style, ['horizontal', 'vertical', 'pills', 'simple'], true)) $style = 'horizontal';
    $level = max(0, (int) ($opts['level'] ?? $opts['depth'] ?? 0));
    $args = [
        'container'       => 'div',
        'menu_class'      => 'nexa-menu-list',
        'depth'           => $level,
        'theme_location'  => (string) ($opts['location'] ?? 'primary'),
    ];
    $args['container_class'] = trim('nexa-menu nexa-menu-' . ($style === 'horizontal' ? 'h' : ($style === 'vertical' ? 'v' : $style)) . ' ' . (string) ($opts['class'] ?? ''));
    if ((string) ($opts['menu'] ?? '') !== '') $args['menu'] = (string) $opts['menu'];
    $html = nexa_nav_menu($args);
    if (!$cssDone) { $cssDone = true; $html = nexa_menu_css() . $html; }
    return $html;
}

/** [menu id="2" style="pills" level="1"] — also accepts name= and location= . */
function nexa_menu_shortcode($atts = []): string {
    $a = shortcode_atts(
        ['id' => '', 'name' => '', 'menu' => '', 'location' => '', 'style' => 'horizontal', 'level' => '0', 'class' => ''],
        array_change_key_case((array) $atts, CASE_LOWER)
    );
    $menu = (string) ($a['id'] !== '' ? $a['id'] : ($a['name'] !== '' ? $a['name'] : $a['menu']));
    return nexa_menu([
        'menu'     => $menu,
        'location' => (string) ($a['location'] !== '' ? $a['location'] : 'primary'),
        'style'    => (string) $a['style'],
        'level'    => (int) $a['level'],
        'class'    => (string) $a['class'],
    ]);
}
if (function_exists('add_shortcode')) {
    add_shortcode('menu', 'nexa_menu_shortcode');
}


/** Core's own editor builder for [menu], with the site's real menus as options. */
function nexa_register_core_editor_shortcodes(): void {
    static $done = false;
    if ($done || !function_exists('register_editor_shortcode')) return;
    $done = true;
    $menuOptions = [];
    try {
        foreach ((array) (get_table('menus')['data'] ?? []) as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') continue;
            $name = (string) ($row['name'] ?? ('Menu #' . $id));
            $location = (string) ($row['function'] ?? '');
            $menuOptions[] = ['value' => $id, 'label' => $name . ' (#' . $id . ($location !== '' ? ', ' . $location : '') . ')'];
        }
    } catch (Throwable $e) { /* menus may not exist during install */ }
    if ($menuOptions === []) $menuOptions = [['value' => '1', 'label' => 'Primary menu (#1)']];
    register_editor_shortcode([
        'id' => 'core-menu', 'plugin' => 'NexaCMS Core', 'plugin_slug' => 'core',
        'label' => 'Menu', 'tag' => 'menu', 'icon' => 'fa fa-bars',
        'description' => 'Insert any navigation menu anywhere in the content.',
        'fields' => [
            ['name' => 'id', 'label' => 'Menu', 'type' => 'select', 'options' => $menuOptions, 'default' => (string) $menuOptions[0]['value'], 'always' => true],
            ['name' => 'style', 'label' => 'Style', 'type' => 'select', 'default' => 'horizontal', 'always' => true, 'options' => ['horizontal' => 'Horizontal', 'vertical' => 'Vertical', 'pills' => 'Pills', 'simple' => 'Simple list']],
            ['name' => 'level', 'label' => 'Levels', 'type' => 'number', 'default' => '0', 'min' => '0', 'max' => '5', 'help' => '0 = all levels; 1 = top level only.'],
            ['name' => 'class', 'label' => 'Extra CSS class', 'type' => 'text', 'placeholder' => 'my-menu'],
        ],
        'template' => '[menu id="{id}" style="{style}" level="{level}" class="{class}"]',
    ]);
}

/* ==========================================================================
 * 8. CONTENT TYPES, POSTS, PAGES, SEARCH & THE NEXACMS LOOP
 * ========================================================================== */

function register_content_type(string $type, array $config = []): void {
    global $di_post_types;
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $type));
    if ($slug === '') return;
    $di_post_types[$slug] = array_merge([
        'label'    => ucfirst($slug) . 's',
        'singular' => ucfirst($slug),
        'public'   => true,
    ], $config);
}

function get_content_types(): array {
    global $di_post_types;
    return $di_post_types;
}

function di_post_fields(): array {
    return [
        'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_keywords',
        'post_desc', 'post_description', 'post_alias', 'post_title', 'post_thumb',
        'post_excerpt', 'post_status', 'comment_status', 'post_password', 'post_name',
        'post_format', 'to_ping', 'pinged', 'post_modified', 'post_content_filtered',
        'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'post_rating',
        'post_layout',
    ];
}

function di_ensure_posts_seeded(): void {
    if (!table_exists('posts')) {
        create_table('posts', di_post_fields());
    }
    if (function_exists('ensure_fields')) {
        try { ensure_fields('posts', ['post_layout']); } catch (Throwable $e) { /* best-effort migration */ }
    }
    if (count_records('posts') === 0) {
        $now = date('Y-m-d H:i:s');
        $gmt = (string) time();
        multi_update_table('posts', [
            [
                'id'            => '1',
                'post_author'   => 'Administrator',
                'post_date'     => $now,
                'post_date_gmt' => $gmt,
                'post_title'    => 'Welcome to NexaCMS 1.0',
                'post_name'     => 'welcome-to-nexacms',
                'post_alias'    => 'welcome-to-nexacms',
                'post_excerpt'  => 'Unified standalone PHP 8.4 CMS with polyglot SQL-over-anything storage (CSV, JSON, PHP Array, SQLite, MySQL, PostgreSQL) and 9 built-in plugins.',
                'post_content'  => '<p>Welcome to <strong>NexaCMS</strong>! This unified platform merges UPCMS and DiCimes into one clean, fast, standalone CMS.</p><ul><li><strong>Write SQL Once, Run Everywhere:</strong> Plugins call <code>query($sql, $params)</code> and NexaCMS executes it natively on SQLite/MySQL/PostgreSQL or translates it transparently to CSV, JSON, or PHP Array files.</li><li><strong>Stage-Scoped Plugins:</strong> Toggle plugins independently across <code>Pre</code>, <code>Head</code>, <code>Body</code>, and <code>Post</code> stages.</li><li><strong>9 Essential Built-In Plugins:</strong> Contact Inbox, SEO + Sitemap, Comments, Analytics, Lightbox Gallery, Speed Cache, Security Guard, Newsletter, and Storefront Cart!</li></ul><hr><h4>Subscribe to Our Newsletter</h4>[newsletter_form]',
                'post_status'   => 'published',
                'comment_status'=> 'open',
                'post_type'     => 'post',
                'guid'          => '?p=1',
                'menu_order'    => '1',
                'post_parent'   => '0',
            ],
            [
                'id'            => '2',
                'post_author'   => 'Administrator',
                'post_date'     => $now,
                'post_date_gmt' => $gmt,
                'post_title'    => 'About NexaCMS',
                'post_name'     => 'about',
                'post_alias'    => 'about',
                'post_excerpt'  => 'Learn how NexaCMS unifies flat-file simplicity with enterprise SQL scalability.',
                'post_content'  => '<h3>About NexaCMS</h3><p>NexaCMS features a clean <code>admin/</code>, <code>content/</code>, and <code>includes/</code> architecture with zero legacy framework bloat. Total site views tracked so far: <strong>[site_hits]</strong>.</p><h4>Popular Pages</h4>[popular_posts limit="5"]',
                'post_status'   => 'published',
                'comment_status'=> 'open',
                'post_type'     => 'page',
                'guid'          => '?p=2',
                'menu_order'    => '2',
                'post_parent'   => '0',
            ],
            [
                'id'            => '3',
                'post_author'   => 'Administrator',
                'post_date'     => $now,
                'post_date_gmt' => $gmt,
                'post_title'    => 'Polyglot SQL Translator & Stage-Scoped Plugins',
                'post_name'     => 'polyglot-sql-engine',
                'post_alias'    => 'polyglot-sql-engine',
                'post_excerpt'  => 'How plugins write standard SQL JOINs, GROUP BY, and aggregates once while running across 6 storage backends.',
                'post_content'  => '<p>Every NexaCMS plugin writes standard SQL via <code>query()</code> — including <code>LEFT JOIN</code>, <code>GROUP BY</code>, <code>HAVING</code>, <code>COUNT/SUM/AVG</code>, <code>BETWEEN</code>, and prepared <code>?</code> / <code>:named</code> parameters — and works identically on CSV, JSON, PHP Array, SQLite, MySQL, and PostgreSQL.</p>',
                'post_status'   => 'published',
                'comment_status'=> 'open',
                'post_type'     => 'post',
                'guid'          => '?p=3',
                'menu_order'    => '3',
                'post_parent'   => '0',
            ],
            [
                'id'            => '4',
                'post_author'   => 'Administrator',
                'post_date'     => $now,
                'post_date_gmt' => $gmt,
                'post_title'    => 'NexaCMS Storefront',
                'post_name'     => 'shop',
                'post_alias'    => 'shop',
                'post_excerpt'  => 'Browse products and test the built-in Nexa Shop catalog & session cart.',
                'post_content'  => '[shop_cart][shop_catalog]',
                'post_status'   => 'published',
                'comment_status'=> 'closed',
                'post_type'     => 'page',
                'guid'          => '?p=4',
                'menu_order'    => '4',
                'post_parent'   => '0',
            ],
            [
                'id'            => '5',
                'post_author'   => 'Administrator',
                'post_date'     => $now,
                'post_date_gmt' => $gmt,
                'post_title'    => 'Media Gallery',
                'post_name'     => 'gallery',
                'post_alias'    => 'gallery',
                'post_excerpt'  => 'Responsive media gallery with built-in lightbox viewer.',
                'post_content'  => '<p>Click any image below to open the responsive lightbox preview:</p>[gallery]',
                'post_status'   => 'published',
                'comment_status'=> 'closed',
                'post_type'     => 'page',
                'guid'          => '?p=5',
                'menu_order'    => '5',
                'post_parent'   => '0',
            ],
            [
                'id'            => '6',
                'post_author'   => 'Administrator',
                'post_date'     => $now,
                'post_date_gmt' => $gmt,
                'post_title'    => 'Contact Us',
                'post_name'     => 'contact',
                'post_alias'    => 'contact',
                'post_excerpt'  => 'Send us a message directly into the Admin Contact Inbox.',
                'post_content'  => '<p>Have a question or feedback? Send us a message using the form below:</p>[contact_form]',
                'post_status'   => 'published',
                'comment_status'=> 'closed',
                'post_type'     => 'page',
                'guid'          => '?p=6',
                'menu_order'    => '6',
                'post_parent'   => '0',
            ],
        ], 'insert');
    }
}

function post_url($id): string {
    $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$id], true);
    if (empty($rows[0])) return nexa_preview_link('?p=' . urlencode((string) $id));
    return nexa_preview_link(nexa_build_permalink($rows[0]));
}

function get_page_url(array $a) {
    di_ensure_posts_seeded();
    if (isset($a['id'])) {
        $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$a['id']], true);
        if (!empty($rows[0])) return nexa_preview_link(nexa_build_permalink($rows[0]));
    }
    if (isset($a['post_name'])) {
        $rows = get_result('posts', 1, ['*'], ['search' => ['post_name']], 1, [$a['post_name']], true);
        if (!empty($rows[0])) return nexa_preview_link(nexa_build_permalink($rows[0]));
    }
    return false;
}

function getpostbypermalink(string $permalink) {
    $id = get_page_id(['url' => $permalink]);
    return $id ? list_posts($id) : false;
}

function get_page_id(array $a = []) {
    global $settings, $allids;
    di_ensure_posts_seeded();

    if (isset($_GET['p']) && $_GET['p'] !== '') {
        return $_GET['p'];
    }

    $reqPath = parse_url($a['url'] ?? url(), PHP_URL_PATH) ?: '/';
    $basePath = parse_url(baseurl(), PHP_URL_PATH) ?: '/';
    $relSlug = trim(substr($reqPath, strlen(rtrim($basePath, '/'))), '/');

    $t = get_table('posts');
    $allids = array_column($t['data'], 'id');

    if ($relSlug === '' || $relSlug === 'index.php') {
        return $settings['page_on_front']['value'] ?? '1';
    }

    // Pretty permalinks: match the full path, or just its last segment
    // (covers /%year%/%monthnum%/%postname%/ and similar structures).
    $segments  = array_values(array_filter(explode('/', $relSlug), static fn($s) => $s !== ''));
    $lastSeg   = $segments ? (string) end($segments) : '';
    $matchKeys = array_unique(array_filter([$relSlug, $lastSeg]));
    foreach ($t['data'] as $row) {
        $slugCandidates = array_filter([
            $row['post_alias'] ?? '',
            $row['post_name'] ?? '',
            str_replace([' ', '_'], '-', (string) ($row['post_name'] ?? '')),
            function_exists('nexa_post_slug') ? nexa_post_slug($row) : '',
        ]);
        foreach ($matchKeys as $key) {
            if (in_array($key, $slugCandidates, true)) {
                // Shim: populate $_GET['p'] so the loop, themes and plugins
                // (which all detect single posts via $_GET['p']) work as-is.
                if (empty($a) && !isset($_GET['p'])) {
                    $_GET['p'] = $row['id'];
                    $GLOBALS['nexa_pretty_resolved'] = $row['id'];
                }
                return $row['id'];
            }
        }
    }
    // /%post_id%/ style: a trailing numeric segment is the post ID.
    if ($lastSeg !== '' && ctype_digit($lastSeg)) {
        foreach ($t['data'] as $row) {
            if ((string) $row['id'] === $lastSeg) {
                if (empty($a) && !isset($_GET['p'])) {
                    $_GET['p'] = $row['id'];
                    $GLOBALS['nexa_pretty_resolved'] = $row['id'];
                }
                return $row['id'];
            }
        }
    }
    return false;
}

function update_post(array $args, string $do = 'insert') {
    di_ensure_posts_seeded();
    $id = update_table('posts', $args, $do);
    if (empty($args['guid']) && $id) {
        update_table('posts', ['id' => $id, 'guid' => '?p=' . $id], 'update');
    }
    do_action('post_saved', $id, $args, $do);
    return $id;
}

function list_posts($id = null) {
    global $page, $allids;
    di_ensure_posts_seeded();
    $t = get_table('posts');
    $posts = [];
    foreach ($t['data'] as $row) {
        $posts[$row['id']] = $row;
    }
    $allids = array_keys($posts);
    if ($id !== null) return $posts[$id] ?? null;
    if (isset($page) && isset($posts[$page])) return $posts[$page];
    return $posts;
}

function is_search(): bool {
    global $search;
    foreach (['search', 's', 'q'] as $param) {
        if (isset($_GET[$param]) && trim((string) $_GET[$param]) !== '') {
            $search = trim((string) $_GET[$param]);
            return true;
        }
    }
    return false;
}

function is_admin(): bool {
    return !empty($GLOBALS['adminarea']);
}

function no_posts(): array {
    return [
        'id'            => -1,
        'post_author'   => 'System',
        'post_date'     => date('Y-m-d'),
        'post_date_gmt' => (string) time(),
        'post_title'    => 'No Posts Found',
        'post_name'     => 'no-posts',
        'post_type'     => 'error',
        'post_thumb'    => '',
        'post_excerpt'  => 'No matching posts were found.',
        'post_content'  => '<p>No posts matched your criteria.</p>',
    ];
}

function get_404(): array {
    return [
        'id'            => -404,
        'post_author'   => 'System',
        'post_date'     => date('Y-m-d'),
        'post_date_gmt' => (string) time(),
        'post_title'    => '404 - Page Not Found',
        'post_name'     => '404',
        'post_type'     => 'error',
        'post_thumb'    => '',
        'post_excerpt'  => 'The requested page could not be found.',
        'post_content'  => '<div class="jumbotron"><h2>404 - Article Not Found</h2><p>We could not find the page you requested. Try searching below:</p>' . get_search_form() . '</div>',
    ];
}

/* ==========================================================================
 * ERROR PAGES (404 / 403 / 500 / 503-offline)
 * Resolution order: the active theme's own template (<status>.php, then
 * offline.php for 503, then error.php) wins; otherwise the
 * 'nexa_error_page_custom' filter (Nexa Error Pages plugin) supplies
 * admin-created content rendered inside the theme header/footer; otherwise
 * the caller falls back to the legacy behaviour. Returns true when a page
 * was rendered (the caller should then stop).
 * ======================================================================== */
function nexa_error_defaults(int $status): array {
    return match ($status) {
        403 => ['title' => '403 — Access Forbidden', 'message' => 'You do not have permission to view this page. If you believe this is a mistake, please contact the site owner.'],
        500 => ['title' => '500 — Something Went Wrong', 'message' => 'The server ran into a problem and could not complete your request. Please try again in a few minutes.'],
        503 => ['title' => 'We Will Be Right Back', 'message' => 'The site is temporarily offline for maintenance. Please check back shortly.'],
        default => ['title' => '404 — Page Not Found', 'message' => 'We could not find the page you requested. It may have been moved, renamed or removed. Try searching below, or head back to the homepage.'],
    };
}

function nexa_error_template_file(int $status): string {
    $dir = get_template_directory();
    $candidates = [(string) $status . '.php'];
    if ($status === 503) $candidates[] = 'offline.php';
    $candidates[] = 'error.php';
    foreach ($candidates as $f) if (is_file($dir . $f)) return $dir . $f;
    return '';
}

/** Recent-posts list used by error templates (returns HTML). */
function nexa_error_recent_html(int $count = 5): string {
    try {
        $rows = array_slice(array_values(array_filter(
            get_result('posts', 1, ['id', 'post_title', 'post_status'], ['by' => 'id', 'order' => 'DESC'], 12),
            static fn($r) => ($r['post_status'] ?? '') === 'published'
        )), 0, $count);
    } catch (Throwable $e) { $rows = []; }
    if (!$rows) return '';
    $out = '<div class="nx-err-recent"><h3>Recent posts</h3><ul>';
    foreach ($rows as $r) $out .= '<li><a href="' . esc_url(post_url($r['id'])) . '">' . esc_html($r['post_title'] ?? '') . '</a></li>';
    return $out . '</ul></div>';
}

function nexa_render_error_page(int $status, string $title = '', string $message = ''): bool {
    $d = nexa_error_defaults($status);
    $GLOBALS['nexa_error'] = [
        'status'  => $status,
        'title'   => $title !== '' ? $title : $d['title'],
        'message' => $message !== '' ? $message : $d['message'],
    ];
    if (!headers_sent()) http_response_code($status);
    if ($status === 503 && !headers_sent()) header('Retry-After: 3600');

    // Error pages can render before load_theme() runs, so make sure the
    // theme's own functions.php (helpers its header/footer rely on) is in.
    $themeDir = get_template_directory();
    if (is_file($themeDir . 'functions.php')) include_once $themeDir . 'functions.php';
    $GLOBALS['theme_dir_url'] = get_template_directory_uri();

    $tpl = nexa_error_template_file($status);
    if ($tpl !== '') { include $tpl; return true; }

    $custom = apply_filters('nexa_error_page_custom', null, $status);
    if (is_array($custom)) {
        $e = $GLOBALS['nexa_error'];
        if (!empty($custom['title'])) $e['title'] = (string) $custom['title'];
        $body = '<main class="nx-err-custom" style="max-width:760px;margin:48px auto;padding:24px;text-align:center;">'
            . '<div style="font-size:64px;font-weight:800;line-height:1;opacity:.25;">' . (int) $status . '</div>'
            . '<h1>' . esc_html($e['title']) . '</h1>';
        if (!empty($custom['html'])) $body .= '<div class="nx-err-body">' . do_shortcode((string) $custom['html']) . '</div>';
        elseif (!empty($custom['message']) || true) $body .= '<p>' . esc_html((string) ($custom['message'] ?? $e['message'])) . '</p>';
        if ($status === 404 && !empty($custom['show_search'])) $body .= get_search_form();
        if ($status === 404 && !empty($custom['show_recent'])) $body .= nexa_error_recent_html();
        $body .= '<p style="margin-top:22px;"><a class="button" href="' . esc_url(home_url()) . '">&larr; Back to the homepage</a></p></main>';
        if (!empty($custom['retry_after']) && $status === 503 && !headers_sent()) header('Retry-After: ' . max(60, (int) $custom['retry_after']));
        get_header();
        echo $body;
        get_footer();
        return true;
    }
    return false;
}

function nexa_reset_loop(): void {
    unset($GLOBALS['di_loop_state']);
}

function have_posts(): bool {
    global $page, $settings, $post, $maxpage, $maxpost, $search;

    if (!isset($GLOBALS['di_loop_state'])) {
        di_ensure_posts_seeded();
        if (is_search()) {
            $matches = get_result(
                'posts',
                1,
                ['*'],
                ['by' => 'id', 'order' => 'DESC', 'search' => ['post_title', 'post_content', 'post_excerpt', 'post_keywords']],
                -1,
                [$search],
                false
            );
            $published = array_values(array_filter($matches, static fn($p) => ($p['post_status'] ?? '') === 'published'));
            if (empty($published)) $published = [no_posts()];
            $GLOBALS['di_loop_state'] = ['items' => $published, 'index' => -1, 'count' => count($published)];
        } elseif (!isset($_GET['p']) && (((string) $page === (string) ($settings['page_for_posts']['value'] ?? '1')) || (($settings['show_on_front']['value'] ?? 'posts') === 'posts'))) {
            $pp = max(1, (int) ($_GET['page'] ?? 1));
            $pagelimit = max(1, (int) ($settings['posts_per_page']['value'] ?? 10));
            $allPostRows = get_result('posts', 1, ['*'], ['by' => 'id', 'order' => 'DESC', 'search' => ['post_type']], -1, ['post'], true);
            $published = array_values(array_filter($allPostRows, static fn($p) => ($p['post_status'] ?? '') === 'published'));
            $maxpage = max(1, (int) ceil(count($published) / $pagelimit));
            $items = array_slice($published, ($pp - 1) * $pagelimit, $pagelimit);
            foreach ($items as &$it) {
                if (($it['post_excerpt'] ?? '') === '' && !empty($it['post_content'])) {
                    $wrapped = explode("\n", wordwrap(strip_tags((string) $it['post_content']), 280, "\n", true));
                    $it['post_excerpt'] = $wrapped[0] . '...';
                }
            }
            unset($it);
            if (empty($items)) $items = [no_posts()];
            $GLOBALS['di_loop_state'] = ['items' => $items, 'index' => -1, 'count' => count($items)];
        } else {
            if ($page === false || $page === '' || $page === null) {
                $items = [get_404()];
            } else {
                $found = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$page], true);
                $items = [!empty($found[0]) ? $found[0] : get_404()];
            }
            $GLOBALS['di_loop_state'] = ['items' => $items, 'index' => -1, 'count' => 1];
        }
    }

    $state = &$GLOBALS['di_loop_state'];
    if (!empty($state['peeked'])) {
        $state['peeked'] = false;
        return $state['index'] < $state['count'];
    }
    if ($state['index'] + 1 < $state['count']) {
        $state['index']++;
        $post = $state['items'][$state['index']];
        $maxpost = $state['count'];
        if ($state['index'] === 0 && empty($state['started_while'])) {
            $state['started_while'] = true;
            $state['peeked'] = true;
        }
        return true;
    }
    return false;
}

function the_post(): void {
    global $post;
    if (isset($GLOBALS['di_loop_state'])) {
        $GLOBALS['di_loop_state']['peeked'] = false;
        $idx = max(0, $GLOBALS['di_loop_state']['index']);
        if (isset($GLOBALS['di_loop_state']['items'][$idx])) {
            $post = $GLOBALS['di_loop_state']['items'][$idx];
        }
    }
}

function the_ID(): string {
    global $post;
    return (string) ($post['id'] ?? '');
}

function the_title(bool $echo = false): string {
    global $post;
    $t = (string) ($post['post_title'] ?? '');
    if ($echo) echo esc_html($t);
    return $t;
}

function the_content(bool $echo = true): string {
    global $post;
    $rendered = apply_filters('the_content', do_shortcode((string) ($post['post_content'] ?? '')));
    if ($echo) echo $rendered;
    return $rendered;
}

function get_the_excerpt(): string {
    global $post;
    return (string) ($post['post_excerpt'] ?? '');
}

function the_permalink(): string {
    global $post;
    return post_url($post['id'] ?? 0);
}

function has_post_thumbnail(): bool {
    global $post;
    return !empty($post['post_thumb']);
}

function get_post_format(): string {
    global $post;
    return (string) ($post['post_format'] ?? 'standard');
}

function post_class(): string {
    return 'nexa-post';
}

function single_post_title(): void {
    echo esc_html(get_page_title());
}

function get_page_title($p = null): string {
    global $page, $settings, $search;
    if (is_search()) return 'Search: ' . $search;
    if (!empty($GLOBALS['nexa_posts_page'])) {
        $prows = get_result('posts', 1, ['id', 'post_title'], ['search' => ['id']], 1, [$GLOBALS['nexa_posts_page']], true);
        $ptitle = (string) ($prows[0]['post_title'] ?? '');
        return $ptitle !== '' ? $ptitle : 'Blog';
    }
    $p = $p ?? $page;
    if (!isset($_GET['p']) && (string) $p === (string) ($settings['page_for_posts']['value'] ?? '1')) {
        return 'Blog';
    }
    $rows = get_result('posts', 1, ['id', 'post_title'], ['search' => ['id']], 1, [$p], true);
    return (string) ($rows[0]['post_title'] ?? '');
}

function get_page_keywords($p = null): string {
    global $page;
    $p = $p ?? $page;
    $rows = get_result('posts', 1, ['id', 'post_keywords'], ['search' => ['id']], 1, [$p], true);
    return (string) ($rows[0]['post_keywords'] ?? '');
}

function is_home(): bool {
    global $page, $settings;
    return !isset($_GET['p']) && !is_search() && ((string) $page === (string) ($settings['page_for_posts']['value'] ?? '1') || ($settings['show_on_front']['value'] ?? 'posts') === 'posts');
}

function is_front_page(): bool {
    return !isset($_GET['p']) && !is_search();
}

function countlikes($id = null): int {
    return $id === null ? count_records('likes') : count_records('likes', 'postid', $id);
}

if (!function_exists('countcomments')) {
    function countcomments($id = null): int {
        if (!table_exists('comments')) return 0;
        return $id === null ? count_records('comments', 'approved', '1') : count(query('SELECT id FROM comments WHERE postid = ? AND approved = ?', [(string) $id, '1']));
    }
}

/* ==========================================================================
 * 9. CLIENTS, COMMENTS & MESSAGES HELPERS (UNIFIED FROM UPCMS + DICIMES)
 * ========================================================================== */

function nexa_ensure_clients_table(): void {
    if (!table_exists('customers')) {
        create_table('customers', ['f_name', 'l_name', 'gender', 'email', 'phone', 'address', 'state', 'date_of_birth', 'created_at', 'updated_at']);
    }
}

function addclient(array $args) {
    nexa_ensure_clients_table();
    $args['created_at'] = $args['created_at'] ?? date('Y-m-d H:i:s');
    return update_table('customers', $args, 'insert');
}

function updateclient(array $args) {
    nexa_ensure_clients_table();
    $args['updated_at'] = date('Y-m-d H:i:s');
    return update_table('customers', $args, 'update');
}

function delclient($id): int {
    nexa_ensure_clients_table();
    $target = is_array($id) ? ($id['id'] ?? '') : $id;
    return delete_records('customers', $target);
}

function numclient(): int {
    nexa_ensure_clients_table();
    return count_records('customers');
}

function get_all_clients(): array {
    nexa_ensure_clients_table();
    return get_table('customers')['data'];
}

function get_client($id): ?array {
    nexa_ensure_clients_table();
    $rows = get_result('customers', 1, ['*'], ['search' => ['id']], 1, [$id], true);
    return $rows[0] ?? null;
}

function updatecomment(array $args, string $do = 'insert') {
    if (!table_exists('comments')) {
        create_table('comments', ['postid', 'avatar', 'name', 'url', 'email', 'body', 'dt', 'approved']);
    }
    $args['dt']       = $args['dt']       ?? date('Y-m-d H:i:s');
    $args['approved'] = $args['approved'] ?? '1';
    return update_table('comments', $args, $do);
}

function delcomment($id): int {
    if (!table_exists('comments')) return 0;
    $target = is_array($id) ? ($id['id'] ?? '') : $id;
    return delete_records('comments', $target);
}

function nexa_ensure_messages_table(): void {
    if (!table_exists('pm')) {
        create_table('pm', ['sender', 'recipient', 'subject', 'body', 'status', 'is_spam', 'is_read', 'created_at']);
    }
}

function updatemessage(array $args, string $do = 'insert') {
    nexa_ensure_messages_table();
    $args['created_at'] = $args['created_at'] ?? date('Y-m-d H:i:s');
    $args['status']     = $args['status']     ?? 'inbox';
    $args['is_spam']    = $args['is_spam']    ?? '0';
    $args['is_read']    = $args['is_read']    ?? '0';
    return update_table('pm', $args, $do);
}

function replyto($id): ?array {
    nexa_ensure_messages_table();
    $rows = get_result('pm', 1, ['*'], ['search' => ['id']], 1, [$id], true);
    return $rows[0] ?? null;
}

function delmessage($id): int {
    nexa_ensure_messages_table();
    return delete_records('pm', $id);
}

function trashmessage($id): void {
    nexa_ensure_messages_table();
    update_table('pm', ['id' => $id, 'status' => 'trash'], 'update');
}

function untrashmessage($id): void {
    nexa_ensure_messages_table();
    update_table('pm', ['id' => $id, 'status' => 'inbox'], 'update');
}

function spam($id): void {
    nexa_ensure_messages_table();
    update_table('pm', ['id' => $id, 'is_spam' => '1'], 'update');
}

function unspam($id): void {
    nexa_ensure_messages_table();
    update_table('pm', ['id' => $id, 'is_spam' => '0'], 'update');
}

function unread($id): void {
    nexa_ensure_messages_table();
    update_table('pm', ['id' => $id, 'is_read' => '0'], 'update');
}

/* ==========================================================================
 * 10. THEMES, TEMPLATE TAGS & AJAX
 * ========================================================================== */

function get_template_directory(): string {
    global $settings;
    $theme = function_exists('nexa_multisite_forced_theme') && nexa_multisite_forced_theme() !== '' ? nexa_multisite_forced_theme() : basename((string) ($settings['template']['value'] ?? 'multi'));
    return nexa_resolve_theme_path($theme) . '/';
}

function get_template_directory_uri(): string {
    global $settings;
    $theme = function_exists('nexa_multisite_forced_theme') && nexa_multisite_forced_theme() !== '' ? nexa_multisite_forced_theme() : basename((string) ($settings['template']['value'] ?? 'multi'));
    return home_url(nexa_resolve_theme_rel($theme) . '/');
}

function load_theme(): void {
    global $settings, $theme_dir_url;
    if (!empty($_REQUEST['theme'])) {
        $candidate = basename((string) $_REQUEST['theme']);
        if (is_dir(nexa_resolve_theme_path($candidate))) {
            $settings['template']['value'] = $candidate;
        }
    }
    $dir = get_template_directory();
    if (!is_dir($dir)) {
        $settings['template']['value'] = 'multi';
        $dir = get_template_directory();
    }
    $theme_dir_url = get_template_directory_uri();
    if (is_file($dir . 'functions.php')) {
        include_once $dir . 'functions.php';
    }
    if (is_file($dir . 'index.php')) {
        include_once $dir . 'index.php';
    }
}

function get_header(): void {
    $dir = get_template_directory();
    foreach (['header.php', 'Header.php'] as $f) {
        if (is_file($dir . $f)) {
            include_once $dir . $f;
            return;
        }
    }
}

function get_sidebar(): void {
    $f = get_template_directory() . 'sidebar.php';
    if (is_file($f)) include_once $f;
}

function get_footer(): void {
    $f = get_template_directory() . 'footer.php';
    if (is_file($f)) include_once $f;
}

function get_template_part(string $slug, ?string $name = null): void {
    $dir = get_template_directory();
    if ($name !== null && $name !== '' && is_file("{$dir}{$slug}-{$name}.php")) {
        include "{$dir}{$slug}-{$name}.php";
        return;
    }
    if (is_file("{$dir}{$slug}.php")) {
        include "{$dir}{$slug}.php";
    }
}

function nexa_head(): void {
    global $head;
    do_action('up_head');
    do_action('nexa_head');
    echo (string) ($head ?? '');
    // Universal colour knobs (Admin > Appearance > Live Theme Editor)
    if (function_exists('nexa_theme_knobs_css')) echo nexa_theme_knobs_css();
    // Per-theme Custom CSS (Admin > Appearance > Theme Settings)
    if (function_exists('theme_setting')) {
        $customCss = trim(theme_setting('custom_css', ''));
        if ($customCss !== '') {
            echo "\n<style>\n" . str_ireplace('</style', '<\\/style', $customCss) . "\n</style>\n";
        }
    }
}
function up_head(): void { nexa_head(); }

function nexa_body(): void {
    global $body;
    do_action('up_body');
    do_action('nexa_body');
    echo (string) ($body ?? '');
}
function up_body(): void { nexa_body(); }

function nexa_sidebar(): void {
    global $sidebar;
    do_action('sidebar');
    if (is_string($sidebar)) echo $sidebar;
}
function up_sidebar(): void { nexa_sidebar(); }

function nexa_post(): void {
    global $post;
    do_action('up_post');
    do_action('nexa_post');
    if (is_string($post)) echo $post;
}
function up_post(): void { nexa_post(); }

function nexa_footer(): void {
    global $footer;
    do_action('up_footer');
    do_action('nexa_footer');
    echo (string) ($footer ?? '');
}
function up_footer(): void { nexa_footer(); }

function nexa_admin(): void {
    do_action('nexa_admin');
    do_action('di_admin');
    do_action('up_admin');
}
function di_admin(): void { nexa_admin(); }
function up_admin(): void { nexa_admin(); }

function get_bloginfo(string $info = 'name'): string {
    return match ($info) {
        'charset'     => (string) get_setting('blog_charset', 'UTF-8'),
        'name'        => (string) get_setting('blogname', 'NexaCMS'),
        'description' => (string) get_setting('blogdescription', ''),
        'url', 'home' => home_url(),
        default       => (string) get_setting($info, ''),
    };
}

function bloginfo(string $info = 'name'): void {
    echo esc_html(get_bloginfo($info));
}

function site_info(string $key = 'name', bool $echo = true): string {
    $val = get_bloginfo($key);
    if ($echo) echo esc_html($val);
    return $val;
}

function get_search_form(): string {
    return '<form class="form-inline" method="get" action="' . esc_attr(home_url()) . '">
      <div class="input-group">
        <input type="text" class="form-control" name="s" placeholder="Search..." value="' . esc_attr($_GET['s'] ?? $_GET['search'] ?? '') . '">
        <span class="input-group-btn"><button class="btn btn-primary" type="submit">Search</button></span>
      </div>
    </form>';
}

function the_posts_navigation(): void {
    global $maxpage;
    $curr = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) ($maxpage ?? 1);
    if ($total <= 1) return;
    echo '<ul class="pagination">';
    for ($i = 1; $i <= $total; $i++) {
        $cls = ($i === $curr) ? ' class="active"' : '';
        echo "<li{$cls}><a href=\"?page={$i}\">{$i}</a></li>";
    }
    echo '</ul>';
}

function queajax(): void {
    if (!empty($_GET['js'])) {
        $jsName = basename((string) $_GET['js']);
        $jsPath = __DIR__ . '/' . $jsName . '.js';
        if (is_file($jsPath)) {
            header('Content-Type: application/javascript; charset=UTF-8');
            readfile($jsPath);
        }
    }
}

/* ==========================================================================
 * 11. SERIALIZATION, FORM BUTTONS, ZIP BACKUP & PACKAGE UPLOAD
 * ========================================================================== */

function is_serialized($data, bool $strict = true): bool {
    if (!is_string($data)) return false;
    $data = trim($data);
    if ($data === 'N;') return true;
    if (strlen($data) < 4 || $data[1] !== ':') return false;
    if ($strict) {
        $lastc = substr($data, -1);
        if ($lastc !== ';' && $lastc !== '}') return false;
    }
    return in_array($data[0], ['s', 'a', 'O', 'b', 'i', 'd'], true);
}

function is_serialized_string($data): bool {
    return is_string($data) && str_starts_with(trim($data), 's:') && is_serialized($data);
}

function maybe_serialize($data) {
    return (is_array($data) || is_object($data)) ? serialize($data) : $data;
}

function maybe_unserialize($original) {
    return is_serialized($original) ? @unserialize($original, ['allowed_classes' => false]) : $original;
}

function disabled($disabled, $current = true, bool $echo = true): string {
    $res = ((string) $disabled === (string) $current) ? " disabled='disabled'" : '';
    if ($echo) echo $res;
    return $res;
}

function get_submit_button(?string $text = 'Save Changes', $type = 'primary', string $name = 'submit', bool $wrap = true): string {
    $label = ($text !== null && $text !== '') ? $text : 'Save Changes';
    $btn = '<button type="submit" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" class="btn btn-primary">' . esc_html($label) . '</button>';
    return $wrap ? '<p class="submit">' . $btn . '</p>' : $btn;
}

function submit_button(?string $text = 'Save Changes', $type = 'primary', string $name = 'submit', bool $wrap = true): void {
    echo get_submit_button($text, $type, $name, $wrap);
}

function recurse_copy(string $src, string $dst): void {
    @mkdir($dst, 0755, true);
    foreach (scandir($src) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $s = "{$src}/{$file}";
        $d = "{$dst}/{$file}";
        if (is_dir($s)) recurse_copy($s, $d);
        else copy($s, $d);
    }
}

/**
 * Structural validation for uploaded packages. Returns an error message when
 * the extracted archive is not a plausible NexaCMS plugin/theme, null when OK.
 * This stops random zips (image bundles, the wrong package type, source
 * archives, ...) from being "installed" into content/plugins|themes.
 */
function nexa_validate_package(string $type, string $dir): ?string {
    if ($type === 'plugin') {
        $hasThemeFiles = is_file($dir . '/index.php') && is_file($dir . '/style.css');
        if (!is_file($dir . '/plugin.json')) {
            return $hasThemeFiles
                ? 'This archive looks like a <strong>theme</strong> (it has index.php + style.css but no plugin.json). Please install it via <strong>Appearance &rarr; Themes &rarr; Upload Theme</strong> instead.'
                : 'Not a valid NexaCMS plugin: <code>plugin.json</code> is missing from the package. A plugin zip must contain plugin.json (with at least a <code>name</code>) plus PHP files such as functions.php.';
        }
        $meta = json_decode((string) file_get_contents($dir . '/plugin.json'), true);
        if (!is_array($meta) || trim((string) ($meta['name'] ?? '')) === '') {
            return 'Not a valid NexaCMS plugin: <code>plugin.json</code> is unreadable or has no <code>name</code>.';
        }
        foreach (['functions.php', 'admin.php', 'pre.php', 'head.php', 'body.php', 'post.php'] as $f) {
            if (is_file($dir . '/' . $f)) return null;
        }
        return 'Not a valid NexaCMS plugin: no PHP entry file found (expected functions.php or a stage file such as head.php).';
    }
    // theme
    if (!is_file($dir . '/index.php') || !is_file($dir . '/style.css')) {
        if (is_file($dir . '/plugin.json')) {
            return 'This archive looks like a <strong>plugin</strong> (it has plugin.json but no theme index.php + style.css). Please install it via <strong>Plugins &rarr; Upload Plugin</strong> instead.';
        }
        return 'Not a valid NexaCMS theme: the package must contain <code>index.php</code> and <code>style.css</code> at its root (optionally wrapped in one folder).';
    }
    return null;
}

/** Human-readable "Name v1.2.3" for a validated package, for the success message. */
function nexa_package_label(string $type, string $dir, string $slug): string {
    if ($type === 'plugin') {
        $meta = json_decode((string) @file_get_contents($dir . '/plugin.json'), true);
        if (is_array($meta) && trim((string) ($meta['name'] ?? '')) !== '') {
            return esc_html((string) $meta['name']) . (trim((string) ($meta['version'] ?? '')) !== '' ? ' v' . esc_html((string) $meta['version']) : '');
        }
        return esc_html($slug);
    }
    $css = (string) @file_get_contents($dir . '/style.css');
    if (preg_match('/Theme Name:\s*(.+)/i', $css, $m)) {
        $label = esc_html(trim($m[1]));
        if (preg_match('/Version:\s*([0-9][^\s*]*)/i', $css, $v)) $label .= ' v' . esc_html($v[1]);
        return $label;
    }
    return esc_html($slug);
}

function nexa_remove_dir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $path = $dir . '/' . $e;
        is_dir($path) ? nexa_remove_dir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function upload(string $type = 'plugin', ?string $destParent = null): void {
    global $include_dir;
    if (!in_array($type, ['plugin', 'theme'], true)) {
        echo errorMessage('Invalid upload target.');
        return;
    }
    // Server-side (network-approval) installs arrive with a local file and
    // are pre-reviewed by an admin, so they skip the overwrite warning.
    $calledWithLocalFile = !empty($GLOBALS['nexa_package_local_file']);

    // "Package already exists" confirmation round-trip: the first upload
    // stashes the zip under content/temp_uploads/pending-<token>.zip and
    // asks; this branch resumes (or cancels) that staged upload.
    $pkgAction = (string) ($_POST['nexa_pkg_action'] ?? '');
    if ($pkgAction === 'confirm_replace' || $pkgAction === 'cancel_replace') {
        $root0 = rtrim((string) ($include_dir ?? dirname(__DIR__)), '/\\');
        $tempDir0 = $root0 . '/content/temp_uploads';
        $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['nexa_pkg_token'] ?? ''));
        $stageZip = $tempDir0 . '/pending-' . $token . '.zip';
        $stageMetaFile = $tempDir0 . '/pending-' . $token . '.json';
        if ($pkgAction === 'cancel_replace') {
            @unlink($stageZip); @unlink($stageMetaFile);
            echo '<div class="alert alert-info">Upload cancelled &mdash; the existing ' . esc_html($type) . ' was left untouched.</div>';
            return;
        }
        $meta = is_file($stageMetaFile) ? json_decode((string) file_get_contents($stageMetaFile), true) : null;
        if (!is_array($meta) || (string) ($meta['type'] ?? '') !== $type || !is_file($stageZip)) {
            echo errorMessage('That upload confirmation has expired. Please choose the .zip file again.');
            return;
        }
        $GLOBALS['nexa_package_replace_confirmed'] = true;
        $GLOBALS['nexa_package_local_file'] = true; // staged zip is already on disk
        $_FILES[$type] = ['tmp_name' => $stageZip, 'name' => (string) ($meta['orig'] ?? ($type . '.zip')), 'type' => 'application/zip', 'size' => (int) filesize($stageZip), 'error' => 0];
        @unlink($stageMetaFile);
    }
    $field = isset($_FILES[$type]) ? $type : (isset($_FILES['pluginzip']) ? 'pluginzip' : $type);
    $localFile = !empty($GLOBALS['nexa_package_local_file']) && !empty($_FILES[$field]['tmp_name']) && is_file((string) $_FILES[$field]['tmp_name']);
    if (empty($_FILES[$field]['tmp_name']) || (!$localFile && !is_uploaded_file($_FILES[$field]['tmp_name']))) {
        echo errorMessage('No valid .zip archive selected.');
        return;
    }
    $origName = basename((string) $_FILES[$field]['name']);
    if (!str_ends_with(strtolower($origName), '.zip')) {
        echo errorMessage('Only .zip packages are allowed.');
        return;
    }
    $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', substr($origName, 0, -4)) ?: ('pkg_' . time());
    $root = rtrim((string) ($include_dir ?? dirname(__DIR__)), '/\\');
    $tempDir = $root . '/content/temp_uploads';
    $destParent = $destParent !== null ? rtrim(str_replace('\\', '/', $destParent), '/') : $root . '/content/' . $type . 's';
    @mkdir($tempDir, 0755, true);
    @mkdir($destParent, 0755, true);

    $zipPath = $tempDir . '/' . $slug . '.zip';
    if (!($localFile ? @rename((string) $_FILES[$field]['tmp_name'], $zipPath) : move_uploaded_file($_FILES[$field]['tmp_name'], $zipPath))) {
        echo errorMessage('Failed to move uploaded archive.');
        return;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        @unlink($zipPath);
        echo errorMessage('Could not open .zip archive.');
        return;
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = (string) $zip->getNameIndex($i);
        if (str_contains($entryName, '../') || str_contains($entryName, '..\\') || str_starts_with($entryName, '/')) {
            $zip->close();
            @unlink($zipPath);
            echo errorMessage('Archive rejected due to unsafe path entry.');
            return;
        }
    }
    $extractDir = $tempDir . '/extract_' . $slug . '_' . uniqid();
    @mkdir($extractDir, 0755, true);
    $zip->extractTo($extractDir);
    $zip->close();
    // NOTE: $zipPath is kept for now - it may be staged for an
    // "already exists" confirmation; it is unlinked on every exit below.

    $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
    $wrapped = (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0])) ? $entries[0] : null;
    $sourceDir = $wrapped !== null ? ($extractDir . '/' . $wrapped) : $extractDir;
    if ($wrapped !== null) {
        // Trust the package's own folder name over a possibly renamed .zip filename.
        $folderSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $wrapped);
        if ($folderSlug !== '' && $folderSlug !== '.' && $folderSlug !== '..') $slug = $folderSlug;
    }

    $invalid = nexa_validate_package($type, $sourceDir);
    if ($invalid !== null) {
        nexa_remove_dir($extractDir);
        @unlink($zipPath);
        echo errorMessage($invalid . ' Nothing was installed.');
        return;
    }

    // Already-exists warning: never silently overwrite an installed
    // plugin/theme. Stage the zip and ask the admin first; the staged
    // upload resumes through the confirmation branch at the top.
    $destDir = $destParent . '/' . $slug;
    if (is_dir($destDir) && empty($GLOBALS['nexa_package_replace_confirmed']) && !$calledWithLocalFile) {
        foreach (glob($tempDir . '/pending-*.zip') ?: [] as $stale) {
            if (filemtime($stale) < time() - 3600) { @unlink($stale); @unlink(substr($stale, 0, -4) . '.json'); }
        }
        $token = bin2hex(random_bytes(8));
        @rename($zipPath, $tempDir . '/pending-' . $token . '.zip');
        @file_put_contents($tempDir . '/pending-' . $token . '.json', json_encode(['type' => $type, 'slug' => $slug, 'orig' => $origName, 'created' => time()]));
        $metaFn = $type === 'plugin' ? 'di_read_plugin_manifest' : 'nexa_read_theme_manifest';
        $exMeta = $metaFn($destDir, $slug);
        $newMeta = $metaFn($sourceDir, $slug);
        $exV = trim((string) ($exMeta['version'] ?? '')) ?: 'unknown version';
        $newV = trim((string) ($newMeta['version'] ?? '')) ?: 'unknown version';
        $rel = ($exV !== 'unknown version' && $newV !== 'unknown version') ? version_compare($newV, $exV) : 0;
        $relText = $rel < 0 ? ' <strong>The uploaded package is OLDER than what is installed.</strong>'
            : ($rel === 0 && $exV === $newV ? ' It is the <strong>same version</strong>.' : '');
        echo '<div class="alert alert-warning"><h4 style="margin-top:0"><i class="fa fa-exclamation-triangle"></i> This ' . esc_html($type) . ' is already installed</h4>'
            . '<p>A ' . esc_html($type) . ' named <strong>' . esc_html((string) ($exMeta['name'] ?? $slug)) . '</strong> (<code>' . esc_html($slug) . '</code>, version <strong>' . esc_html($exV) . '</strong>) already exists. '
            . 'The uploaded package is version <strong>' . esc_html($newV) . '</strong>.' . $relText . '<br>'
            . 'Replacing overwrites the package files in place (settings and data stored outside the package folder are kept). Nothing has been installed yet.</p>'
            . '<form method="post">' . di_csrf_field() . '<input type="hidden" name="nexa_pkg_token" value="' . esc_attr($token) . '">'
            . '<button type="submit" name="nexa_pkg_action" value="cancel_replace" class="btn btn-default">Cancel &mdash; keep existing</button> '
            . '<button type="submit" name="nexa_pkg_action" value="confirm_replace" class="btn btn-danger">Replace existing ' . esc_html($type) . '</button></form></div>';
        nexa_remove_dir($extractDir);
        return;
    }

    @unlink($zipPath);
    recurse_copy($sourceDir, $destDir);
    $label = nexa_package_label($type, $sourceDir, $slug);
    nexa_remove_dir($extractDir);
    $destRel = str_starts_with($destParent, $root . '/') ? substr($destParent, strlen($root) + 1) : $destParent;
    echo successMessage(ucfirst($type) . ' <strong>' . $label . '</strong> installed successfully into <code>' . esc_html($destRel) . '/' . esc_html($slug) . '/</code>!');
}

if (!class_exists('FlxZipArchive')) {
    class FlxZipArchive extends ZipArchive {
        public function addDir(string $location, string $name, array $excludeDirs = []): void {
            $this->addEmptyDir($name);
            $this->addDirDo($location, $name, $excludeDirs);
        }
        private function addDirDo(string $location, string $name, array $excludeDirs): void {
            $name .= '/';
            $location .= '/';
            $dir = opendir($location);
            if (!$dir) return;
            while (($file = readdir($dir)) !== false) {
                if ($file === '.' || $file === '..') continue;
                $full = $location . $file;
                if (is_dir($full)) {
                    if (in_array($file, $excludeDirs, true)) continue;
                    $this->addDir($full, $name . $file, $excludeDirs);
                } elseif (is_file($full)) {
                    $this->addFile($full, $name . $file);
                }
            }
            closedir($dir);
        }
    }
}

/* ==========================================================================
 * 12. ADMIN BAR (frontend toolbar for author-and-above roles, tiered links)
 * ========================================================================== */

function nexa_render_admin_bar(): void {
    static $done = false;
    if ($done || !empty($GLOBALS['adminarea']) || !function_exists('is_user_logged_in') || !is_user_logged_in()) return;
    // Author and above: anyone who can legitimately work in the dashboard
    // (author/editor/admin/super_admin via the edit_posts capability).
    // Ordinary accounts (subscribers, members, shop customers...) never see
    // it — their account home is the frontend My Account area. Links are
    // tiered by role below: nobody is shown a link they cannot use.
    if (!function_exists('current_user_can') || !current_user_can('edit_posts')) return;
    $done = true;
    $admin    = home_url('admin/');
    $name     = (string) ($_SESSION['display-name'] ?? $_SESSION['name'] ?? 'Admin');
    $pid      = (string) ($_GET['p'] ?? ($GLOBALS['page'] ?? ''));
    $role     = (string) ($_SESSION['admin_type'] ?? '');
    $isAdmin  = in_array($role, ['super_admin', 'admin'], true);
    $canPages = $isAdmin || $role === 'editor';
    echo '<style>#nexa-adminbar{position:fixed;top:0;left:0;right:0;height:36px;background:#1c2331;color:#dbe4f5;z-index:99999;font:13px/36px sans-serif;padding:0 10px}#nexa-adminbar a{color:#dbe4f5;text-decoration:none;margin-right:14px}#nexa-adminbar a:hover{color:#fff}#nexa-adminbar .nx-right{float:right}body{padding-top:36px!important}</style>';
    echo '<div id="nexa-adminbar"><a href="' . esc_url($admin) . '"><strong>&#9670; ' . esc_html(get_bloginfo('name')) . '</strong></a>'
       . '<a href="' . esc_url($admin) . '">Dashboard</a>'
       . '<a href="' . esc_url(home_url('admin/edit_post.php?type=post')) . '">+ Post</a>'
       . ($canPages ? '<a href="' . esc_url(home_url('admin/edit_post.php?type=page')) . '">+ Page</a>' : '')
       . ($pid !== '' && is_numeric($pid) ? '<a href="' . esc_url(home_url('admin/edit_post.php?id=' . urlencode($pid))) . '">Edit This</a>' : '')
       . ($isAdmin ? '<a href="' . esc_url(home_url('admin/plugins.php')) . '">Plugins</a>' : '')
       . ($isAdmin ? '<a href="' . esc_url(home_url('admin/admin-themes.php')) . '">Admin Theme</a>' : '')
       . '<span class="nx-right">' . esc_html($name) . ' &middot; <a href="' . esc_url(home_url('admin/logout.php')) . '">Log Out</a></span></div>';
}
add_action('nexa_head', 'nexa_render_admin_bar');

/* ---- Theme preview (admins): floating bar with "Use This Theme" ------------
 * Detection runs on up_pre, BEFORE load_theme() swaps the in-memory template
 * setting for the ?theme= preview, so we compare against the saved setting. */
function nexa_preview_detect(): void {
    $GLOBALS['nexa_preview_theme'] = null;
    if (!empty($GLOBALS['adminarea']) || empty($_REQUEST['theme'])) return;
    if (!is_user_logged_in()) return;
    if (!in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true)) return;
    $candidate = basename((string) $_REQUEST['theme']);
    if ($candidate === '' || $candidate === basename((string) get_setting('template', 'multi'))) return;
    if (!is_dir(nexa_resolve_theme_path($candidate))) return;
    $GLOBALS['nexa_preview_theme'] = $candidate;
}
add_action('up_pre', 'nexa_preview_detect', [], 1);

/**
 * While an admin is previewing a theme (?theme=slug), internal page/post
 * links carry the theme parameter so the whole site can be browsed in
 * preview - not just the first page. Asset URLs (theme/plugin/upload files,
 * css/js/images), admin links, external links and already-tagged URLs are
 * left untouched.
 */
function nexa_preview_link(string $url): string {
    $slug = $GLOBALS['nexa_preview_theme'] ?? null;
    if ($slug === null || $url === '' || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) return $url;
    if (str_contains($url, 'theme=')) return $url;
    $host = parse_url($url, PHP_URL_HOST);
    $siteHost = parse_url(baseurl(), PHP_URL_HOST);
    if ($host !== null && $host !== '' && $siteHost && strcasecmp((string) $host, (string) $siteHost) !== 0) return $url;
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    foreach (['/content/themes/', '/content/themes-', '/content/plugins/', '/content/plugins-', '/content/uploads/', '/admin/'] as $skip) {
        if (str_contains($path, $skip)) return $url;
    }
    if ($path === '/admin' || str_ends_with($path, '/admin')) return $url;
    if (preg_match('/\.(css|js|mjs|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|xml|txt|pdf|zip|mp3|mp4|webm)$/i', $path)) return $url;
    $frag = '';
    if (($pos = strpos($url, '#')) !== false) { $frag = substr($url, $pos); $url = substr($url, 0, $pos); }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'theme=' . urlencode((string) $slug) . $frag;
}

function nexa_preview_url(array $set, array $unset): string {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $q = $_GET;
    foreach ($unset as $k) unset($q[$k]);
    foreach ($set as $k => $v) $q[$k] = $v;
    return $path . ($q ? '?' . http_build_query($q) : '');
}

function nexa_render_theme_preview_bar(): void {
    static $done = false;
    if ($done) return;
    $slug = $GLOBALS['nexa_preview_theme'] ?? null;
    if ($slug === null) return;
    $done = true;
    $name = $slug;
    $css = @file_get_contents(nexa_resolve_theme_path($slug) . '/style.css');
    if (is_string($css) && preg_match('/Theme Name:\s*(.+)/i', $css, $m)) $name = trim($m[1]);
    $useUrl = nexa_preview_url(['nexa_use_theme' => $slug, 'nexa_csrf_token' => nexa_csrf_token()], []);
    $exitUrl = nexa_preview_url([], ['theme']);
    echo '<style>body{padding-bottom:54px!important}'
        . '#nexa-previewbar{position:fixed;left:0;right:0;bottom:0;z-index:100001;background:#111827;color:#e5e7eb;font:14px/1.4 sans-serif;padding:10px 16px;display:flex;gap:12px;align-items:center;box-shadow:0 -4px 18px rgba(0,0,0,.35)}'
        . '#nexa-previewbar .grow{flex:1}#nexa-previewbar a.btn{display:inline-block;padding:8px 18px;border-radius:999px;text-decoration:none;font-weight:700}'
        . '#nexa-previewbar a.use{background:#16a34a;color:#fff}#nexa-previewbar a.exit{background:transparent;color:#e5e7eb;border:1px solid #4b5563}'
        . '</style>'
        . '<div id="nexa-previewbar"><span>&#128065; Previewing theme <strong>' . esc_html($name) . '</strong> <span style="opacity:.65">(' . esc_html($slug) . ')</span></span>'
        . '<span class="grow"></span>'
        . '<a class="btn exit" href="' . esc_url($exitUrl) . '">Exit Preview</a>'
        . '<a class="btn use" href="' . esc_url($useUrl) . '">&#10003; Use This Theme</a></div>';
}
add_action('nexa_head', 'nexa_render_theme_preview_bar');

function nexa_use_theme_handler(): void {
    if (empty($_GET['nexa_use_theme'])) return;
    if (!is_user_logged_in() || !in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true)) return;
    if (!nexa_verify_csrf()) return;
    $slug = basename((string) $_GET['nexa_use_theme']);
    if ($slug === '' || !is_dir(nexa_resolve_theme_path($slug))) return;
    if (function_exists('nexa_multisite_theme_allowed') && !nexa_multisite_theme_allowed($slug)) return;
    set_setting('template', $slug, 'Current Theme');
    if (function_exists('nexa_cache_purge')) {
        nexa_cache_purge();
    } else {
        foreach (glob(dirname(__DIR__) . '/content/cache/*.html') ?: [] as $f) @unlink($f);
    }
    header('Location: ' . nexa_preview_url([], ['theme', 'nexa_use_theme', 'nexa_csrf_token']));
    exit;
}
add_action('up_pre', 'nexa_use_theme_handler', [], 2);


/* ==========================================================================
 * 13. SELF-UPDATE SYSTEM (updat.zip: root file / admin upload / remote URL)
 * Package format: a zip of site-root-relative files (index.php, includes/,
 * admin/, content/plugins/...), optionally wrapped in ONE folder, plus an
 * optional update.json {"version":"1.1.0","notes":"..."} at the package root.
 * If update.json is absent the version is read from includes/version.php
 * inside the zip. config.php, admin/database/, content/uploads/ and
 * content/backup/ are NEVER overwritten.
 * ========================================================================== */

function nexa_update_inspect(string $zipPath): ?array {
    if (!is_file($zipPath) || !class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = (string) $zip->getNameIndex($i);
    $version = null; $notes = ''; $partial = false; $from = ''; $deletes = [];
    foreach ($names as $n) {
        if (preg_match('#^(?:[^/]+/)?update\.json$#', $n)) {
            $j = json_decode((string) $zip->getFromName($n), true);
            if (is_array($j) && !empty($j['version'])) {
                $version = (string) $j['version']; $notes = (string) ($j['notes'] ?? '');
                $partial = !empty($j['partial']); $from = (string) ($j['from'] ?? '');
                $deletes = is_array($j['delete'] ?? null) ? array_values(array_filter(array_map('strval', $j['delete']))) : [];
                break;
            }
        }
    }
    if ($version === null) {
        foreach ($names as $n) {
            if (preg_match('#(?:^|/)includes/version\.php$#', $n)) {
                $code = (string) $zip->getFromName($n);
                if (preg_match("/NEXACMS_VERSION'\\s*,\\s*'([^']+)'/", $code, $m)) { $version = $m[1]; break; }
            }
        }
    }
    if ($version === null) {
        foreach ($names as $n) {
            if (preg_match('#^(?:[^/]+/)?version\.txt$#', $n)) { $version = trim(strtok((string) $zip->getFromName($n), "\r\n")); break; }
        }
    }
    $zip->close();
    if ($version === null || $version === '') return null;
    return ['version' => $version, 'notes' => $notes, 'files' => count($names), 'size' => filesize($zipPath), 'partial' => $partial, 'from' => $from, 'delete' => $deletes];
}

function nexa_update_is_newer(string $packageVersion): bool {
    return version_compare($packageVersion, defined('NEXACMS_VERSION') ? NEXACMS_VERSION : '0.0.0', '>');
}

function nexa_apply_update(string $zipPath, bool $force = false): array {
    global $config;
    $root = rtrim((string) ($config['abs'] ?? dirname(__DIR__)), '/');
    $info = nexa_update_inspect($zipPath);
    if ($info === null) return ['ok' => false, 'error' => 'Could not read a version from the package. An updat.zip needs update.json ({"version":"x.y.z"}) or includes/version.php inside the zip.'];
    if (!nexa_update_is_newer($info['version']) && !$force) {
        return ['ok' => false, 'error' => "Package version {$info['version']} is not newer than installed " . (defined('NEXACMS_VERSION') ? NEXACMS_VERSION : '?') . '. Tick "force reinstall" to apply it anyway.', 'version' => $info['version']];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return ['ok' => false, 'error' => 'Could not open the zip file.'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string) $zip->getNameIndex($i);
        if (str_contains($n, '../') || str_contains($n, '..\\') || str_starts_with($n, '/')) { $zip->close(); return ['ok' => false, 'error' => 'Package rejected: unsafe path entry ' . $n]; }
    }
    $staging = $root . '/content/temp_uploads/update_' . date('YmdHis') . '_' . random_int(100, 999);
    @mkdir($staging, 0755, true);
    $zip->extractTo($staging);
    $zip->close();
    // Unwrap a single wrapping folder (e.g. nexacms-1.1.0/...)
    $entries = array_values(array_diff(scandir($staging) ?: [], ['.', '..']));
    $src = $staging;
    if (count($entries) === 1 && is_dir($staging . '/' . $entries[0])) {
        $inner = $staging . '/' . $entries[0];
        if (is_file($inner . '/update.json') || is_dir($inner . '/includes') || is_file($inner . '/index.php') || is_dir($inner . '/admin')) $src = $inner;
    }
    $excluded = static fn(string $rel): bool =>
        $rel === 'config.php' || $rel === 'updat.zip' || $rel === 'update.json'
        || str_starts_with($rel, 'admin/database/') || str_starts_with($rel, 'content/uploads/')
        || str_starts_with($rel, 'content/backup/') || str_starts_with($rel, 'content/cache/')
        || str_starts_with($rel, 'content/temp_uploads/');
    // Backup every file that is about to be overwritten (+ config.php for safety)
    @mkdir($root . '/content/backup', 0755, true);
    $backupName = 'pre-update-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $info['version']) . '-' . date('Ymd-His') . '.zip';
    $backup = new ZipArchive();
    $backupOk = $backup->open($root . '/content/backup/' . $backupName, ZipArchive::CREATE) === true;
    $copied = 0; $backedUp = 0;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
        if ($excluded($rel)) continue;
        $dest = $root . '/' . $rel;
        if ($backupOk && is_file($dest)) { $backup->addFromString($rel, (string) file_get_contents($dest)); $backedUp++; }
        @mkdir(dirname($dest), 0755, true);
        if (@copy($file->getPathname(), $dest)) $copied++;
    }
    // Partial packages may list files to DELETE (they are backed up first, and
    // the same never-touch exclusions apply - config/database/uploads are safe).
    $deleted = 0;
    foreach (($info['delete'] ?? []) as $rel) {
        $rel = str_replace('\\', '/', (string) $rel);
        if ($rel === '' || str_contains($rel, '..') || $excluded($rel)) continue;
        $dest = $root . '/' . $rel;
        if (is_file($dest)) {
            if ($backupOk) $backup->addFromString($rel, (string) file_get_contents($dest)); // read NOW: source is about to be deleted/overwritten, addFile() would read at close()
            if (@unlink($dest)) $deleted++;
        }
    }
    if ($backupOk) { if (is_file($root . '/config.php')) $backup->addFromString('config.php', (string) file_get_contents($root . '/config.php')); $backup->close(); nexa_prune_backups($backupName); }
    // Cleanup staging + page cache, then refresh plugin/setting discovery
    $cleanup = static function (string $dir) use (&$cleanup): void {
        foreach (scandir($dir) ?: [] as $f) { if ($f === '.' || $f === '..') continue; $p = $dir . '/' . $f; is_dir($p) ? $cleanup($p) : @unlink($p); }
        @rmdir($dir);
    };
    $cleanup($staging);
    foreach (glob($root . '/content/cache/*.html') ?: [] as $c) @unlink($c);
    if (function_exists('updatepluginlist')) { try { updatepluginlist(); queplugins(); } catch (Throwable $e) {} }
    @file_put_contents($root . '/content/backup/update-log.txt',
        date('Y-m-d H:i:s') . " updated to {$info['version']} ({$copied} files written, {$deleted} deleted" . (!empty($info['partial']) ? ', partial' : '') . ", backup {$backupName})\n", FILE_APPEND);
    return ['ok' => true, 'version' => $info['version'], 'files' => $copied, 'deleted' => $deleted, 'partial' => !empty($info['partial']), 'backed_up' => $backedUp, 'backup' => $backupName, 'notes' => $info['notes']];
}

/* ==========================================================================
 * 12b. BACKUP RETENTION — stop content/backup/ growing without bound.
 * Policy (Admin -> Site Backups): backup_retention_mode = count | days | off
 * with backup_keep_count / backup_keep_days. Applies to the *.zip archives
 * directly inside content/backup/ (manual site backups, Backups+ archives
 * and the updater's pre-update-*.zip safety backups alike). Safety rules:
 * the newest backup overall is NEVER deleted, and neither is the file a
 * caller just created — cleanup can never leave a site with zero backups.
 * Manual per-file delete stays available regardless of policy.
 * ========================================================================== */

function nexa_backup_retention(): array {
    $mode = (string) get_setting('backup_retention_mode', 'count');
    if (!in_array($mode, ['count', 'days', 'off'], true)) $mode = 'count';
    return [
        'mode' => $mode,
        'keep' => max(1, min(100, (int) get_setting('backup_keep_count', '5'))),
        'days' => max(1, min(3650, (int) get_setting('backup_keep_days', '30'))),
    ];
}

/** Backup archives in content/backup/, newest first. */
function nexa_backup_files(): array {
    global $config;
    $dir = rtrim((string) ($config['abs'] ?? dirname(__DIR__)), '/') . '/content/backup';
    $out = [];
    foreach (glob($dir . '/*.zip') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'path' => $f, 'size' => (int) @filesize($f), 'mtime' => (int) @filemtime($f)];
    }
    usort($out, static fn($a, $b) => ($b['mtime'] <=> $a['mtime']) ?: strcmp($b['name'], $a['name']));
    return $out;
}

function nexa_prune_backups(?string $justCreated = null): array {
    $policy = nexa_backup_retention();
    $files  = nexa_backup_files();
    $result = ['deleted' => [], 'kept' => count($files), 'mode' => $policy['mode']];
    if ($policy['mode'] === 'off' || count($files) <= 1) return $result;
    $cutoff = time() - $policy['days'] * 86400;
    foreach ($files as $i => $f) {
        if ($i === 0) continue; // newest backup overall: untouchable
        if ($justCreated !== null && $f['name'] === $justCreated) continue;
        $drop = $policy['mode'] === 'count' ? $i >= $policy['keep'] : $f['mtime'] < $cutoff;
        if ($drop && @unlink($f['path'])) $result['deleted'][] = $f['name'];
    }
    $result['kept'] = count($files) - count($result['deleted']);
    return $result;
}

/** Official NexaCMS release source (GitHub Releases, latest). */
function nexa_official_update_url(): string {
    return 'https://github.com/nexaCMS/NexaCMS/releases/latest/download/updat.zip';
}

/**
 * Effective remote-update URL for this site. An explicitly saved URL
 * always wins (including an explicitly cleared one); when the setting
 * has never been saved, fresh installs fall back to the official
 * release source. Nothing is contacted until an admin opens System
 * Update and clicks Check/Download.
 */
function nexa_update_remote_url(): string {
    global $settings;
    if (empty($settings)) loadsettings();
    if (isset($settings['update_remote_url'])) {
        return trim((string) ($settings['update_remote_url']['value'] ?? ''));
    }
    return nexa_official_update_url();
}

/** Peek at {dir}/update.json next to a remote updat.zip (light check, GitHub-friendly). */
function nexa_remote_update_manifest(string $zipUrl): ?array {
    $base = substr($zipUrl, 0, strrpos($zipUrl, '/') + 1);
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'NexaCMS-Updater', 'follow_location' => 1], 'https' => ['timeout' => 15, 'user_agent' => 'NexaCMS-Updater', 'follow_location' => 1], 'ssl' => ['verify_peer' => true]]);
    $json = @file_get_contents($base . 'update.json', false, $ctx);
    if ($json === false) return null;
    $j = json_decode($json, true);
    return (is_array($j) && !empty($j['version'])) ? ['version' => (string) $j['version'], 'notes' => (string) ($j['notes'] ?? '')] : null;
}

/**
 * Expected SHA-256 for a remote update zip, read from a SHA256SUMS file
 * hosted next to it (same directory URL, lines "<hash>  <filename>").
 * Returns null when no sums file/entry exists (verification then is
 * simply not available; the caller should say so, not fail silently).
 */
function nexa_update_expected_sha256(string $zipUrl): ?string {
    $base = substr($zipUrl, 0, strrpos($zipUrl, '/') + 1);
    $name = basename((string) parse_url($zipUrl, PHP_URL_PATH));
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'NexaCMS-Updater', 'follow_location' => 1], 'https' => ['timeout' => 15, 'user_agent' => 'NexaCMS-Updater', 'follow_location' => 1], 'ssl' => ['verify_peer' => true]]);
    $sums = @file_get_contents($base . 'SHA256SUMS', false, $ctx);
    if ($sums === false) return null;
    foreach (preg_split('/\r\n|\r|\n/', (string) $sums) ?: [] as $line) {
        if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim((string) $line), $m) && basename(trim($m[2])) === $name) {
            return strtolower($m[1]);
        }
    }
    return null;
}

function nexa_download_update(string $url, string $dest): array {
    if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'URL must start with http:// or https://'];
    // Plain HTTP is only acceptable on loopback (local testing); public
    // update sources must be HTTPS so packages cannot be swapped in transit.
    if (stripos($url, 'http://') === 0) {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
            return ['ok' => false, 'error' => 'Remote updates require HTTPS (plain HTTP is only allowed for localhost).'];
        }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'NexaCMS-Updater', 'follow_location' => 1], 'https' => ['timeout' => 60, 'user_agent' => 'NexaCMS-Updater', 'follow_location' => 1], 'ssl' => ['verify_peer' => true]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) return ['ok' => false, 'error' => 'Download failed (check the URL / server internet access).'];
    if (strlen($data) > 50 * 1024 * 1024) return ['ok' => false, 'error' => 'Package exceeds the 50 MB limit.'];
    if (!str_starts_with($data, "PK")) return ['ok' => false, 'error' => 'Downloaded file is not a zip archive.'];
    @mkdir(dirname($dest), 0755, true);
    file_put_contents($dest, $data);
    // Integrity: when the source publishes SHA256SUMS, a mismatch is
    // fatal (tampered or corrupted package); a match is reported so the
    // UI can show the package was verified.
    $expected = nexa_update_expected_sha256($url);
    if ($expected !== null) {
        $actual = (string) hash_file('sha256', $dest);
        if (!hash_equals($expected, $actual)) {
            @unlink($dest);
            return ['ok' => false, 'error' => 'Checksum verification FAILED — the downloaded package does not match the published SHA256SUMS. It may be corrupted or tampered with; nothing was installed.'];
        }
        return ['ok' => true, 'size' => strlen($data), 'verified' => true];
    }
    return ['ok' => true, 'size' => strlen($data), 'verified' => false];
}

/* ==========================================================================
 * 14. EDITOR ENGINES (built-in Nexa WYSIWYG vs vendored TinyMCE)
 * ========================================================================== */

function nexa_tinymce_available(): bool {
    global $config;
    $root = rtrim((string) ($config['abs'] ?? dirname(__DIR__)), '/');
    return is_file($root . '/admin/vendor/tinymce/tinymce.min.js');
}
function nexa_editor_engine(): string {
    $e = (string) get_setting('wysiwyg_editor', 'nexa');
    if ($e === 'tinymce' && !nexa_tinymce_available()) return 'nexa';
    return in_array($e, ['nexa', 'tinymce'], true) ? $e : 'nexa';
}
function nexa_tinymce_url(): string { return home_url('admin/vendor/tinymce/tinymce.min.js'); }

/* ==========================================================================
 * 15. THEME SETTINGS (per-theme options, edited in Admin > Theme Settings)
 * A theme declares its options in content/themes/{slug}/theme-settings.php
 * returning a list of fields:
 *   ['key'=>'accent_color','label'=>'Accent Color','type'=>'color','default'=>'#333']
 * Types: text, textarea, select (with 'options'), checkbox, color.
 * A field may set 'setting' to write to an existing global setting instead
 * of the per-theme store. Values are stored as theme_{slug}_{key} so every
 * theme keeps its OWN settings when you switch back and forth.
 * ========================================================================== */

function nexa_active_theme_slug(): string {
    global $settings;
    if (function_exists('nexa_multisite_forced_theme') && nexa_multisite_forced_theme() !== '') return nexa_multisite_forced_theme();
    return basename((string) ($settings['template']['value'] ?? 'multi')) ?: 'multi';
}

function nexa_theme_schema(string $slug): array {
    $file = nexa_resolve_theme_path($slug) . '/theme-settings.php';
    if (is_file($file)) {
        $schema = include $file;
        if (is_array($schema)) return $schema;
    }
    return [
        ['key' => 'footer_text', 'label' => 'Footer Text', 'type' => 'text', 'default' => '', 'desc' => 'Leave empty for the default footer text.'],
        ['key' => 'custom_css', 'label' => 'Custom CSS', 'type' => 'textarea', 'default' => '', 'desc' => 'Extra CSS injected into every page head for this theme.'],
    ];
}

function nexa_theme_setting_name(string $slug, array $field): string {
    return (string) ($field['setting'] ?? ('theme_' . $slug . '_' . $field['key']));
}

/** Read a setting of the ACTIVE theme (schema default applies). */
function theme_setting(string $key, string $default = ''): string {
    $slug = nexa_active_theme_slug();
    // Live Theme Editor draft: while an admin previews a theme (?theme=slug),
    // unsaved customizer values win — visitors and the live site never see them.
    if (!empty($_REQUEST['theme']) && empty($GLOBALS['adminarea']) && is_user_logged_in()
        && in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true)
        && !empty($_SESSION['nexa_customizer_draft']) && is_array($_SESSION['nexa_customizer_draft'])
        && (string) ($_SESSION['nexa_customizer_draft']['slug'] ?? '') === $slug
        && array_key_exists($key, (array) ($_SESSION['nexa_customizer_draft']['values'] ?? []))) {
        return (string) $_SESSION['nexa_customizer_draft']['values'][$key];
    }
    foreach (nexa_theme_schema($slug) as $field) {
        if (($field['key'] ?? '') === $key) {
            return (string) get_setting(nexa_theme_setting_name($slug, $field), (string) ($field['default'] ?? $default));
        }
    }
    return (string) get_setting('theme_' . $slug . '_' . $key, $default);
}


/** Universal colour knobs available for EVERY theme (Live Theme Editor).
 * Stored per theme as theme_{slug}_{key}; empty = keep the theme's own colour. */
function nexa_theme_knobs(): array {
    return [
        'k_page_bg'        => ['label' => 'Page Background', 'hint' => '#ffffff'],
        'k_body_text'      => ['label' => 'Body Text Colour', 'hint' => '#212529'],
        'k_heading_color'  => ['label' => 'Heading Text Colour (H1–H6)', 'hint' => '#111827'],
        'k_link_color'     => ['label' => 'Link Colour', 'hint' => '#2563eb'],
        'k_link_hover'     => ['label' => 'Link Hover Colour', 'hint' => '#1d4ed8'],
        'k_muted_color'    => ['label' => 'Muted / Secondary Text Colour', 'hint' => '#6b7280'],
        'k_header_bg'      => ['label' => 'Header / Nav Bar Background', 'hint' => '#111827'],
        'k_header_text'    => ['label' => 'Header Text Colour', 'hint' => '#ffffff'],
        'k_nav_link'       => ['label' => 'Navigation Menu Link Colour', 'hint' => '#ffffff'],
        'k_button_bg'      => ['label' => 'Button Background', 'hint' => '#2563eb'],
        'k_button_text'    => ['label' => 'Button Text Colour', 'hint' => '#ffffff'],
        'k_footer_bg'      => ['label' => 'Footer Background', 'hint' => '#111827'],
        'k_footer_text'  => ['label' => 'Footer Text Colour', 'hint' => '#e5e7eb'],
    ];
}

function nexa_theme_knobs_css(): string {
    if (!function_exists('theme_setting')) return '';
    $v = [];
    foreach (array_keys(nexa_theme_knobs()) as $k) {
        $val = trim(theme_setting($k, ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) $v[$k] = $val;
    }
    if (!$v) return '';
    $css = ':root{';
    foreach ($v as $k => $val) $css .= '--nexa-' . str_replace('_', '-', $k) . ':' . $val . ';';
    $css .= '}';
    if (isset($v['k_page_bg'])) $css .= 'body{background-color:' . $v['k_page_bg'] . '!important}';
    if (isset($v['k_body_text'])) $css .= 'body{color:' . $v['k_body_text'] . '!important}';
    if (isset($v['k_heading_color'])) $css .= 'h1,h2,h3,h4,h5,h6,h1 a,h2 a,h3 a,h4 a,h5 a,h6 a{color:' . $v['k_heading_color'] . '!important}';
    if (isset($v['k_link_color'])) $css .= 'a{color:' . $v['k_link_color'] . '!important}';
    if (isset($v['k_link_hover'])) $css .= 'a:hover,a:focus{color:' . $v['k_link_hover'] . '!important}';
    if (isset($v['k_muted_color'])) $css .= '.text-muted,.muted,small,.post-meta,.meta,figcaption,.help-block{color:' . $v['k_muted_color'] . '!important}';
    $hdr = 'header,.site-header,.navbar,.navbar-default,[class*="-header"]';
    if (isset($v['k_header_bg'])) $css .= $hdr . '{background-color:' . $v['k_header_bg'] . '!important}';
    if (isset($v['k_header_text'])) $css .= 'header,.site-header,[class*="-header"]{color:' . $v['k_header_text'] . '!important}';
    if (isset($v['k_nav_link'])) $css .= 'nav a,.nav a,.menu a,.navbar a,header a{color:' . $v['k_nav_link'] . '!important}';
    elseif (isset($v['k_header_text'])) $css .= 'header a,.site-header a{color:' . $v['k_header_text'] . '!important}';
    if (isset($v['k_button_bg']) || isset($v['k_button_text'])) {
        $css .= '.btn,button,input[type="submit"],input[type="button"],.button{';
        if (isset($v['k_button_bg'])) $css .= 'background-color:' . $v['k_button_bg'] . '!important;border-color:' . $v['k_button_bg'] . '!important;';
        if (isset($v['k_button_text'])) $css .= 'color:' . $v['k_button_text'] . '!important;';
        $css .= '}';
    }
    $ftr = 'footer,.site-footer,[class*="-footer"],.footer';
    if (isset($v['k_footer_bg'])) $css .= $ftr . '{background-color:' . $v['k_footer_bg'] . '!important}';
    if (isset($v['k_footer_text'])) $css .= $ftr . ',' . 'footer a,.site-footer a,[class*="-footer"] a,.footer a{color:' . $v['k_footer_text'] . '!important}';
    return '<style id="nexa-theme-knobs">' . $css . '</style>' . "\n";
}

/* ==========================================================================
 * 16. PERMALINKS — Settings > Permalinks (permalink_structure)
 * Plain:  ?p=%post_id%                       -> /?p=3           (always works)
 * Pretty: /%postname%/                       -> /my-post/
 *         /%year%/%monthnum%/%postname%/     -> /2026/11/my-post/
 *         /%post_id%/ , /%year%/%monthnum%/%day%/%postname%/ , %author%
 * Pretty URLs need a rewrite to index.php: bundled .htaccess (Apache),
 * router.php (PHP built-in server, used by the preview), or nginx
 * `try_files $uri $uri/ /index.php?$args;`. ?p= links always keep working,
 * and with pretty permalinks on they 301-redirect to the canonical URL.
 * ========================================================================== */

function nexa_permalink_structure(): string {
    return trim((string) get_setting('permalink_structure', '/%postname%/'));
}
function nexa_pretty_permalinks(): bool {
    $s = nexa_permalink_structure();
    return $s !== '' && !str_contains($s, '?');
}
function nexa_sanitize_slug(string $text, string $fallback = 'post'): string {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : $fallback;
}
function nexa_post_slug(array $row): string {
    $name = trim((string) ($row['post_name'] ?? ''));
    if ($name !== '') return nexa_sanitize_slug($name, 'post-' . ($row['id'] ?? '0'));
    $title = trim((string) ($row['post_title'] ?? ''));
    if ($title !== '') return nexa_sanitize_slug($title, 'post-' . ($row['id'] ?? '0'));
    return 'post-' . ($row['id'] ?? '0');
}
function nexa_build_permalink(array $row): string {
    $id = (string) ($row['id'] ?? '0');
    $structure = nexa_permalink_structure();
    if ($structure === '' || str_contains($structure, '?')) {
        return home_url('?p=' . $id);
    }
    $ts = strtotime((string) ($row['post_date'] ?? '')) ?: time();
    $path = strtr($structure, [
        '%postname%' => nexa_post_slug($row),
        '%post_id%'  => $id,
        '%year%'     => date('Y', $ts),
        '%monthnum%' => date('m', $ts),
        '%day%'      => date('d', $ts),
        '%author%'   => nexa_sanitize_slug((string) ($row['post_author'] ?? 'admin'), 'admin'),
        '%category%' => '',
    ]);
    $path = trim(preg_replace('#/+#', '/', $path) ?? '', '/');
    if ($path === '') return home_url('?p=' . $id);
    return home_url($path . (str_ends_with($structure, '/') ? '/' : ''));
}

/* ==========================================================================
 * 17. SUBSITES / MULTISITE (off by default; main site = site 1)
 * Registry lives in the MAIN database (network_sites / network_site_users,
 * never table-prefixed). Routing: wildcard subdomain or explicit host, or
 * subfolder in /sites/<slug>/ (prefixed) or /<slug>/ (root) mode chosen by
 * the network admin. Storage per subsite: 'separate' (own file database /
 * database name) or 'shared' (same database, s<id>_ table prefix on the
 * file drivers). Admin is centralised at /admin: network admins switch
 * context; assigned subsite admins are pinned to their site.
 * ========================================================================== */

function nexa_sites_reserved_slugs(): array {
    return ['admin','content','sites','api','login','logout','register','search','feed','sitemap','robots','assets','uploads','wp-admin','wp-login','index','home','blog','shop','cart','checkout','account','dashboard','404'];
}

function nexa_multisite_ensure_registry(): void {
    try {
        if (!table_exists('network_sites')) {
            create_table('network_sites', ['slug','name','host','storage','db_name','status','theme','allowed_themes','allowed_plugins','mandatory_plugins','allow_uploads','created_at']);
        }
        if (!table_exists('network_site_users')) {
            create_table('network_site_users', ['user_id','site_id','role','created_at']);
        }
        if (!table_exists('network_package_requests')) {
            create_table('network_package_requests', ['site_id','site_slug','type','file','orig_name','requested_by','status','created_at','reviewed_by','reviewed_at']);
        } else {
            ensure_fields('network_package_requests', ['reviewed_by','reviewed_at']);
        }
    } catch (Throwable $e) { /* registry must never break the main site */ }
}

function nexa_multisite_main_settings(): array {
    $out = ['multisite_enabled' => '0', 'multisite_path_mode' => 'sites', 'multisite_main_host' => ''];
    try {
        foreach (get_table('settings')['data'] as $row) {
            $k = (string) ($row['setting'] ?? '');
            if (array_key_exists($k, $out)) $out[$k] = (string) ($row['value'] ?? '');
        }
    } catch (Throwable $e) {}
    return $out;
}

function nexa_multisite_sites(bool $activeOnly = false): array {
    try {
        $rows = get_table('network_sites')['data'];
    } catch (Throwable $e) { return []; }
    if ($activeOnly) $rows = array_values(array_filter($rows, static fn($r) => ($r['status'] ?? 'active') === 'active'));
    return $rows;
}

function nexa_multisite_site_by_slug(string $slug): ?array {
    foreach (nexa_multisite_sites() as $s) if (strcasecmp((string) ($s['slug'] ?? ''), $slug) === 0) return $s;
    return null;
}

function nexa_current_site(): ?array {
    return $GLOBALS['nexa_current_site'] ?? null;
}

function nexa_multisite_csv_list($value): array {
    $out = [];
    foreach (preg_split('/[,\n|]+/', (string) $value) ?: [] as $v) { $v = trim($v); if ($v !== '') $out[] = $v; }
    return array_values(array_unique($out));
}

function nexa_multisite_apply_storage(array $site): void {
    global $config;
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($site['slug'] ?? 'site'))) ?: 'site';
    if ((string) ($site['storage'] ?? 'separate') === 'shared') {
        $GLOBALS['nexa_db_table_prefix'] = 's' . (int) ($site['id'] ?? 0) . '_';
        return;
    }
    $GLOBALS['nexa_db_table_prefix'] = '';
    $driver = strtolower((string) ($config['database']['type'] ?? 'csv'));
    if (in_array($driver, ['csv', 'json', 'array'], true)) {
        $config['database']['location'] = 'content/sites/' . $slug . '/database';
        $dir = rtrim((string) ($config['abs'] ?? dirname(__DIR__)), '/') . '/content/sites/' . $slug . '/database';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    } elseif ($driver === 'sqlite') {
        $config['database']['file'] = 'content/sites/' . $slug . '/database.sqlite';
        $dir = rtrim((string) ($config['abs'] ?? dirname(__DIR__)), '/') . '/content/sites/' . $slug;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    } elseif (!empty($site['db_name'])) {
        $config['database']['name'] = (string) $site['db_name'];
    } else {
        // SQL without a per-site database name: fall back to table prefixing
        // of file-style access; raw SQL plugins keep main tables (documented).
        $GLOBALS['nexa_db_table_prefix'] = 's' . (int) ($site['id'] ?? 0) . '_';
    }
}

/** Run $fn against the MAIN database (from inside a subsite context). */
function nexa_network_call(callable $fn) {
    global $config;
    $savedDb = $config['database'] ?? null;
    $savedPrefix = $GLOBALS['nexa_db_table_prefix'] ?? '';
    $config['database'] = $GLOBALS['nexa_main_db_config'] ?? $savedDb;
    $GLOBALS['nexa_db_table_prefix'] = '';
    try { return $fn(); }
    finally {
        if ($savedDb !== null) $config['database'] = $savedDb;
        $GLOBALS['nexa_db_table_prefix'] = $savedPrefix;
    }
}

function nexa_multisite_site_public_url(array $site): string {
    $base = rtrim(baseurl(), '/');
    $host = trim((string) ($site['host'] ?? ''));
    if ($host !== '') {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'http';
        $installPath = rtrim((string) (parse_url($base, PHP_URL_PATH) ?? ''), '/');
        return $scheme . '://' . $host . $installPath . '/';
    }
    $mode = (string) ($GLOBALS['nexa_multisite_path_mode'] ?? 'sites');
    $mainHost = trim((string) ($GLOBALS['nexa_multisite_main_host'] ?? ''));
    $curHost = (string) (parse_url($base, PHP_URL_HOST) ?? '');
    if ($mainHost !== '' && strcasecmp($curHost, $mainHost) === 0) {
        // Wildcard subdomain URL.
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'http';
        $installPath = rtrim((string) (parse_url($base, PHP_URL_PATH) ?? ''), '/');
        $port = parse_url($base, PHP_URL_PORT);
        return $scheme . '://' . $site['slug'] . '.' . $mainHost . ($port ? ':' . $port : '') . $installPath . '/';
    }
    return $base . '/' . ($mode === 'root' ? '' : 'sites/') . $site['slug'] . '/';
}

/** Central resolver — called from inc.php before settings are loaded. */
function nexa_multisite_resolve(): void {
    global $config;
    $GLOBALS['nexa_current_site'] = null;
    $GLOBALS['nexa_site_url_prefix'] = '';
    $GLOBALS['nexa_site_url_host'] = '';
    $GLOBALS['nexa_site_admin_prefix'] = '';
    $GLOBALS['nexa_in_site_admin'] = false;
    $GLOBALS['nexa_db_table_prefix'] = '';
    if (empty($config['installed'])) return;
    if ((PHP_SAPI !== 'cli') && session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();

    $GLOBALS['nexa_main_db_config'] = $config['database'] ?? [];
    nexa_multisite_ensure_registry();
    $flags = nexa_multisite_main_settings();
    $GLOBALS['nexa_multisite_path_mode'] = in_array($flags['multisite_path_mode'], ['sites', 'root'], true) ? $flags['multisite_path_mode'] : 'sites';
    $GLOBALS['nexa_multisite_main_host'] = strtolower(trim((string) $flags['multisite_main_host']));

    $sites = nexa_multisite_sites();
    $bySlug = [];
    foreach ($sites as $s) $bySlug[strtolower((string) $s['slug'])] = $s;

    $reqPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $basePath = rtrim((string) (parse_url(baseurl(), PHP_URL_PATH) ?? ''), '/');
    $rel = trim(substr($reqPath, strlen($basePath)), '/');
    $segments = $rel === '' ? [] : explode('/', $rel);
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $isNetworkPage = basename($scriptName) === 'network.php';

    $site = null; $pathPrefix = ''; $viaHost = false;

    // CLI override (used by the subsite seeding tool).
    if (PHP_SAPI === 'cli' && getenv('NEXA_MULTISITE_SITE')) {
        $site = $bySlug[strtolower((string) getenv('NEXA_MULTISITE_SITE'))] ?? null;
    }

    /* ------------------------------------------------------------------
     * Per-subsite admin areas: /<slug>/admin/... and /sites/<slug>/admin/...
     * (plus /admin on a subsite's own host). The URL itself pins the site
     * context: whatever you edit there belongs to that site alone.
     * ------------------------------------------------------------------ */
    $siteAdminSlug = null; $siteAdminPrefix = '';
    if (count($segments) >= 3 && $segments[0] === 'sites' && $segments[2] === 'admin') {
        $siteAdminSlug = strtolower((string) $segments[1]); $siteAdminPrefix = 'sites/' . $segments[1];
    } elseif (count($segments) >= 2 && $segments[1] === 'admin'
        && (string) $segments[0] !== 'admin'
        && !in_array(strtolower((string) $segments[0]), nexa_sites_reserved_slugs(), true)) {
        $siteAdminSlug = strtolower((string) $segments[0]); $siteAdminPrefix = (string) $segments[0];
    }
    $isAdminReq = str_contains($scriptName, '/admin/') || ($segments[0] ?? '') === 'admin' || $siteAdminSlug !== null;

    if ($siteAdminSlug !== null) {
        // A /<something>/admin URL must never silently fall back to the main
        // admin: if multisite is off or the slug is not a subsite, say so.
        if ((string) $flags['multisite_enabled'] !== '1' || !isset($bySlug[$siteAdminSlug])) {
            http_response_code(404);
            echo 'Subsite not found.'; exit;
        }
        $site = $bySlug[$siteAdminSlug];
        $pathPrefix = $siteAdminPrefix;
        // Canonical URL: /<slug>/admin must become /<slug>/admin/ — without
        // the trailing slash every relative link and stylesheet in the
        // admin resolves one folder too high and the UI loads unstyled.
        if ($rel === $siteAdminPrefix . '/admin' && !str_ends_with($reqPath, '/')) {
            $qs = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
            header('Location: ' . $reqPath . '/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
            exit;
        }
    }

    if ((string) $flags['multisite_enabled'] !== '1' && !(PHP_SAPI === 'cli' && getenv('NEXA_MULTISITE_SITE'))) return;
    if (!$sites) return;

    if ($site === null && !$isAdminReq) {
        // 1) Host: explicit mapping, then wildcard subdomain.
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $hostNoPort = preg_replace('/:\d+$/', '', $host) ?? $host;
        foreach ($sites as $s) {
            if (!empty($s['host']) && strcasecmp((string) (preg_replace('/:\d+$/', '', strtolower((string) $s['host'])) ?? ''), $hostNoPort) === 0) { $site = $s; $viaHost = true; break; }
        }
        if ($site === null) {
            $mainHost = $GLOBALS['nexa_multisite_main_host'];
            if ($mainHost === '') {
                try { foreach (get_table('settings')['data'] as $r) if (($r['setting'] ?? '') === 'siteurl') { $mainHost = strtolower((string) (parse_url((string) $r['value'], PHP_URL_HOST) ?? '')); break; } } catch (Throwable $e) {}
            }
            if ($mainHost !== '' && $hostNoPort !== $mainHost && str_ends_with($hostNoPort, '.' . $mainHost)) {
                $label = substr($hostNoPort, 0, -strlen('.' . $mainHost));
                if ($label !== '' && !str_contains($label, '.') && isset($bySlug[$label])) { $site = $bySlug[$label]; $viaHost = true; }
            }
        }
        // 2) Path: /sites/<slug>/... or /<slug>/...
        if ($site === null && $segments) {
            if ($GLOBALS['nexa_multisite_path_mode'] === 'sites') {
                if (($segments[0] ?? '') === 'sites' && isset($segments[1]) && isset($bySlug[strtolower($segments[1])])) {
                    $site = $bySlug[strtolower($segments[1])]; $pathPrefix = 'sites/' . $segments[1];
                }
            } else {
                $first = strtolower((string) $segments[0]);
                if (!in_array($first, nexa_sites_reserved_slugs(), true) && isset($bySlug[$first])) {
                    $site = $bySlug[$first]; $pathPrefix = (string) $segments[0];
                }
            }
        }
    }

    // 2b) A subsite's own host + /admin is that subsite's admin area too.
    if ($site === null && $isAdminReq && $siteAdminSlug === null && !$isNetworkPage) {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $hostNoPort = preg_replace('/:\d+$/', '', $host) ?? $host;
        foreach ($sites as $s) {
            if (!empty($s['host']) && strcasecmp((string) (preg_replace('/:\d+$/', '', strtolower((string) $s['host'])) ?? ''), $hostNoPort) === 0) { $site = $s; $viaHost = true; break; }
        }
        if ($site === null) {
            $mainHost = $GLOBALS['nexa_multisite_main_host'];
            if ($mainHost === '') {
                try { foreach (get_table('settings')['data'] as $r) if (($r['setting'] ?? '') === 'siteurl') { $mainHost = strtolower((string) (parse_url((string) $r['value'], PHP_URL_HOST) ?? '')); break; } } catch (Throwable $e) {}
            }
            if ($mainHost !== '' && $hostNoPort !== $mainHost && str_ends_with($hostNoPort, '.' . $mainHost)) {
                $label = substr($hostNoPort, 0, -strlen('.' . $mainHost));
                if ($label !== '' && !str_contains($label, '.') && isset($bySlug[$label])) { $site = $bySlug[$label]; $viaHost = true; }
            }
        }
        if ($site !== null) $siteAdminSlug = strtolower((string) $site['slug']); // host-based site admin
    }

    $isSiteAdminReq = $site !== null && ($siteAdminSlug !== null);

    // 3) Central admin (/admin): context comes from the session.
    if ($site === null && $isAdminReq && !$isNetworkPage && !empty($_SESSION['user_id'])) {
        $uid = (int) $_SESSION['user_id'];
        $assigned = null;
        try {
            foreach (get_table('network_site_users')['data'] as $r) if ((int) ($r['user_id'] ?? 0) === $uid) { $assigned = (int) $r['site_id']; break; }
        } catch (Throwable $e) {}
        if ($assigned) {
            foreach ($sites as $s) if ((int) $s['id'] === $assigned) { $site = $s; break; }
            if ($site) $_SESSION['nexa_admin_site_id'] = $assigned;
        } elseif (!empty($_SESSION['nexa_admin_site_id']) && (!empty($_SESSION['nexa_network_admin']) || ($_SESSION['admin_type'] ?? '') === 'super_admin')) {
            foreach ($sites as $s) if ((int) $s['id'] === (int) $_SESSION['nexa_admin_site_id']) { $site = $s; break; }
        }
    }
    if ($isNetworkPage) { $GLOBALS['nexa_network_context'] = true; return; }
    if ($site === null) return;

    $isNetworkAdminUser = static function (): bool {
        return !empty($_SESSION['nexa_network_admin'])
            || in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true);
    };

    if ((string) ($site['status'] ?? 'active') !== 'active') {
        if ($isSiteAdminReq && $isNetworkAdminUser()) {
            // Network admins may still enter a suspended site's admin.
        } elseif (!$isAdminReq) {
            http_response_code(503);
            echo 'This site is temporarily unavailable.';
            exit;
        } elseif ($isSiteAdminReq) {
            http_response_code(503);
            echo 'This site is suspended. A network admin can reactivate it from the main Network Admin page.';
            exit;
        } else {
            return; // suspended sites cannot be administered by their own admins
        }
    }

    /* Site-admin access control: the URL names the site, so enforce it.
     * - users pinned (in Network Admin) to a DIFFERENT subsite: 403
     * - users whose account lives in a different subsite's own DB: 403
     * - network admins: welcome, and sync the central-admin context too
     * - accounts that live only in THIS subsite's own database were
     *   authenticated against it by admin/authenticate.php (marker set) */
    if ($isSiteAdminReq && !empty($_SESSION['user_id'])) {
        $uid = (int) $_SESSION['user_id'];
        $mySites = [];
        try {
            foreach (get_table('network_site_users')['data'] as $r) if ((int) ($r['user_id'] ?? 0) === $uid) $mySites[] = (int) $r['site_id'];
        } catch (Throwable $e) {}
        $dbUserSite = (int) ($_SESSION['nexa_site_db_user'] ?? 0);
        if ($dbUserSite > 0) {
            if ($dbUserSite !== (int) $site['id']) {
                http_response_code(403);
                echo 'This admin area belongs to "' . htmlspecialchars((string) ($site['name'] ?? $site['slug']), ENT_QUOTES, 'UTF-8') . '". Your account belongs to a different subsite.';
                exit;
            }
        } elseif ($mySites) {
            if (!in_array((int) $site['id'], $mySites, true)) {
                http_response_code(403);
                echo 'This admin area belongs to "' . htmlspecialchars((string) ($site['name'] ?? $site['slug']), ENT_QUOTES, 'UTF-8') . '". Your account is assigned to a different subsite. Sign in via your own /subsite/admin/ address.';
                exit;
            }
        } elseif ($isNetworkAdminUser()) {
            $_SESSION['nexa_admin_site_id'] = (int) $site['id'];
        } else {
            http_response_code(403);
            echo 'This admin area belongs to "' . htmlspecialchars((string) ($site['name'] ?? $site['slug']), ENT_QUOTES, 'UTF-8') . '". Your account has no access to it.';
            exit;
        }
    }

    $GLOBALS['nexa_current_site'] = $site;

    if ($isSiteAdminReq) {
        $GLOBALS['nexa_in_site_admin'] = true;
        // Login handshake: authenticate.php must check credentials against
        // the MAIN users table first (network + pinned admins live there),
        // so for the authenticate POST we keep the main database and only
        // remember which site admin the user is signing in to.
        $baseScript = basename($scriptName);
        if ($baseScript === 'authenticate.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $_SESSION['nexa_pending_site_admin'] = (int) $site['id'];
            $GLOBALS['nexa_site_admin_deferred'] = true;
            if ($viaHost) $GLOBALS['nexa_site_url_host'] = (string) ($_SERVER['HTTP_HOST'] ?? ($site['host'] ?? ''));
            elseif ($pathPrefix !== '') $GLOBALS['nexa_site_url_prefix'] = $pathPrefix;
            $GLOBALS['nexa_site_admin_prefix'] = $viaHost ? '' : $pathPrefix;
            return;
        }
        if (empty($_SESSION['user_id']) && $baseScript === 'login.php') {
            $_SESSION['nexa_pending_site_admin'] = (int) $site['id'];
        }
        nexa_multisite_apply_storage($site);
        if ($viaHost) {
            $GLOBALS['nexa_site_url_host'] = (string) ($_SERVER['HTTP_HOST'] ?? ($site['host'] ?? ''));
            $GLOBALS['nexa_site_admin_prefix'] = '';
        } elseif ($pathPrefix !== '') {
            $GLOBALS['nexa_site_url_prefix'] = $pathPrefix;
            $GLOBALS['nexa_site_admin_prefix'] = $pathPrefix;
        }
        return;
    }

    nexa_multisite_apply_storage($site);

    if ($viaHost) {
        $GLOBALS['nexa_site_url_host'] = (string) ($_SERVER['HTTP_HOST'] ?? ($site['host'] ?? ''));
    } elseif ($pathPrefix !== '') {
        $GLOBALS['nexa_site_url_prefix'] = $pathPrefix;
        // Strip the prefix so permalinks/themes see a site-local path.
        $rest = trim(substr($rel, strlen($pathPrefix)), '/');
        $qs = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '');
        $_SERVER['REQUEST_URI'] = ($basePath === '' ? '' : $basePath) . '/' . $rest . ($qs !== '' ? '?' . $qs : '');
        $GLOBALS['nexa_site_original_uri'] = $reqPath;
    } elseif ($isAdminReq) {
        // Central admin: frontend links should still point at the real site URL.
        $url = nexa_multisite_site_public_url($site);
        $hostPart = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $pathPart = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        $installPath = trim((string) (parse_url(baseurl(), PHP_URL_PATH) ?? ''), '/');
        if ($pathPart !== '' && $pathPart !== $installPath) {
            $GLOBALS['nexa_site_url_prefix'] = $pathPart;
        } elseif ($hostPart !== '' && strcasecmp($hostPart, (string) (parse_url(baseurl(), PHP_URL_HOST) ?? '')) !== 0) {
            $GLOBALS['nexa_site_url_host'] = $hostPart;
        }
    }
}

/** Canonical URL of a subsite's own admin area, e.g. https://example.com/shopping/admin/ */
function nexa_multisite_site_admin_url(array $site): string {
    return rtrim(nexa_multisite_site_public_url($site), '/') . '/admin/';
}

/** Slug clash check used by subsite creation AND the main-site editor. */
function nexa_multisite_slug_taken_by_content(string $slug): bool {
    // Always evaluated against the MAIN site's posts (main DB context).
    return nexa_network_call(static function () use ($slug) {
        try {
            foreach (get_table('posts')['data'] as $row) {
                foreach ([(string) ($row['post_name'] ?? ''), (string) ($row['post_alias'] ?? ''), nexa_sanitize_slug((string) ($row['post_title'] ?? ''), '')] as $cand) {
                    if ($cand !== '' && strcasecmp($cand, $slug) === 0) return true;
                }
            }
        } catch (Throwable $e) {}
        return false;
    });
}

function nexa_multisite_forced_theme(): string {
    $site = nexa_current_site();
    if (!$site || trim((string) ($site['theme'] ?? '')) === '') return '';
    $slug = basename((string) $site['theme']);
    return is_dir(nexa_resolve_theme_path($slug, $site)) ? $slug : '';
}

function nexa_multisite_theme_allowed(string $slug): bool {
    $site = nexa_current_site();
    if (!$site) return true;
    if (nexa_multisite_forced_theme() !== '') return $slug === nexa_multisite_forced_theme();
    // A theme this subsite uploaded itself is its own copy — always usable here.
    if (nexa_site_has_theme_copy($slug, $site)) return true;
    $allowed = nexa_multisite_csv_list($site['allowed_themes'] ?? '');
    return !$allowed || in_array($slug, $allowed, true);
}

function nexa_multisite_plugin_allowed(string $slug): bool {
    $site = nexa_current_site();
    if (!$site) return true;
    $allowed = nexa_multisite_csv_list($site['allowed_plugins'] ?? '');
    $mandatory = nexa_multisite_csv_list($site['mandatory_plugins'] ?? '');
    return !$allowed || in_array($slug, $allowed, true) || in_array($slug, $mandatory, true);
}

/**
 * Is the signed-in user a NETWORK admin (may manage subsites, approve
 * packages, change network settings)? Subsite admins are NOT — not even
 * when their account role is "admin", and not even when their account
 * lives in a subsite's own database. Only an unassigned main-site admin
 * (flagged at login) qualifies. Subsites can never create subsites.
 */
function nexa_is_network_admin(): bool {
    if (!empty($_SESSION['nexa_site_db_user'])) return false; // account lives in a subsite's own DB
    if (!empty($_SESSION['nexa_network_admin'])) return true; // unassigned main-site admin (set at login)
    if (!empty($_SESSION['nexa_admin_site_id'])) return false; // pinned to / managing a subsite without the network flag
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && function_exists('nexa_network_call')) {
        $assigned = nexa_network_call(static function () use ($uid) {
            try { foreach (get_table('network_site_users')['data'] as $r) if ((int) ($r['user_id'] ?? 0) === $uid) return true; } catch (Throwable $e) {}
            return false;
        });
        if ($assigned) return false;
    }
    return in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true);
}

/* ==========================================================================
 * 17b. PACKAGE REPOSITORIES — browse & install plugins/themes from remote
 * indexes. A repository is any URL serving a NexaCMS repo index JSON:
 *   {"name":"My Repo","packages":[{"type":"plugin|theme","slug":"...",
 *     "name":"...","version":"1.0.0","author":"...","description":"...",
 *     "download_url":"https://.../pkg.zip","sha256":"(optional)",
 *     "thumbnail":"https://.../pkg.png (optional)"}]}
 * Sources: a NexaCMS site running the "Nexa Repository Hub" plugin
 * (type "hub": the site's base URL), a GitHub repo holding a
 * nexa-repo.json at its root (type "github": owner/repo + branch), or
 * any direct index URL (type "index"). Repositories are user-managed:
 * nothing is pre-registered or phoned home.
 * ========================================================================== */

/** The official NexaCMS packages repository (pre-registered on new installs). */
function nexa_official_repository(): array {
    return ['name' => 'NexaCMS Official Packages', 'type' => 'github', 'url' => '', 'repo' => 'nexaCMS/nexacms-packages', 'branch' => 'main', 'official' => true];
}

/**
 * Configured repositories for THIS site (stored as JSON in settings).
 * When repositories have never been configured on the MAIN site, the
 * official packages repository is pre-registered (it is labelled
 * "official" and can be removed like any other - saving any change
 * makes the list fully user-managed from then on). Subsites start
 * with a clean slate; their admins add repositories explicitly.
 * Nothing is contacted until an admin opens the Repositories page.
 */
function nexa_repositories(): array {
    global $settings;
    if (empty($settings)) loadsettings();
    if (!isset($settings['nexa_repositories'])) {
        $msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;
        return $msSite ? [] : [nexa_official_repository()];
    }
    $raw = (string) get_setting('nexa_repositories', '[]');
    $list = json_decode($raw, true);
    return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
}

function nexa_repo_index_url(array $repo): string {
    $type = (string) ($repo['type'] ?? 'hub');
    if ($type === 'github') {
        $slug = trim((string) ($repo['repo'] ?? ''), '/');
        $branch = trim((string) ($repo['branch'] ?? 'main')) ?: 'main';
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $slug)) return '';
        return 'https://raw.githubusercontent.com/' . $slug . '/' . rawurlencode($branch) . '/nexa-repo.json';
    }
    $url = trim((string) ($repo['url'] ?? ''));
    if ($url === '') return '';
    if ($type === 'index' || str_ends_with(strtolower($url), '.json')) return $url;
    // Hub: a NexaCMS site base URL; the hub plugin answers ?nexa_repo=1.
    return rtrim($url, '/') . '/?nexa_repo=1';
}

/** Small HTTPS/HTTP GET with a size cap. Returns null on any failure. */
function nexa_repo_http_get(string $url, int $maxBytes = 2097152): ?string {
    if (!preg_match('#^https?://#i', $url)) return null;
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 25, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: NexaCMS-Repository/1.0\r\nAccept: application/json, application/zip, */*\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    if ($data === false || strlen($data) > $maxBytes) return null;
    return $data;
}

/**
 * Fetch + normalise a repository index.
 * Returns ['name' => string, 'packages' => list, 'error' => string].
 */
function nexa_repo_fetch(array $repo): array {
    $indexUrl = nexa_repo_index_url($repo);
    if ($indexUrl === '') return ['name' => (string) ($repo['name'] ?? 'Repository'), 'packages' => [], 'error' => 'Repository is not configured correctly (missing URL or owner/repo).'];
    $raw = nexa_repo_http_get($indexUrl, 2097152);
    if ($raw === null) return ['name' => (string) ($repo['name'] ?? 'Repository'), 'packages' => [], 'error' => 'Could not fetch the repository index from ' . $indexUrl];
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
        return ['name' => (string) ($repo['name'] ?? 'Repository'), 'packages' => [], 'error' => 'The repository index is not valid NexaCMS repo JSON (expected an object with a "packages" list).'];
    }
    $baseDir = preg_replace('#/[^/]*$#', '/', $indexUrl) ?? $indexUrl;
    $origin = preg_match('#^(https?://[^/]+)#i', $indexUrl, $om) ? $om[1] : '';
    $packages = [];
    foreach ($data['packages'] as $p) {
        if (!is_array($p)) continue;
        $type = (string) ($p['type'] ?? '');
        $slug = basename((string) ($p['slug'] ?? ''));
        $dl = trim((string) ($p['download_url'] ?? ''));
        if (!in_array($type, ['plugin', 'theme'], true) || $slug === '' || $dl === '') continue;
        if (preg_match('#^https?://#i', $dl)) $dlAbs = $dl;
        elseif (str_starts_with($dl, '/')) $dlAbs = $origin . $dl;
        else $dlAbs = $baseDir . $dl;
        // Optional package artwork: "thumbnail" (or "icon"/"screenshot")
        // as an absolute or index-relative URL. http(s) only.
        $thumb = trim((string) ($p['thumbnail'] ?? $p['icon'] ?? $p['screenshot'] ?? ''));
        if ($thumb !== '' && !preg_match('#^https?://#i', $thumb)) {
            $thumb = str_starts_with($thumb, '/') ? $origin . $thumb : $baseDir . $thumb;
        }
        $packages[] = [
            'type' => $type, 'slug' => $slug,
            'name' => (string) ($p['name'] ?? $slug), 'version' => (string) ($p['version'] ?? ''),
            'author' => (string) ($p['author'] ?? ''), 'description' => (string) ($p['description'] ?? ''),
            'download_url' => $dlAbs, 'sha256' => strtolower(trim((string) ($p['sha256'] ?? ''))),
            'thumbnail' => $thumb,
        ];
    }
    return ['name' => (string) ($data['name'] ?? ($repo['name'] ?? 'Repository')), 'packages' => $packages, 'error' => ''];
}

function nexa_multisite_upload_policy(): string {
    $site = nexa_current_site();
    if (!$site) return 'allow';
    // Unset/legacy rows default to 'approval': a subsite package should
    // never install network-wide without a network admin saying yes.
    $p = (string) ($site['allow_uploads'] ?? '');
    return in_array($p, ['allow', 'deny', 'approval'], true) ? $p : 'approval';
}

/** All subsite package requests (optionally filtered by status), newest first. Reads the MAIN registry from any context. */
function nexa_network_package_requests(?string $status = null): array {
    $rows = nexa_network_call(static function () {
        nexa_multisite_ensure_registry();
        return get_table('network_package_requests')['data'];
    });
    if ($status !== null) $rows = array_values(array_filter($rows, static fn($r) => (string) ($r['status'] ?? '') === $status));
    return array_reverse(array_values($rows));
}

function nexa_network_pending_count(): int {
    try { return count(nexa_network_package_requests('pending')); } catch (Throwable $e) { return 0; }
}
