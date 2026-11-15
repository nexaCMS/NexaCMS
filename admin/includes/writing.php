<?php $diEditor = function_exists('nexa_editor_engine') ? nexa_editor_engine() : 'nexa'; $diTiny = function_exists('nexa_tinymce_available') && nexa_tinymce_available(); ?>
<h4>Content Editor</h4>
<div class="row">
  <div class="col-md-6 form-group">
    <label>Editor for Posts &amp; Pages</label>
    <select name="wysiwyg_editor" class="form-control">
      <option value="nexa" <?= $diEditor === 'nexa' ? 'selected' : '' ?>>Nexa WYSIWYG (built-in)</option>
      <option value="tinymce" <?= $diEditor === 'tinymce' ? 'selected' : '' ?> <?= $diTiny ? '' : 'disabled' ?>>TinyMCE<?= $diTiny ? '' : ' — not installed' ?></option>
    </select>
    <?php if ($diTiny): ?>
      <p class="help-block"><span class="label label-success">TinyMCE installed</span> <code>admin/vendor/tinymce/tinymce.min.js</code> found. You can also switch per-screen from the <strong>Editor</strong> toggle above the content box when editing any post or page.</p>
    <?php else: ?>
      <p class="help-block"><span class="label label-danger">TinyMCE not installed</span> The files were not found at <code>admin/vendor/tinymce/</code> — apply a <strong>FULL</strong> update package (partial packages may not include the 5.7&nbsp;MB vendor files), or upload TinyMCE there manually. Until then Nexa WYSIWYG is used.</p>
    <?php endif; ?>
    <p class="help-block">Comment form editor is separate: <a href="admin.php?plugin_page=nexa-comments">Plugins &rarr; Nexa Comments &rarr; Comment editor</a>.</p>
  </div>
</div>
<hr>
<div class="row">
  <div class="col-md-6 form-group">
    <label>Default Post Category</label>
    <input type="text" name="default_category" class="form-control" value="<?= esc_attr(get_setting('default_category', 'General')) ?>">
  </div>
</div>
<h4>Post via Email / Outbound Mail Server</h4>
<div class="row">
  <div class="col-md-6 form-group">
    <label>Mail Server Host</label>
    <input type="text" name="mailserver_url" class="form-control" value="<?= esc_attr(get_setting('mailserver_url', 'mail.example.com')) ?>">
  </div>
  <div class="col-md-2 form-group">
    <label>Port</label>
    <input type="number" name="mailserver_port" class="form-control" value="<?= esc_attr(get_setting('mailserver_port', '587')) ?>">
  </div>
</div>
<div class="row">
  <div class="col-md-4 form-group">
    <label>Login Name</label>
    <input type="text" name="mailserver_login" class="form-control" value="<?= esc_attr(get_setting('mailserver_login', '')) ?>">
  </div>
  <div class="col-md-4 form-group">
    <label>Password</label>
    <input type="password" name="mailserver_pass" class="form-control" value="<?= esc_attr(get_setting('mailserver_pass', '')) ?>">
  </div>
</div>
