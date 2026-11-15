<div class="alert alert-info">
  <strong>Automatic logoff (idle timeout):</strong> a signed-in user with no activity for the
  effective time limit is signed out, and must sign in again. Activity is any admin page,
  AJAX call or member action — an open-but-untouched browser tab does <em>not</em> count.
  The limit is enforced for admin sessions, AJAX/API calls and member (front-end) sessions.
  On a multisite network this page sets the rule for <strong>this site only</strong>.
</div>

<div class="checkbox">
  <label>
    <input type="checkbox" name="sess_idle_enabled" value="1" <?= get_setting('sess_idle_enabled', '1') === '1' ? 'checked' : '' ?>>
    Enable automatic logoff after inactivity
  </label>
</div>

<div class="form-group" style="max-width:280px;">
  <label>Default idle timeout (minutes)</label>
  <input type="number" name="sess_idle_minutes" class="form-control" min="1" max="10080" step="1"
         value="<?= esc_attr(get_setting('sess_idle_minutes', '30')) ?>">
  <p class="help-block">Applies to everyone without a personal setting. 30 is a good default for shared computers.</p>
</div>

<div class="checkbox">
  <label>
    <input type="checkbox" name="sess_idle_allow_user" value="1" <?= get_setting('sess_idle_allow_user', '1') === '1' ? 'checked' : '' ?>>
    Let users choose their own timeout (Profile &rarr; Auto Log Off; admins can also set it per user in Edit User)
  </label>
</div>

<div class="form-group" style="max-width:280px;">
  <label>Maximum personal timeout (minutes)</label>
  <input type="number" name="sess_idle_max_minutes" class="form-control" min="1" max="10080" step="1"
         value="<?= esc_attr(get_setting('sess_idle_max_minutes', '1440')) ?>">
  <p class="help-block">A personal setting can be shorter than the default, but never longer than this. 1440 = 24 hours.</p>
</div>

<div class="checkbox">
  <label>
    <input type="checkbox" name="sess_idle_allow_never" value="1" <?= get_setting('sess_idle_allow_never', '0') === '1' ? 'checked' : '' ?>>
    Allow users to choose &ldquo;Never&rdquo; (no automatic logoff for their own account)
  </label>
</div>

<?php if (function_exists('nexa_session_idle_limit_minutes')): ?>
<p class="text-muted" style="margin-top:12px;"><strong>Your effective limit right now:</strong>
  <?php $lim = nexa_session_idle_limit_minutes(); echo $lim > 0 ? esc_html($lim . ' minutes') : 'no automatic logoff'; ?></p>
<?php endif; ?>
