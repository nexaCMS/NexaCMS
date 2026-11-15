<?php
$gmt = (float) get_setting('gmt_offset', '-5');
$localPreview = gmdate(get_setting('date_format', 'Y-m-d') . ' ' . get_setting('time_format', 'g:i a'), time() + (int) round($gmt * 3600));
?>
<div class="row">
  <div class="col-md-6 form-group">
    <label>Site Title</label>
    <input type="text" name="blogname" class="form-control" value="<?= esc_attr(get_setting('blogname', 'NexaCMS')) ?>" required>
  </div>
  <div class="col-md-6 form-group">
    <label>Tagline</label>
    <input type="text" name="blogdescription" class="form-control" value="<?= esc_attr(get_setting('blogdescription', '')) ?>">
  </div>
</div>
<div class="row">
  <div class="col-md-6 form-group">
    <label>Administration Email</label>
    <input type="email" name="anim_email" class="form-control" value="<?= esc_attr(get_setting('anim_email', 'admin@localhost')) ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>New User Default Role</label>
    <?php $dr = get_setting('default_role', 'subscriber'); ?>
    <select name="default_role" class="form-control">
      <?php foreach (['subscriber' => 'Subscriber', 'author' => 'Author', 'editor' => 'Editor', 'admin' => 'Administrator'] as $rk => $rl): ?>
        <option value="<?= esc_attr($rk) ?>" <?= $dr === $rk ? 'selected' : '' ?>><?= esc_html($rl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3 form-group" style="padding-top:24px;">
    <label class="checkbox-inline">
      <input type="checkbox" name="users_can_register" value="1" <?= get_setting('users_can_register', '0') === '1' ? 'checked' : '' ?>> Anyone can register
    </label>
  </div>
</div>
<div class="row">
  <div class="col-md-3 form-group">
    <label>UTC / GMT Offset (hours)</label>
    <input type="number" step="0.5" name="gmt_offset" class="form-control" value="<?= esc_attr((string) get_setting('gmt_offset', '-5')) ?>">
    <small class="text-muted">Local time: <?= esc_html($localPreview) ?></small>
  </div>
  <div class="col-md-3 form-group">
    <label>Date Format</label>
    <input type="text" name="date_format" class="form-control" value="<?= esc_attr(get_setting('date_format', 'Y-m-d')) ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>Time Format</label>
    <input type="text" name="time_format" class="form-control" value="<?= esc_attr(get_setting('time_format', 'g:i a')) ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>Week Starts On</label>
    <?php $sow = (string) get_setting('start_of_week', '1'); ?>
    <select name="start_of_week" class="form-control">
      <?php foreach (['0' => 'Sunday', '1' => 'Monday', '6' => 'Saturday'] as $dk => $dl): ?>
        <option value="<?= esc_attr($dk) ?>" <?= $sow === $dk ? 'selected' : '' ?>><?= esc_html($dl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
