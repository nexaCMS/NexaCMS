<?php
/**
 * NexaCMS Admin Theme Manager (admin/admin-themes.php)
 * Admin themes live in admin/themes/{slug}/ (theme.json + theme.css) and are
 * stored in the `admin_theme` setting. Frontend themes are under Appearance > Themes.
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && nexa_verify_csrf()) {
    $slug = basename((string) ($_POST['activate_admin_theme'] ?? 'default'));
    if (isset(nexa_admin_themes()[$slug])) {
        set_setting('admin_theme', $slug, 'Admin Theme');
        $_SESSION['success'] = "Admin theme '{$slug}' activated!";
    }
    header('Location: admin-themes.php');
    exit;
}

$active = nexa_get_admin_theme();
$themes = nexa_admin_themes();
include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row"><div class="col-lg-12"><h1 class="page-header">Admin Themes</h1></div></div>
  <?php include __DIR__ . '/includes/flash_messages.php'; ?>
  <p class="text-muted">The admin area is themed independently from the frontend. Drop a new folder into
     <code>admin/themes/</code> containing <code>theme.json</code> + <code>theme.css</code> and it appears here.
     The active theme is saved in the <code>admin_theme</code> setting on every driver.</p>
  <div class="row">
    <?php foreach ($themes as $slug => $meta): ?>
      <div class="col-sm-6 col-md-3" style="margin-bottom:18px">
        <div class="thumbnail" style="padding:14px;height:100%">
          <div style="height:64px;border-radius:6px;background:<?= esc_attr($meta['swatch'] ?? '#337ab7') ?>"></div>
          <h4><?= esc_html($meta['name'] ?? $slug) ?></h4>
          <p><?= esc_html($meta['description'] ?? '') ?></p>
          <p><small class="text-muted">Folder: <code>admin/themes/<?= esc_html($slug) ?></code></small></p>
          <?php if ($slug === $active): ?>
            <span class="label label-success">Active</span>
          <?php else: ?>
            <form method="post"><?= nexa_csrf_field() ?><button name="activate_admin_theme" value="<?= esc_attr($slug) ?>" class="btn btn-primary btn-sm">Activate</button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
