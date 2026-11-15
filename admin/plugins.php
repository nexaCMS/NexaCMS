<?php
/**
 * NexaCMS Stage-Scoped Plugin Manager (admin/plugins.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $plugin = trim((string) ($_POST['plugin'] ?? ''));
    $action = trim((string) ($_POST['action'] ?? ''));
    $stage  = trim((string) ($_POST['stage'] ?? 'all'));
    if ($plugin !== '') {
        $siteGov = function_exists('nexa_current_site') ? nexa_current_site() : null;
        if ($siteGov && $action === 'activate' && function_exists('nexa_multisite_plugin_allowed') && !nexa_multisite_plugin_allowed($plugin)) {
            $_SESSION['failure'] = "Plugin '{$plugin}' is not on this subsite's network allow-list.";
        } elseif ($siteGov && $action === 'deactivate' && in_array($plugin, function_exists('nexa_multisite_csv_list') ? nexa_multisite_csv_list($siteGov['mandatory_plugins'] ?? '') : [], true)) {
            $_SESSION['failure'] = "Plugin '{$plugin}' is mandatory on this subsite (set by the network admin) and cannot be deactivated.";
        } elseif ($action === 'activate') {
            $invalid = function_exists('nexa_validate_package') ? nexa_validate_package('plugin', di_resolve_plugin_path($plugin)) : null;
            if ($invalid !== null) {
                $_SESSION['failure'] = "Plugin '{$plugin}' was <strong>not activated</strong> — its files are incomplete: " . esc_html(strip_tags($invalid)) . ' Fix the package and try again.';
            } else {
                activateplugin($plugin, $stage);
                $_SESSION['success'] = "Plugin '{$plugin}' ({$stage}) activated.";
            }
        } elseif ($action === 'deactivate') {
            deactivateplugin($plugin, $stage);
            $_SESSION['info'] = "Plugin '{$plugin}' ({$stage}) deactivated.";
        }
    }
    header('Location: plugins.php');
    exit;
}

updatepluginlist();
queplugins();
$pluginRows = array_values(array_filter(
    get_table('plugins')['data'],
    static fn($r) => !empty($r['name']) && is_dir(di_resolve_plugin_path($r['name']))
));

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        Stage-Scoped Plugins
        <a href="plugin-install.php" class="btn btn-primary btn-sm" style="margin-left:10px;"><i class="fa fa-upload"></i> Upload Plugin (.zip)</a>
        <a href="plugin-editor.php" class="btn btn-default btn-sm"><i class="fa fa-code"></i> Plugin Editor</a>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <div class="panel panel-default">
    <div class="panel-heading">
      Installed Plugins in <code>content/plugins/</code> &mdash; Toggle individual execution stages (<code>Pre</code>, <code>Head</code>, <code>Body</code>, <code>Post</code>)
    </div>
    <div class="panel-body">
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th>Plugin</th>
              <th>Description</th>
              <th width="290" class="text-center">Lifecycle Stages</th>
              <th width="190" class="text-center">Quick Controls</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pluginRows as $p):
              $meta = di_read_plugin_manifest(di_resolve_plugin_path($p['name']), $p['name']);
              $disp = ($p['displayed-name'] ?? '') ?: $meta['displayed-name'];
              $desc = ($p['description'] ?? '') ?: $meta['description'];
              $ver  = ($p['version'] ?? '') ?: $meta['version'];
              $auth = ($p['author'] ?? '') ?: $meta['author'];
              $anyOn = (($p['pre'] ?? '0') == '1' || ($p['head'] ?? '0') == '1' || ($p['body'] ?? '0') == '1' || ($p['post'] ?? '0') == '1');
            ?>
              <tr>
                <td>
                  <strong><?= esc_html($disp) ?></strong> <span class="text-muted">v<?= esc_html($ver) ?></span>
                  <?php
                    $resolvedP = str_replace('\\', '/', di_resolve_plugin_path($p['name']));
                    $isSiteCopy = function_exists('nexa_site_plugins_dir') && str_starts_with($resolvedP, str_replace('\\', '/', nexa_site_plugins_dir()));
                    $inSiteCtx = function_exists('nexa_current_site') && nexa_current_site();
                  ?>
                  <?php if ($isSiteCopy): ?><span class="label label-primary">Site copy — this site only</span><?php elseif ($inSiteCtx): ?><span class="label label-default">Network copy (shared)</span><?php endif; ?>
                  <br>
                  <small class="text-muted">Folder: <code><?= esc_html($p['name']) ?></code> &bull; By <?= esc_html($auth) ?></small>
                  <?php $invalid = function_exists('nexa_validate_package') ? nexa_validate_package('plugin', di_resolve_plugin_path($p['name'])) : null; ?>
                  <?php if ($invalid !== null): ?>
                    <br><span class="label label-danger"><i class="fa fa-warning"></i> Incomplete package</span>
                    <small class="text-danger"><?= esc_html(strip_tags($invalid)) ?></small>
                  <?php endif; ?>
                </td>
                <td><?= esc_html($desc) ?></td>
                <td class="text-center">
                  <?php foreach (['pre' => 'Pre', 'head' => 'Head', 'body' => 'Body', 'post' => 'Post'] as $stKey => $stLabel):
                    $isOn = ($p[$stKey] ?? '0') == '1';
                  ?>
                    <form method="post" action="plugins.php" style="display:inline-block;margin:2px;">
                      <?= di_csrf_field() ?>
                      <input type="hidden" name="plugin" value="<?= esc_attr($p['name']) ?>">
                      <input type="hidden" name="stage" value="<?= esc_attr($stKey) ?>">
                      <input type="hidden" name="action" value="<?= $isOn ? 'deactivate' : 'activate' ?>">
                      <button type="submit" class="btn btn-xs btn-<?= $isOn ? 'success' : 'default' ?>" title="Toggle <?= esc_attr($stLabel) ?> stage">
                        <i class="fa fa-<?= $isOn ? 'check-square-o' : 'square-o' ?>"></i> <?= esc_html($stLabel) ?>
                      </button>
                    </form>
                  <?php endforeach; ?>
                </td>
                <td class="text-center">
                  <?php if (!$anyOn): ?>
                    <form method="post" action="plugins.php" style="display:inline;">
                      <?= di_csrf_field() ?>
                      <input type="hidden" name="plugin" value="<?= esc_attr($p['name']) ?>">
                      <input type="hidden" name="stage" value="all">
                      <input type="hidden" name="action" value="activate">
                      <button type="submit" class="btn btn-xs btn-primary">Activate All</button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="plugins.php" style="display:inline;">
                      <?= di_csrf_field() ?>
                      <input type="hidden" name="plugin" value="<?= esc_attr($p['name']) ?>">
                      <input type="hidden" name="stage" value="all">
                      <input type="hidden" name="action" value="deactivate">
                      <button type="submit" class="btn btn-xs btn-warning">Deactivate All</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!empty($meta['admin_page'])): ?><a href="admin.php?plugin_page=<?= urlencode($p['name']) ?>" class="btn btn-xs btn-info"><i class="fa fa-wrench"></i> Manage</a><?php endif; ?>
                  <a href="plugin-editor.php?plugin=<?= urlencode($p['name']) ?>" class="btn btn-xs btn-default"><i class="fa fa-code"></i> Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
