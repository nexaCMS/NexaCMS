<?php
/**
 * NexaCMS Admin Login Screen (admin/login.php)
 */
require_once __DIR__ . '/inc.php';

$authKey   = $config['auth_key'] ?? '';
$secretKey = ($config['secret_key'] ?? '') ?: 'dicimes-default-secret';
$sessFlag  = 'user_logged_in' . $authKey;

if (!empty($_SESSION[$sessFlag])) {
    header('Location: index.php');
    exit;
}

// Validate HMAC-signed remember-me cookie if present
$remUserCookie = 'di_rem_user' . $authKey;
$remSigCookie  = 'di_rem_sig'  . $authKey;
if (!empty($_COOKIE[$remUserCookie]) && !empty($_COOKIE[$remSigCookie]) && empty($_SESSION['login_failure'])) {
    $candidate = di_find_user_by_login((string) $_COOKIE[$remUserCookie]);
    if ($candidate !== null) {
        $expectedSig = hash_hmac('sha256', $candidate['id'] . '|' . ($candidate['pass'] ?? ''), $secretKey);
        if (hash_equals($expectedSig, (string) $_COOKIE[$remSigCookie])) {
            // A valid remember-me cookie is a validated credential, not a
            // completed login: it enters the same challenge lifecycle as
            // a password, so it can never bypass a required second factor.
            nexa_auth_attempt($candidate, [
                'realm'    => 'main',
                'method'   => 'remember',
                'remember' => false,
                'site_id'  => (int) ($_SESSION['nexa_pending_site_admin'] ?? 0),
                'pending_site_admin' => (int) ($_SESSION['nexa_pending_site_admin'] ?? 0),
            ]);
        }
    }
    setcookie($remUserCookie, '', time() - 3600, '/');
    setcookie($remSigCookie, '', time() - 3600, '/');
}

include_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="margin-top:60px;">
  <div class="row">
    <div class="col-md-4 col-md-offset-4">
      <form class="form loginform" method="POST" action="authenticate.php">
        <?= di_csrf_field() ?>
        <div class="login-panel panel panel-primary" style="margin-top:15%;">
          <div class="panel-heading">
            <h3 class="panel-title"><i class="fa fa-lock fa-fw"></i> Sign in to <?= esc_html(get_bloginfo('name')) ?></h3>
          </div>
          <div class="panel-body">
            <?php if (!empty($_SESSION['login_failure'])): ?>
              <div class="alert alert-danger alert-dismissable">
                <a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>
                <?= esc_html($_SESSION['login_failure']); unset($_SESSION['login_failure']); ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['login_notice'])): ?>
              <div class="alert alert-info alert-dismissable">
                <a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>
                <?= esc_html($_SESSION['login_notice']); unset($_SESSION['login_notice']); ?>
              </div>
            <?php endif; ?>

            <div class="form-group">
              <label class="control-label">Username or Email</label>
              <input type="text" name="username" class="form-control" placeholder="admin" required autofocus>
            </div>
            <div class="form-group">
              <label class="control-label">Password</label>
              <input type="password" name="passwd" class="form-control" placeholder="••••••••" required>
            </div>
            <div class="checkbox">
              <label>
                <input name="remember" type="checkbox" value="1"> Remember Me (30 days)
              </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-sign-in"></i> Login</button>
            <?php if ((string) get_setting('pw_reset_enabled', '1') === '1'): ?>
            <div style="margin-top:10px;text-align:center;">
              <a href="forgot-password.php"><i class="fa fa-question-circle"></i> Forgot your password?</a>
            </div>
            <?php endif; ?>
            <div style="margin-top:12px;text-align:center;">
              <a href="../">&larr; Back to <?= esc_html(get_bloginfo('name')) ?></a>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
