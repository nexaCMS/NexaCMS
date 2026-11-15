<?php
/**
 * NexaCMS Login Authenticator (admin/authenticate.php)
 */
require_once __DIR__ . '/inc.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$passwd   = (string) ($_POST['passwd'] ?? '');
$remember = !empty($_POST['remember']);

$authKey   = $config['auth_key'] ?? '';
$secretKey = ($config['secret_key'] ?? '') ?: 'dicimes-default-secret';
$sessFlag  = 'user_logged_in' . $authKey;

$mainAuthOk = $username !== '' && checkpass($username, $passwd, $remember, false);
$siteDbAuthSite = 0;
if (!$mainAuthOk && $username !== '' && !empty($_SESSION['nexa_pending_site_admin']) && function_exists('nexa_multisite_apply_storage')) {
    // Accounts that live only in a subsite's OWN database (separate storage)
    // sign in through that subsite's /subsite/admin address: retry there.
    $pendingSite = null;
    try { foreach (get_table('network_sites')['data'] as $s) if ((int) ($s['id'] ?? 0) === (int) $_SESSION['nexa_pending_site_admin']) { $pendingSite = $s; break; } } catch (Throwable $e) {}
    if ($pendingSite) {
        nexa_multisite_apply_storage($pendingSite);
        if (checkpass($username, $passwd, $remember, false)) {
            $siteDbAuthSite = (int) $pendingSite['id'];
        } else {
            // Restore the main database before reporting the failure.
            $config['database'] = $GLOBALS['nexa_main_db_config'] ?? $config['database'];
            $GLOBALS['nexa_db_table_prefix'] = '';
        }
    }
}

if ($mainAuthOk || $siteDbAuthSite > 0) {
    // Re-fetch user after checkpass() in case MD5 password was upgraded to bcrypt
    $account = di_find_user_by_login($username);
    if ($account !== null) {
        $pendingSiteAdmin = (int) ($_SESSION['nexa_pending_site_admin'] ?? 0);
        unset($_SESSION['nexa_pending_site_admin']);
        $authOpts = [
            'realm'              => $siteDbAuthSite > 0 ? 'site:' . $siteDbAuthSite : 'main',
            'site_db_site'       => $siteDbAuthSite,
            'pending_site_admin' => $pendingSiteAdmin,
            'site_id'            => $pendingSiteAdmin,
            'remember'           => $remember,
            'method'             => 'password',
        ];
        // Password-policy upgrade: the plaintext is only available NOW.
        // If it no longer meets the current policy, the sign-in pauses
        // (granting nothing) until a compliant password is chosen; the
        // normal challenge/finalize lifecycle resumes afterwards.
        if ((string) get_setting('pw_prompt_on_login', '1') === '1'
            && function_exists('nexa_password_validate') && function_exists('nexa_pwchange_begin')) {
            $pwErrs = nexa_password_validate($passwd, (string) ($account['user'] ?? $username));
            if ($pwErrs) nexa_pwchange_begin($account, $authOpts, $pwErrs);
        }
        // Password is valid. The login is NOT complete yet: registered
        // challenge providers (2FA/enrollment) decide next, and only
        // nexa_auth_finalize_login() grants the authenticated session.
        nexa_auth_attempt($account, $authOpts);
    }
}

$_SESSION['login_failure'] = 'Invalid username/email or password.';
header('Location: login.php');
exit;
