<?php
/**
 * NexaCMS Theme Manager (admin/themes.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$themesRoot = dirname(__DIR__) . '/content/themes';
$msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    if (!empty($_POST['activate_theme'])) {
        $slug = basename((string) $_POST['activate_theme']);
        if (function_exists('nexa_current_site') && nexa_current_site() && function_exists('nexa_multisite_theme_allowed') && !nexa_multisite_theme_allowed($slug)) {
            $_SESSION['failure'] = function_exists('nexa_multisite_forced_theme') && nexa_multisite_forced_theme() !== ''
                ? "This subsite must use the network-mandated theme '" . esc_html(nexa_multisite_forced_theme()) . "'."
                : "Theme '{$slug}' is not on this subsite's network allow-list.";
        } elseif (is_dir(nexa_resolve_theme_path($slug))) {
            set_setting('template', $slug, 'Current Theme');
            // Bust the frontend page cache, otherwise visitors keep seeing
            // the PREVIOUS theme (and report "the theme didn't change").
            foreach (glob(dirname(__DIR__) . '/content/cache/*.html') ?: [] as $cached) @unlink($cached);
            $_SESSION['success'] = "Theme '{$slug}' activated! (page cache cleared)";
        }
    }
    if (isset($_POST['toggle_slider'])) {
        set_setting('multi_slider', $_POST['multi_slider'] === '1' ? '1' : '0', 'Show Theme Hero Slider');
        $_SESSION['success'] = 'Theme slider preference updated.';
    }
    header('Location: themes.php');
    exit;
}

$activeTheme = get_setting('template', 'multi');
$themes = [];
$themeMeta = [
    'multi'     => ['Multi', 'Responsive Bootstrap 3 corporate & blog theme with hero image slider and widgetized sidebar. Top menu bar included.'],
    'darrs'     => ['Darrs', 'DARR Tech Dream Theme — classic two-column dark/accent layout with top menubar.'],
    'flip-turn' => ['Flip Turn', 'Templatemo Flip Turn portfolio theme, converted to a full NexaCMS theme with dynamic top navigation.'],
    'nova'      => ['Nova Blog', 'Clean modern magazine/blog theme — indigo accent, sticky top menu bar, card layout. No external assets.'],
    'corporate' => ['Corporate Pro', 'Business theme — navy/teal, utility top strip + sticky main menu bar, service-friendly sections. No external assets.'],
    'olivia'    => ['Olivia', 'Advanced portfolio theme (from the Olivia template): dark mode with device memory, reading progress bar, hero + project grid, primary + footer menu locations, rich theme settings.'],
    'folio'     => ['Folio Creative', 'Dark creative/portfolio theme — charcoal + amber, bold top menu bar, grid cards. No external assets.'],
];
foreach (nexa_available_theme_slugs($msSite) as $entry) {
    $tDir = nexa_resolve_theme_path($entry, $msSite);
    $isSiteCopy = $msSite && function_exists('nexa_site_has_theme_copy') && nexa_site_has_theme_copy($entry, $msSite);
    $manifest = function_exists('nexa_read_theme_manifest') ? nexa_read_theme_manifest($tDir, $entry) : [];
    $themes[] = [
        'slug'        => $entry,
        'name'        => $themeMeta[$entry][0] ?? (($manifest['name'] ?? '') !== '' ? $manifest['name'] : ucfirst($entry)),
        'description' => $themeMeta[$entry][1] ?? (($manifest['description'] ?? '') !== '' ? $manifest['description'] : 'NexaCMS frontend theme.'),
        'version'     => (string) ($manifest['version'] ?? ''),
        'site_copy'   => $isSiteCopy,
        'active'      => ($entry === $activeTheme),
    ];
}

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        Themes
        <a href="theme-install.php" class="btn btn-primary btn-sm" style="margin-left:10px;"><i class="fa fa-upload"></i> Upload Theme (.zip)</a>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <div class="row">
    <?php foreach ($themes as $th): ?>
      <div class="col-md-6">
        <div class="panel panel-<?= $th['active'] ? 'primary' : 'default' ?>">
          <div class="panel-heading">
            <strong><?= esc_html($th['name']) ?></strong> (<code><?= esc_html($th['slug']) ?></code>)<?php if (!empty($th['version'])): ?> <small>v<?= esc_html($th['version']) ?></small><?php endif; ?>
            <?php if ($msSite): ?><?php if (!empty($th['site_copy'])): ?><span class="label label-primary">Site copy — this site only</span><?php else: ?><span class="label label-default">Shared theme</span><?php endif; ?><?php endif; ?>
            <?php if ($th['active']): ?>
              <span class="label label-success pull-right">Active Theme</span>
            <?php endif; ?>
          </div>
          <div class="panel-body">
            <p><?= esc_html($th['description']) ?></p>
            <div style="margin-top:15px;">
              <?php if (!$th['active']): ?>
                <form method="post" action="themes.php" style="display:inline;">
                  <?= di_csrf_field() ?>
                  <input type="hidden" name="activate_theme" value="<?= esc_attr($th['slug']) ?>">
                  <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-check"></i> Activate</button>
                </form>
              <?php endif; ?>
              <a href="theme-customizer.php?theme=<?= urlencode($th['slug']) ?>" class="btn btn-success btn-sm">
                <i class="fa fa-magic"></i> Live Edit
              </a>
              <a href="theme-settings.php?theme=<?= urlencode($th['slug']) ?>" class="btn btn-info btn-sm">
                <i class="fa fa-sliders"></i> Theme Settings
              </a>
              <a href="../?theme=<?= urlencode($th['slug']) ?>" target="_blank" class="btn btn-default btn-sm">
                <i class="fa fa-eye"></i> Live Preview
              </a>
            </div>

            <?php if ($th['slug'] === 'multi'): ?>
              <hr>
              <form method="post" action="themes.php" class="form-inline">
                <?= di_csrf_field() ?>
                <input type="hidden" name="toggle_slider" value="1">
                <label style="margin-right:10px;">Hero Image Slider:</label>
                <select name="multi_slider" class="form-control input-sm">
                  <option value="1" <?= get_setting('multi_slider', '1') === '1' ? 'selected' : '' ?>>Enabled on Homepage</option>
                  <option value="0" <?= get_setting('multi_slider', '1') === '0' ? 'selected' : '' ?>>Hidden</option>
                </select>
                <button type="submit" class="btn btn-default btn-sm">Save</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
