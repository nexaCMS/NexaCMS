<?php $perm = get_setting('permalink_structure', '/%postname%/'); ?>
<div class="form-group">
  <label>Common Permalink Formats</label>
  <?php
  $presets = [
      '?p=%post_id%'                          => ['Plain (GET)', '?p=1'],
      '/%postname%/'                          => ['Post name', 'my-post/'],
      '/%year%/%monthnum%/%postname%/'        => ['Month and name', '2026/11/my-post/'],
      '/%year%/%monthnum%/%day%/%postname%/'  => ['Day and name', '2026/11/14/my-post/'],
      '/%post_id%/'                           => ['Numeric', '3/'],
  ];
  foreach ($presets as $pattern => [$label, $example]): ?>
  <div class="radio">
    <label>
      <input type="radio" name="di_perm_preset" onclick="document.getElementById('di_perm_input').value='<?= esc_attr($pattern) ?>'" <?= $perm === $pattern ? 'checked' : '' ?>>
      <?= esc_html($label) ?> &mdash; <code><?= esc_html(home_url($example)) ?></code>
    </label>
  </div>
  <?php endforeach; ?>
</div>
<div class="row">
  <div class="col-md-6 form-group">
    <label>Permalink Structure Pattern</label>
    <input type="text" id="di_perm_input" name="permalink_structure" class="form-control" value="<?= esc_attr($perm) ?>">
    <p class="help-block">Tags: <code>%postname%</code> <code>%post_id%</code> <code>%year%</code> <code>%monthnum%</code> <code>%day%</code> <code>%author%</code>. Anything without a <code>?</code> is a pretty permalink. Pretty URLs need a rewrite to <code>index.php</code>: the bundled <code>.htaccess</code> handles Apache, the PHP built-in server (preview) handles itself, and nginx needs <code>try_files $uri $uri/ /index.php?$args;</code>. Existing <code>?p=</code> links keep working and 301-redirect to the canonical pretty URL.</p>
  </div>
</div>
