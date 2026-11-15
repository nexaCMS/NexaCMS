<h4>Image Dimensions</h4>
<div class="row">
  <div class="col-md-3 form-group">
    <label>Thumbnail Width (px)</label>
    <input type="number" name="thumbnail_size_w" class="form-control" value="<?= (int) get_setting('thumbnail_size_w', '150') ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>Thumbnail Height (px)</label>
    <input type="number" name="thumbnail_size_h" class="form-control" value="<?= (int) get_setting('thumbnail_size_h', '150') ?>">
  </div>
</div>
<div class="row">
  <div class="col-md-3 form-group">
    <label>Medium Max Width (px)</label>
    <input type="number" name="medium_size_w" class="form-control" value="<?= (int) get_setting('medium_size_w', '300') ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>Medium Max Height (px)</label>
    <input type="number" name="medium_size_h" class="form-control" value="<?= (int) get_setting('medium_size_h', '300') ?>">
  </div>
</div>
<div class="row">
  <div class="col-md-3 form-group">
    <label>Large Max Width (px)</label>
    <input type="number" name="large_size_w" class="form-control" value="<?= (int) get_setting('large_size_w', '1024') ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>Large Max Height (px)</label>
    <input type="number" name="large_size_h" class="form-control" value="<?= (int) get_setting('large_size_h', '1024') ?>">
  </div>
</div>
<div class="checkbox">
  <label>
    <input type="checkbox" name="uploads_use_yearmonth_folders" value="1" <?= get_setting('uploads_use_yearmonth_folders', '1') === '1' ? 'checked' : '' ?>>
    Organize uploads into month- and year-based folders (<code>content/uploads/YYYY/MM</code>)
  </label>
</div>
