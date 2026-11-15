<?php
/**
 * NexaCMS Plugin Code Editor (admin/plugin-editor.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$pluginsRoot = realpath(di_plugins_dir()) ?: di_plugins_dir();
$availablePlugins = [];
if (is_dir($pluginsRoot)) {
    foreach (scandir($pluginsRoot) ?: [] as $d) {
        if ($d !== '.' && $d !== '..' && is_dir($pluginsRoot . '/' . $d)) {
            $availablePlugins[] = $d;
        }
    }
}
// Inside a subsite, also offer its own plugin copies (they shadow the
// network ones, exactly like the loader resolves them).
$msSiteEd = function_exists('nexa_current_site') ? nexa_current_site() : null;
if ($msSiteEd && function_exists('nexa_site_plugin_slugs')) {
    foreach (nexa_site_plugin_slugs($msSiteEd) as $sp) {
        if (!in_array($sp, $availablePlugins, true)) $availablePlugins[] = $sp;
    }
}
sort($availablePlugins);

$selectedPlugin = basename((string) ($_REQUEST['plugin'] ?? ($availablePlugins[0] ?? '')));
$pluginDir = function_exists('nexa_resolve_plugin_path') ? nexa_resolve_plugin_path($selectedPlugin) : $pluginsRoot . '/' . $selectedPlugin;
$editingSiteCopy = $msSiteEd && function_exists('nexa_site_plugins_dir') && str_starts_with(str_replace('\\', '/', $pluginDir), str_replace('\\', '/', nexa_site_plugins_dir($msSiteEd)));

$pluginFiles = [];
if ($selectedPlugin !== '' && is_dir($pluginDir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $fObj) {
        if ($fObj->isFile()) {
            $ext = strtolower($fObj->getExtension());
            if (in_array($ext, ['php', 'js', 'css', 'json', 'txt', 'html', 'md'], true)) {
                $rel = ltrim(str_replace('\\', '/', substr($fObj->getPathname(), strlen($pluginDir))), '/');
                $pluginFiles[] = $rel;
            }
        }
    }
}
sort($pluginFiles);

$selectedFile = (string) ($_REQUEST['file'] ?? (in_array('functions.php', $pluginFiles, true) ? 'functions.php' : ($pluginFiles[0] ?? '')));
if (!in_array($selectedFile, $pluginFiles, true)) {
    $selectedFile = $pluginFiles[0] ?? '';
}

$targetPath = ($selectedPlugin !== '' && $selectedFile !== '') ? ($pluginDir . '/' . $selectedFile) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf() && $targetPath !== '' && is_file($targetPath)) {
    $realTarget = realpath($targetPath);
    $realPluginDir = realpath($pluginDir) ?: $pluginDir;
    if ($realTarget !== false && ($realTarget === $realPluginDir || str_starts_with($realTarget, rtrim($realPluginDir, '/') . '/'))) {
        $newContent = (string) ($_POST['newcontent'] ?? '');
        if (str_ends_with(strtolower($realTarget), '.php')) {
            $tmpLint = tempnam(sys_get_temp_dir(), 'di_lint_');
            file_put_contents($tmpLint, $newContent);
            $lintOut = [];
            $lintCode = 0;
            exec('php -l ' . escapeshellarg($tmpLint) . ' 2>&1', $lintOut, $lintCode);
            @unlink($tmpLint);
            if ($lintCode !== 0) {
                $_SESSION['failure'] = 'PHP syntax error prevented saving: ' . implode(' ', $lintOut);
                header('Location: plugin-editor.php?plugin=' . urlencode($selectedPlugin) . '&file=' . urlencode($selectedFile));
                exit;
            }
        }
        file_put_contents($realTarget, $newContent);
        $_SESSION['success'] = "Saved {$selectedPlugin}/{$selectedFile} successfully.";
    }
    header('Location: plugin-editor.php?plugin=' . urlencode($selectedPlugin) . '&file=' . urlencode($selectedFile));
    exit;
}

$fileContent = ($targetPath !== '' && is_file($targetPath)) ? (string) file_get_contents($targetPath) : '';

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        Plugin Editor
        <form method="get" action="plugin-editor.php" class="form-inline pull-right" style="font-size:14px;font-weight:normal;">
          <label>Select Plugin:</label>
          <select name="plugin" class="form-control input-sm" onchange="this.form.submit()">
            <?php foreach ($availablePlugins as $ap): ?>
              <option value="<?= esc_attr($ap) ?>" <?= $ap === $selectedPlugin ? 'selected' : '' ?>><?= esc_html($ap) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <div class="row">
    <div class="col-md-9">
      <div class="panel panel-default">
        <div class="panel-heading">Editing <code><?= esc_html($selectedPlugin . '/' . $selectedFile) ?></code><?php if (!empty($editingSiteCopy)): ?> <span class="label label-primary">Site copy — this site only</span><?php endif; ?></div>
        <div class="panel-body">
          <?php if ($targetPath !== ''): ?>
            <form method="post" action="plugin-editor.php">
              <?= di_csrf_field() ?>
              <input type="hidden" name="plugin" value="<?= esc_attr($selectedPlugin) ?>">
              <input type="hidden" name="file" value="<?= esc_attr($selectedFile) ?>">
              <textarea name="newcontent" class="form-control" rows="20" style="font-family:monospace;font-size:13px;"><?= esc_textarea($fileContent) ?></textarea>
              <div style="margin-top:12px;">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save File (Syntax Checked)</button>
              </div>
            </form>
          <?php else: ?>
            <p class="text-muted">No editable files found for this plugin.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-3">
      <div class="panel panel-default">
        <div class="panel-heading">Plugin Files</div>
        <div class="list-group">
          <?php foreach ($pluginFiles as $pf): ?>
            <a href="plugin-editor.php?plugin=<?= urlencode($selectedPlugin) ?>&file=<?= urlencode($pf) ?>" class="list-group-item <?= $pf === $selectedFile ? 'active' : '' ?>">
              <?= esc_html($pf) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
