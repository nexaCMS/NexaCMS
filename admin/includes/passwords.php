<div class="alert alert-info">
  These rules are enforced <strong>every time a password is created or changed</strong> — Add User, Edit User, Profile, public registration and subsite-admin creation.
  Existing passwords cannot be re-checked from their stored hash — but at the next <strong>password sign-in</strong> the user
  can be asked to update a password that no longer meets this policy before continuing (see below).
  Remember-me sign-ins carry no password and are never blocked by this.
  On a multisite network this page sets the policy for <strong>this site only</strong>; each subsite has its own.
</div>

<div class="form-group" style="max-width:280px;">
  <label>Minimum password length</label>
  <input type="number" name="pw_min_length" class="form-control" min="4" max="64" step="1"
         value="<?= esc_attr(get_setting('pw_min_length', '8')) ?>">
  <p class="help-block">Between 4 and 64 characters. 12 or more is recommended.</p>
</div>

<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_require_upper" value="1" <?= get_setting('pw_require_upper', '0') === '1' ? 'checked' : '' ?>>
    Require an uppercase letter (A–Z)
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_require_lower" value="1" <?= get_setting('pw_require_lower', '0') === '1' ? 'checked' : '' ?>>
    Require a lowercase letter (a–z)
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_require_digit" value="1" <?= get_setting('pw_require_digit', '0') === '1' ? 'checked' : '' ?>>
    Require a number (0–9)
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_require_symbol" value="1" <?= get_setting('pw_require_symbol', '0') === '1' ? 'checked' : '' ?>>
    Require a symbol (e.g. ! @ # $ % &amp; *)
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_no_username" value="1" <?= get_setting('pw_no_username', '1') === '1' ? 'checked' : '' ?>>
    Password must not contain the username
  </label>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_forbid_common" value="1" <?= get_setting('pw_forbid_common', '0') === '1' ? 'checked' : '' ?>>
    Block common / easily guessed passwords (password123, qwerty123, …)
  </label>
</div>

<hr>
<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_prompt_on_login" value="1" <?= get_setting('pw_prompt_on_login', '1') === '1' ? 'checked' : '' ?>>
    At sign-in, require users whose password no longer meets this policy to choose a compliant one before continuing
  </label>
  <p class="help-block" style="margin-left:20px;">Recommended after tightening this policy: affected users update their password at their next password sign-in (nothing is granted until they do; any 2FA challenge still runs afterwards). Existing sessions are not interrupted.</p>
</div>

<hr>
<h4 style="margin-top:20px;">Forgot Password (emailed reset links)</h4>
<div class="checkbox">
  <label>
    <input type="checkbox" name="pw_reset_enabled" value="1" <?= get_setting('pw_reset_enabled', '1') === '1' ? 'checked' : '' ?>>
    Allow users to reset a forgotten password by email
  </label>
  <p class="help-block" style="margin-left:20px;">Adds a &ldquo;Forgot your password?&rdquo; link to the sign-in page. The user receives a single-use link; only a hash of the token is stored. Sending uses the SMTP plugin's <code>nexa_mail()</code> when active, otherwise PHP <code>mail()</code> — make sure outgoing mail works on this server.</p>
</div>
<div class="form-group" style="max-width:280px;">
  <label>Reset link lifetime (minutes)</label>
  <input type="number" name="pw_reset_expire_minutes" class="form-control" min="5" max="1440" step="1"
         value="<?= esc_attr(get_setting('pw_reset_expire_minutes', '60')) ?>">
  <p class="help-block">Between 5 minutes and 24 hours. 60 is a good default.</p>
</div>

<p class="text-muted" style="margin-top:12px;"><strong>Current policy:</strong> <?= esc_html(function_exists('nexa_password_policy_summary') ? nexa_password_policy_summary() : '') ?></p>
