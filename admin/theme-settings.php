<?php
/**
 * NexaCMS Theme Settings (admin/theme-settings.php)
 * Per-theme options declared by each theme's theme-settings.php schema.
 * Appearance > Theme Settings. Values are stored per theme
 * (theme_{slug}_{key}), so switching themes never loses a theme's options.
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$activeTheme = get_setting('template', 'multi');
$msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;
$installed = function_exists('nexa_available_theme_slugs') ? nexa_available_theme_slugs($msSite) : [];

$slug = basename((string) ($_REQUEST['theme'] ?? $activeTheme));
if (!in_array($slug, $installed, true)) $slug = $activeTheme;
$schema = nexa_theme_schema($slug);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    if (isset($_POST['reset_theme_settings'])) {
        foreach ($schema as $field) {
            set_setting(nexa_theme_setting_name($slug, $field), (string) ($field['default'] ?? ''), 'Theme: ' . $slug);
        }
        foreach (glob(dirname(__DIR__) . '/content/cache/*.html') ?: [] as $cached) @unlink($cached);
        $_SESSION['success'] = "Settings for theme '{$slug}' reset to defaults.";
    } else {
        foreach ($schema as $field) {
            $key  = (string) ($field['key'] ?? '');
            if ($key === '') continue;
            $type = (string) ($field['type'] ?? 'text');
            $raw  = $_POST['ts'][$key] ?? null;
            if ($type === 'checkbox') {
                $value = ($raw === '1') ? '1' : '0';
            } elseif ($type === 'color') {
                $value = (is_string($raw) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($raw))) ? trim($raw) : (string) ($field['default'] ?? '#000000');
            } elseif ($type === 'select') {
                $opts  = array_keys((array) ($field['options'] ?? []));
                $value = in_array($raw, $opts, true) ? (string) $raw : (string) ($field['default'] ?? ($opts[0] ?? ''));
            } else {
                $value = trim((string) ($raw ?? ''));
            }
            set_setting(nexa_theme_setting_name($slug, $field), $value, 'Theme: ' . $slug);
        }
        // Theme options change how pages look - bust the frontend page cache.
        foreach (glob(dirname(__DIR__) . '/content/cache/*.html') ?: [] as $cached) @unlink($cached);
        $_SESSION['success'] = "Theme settings saved for '{$slug}'." . ($slug === $activeTheme ? ' (page cache cleared)' : ' Note: this theme is not the active one.');
    }
    header('Location: theme-settings.php?theme=' . urlencode($slug));
    exit;
}

$values = [];
foreach ($schema as $field) {
    $values[$field['key']] = (string) get_setting(nexa_theme_setting_name($slug, $field), (string) ($field['default'] ?? ''));
}

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header"><i class="fa fa-sliders fa-fw"></i> Theme Settings
        <small style="font-size:15px;">options for
          <form method="get" action="theme-settings.php" style="display:inline;">
            <select name="theme" onchange="this.form.submit()" class="form-control" style="display:inline;width:auto;">
              <?php foreach ($installed as $th): ?>
                <option value="<?= esc_attr($th) ?>" <?= $th === $slug ? 'selected' : '' ?>><?= esc_html(ucfirst($th)) ?><?= $th === $activeTheme ? ' (active)' : '' ?><?= $msSite && nexa_site_has_theme_copy($th, $msSite) ? ' — site copy' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </small>
        <?php if ($slug !== $activeTheme): ?>
          <a href="themes.php" class="btn btn-warning btn-sm">“<?= esc_html($slug) ?>” is not active — Activate it</a>
        <?php endif; ?>
        <a href="../?theme=<?= urlencode($slug) ?>" target="_blank" class="btn btn-default btn-sm"><i class="fa fa-eye"></i> Live Preview</a>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <div class="row">
    <div class="col-lg-8">
      <div class="panel panel-primary">
        <div class="panel-heading"><strong><?= esc_html(ucfirst($slug)) ?></strong> settings <span class="text-muted" style="color:#d6e9f8;">— saved per-theme, restored whenever you switch back</span></div>
        <div class="panel-body">
          <form method="post" action="theme-settings.php?theme=<?= urlencode($slug) ?>">
            <?= di_csrf_field() ?>
            <?php foreach ($schema as $field):
              $key = (string) $field['key']; $type = (string) ($field['type'] ?? 'text'); $val = $values[$key] ?? '';
            ?>
              <div class="form-group">
                <label><?= esc_html($field['label'] ?? $key) ?></label>
                <?php if ($type === 'textarea'): ?>
                  <textarea name="ts[<?= esc_attr($key) ?>]" class="form-control" rows="4"><?= esc_html($val) ?></textarea>
                <?php elseif ($type === 'select'): ?>
                  <select name="ts[<?= esc_attr($key) ?>]" class="form-control">
                    <?php foreach ((array) ($field['options'] ?? []) as $ov => $ol): ?>
                      <option value="<?= esc_attr((string) $ov) ?>" <?= $val === (string) $ov ? 'selected' : '' ?>><?= esc_html((string) $ol) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($type === 'checkbox'): ?>
                  <div class="checkbox"><label><input type="checkbox" name="ts[<?= esc_attr($key) ?>]" value="1" <?= $val === '1' ? 'checked' : '' ?>> Enabled</label></div>
                <?php elseif ($type === 'color'): ?>
                  <div class="input-group" style="max-width:280px;">
                    <input type="color" name="ts[<?= esc_attr($key) ?>]" value="<?= esc_attr(preg_match('/^#[0-9a-fA-F]{6}$/', $val) ? $val : '#000000') ?>" class="form-control" style="height:38px;padding:2px;">
                    <span class="input-group-addon"><?= esc_html($val) ?></span>
                  </div>
                <?php else: ?>
                  <input type="text" name="ts[<?= esc_attr($key) ?>]" value="<?= esc_attr($val) ?>" class="form-control">
                <?php endif; ?>
                <?php if (!empty($field['desc'])): ?><p class="help-block"><?= esc_html((string) $field['desc']) ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Theme Settings</button>
            <button type="submit" name="reset_theme_settings" value="1" class="btn btn-default" onclick="return confirm('Reset all settings for this theme to defaults?')">Reset to Defaults</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel panel-default">
        <div class="panel-heading"><strong>How this works</strong></div>
        <div class="panel-body">
          <p>Each theme declares its own options in its <code>theme-settings.php</code> file (in the shared <code>content/themes/&lt;theme&gt;/</code> folder, or in this subsite's own <code>content/themes-&lt;siteslug&gt;/&lt;theme&gt;/</code> copy when it has one). Settings are stored per theme (<code>theme_{theme}_{option}</code>), so Nova, Corporate, Folio etc. each remember their own look when you switch between them.</p>
          <p>Saving clears the frontend page cache automatically, so changes show immediately. Use <strong>Live Preview</strong> to check a non-active theme before activating it.</p>
          <p><a href="themes.php">Choose the active theme &raquo;</a><br><a href="menus.php">Edit navigation menus &raquo;</a></p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
