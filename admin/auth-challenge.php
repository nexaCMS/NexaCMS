<?php
/**
 * NexaCMS Authentication Challenge (admin/auth-challenge.php)
 * Core-owned pre-authentication route for challenge providers (see
 * includes/common-core.php §4b). A pending challenge grants NOTHING:
 * no login flag, user id or role exists until a registered provider's
 * verify() succeeds server-side and core finalizes the login itself.
 * A posted user id / success flag / return URL can never grant access.
 */
require_once __DIR__ . '/inc.php';

$pending = function_exists('nexa_auth_pending') ? nexa_auth_pending() : null;

if ($pending === null) {
    if (is_user_logged_in()) { header('Location: index.php'); exit; }
    $_SESSION['login_notice'] = 'Your sign-in verification expired or was cancelled. Please sign in again.';
    header('Location: login.php');
    exit;
}

$providers = nexa_auth_challenge_providers();
$provider = $providers[(string) $pending['provider']] ?? null;
$ctx = [
    'user_id'  => (int) $pending['user_id'],
    'username' => (string) $pending['username'],
    'role'     => (string) $pending['role'],
    'realm'    => (string) $pending['realm'],
    'site_id'  => (int) $pending['site_id'],
    'method'   => (string) $pending['method'],
    'remember' => !empty($pending['remember']),
    'return'   => 'index.php',
];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nexa_verify_csrf()) {
        $error = 'Security check failed. Please try again.';
    } elseif (!hash_equals((string) $pending['id'], (string) ($_POST['pending_id'] ?? ''))) {
        // Pending transaction binding: a stale or foreign form can do nothing.
        nexa_auth_clear_pending();
        $_SESSION['login_notice'] = 'Your sign-in verification expired or was cancelled. Please sign in again.';
        header('Location: login.php');
        exit;
    } elseif ((string) ($_POST['challenge_action'] ?? 'verify') === 'cancel') {
        nexa_auth_clear_pending();
        do_action('nexa_auth_challenge_cancelled', $ctx, $pending);
        do_action('nexa_auth_pending_cleared', $ctx, 'cancelled');
        $_SESSION['login_notice'] = 'Sign-in verification cancelled.';
        header('Location: login.php');
        exit;
    } elseif ($provider === null || !is_callable($provider['verify'] ?? null)) {
        // Fail closed: without its provider, a required challenge can
        // never complete (and can never be bypassed from here).
        $error = 'The required sign-in verification service is unavailable. Please contact the site administrator.';
    } else {
        try {
            $result = ($provider['verify'])($ctx, $pending, $_POST);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => 'Verification failed. Please try again.'];
        }
        if (!empty($result['ok'])) {
            do_action('nexa_auth_challenge_verified', $ctx, $pending);
            // Multi-provider contract: every provider that required a
            // challenge must complete, in order. Advance the SAME
            // pending transaction to the next provider.
            $completed = (array) ($pending['completed'] ?? []);
            $completed[] = (string) $pending['provider'];
            $queue = (array) ($pending['queue'] ?? []);
            if ($queue) {
                $next = (string) array_shift($queue);
                $pending['provider'] = $next;
                $pending['mode'] = (string) (($pending['modes'][$next] ?? 'verify'));
                $pending['queue'] = $queue;
                $pending['completed'] = $completed;
                $_SESSION['nexa_auth_pending'] = $pending;
                do_action('nexa_auth_challenge_started', $ctx, $pending);
                header('Location: auth-challenge.php');
                exit;
            }
            // Final provider done. The account MUST still exist in the
            // expected realm with the SAME user id — a deleted or
            // replaced account can never be resurrected from pending
            // data. Role/eligibility come from the live account row.
            $account = nexa_auth_refetch_account($pending);
            if ($account === null
                || (int) ($account['id'] ?? 0) !== (int) $pending['user_id']
                || strcasecmp((string) ($account['user'] ?? ''), (string) $pending['username']) !== 0) {
                nexa_auth_clear_pending();
                do_action('nexa_auth_challenge_denied', $ctx, ['action' => 'deny', 'provider' => (string) $pending['provider'], 'message' => 'Account no longer exists.']);
                $_SESSION['login_failure'] = 'That account no longer exists. Please contact the site administrator.';
                header('Location: login.php');
                exit;
            }
            // Consume the pending transaction ONCE, then finalize in core.
            nexa_auth_clear_pending();
            nexa_auth_finalize_login($account, [
                'realm'              => (string) $pending['realm'],
                'site_db_site'       => (int) $pending['site_db_site'],
                'pending_site_admin' => (int) $pending['pending_site_admin'],
                'site_id'            => (int) $pending['site_id'],
                'remember'           => !empty($pending['remember']),
                'method'             => (string) $pending['method'],
                'challenge_providers' => $completed,
            ]);
        }
        $error = (string) ($result['error'] ?? 'Verification failed. Please try again.');
    }
}

include_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="margin-top:60px;">
  <div class="row">
    <div class="col-md-4 col-md-offset-4">
      <div class="login-panel panel panel-primary" style="margin-top:15%;">
        <div class="panel-heading">
          <h3 class="panel-title"><i class="fa fa-shield fa-fw"></i> Additional verification required</h3>
        </div>
        <div class="panel-body">
          <p class="text-muted">Hi <?= esc_html($pending['username']) ?> — one more step before you are signed in.</p>
          <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= esc_html($error) ?></div>
          <?php endif; ?>
          <?php if ($provider === null || !is_callable($provider['render'] ?? null)): ?>
            <div class="alert alert-danger">The required sign-in verification service is unavailable. Please contact the site administrator.</div>
          <?php else: ?>
            <form method="post" action="auth-challenge.php">
              <?= nexa_csrf_field() ?>
              <input type="hidden" name="pending_id" value="<?= esc_attr($pending['id']) ?>">
              <input type="hidden" name="challenge_action" value="verify">
              <?php ($provider['render'])($ctx, $pending); ?>
            </form>
          <?php endif; ?>
          <form method="post" action="auth-challenge.php" style="margin-top:10px;">
            <?= nexa_csrf_field() ?>
            <input type="hidden" name="pending_id" value="<?= esc_attr($pending['id']) ?>">
            <input type="hidden" name="challenge_action" value="cancel">
            <button type="submit" class="btn btn-default btn-block">Cancel and return to sign in</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
