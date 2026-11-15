<div class="row">
  <div class="col-sm-6 form-group">
    <label>Username *</label>
    <input type="text" name="user_name" class="form-control" value="<?= esc_attr($edit ? ($admin_account['user'] ?? '') : '') ?>" <?= $edit ? 'readonly' : 'required' ?>>
  </div>
  <div class="col-sm-6 form-group">
    <label>Display Name *</label>
    <input type="text" name="displayname" class="form-control" value="<?= esc_attr($edit ? di_user_display_name($admin_account) : '') ?>" required>
  </div>
</div>
<div class="row">
  <div class="col-sm-6 form-group">
    <label>Email Address *</label>
    <input type="email" name="email" class="form-control" value="<?= esc_attr($edit ? ($admin_account['email'] ?? '') : '') ?>" required>
  </div>
  <div class="col-sm-6 form-group">
    <label><?= $edit ? 'New Password (leave blank to keep unchanged)' : 'Password *' ?></label>
    <input type="password" name="passwd" class="form-control" autocomplete="new-password" <?= $edit ? '' : 'required' ?>>
    <?php if (function_exists('nexa_password_policy_summary')): ?><p class="help-block"><i class="fa fa-shield"></i> <?= esc_html(nexa_password_policy_summary()) ?></p><?php endif; ?>
  </div>
</div>
<div class="row">
  <div class="col-sm-6 form-group">
    <label>Role</label>
    <?php $curRole = $edit ? ($admin_account['role'] ?? 'subscriber') : 'admin'; ?>
    <select name="admin_type" class="form-control">
      <?php foreach (['super_admin' => 'Super Administrator', 'admin' => 'Administrator', 'editor' => 'Editor', 'author' => 'Author', 'subscriber' => 'Subscriber'] as $rk => $rl): ?>
        <option value="<?= esc_attr($rk) ?>" <?= $curRole === $rk ? 'selected' : '' ?>><?= esc_html($rl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-6 form-group">
    <label>Phone</label>
    <input type="text" name="phone" class="form-control" value="<?= esc_attr($edit ? ($admin_account['phone'] ?? '') : '') ?>">
  </div>
</div>
<?php if ($edit && function_exists('nexa_session_idle_settings')): $idleCfg = nexa_session_idle_settings(); $curIdle = (string) ($admin_account['idle_timeout_minutes'] ?? ''); ?>
<div class="row">
  <div class="col-sm-6 form-group">
    <label>Auto Log Off (idle timeout)</label>
    <select name="idle_timeout_minutes" class="form-control">
      <option value="" <?= $curIdle === '' ? 'selected' : '' ?>>Site default (<?= (int) $idleCfg['default_minutes'] ?> minutes)</option>
      <?php foreach (nexa_session_idle_presets() as $mins): if ($mins > (int) $idleCfg['max_minutes']) continue; ?>
        <option value="<?= $mins ?>" <?= $curIdle === (string) $mins ? 'selected' : '' ?>><?= $mins >= 60 && $mins % 60 === 0 ? ($mins / 60) . ' hour' . ($mins > 60 ? 's' : '') : $mins . ' minutes' ?></option>
      <?php endforeach; ?>
      <?php if ($idleCfg['allow_never']): ?><option value="0" <?= $curIdle === '0' ? 'selected' : '' ?>>Never</option><?php endif; ?>
    </select>
    <p class="help-block">Personal setting for this user (Sessions &amp; Login controls availability and the maximum).</p>
  </div>
</div>
<?php endif; ?>
<button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?= $edit ? 'Update User' : 'Create User' ?></button>
<a href="admin_users.php" class="btn btn-default">Cancel</a>
