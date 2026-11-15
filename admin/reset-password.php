<?php
/**
 * NexaCMS Reset Password (admin/reset-password.php)
 * Completes an emailed reset link (see includes/common-core.php §4e).
 * The token is validated server-side (SHA-256 hash + expiry, single
 * use); the new password must satisfy the current password policy.
 * Nothing here grants a session — afterwards the user signs in normally
 * (and any re-login rules, e.g. 2FA challenges, apply as usual).
 */
require_once __DIR__ . '/inc.php';

if (is_user_logged_in()) { header('Location: index.php'); exit; }

$cfg = function_exists('nexa_password_reset_settings') ? nexa_password_reset_settings() : ['enabled' => false, 'expire_minutes' => 60];
$username = trim((string) ($_REQUEST['user'] ?? ''));
$token    = (string) ($_REQUEST['token'] ?? '');

$valid = ($cfg['enabled'] && function_exists('nexa_password_reset_validate'))
    ? nexa_password_reset_validate($username, $token) : null;

$error = '';
$problems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid !== null) {
    if (!nexa_verify_csrf()) {
        $error = 'Security check failed. Please try again.';
    } else {
        [$account, $realm] = $valid;
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($newPass !== $confirm) {
            $error = 'New password and confirmation do not match.';
        } else {
            $problems = nexa_password_reset_complete($account, $realm, $newPass);
            if (!$problems) {
                do_action('nexa_auth_pending_cleared', ['user_id' => (int) $account['id'], 'username' => (string) $account['user']], 'password_reset');
                $_SESSION['login_notice'] = 'Your password has been reset. Please sign in with your new password.';
                header('Location: login.php');
                exit;
            }
            $error = 'Please fix the following:';
            // The token is NOT burned by a failed attempt — reload the
            // still-valid account state for re-rendering.
            $valid = nexa_password_reset_validate($username, $token);
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
          <h3 class="panel-title"><i class="fa fa-key fa-fw"></i> Choose a new password</h3>
        </div>
        <div class="panel-body">
          <?php if (!$cfg['enabled']): ?>
            <div class="alert alert-warning">Password reset by email is currently disabled. Please contact the site administrator.</div>
          <?php elseif ($valid === null): ?>
            <div class="alert alert-danger">This password reset link is invalid or has expired.</div>
            <p class="text-muted">Reset links can be used once and expire after <?= (int) $cfg['expire_minutes'] ?> minutes. You can request a new one at any time.</p>
            <a href="forgot-password.php" class="btn btn-primary btn-block">Request a New Link</a>
          <?php else: ?>
            <p class="text-muted">Hi <?= esc_html($valid[0]['user'] ?? $username) ?> — choose a new password for your account.</p>
            <?php if ($error !== ''): ?>
              <div class="alert alert-danger"><?= esc_html($error) ?></div>
            <?php endif; ?>
            <?php if ($problems): ?>
              <div class="alert alert-warning">
                <ul style="margin-bottom:0; padding-left:18px;">
                  <?php foreach ($problems as $prob): ?><li><?= esc_html($prob) ?></li><?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            <?php if (function_exists('nexa_password_policy_summary')): ?>
              <p class="text-muted"><i class="fa fa-shield"></i> <?= esc_html(nexa_password_policy_summary()) ?></p>
            <?php endif; ?>
            <form method="post" action="reset-password.php">
              <?= nexa_csrf_field() ?>
              <input type="hidden" name="user" value="<?= esc_attr($username) ?>">
              <input type="hidden" name="token" value="<?= esc_attr($token) ?>">
              <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" class="form-control" autocomplete="new-password" required autofocus>
              </div>
              <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" required>
              </div>
              <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-check"></i> Reset Password</button>
            </form>
          <?php endif; ?>
          <div style="margin-top:12px;text-align:center;">
            <a href="login.php">&larr; Back to sign in</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
