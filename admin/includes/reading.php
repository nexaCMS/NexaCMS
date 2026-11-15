<?php
$allPubPages = array_values(array_filter(get_table('posts')['data'], static fn($p) => ($p['post_status'] ?? '') === 'published' && ($p['post_type'] ?? 'post') === 'page'));
$frontMode   = get_setting('show_on_front', 'posts');
$frontPageId = (string) get_setting('page_on_front', '1');
$postsPageId = (string) get_setting('page_for_posts', '1');
?>
<div class="form-group">
  <label>Your Homepage Displays</label>
  <div class="radio">
    <label><input type="radio" name="show_on_front" value="posts" <?= $frontMode === 'posts' ? 'checked' : '' ?>> Your latest blog posts</label>
  </div>
  <div class="radio">
    <label><input type="radio" name="show_on_front" value="page" <?= $frontMode === 'page' ? 'checked' : '' ?>> A static page (select below)</label>
  </div>
</div>
<div class="row">
  <div class="col-md-4 form-group">
    <label>Static Front Page</label>
    <select name="page_on_front" class="form-control">
      <?php foreach ($allPubPages as $pg): ?>
        <option value="<?= esc_attr($pg['id']) ?>" <?= (string) $pg['id'] === $frontPageId ? 'selected' : '' ?>>
          #<?= esc_html($pg['id']) ?> &mdash; <?= esc_html($pg['post_title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4 form-group">
    <label>Posts Page</label>
    <select name="page_for_posts" class="form-control">
      <option value="" <?= $postsPageId === '' ? 'selected' : '' ?>>(None &mdash; posts live on the homepage)</option>
      <?php foreach ($allPubPages as $pg): ?>
        <option value="<?= esc_attr($pg['id']) ?>" <?= (string) $pg['id'] === $postsPageId ? 'selected' : '' ?>>
          #<?= esc_html($pg['id']) ?> &mdash; <?= esc_html($pg['post_title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="row">
</div>
<p class="text-muted" style="margin-top:4px;"><i class="fa fa-info-circle"></i> The <strong>Posts Page</strong> automatically displays your latest posts when visited &mdash; its own content is ignored, so it can (and should) be left empty. Any text you type in it will <em>not</em> appear above the post list.</p>
<div class="row">
  <div class="col-md-3 form-group">
    <label>Blog Pages Show at Most</label>
    <input type="number" min="1" max="100" name="posts_per_page" class="form-control" value="<?= (int) get_setting('posts_per_page', '10') ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>RSS Feed Shows Most Recent</label>
    <input type="number" min="1" max="100" name="posts_per_rss" class="form-control" value="<?= (int) get_setting('posts_per_rss', '15') ?>">
  </div>
  <div class="col-md-3 form-group">
    <label>Character Encoding</label>
    <input type="text" name="blog_charset" class="form-control" value="<?= esc_attr(get_setting('blog_charset', 'UTF-8')) ?>">
  </div>
</div>
