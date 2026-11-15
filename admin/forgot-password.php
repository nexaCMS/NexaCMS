<?php
/**
 * NexaCMS Forgot Password (admin/forgot-password.php)
 * Requests an emailed, single-use reset link (see includes/common-core.php
 * §4e). The response is deliberately identical whether or not an account
 * matches, so this page cannot be used to enumerate usernames/emails.
 */
require_once __DIR__ . '/inc.php';

if (is_user_logged_in()) { header('Location: index.php'); exit; }

$cfg = function_exists('nexa_password_reset_settings') ? nexa_password_reset_settings() : ['enabled' => false, 'expire_minutes' => 60];
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (nexa_verify_csrf()) {
        $login = trim((string) ($_POST['user_login'] ?? ''));
        if ($login !== '' && function_exists('nexa_password_reset_request')) {
            nexa_password_reset_request($login);
        }
    }
    // Same outcome either way — by design (see §4e).
    $sent = true;
}

include_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="margin-top:60px;">
  <div class="row">
    <div class="col-md-4 col-md-offset-4">
      <div class="login-panel panel panel-primary" style="margin-top:15%;">
        <div class="panel-heading">
          <h3 class="panel-title"><i class="fa fa-envelope fa-fw"></i> Forgot your password?</h3>
        </div>
        <div class="panel-body">
          <?php if (!$cfg['enabled']): ?>
            <div class="alert alert-warning">Password reset by email is currently disabled. Please contact the site administrator.</div>
          <?php elseif ($sent): ?>
            <div class="alert alert-success">
              If an account matches that username or email, a password reset link has been sent to the
              account's email address. The link expires in <?= (int) $cfg['expire_minutes'] ?> minutes and can be used once.
            </div>
            <p class="text-muted">Didn't get it? Check your spam folder, wait a minute, then try again.</p>
          <?php else: ?>
            <p class="text-muted">Enter your username or email address and we'll send you a link to choose a new password.</p>
            <form method="post" action="forgot-password.php">
              <?= nexa_csrf_field() ?>
              <div class="form-group">
                <label>Username or Email</label>
                <input type="text" name="user_login" class="form-control" required autofocus autocomplete="username">
              </div>
              <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-paper-plane"></i> Send Reset Link</button>
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
