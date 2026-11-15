<?php
/**
 * NexaCMS User Profile Page (admin/profile.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$currentUser = !empty($_SESSION['name']) ? di_find_user_by_login((string) $_SESSION['name']) : null;
if ($currentUser === null) {
    $allUsers = get_table('users')['data'];
    $currentUser = $allUsers[0] ?? ['id' => '1', 'user' => 'admin', 'email' => '', 'displayname' => 'Administrator'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $displayName = trim((string) ($_POST['displayname'] ?? ''));
    $email       = trim((string) ($_POST['email'] ?? ''));
    $newPass     = (string) ($_POST['new_password'] ?? '');
    $confirmPass = (string) ($_POST['confirm_password'] ?? '');

    $updates = [
        'id'           => $currentUser['id'],
        'displayname'  => $displayName !== '' ? $displayName : $currentUser['user'],
        'display-name' => $displayName !== '' ? $displayName : $currentUser['user'],
        'email'        => $email,
        'fname'        => trim((string) ($_POST['fname'] ?? '')),
        'lname'        => trim((string) ($_POST['lname'] ?? '')),
        'phone'        => trim((string) ($_POST['phone'] ?? '')),
        'address'      => trim((string) ($_POST['address'] ?? '')),
    ];

    // Personal idle timeout (Sessions & Login): only when the admin
    // allows personal settings; '' = site default, 0 = Never.
    if (function_exists('nexa_session_idle_settings')) {
        $idleCfg = nexa_session_idle_settings();
        if ($idleCfg['allow_user'] && isset($_POST['idle_timeout_minutes'])) {
            $raw = trim((string) $_POST['idle_timeout_minutes']);
            $val = '';
            if ($raw === '0' && $idleCfg['allow_never']) $val = '0';
            elseif (in_array((int) $raw, nexa_session_idle_presets(), true)) $val = (string) (int) $raw;
            $updates['idle_timeout_minutes'] = $val;
            $_SESSION['nexa_idle_pref'] = $val;
        }
    }

    if ($newPass !== '') {
        if ($newPass !== $confirmPass) {
            $_SESSION['failure'] = 'New password and confirmation do not match.';
            header('Location: profile.php');
            exit;
        }
        $pwErrors = function_exists('nexa_password_validate') ? nexa_password_validate($newPass, (string) ($currentUser['user'] ?? '')) : [];
        if ($pwErrors) {
            $_SESSION['failure'] = implode('<br>', array_map('esc_html', $pwErrors));
            header('Location: profile.php');
            exit;
        }
        $updates['pass'] = password_hash($newPass, PASSWORD_DEFAULT);
    }

    update_table('users', $updates, 'update');
    $_SESSION['display-name'] = $updates['displayname'];
    $_SESSION['success'] = 'Your profile has been updated.';
    header('Location: profile.php');
    exit;
}

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header"><i class="fa fa-user fa-fw"></i> My Profile</h1>
    </div>
  </div>
  <?php include __DIR__ . '/includes/flash_messages.php'; ?>
  <div class="row">
    <div class="col-md-8">
      <div class="panel panel-default">
        <div class="panel-heading">Account Details (<strong><?= esc_html($currentUser['user'] ?? '') ?></strong> &mdash; Role: <code><?= esc_html($currentUser['role'] ?? 'super_admin') ?></code>)</div>
        <div class="panel-body">
          <form method="post" action="profile.php">
            <?= di_csrf_field() ?>
            <div class="row">
              <div class="col-sm-6 form-group">
                <label>Display Name</label>
                <input type="text" name="displayname" class="form-control" value="<?= esc_attr(di_user_display_name($currentUser)) ?>" required>
              </div>
              <div class="col-sm-6 form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" value="<?= esc_attr($currentUser['email'] ?? '') ?>">
              </div>
            </div>
            <div class="row">
              <div class="col-sm-6 form-group">
                <label>First Name</label>
                <input type="text" name="fname" class="form-control" value="<?= esc_attr($currentUser['fname'] ?? '') ?>">
              </div>
              <div class="col-sm-6 form-group">
                <label>Last Name</label>
                <input type="text" name="lname" class="form-control" value="<?= esc_attr($currentUser['lname'] ?? '') ?>">
              </div>
            </div>
            <div class="row">
              <div class="col-sm-6 form-group">
                <label>Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= esc_attr($currentUser['phone'] ?? '') ?>">
              </div>
              <div class="col-sm-6 form-group">
                <label>Address</label>
                <input type="text" name="address" class="form-control" value="<?= esc_attr($currentUser['address'] ?? '') ?>">
              </div>
            </div>
            <hr>
            <h4>Change Password <small>(leave blank to keep current password)</small></h4>
            <?php if (function_exists('nexa_password_policy_summary')): ?><p class="text-muted"><i class="fa fa-shield"></i> <?= esc_html(nexa_password_policy_summary()) ?></p><?php endif; ?>
            <div class="row">
              <div class="col-sm-6 form-group">
                <label>New Password</label>
                <input type="password" name="new_password" class="form-control" autocomplete="new-password">
              </div>
              <div class="col-sm-6 form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
              </div>
            </div>
            <?php if (function_exists('nexa_session_idle_settings') && nexa_session_idle_settings()['enabled'] && nexa_session_idle_settings()['allow_user']): $idleCfg = nexa_session_idle_settings(); $curIdle = (string) ($currentUser['idle_timeout_minutes'] ?? ''); ?>
            <hr>
            <h4>Auto Log Off <small>(sign out after inactivity)</small></h4>
            <div class="row">
              <div class="col-sm-6 form-group">
                <label>Sign me out after</label>
                <select name="idle_timeout_minutes" class="form-control">
                  <option value="" <?= $curIdle === '' ? 'selected' : '' ?>>Site default (<?= (int) $idleCfg['default_minutes'] ?> minutes)</option>
                  <?php foreach (nexa_session_idle_presets() as $mins): if ($mins > (int) $idleCfg['max_minutes']) continue; ?>
                    <option value="<?= $mins ?>" <?= $curIdle === (string) $mins ? 'selected' : '' ?>><?= $mins >= 60 && $mins % 60 === 0 ? ($mins / 60) . ' hour' . ($mins > 60 ? 's' : '') : $mins . ' minutes' ?></option>
                  <?php endforeach; ?>
                  <?php if ($idleCfg['allow_never']): ?><option value="0" <?= $curIdle === '0' ? 'selected' : '' ?>>Never</option><?php endif; ?>
                </select>
                <p class="help-block">Personal settings can be shorter than the site default, up to the admin maximum of <?= (int) $idleCfg['max_minutes'] ?> minutes.</p>
              </div>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Profile</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
