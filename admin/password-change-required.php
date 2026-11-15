<?php
/**
 * NexaCMS Password Update Required (admin/password-change-required.php)
 * Core-owned pre-authentication route: a password sign-in whose password
 * no longer meets the current password policy pauses here (see
 * includes/common-core.php §4d). The pending state grants NOTHING — no
 * login flag, user id or role exists until the user chooses a compliant
 * password and core resumes the normal login lifecycle (any registered
 * auth challenges still run afterwards, then finalize). A posted user
 * id or success flag can never grant access.
 */
require_once __DIR__ . '/inc.php';

$pending = function_exists('nexa_pwchange_pending') ? nexa_pwchange_pending() : null;

if ($pending === null) {
    if (is_user_logged_in()) { header('Location: index.php'); exit; }
    $_SESSION['login_notice'] = 'That password update expired or was cancelled. Please sign in again.';
    header('Location: login.php');
    exit;
}

$error = '';
$problems = (array) ($pending['errors'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nexa_verify_csrf()) {
        $error = 'Security check failed. Please try again.';
    } elseif (!hash_equals((string) $pending['id'], (string) ($_POST['pending_id'] ?? ''))) {
        // Pending transaction binding: a stale or foreign form can do nothing.
        nexa_pwchange_clear();
        $_SESSION['login_notice'] = 'That password update expired or was cancelled. Please sign in again.';
        header('Location: login.php');
        exit;
    } elseif ((string) ($_POST['pwchange_action'] ?? 'update') === 'cancel') {
        nexa_pwchange_clear();
        do_action('nexa_auth_pending_cleared', ['user_id' => (int) $pending['user_id'], 'username' => (string) $pending['username']], 'pwchange_cancelled');
        $_SESSION['login_notice'] = 'Password update cancelled. Please sign in when you are ready.';
        header('Location: login.php');
        exit;
    } else {
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($newPass !== $confirm) {
            $error = 'New password and confirmation do not match.';
        } else {
            $pwErrs = function_exists('nexa_password_validate')
                ? nexa_password_validate($newPass, (string) $pending['username']) : [];
            if ($pwErrs) {
                $problems = $pwErrs;
                $error = 'Please fix the following:';
            } else {
                // The account MUST still exist in the expected realm with
                // the SAME user id — a deleted or replaced account can
                // never be resurrected from pending data.
                $account = function_exists('nexa_pwchange_complete')
                    ? nexa_pwchange_complete($pending, $newPass) : null;
                if ($account === null) {
                    nexa_pwchange_clear();
                    $_SESSION['login_failure'] = 'That account no longer exists. Please contact the site administrator.';
                    header('Location: login.php');
                    exit;
                }
                // Consume the pending transaction ONCE, then resume the
                // standard lifecycle: challenge providers still decide,
                // and only nexa_auth_finalize_login() grants the session.
                nexa_pwchange_clear();
                do_action('nexa_password_changed', ['user_id' => (int) $pending['user_id'], 'username' => (string) $pending['username'], 'realm' => (string) $pending['realm']], 'login_upgrade');
                nexa_auth_attempt($account, [
                    'realm'              => (string) $pending['realm'],
                    'site_db_site'       => (int) $pending['site_db_site'],
                    'pending_site_admin' => (int) $pending['pending_site_admin'],
                    'site_id'            => (int) $pending['site_id'],
                    'remember'           => !empty($pending['remember']),
                    'method'             => 'password',
                ]);
            }
        }
    }
}

include_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="margin-top:60px;">
  <div class="row">
    <div class="col-md-4 col-md-offset-4">
      <div class="login-panel panel panel-primary" style="margin-top:15%;">
        <div class="panel-heading">
          <h3 class="panel-title"><i class="fa fa-key fa-fw"></i> Password update required</h3>
        </div>
        <div class="panel-body">
          <p class="text-muted">Hi <?= esc_html($pending['username']) ?> — your current password no longer meets this site's password policy. Please choose a new one to continue signing in.</p>
          <?php if ($problems): ?>
            <div class="alert alert-warning">
              <strong>Your current password:</strong>
              <ul style="margin-bottom:0; padding-left:18px;">
                <?php foreach ($problems as $prob): ?><li><?= esc_html($prob) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= esc_html($error) ?></div>
          <?php endif; ?>
          <?php if (function_exists('nexa_password_policy_summary')): ?>
            <p class="text-muted"><i class="fa fa-shield"></i> <?= esc_html(nexa_password_policy_summary()) ?></p>
          <?php endif; ?>
          <form method="post" action="password-change-required.php">
            <?= nexa_csrf_field() ?>
            <input type="hidden" name="pending_id" value="<?= esc_attr($pending['id']) ?>">
            <input type="hidden" name="pwchange_action" value="update">
            <div class="form-group">
              <label>New Password</label>
              <input type="password" name="new_password" class="form-control" autocomplete="new-password" required autofocus>
            </div>
            <div class="form-group">
              <label>Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-check"></i> Update Password &amp; Continue</button>
          </form>
          <form method="post" action="password-change-required.php" style="margin-top:10px;">
            <?= nexa_csrf_field() ?>
            <input type="hidden" name="pending_id" value="<?= esc_attr($pending['id']) ?>">
            <input type="hidden" name="pwchange_action" value="cancel">
            <button type="submit" class="btn btn-default btn-block">Cancel and return to sign in</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
