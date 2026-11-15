<?php
/**
 * NexaCMS Package Repositories (admin/repositories.php)
 * Browse remote plugin/theme indexes and install from them. Repositories
 * are user-managed: a NexaCMS site running the Repository Hub plugin, a
 * GitHub repo with a nexa-repo.json, or any direct index JSON URL.
 * Installs follow the same rule as uploads: from the main site they are
 * shared with everyone; from a subsite they become that subsite's own
 * copy (and respect its upload policy, including approval queueing).
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;
$msPolicy = function_exists('nexa_multisite_upload_policy') ? nexa_multisite_upload_policy() : 'allow';

function nexa_repo_installed_version(string $type, string $slug): ?string {
    if ($type === 'plugin') {
        $dir = nexa_resolve_plugin_path($slug);
        if (!is_dir($dir)) return null;
        return (string) (di_read_plugin_manifest($dir, $slug)['version'] ?? '');
    }
    $dir = nexa_resolve_theme_path($slug);
    if (!is_dir($dir)) return null;
    return (string) (nexa_read_theme_manifest($dir, $slug)['version'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $repos = nexa_repositories();
    $action = (string) ($_POST['repo_action'] ?? '');

    if ($action === 'add') {
        $type = in_array($_POST['type'] ?? '', ['hub', 'github', 'index'], true) ? (string) $_POST['type'] : 'hub';
        $entry = [
            'name'   => trim((string) ($_POST['name'] ?? '')) ?: 'Repository',
            'type'   => $type,
            'url'    => trim((string) ($_POST['url'] ?? '')),
            'repo'   => trim((string) ($_POST['repo'] ?? ''), '/'),
            'branch' => trim((string) ($_POST['branch'] ?? 'main')) ?: 'main',
        ];
        if (nexa_repo_index_url($entry) === '') {
            $_SESSION['failure'] = 'That repository is missing its URL (hub/index) or owner/repo (GitHub).';
        } else {
            $repos[] = $entry;
            set_setting('nexa_repositories', json_encode($repos), 'Package Repositories');
            $_SESSION['success'] = 'Repository "' . esc_html($entry['name']) . '" added.';
        }
        header('Location: repositories.php'); exit;
    }

    if ($action === 'delete') {
        $idx = (int) ($_POST['repo_idx'] ?? -1);
        if (isset($repos[$idx])) {
            $gone = $repos[$idx]['name'] ?? 'Repository';
            array_splice($repos, $idx, 1);
            set_setting('nexa_repositories', json_encode($repos), 'Package Repositories');
            $_SESSION['success'] = 'Repository "' . esc_html((string) $gone) . '" removed.';
        }
        header('Location: repositories.php'); exit;
    }

    if ($action === 'install') {
        $idx = (int) ($_POST['repo_idx'] ?? -1);
        $wantType = (string) ($_POST['pkg_type'] ?? '');
        $wantSlug = basename((string) ($_POST['pkg_slug'] ?? ''));
        $repo = $repos[$idx] ?? null;
        if ($repo === null) {
            $_SESSION['failure'] = 'That repository no longer exists.';
            header('Location: repositories.php'); exit;
        }
        $fetched = nexa_repo_fetch($repo);
        $pkg = null;
        foreach ($fetched['packages'] as $p) if ($p['type'] === $wantType && $p['slug'] === $wantSlug) { $pkg = $p; break; }
        if ($pkg === null) {
            $_SESSION['failure'] = 'Package not found in that repository (it may have been removed). ' . esc_html($fetched['error']);
            header('Location: repositories.php?repo=' . $idx); exit;
        }
        if ($msSite && $msPolicy === 'deny') {
            $_SESSION['failure'] = 'Installing packages is disabled for this subsite by the network admin.';
            header('Location: repositories.php?repo=' . $idx); exit;
        }
        $bytes = nexa_repo_http_get($pkg['download_url'], 67108864);
        if ($bytes === null) {
            $_SESSION['failure'] = 'Could not download ' . esc_html($pkg['name']) . ' from the repository.';
            header('Location: repositories.php?repo=' . $idx); exit;
        }
        if ($pkg['sha256'] !== '' && !hash_equals($pkg['sha256'], hash('sha256', $bytes))) {
            $_SESSION['failure'] = 'Integrity check failed for ' . esc_html($pkg['name']) . ' (sha256 mismatch). Nothing was installed.';
            header('Location: repositories.php?repo=' . $idx); exit;
        }
        if ($msSite && $msPolicy === 'approval') {
            // Same queue as a subsite upload: nothing installs until the
            // network admin approves it for THIS subsite.
            $pendingDir = dirname(__DIR__) . '/content/network-pending';
            if (!is_dir($pendingDir)) @mkdir($pendingDir, 0755, true);
            if (!is_file($pendingDir . '/.htaccess')) @file_put_contents($pendingDir . '/.htaccess', "Require all denied\nDeny from all\n");
            if (!is_file($pendingDir . '/index.html')) @file_put_contents($pendingDir . '/index.html', '');
            $pendName = (int) $msSite['id'] . '-' . time() . '-' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $pkg['slug'] . '.zip');
            if (@file_put_contents($pendingDir . '/' . $pendName, $bytes) !== false) {
                $siteId = (int) $msSite['id']; $siteSlug = (string) $msSite['slug']; $type = $pkg['type']; $orig = $pkg['slug'] . '.zip';
                nexa_network_call(static function () use ($siteId, $siteSlug, $type, $pendName, $orig) {
                    update_table('network_package_requests', ['site_id' => (string) $siteId, 'site_slug' => $siteSlug, 'type' => $type, 'file' => $pendName, 'orig_name' => $orig, 'requested_by' => (string) ($_SESSION['name'] ?? 'subsite admin'), 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')], 'insert');
                });
                $_SESSION['success'] = esc_html($pkg['name']) . ' was downloaded and is <strong>waiting for network-admin approval</strong>. Once approved it installs for this subsite only.';
            } else {
                $_SESSION['failure'] = 'Could not store the downloaded package for approval.';
            }
            header('Location: repositories.php?repo=' . $idx); exit;
        }
        // Direct install through the standard package installer (validates
        // the zip exactly like an upload). Destination follows the origin
        // rule: subsite = its own copy, main site = shared.
        $tempDir = dirname(__DIR__) . '/content/temp_uploads';
        if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
        $tmpPath = $tempDir . '/repo_' . $pkg['slug'] . '_' . uniqid() . '.zip';
        @file_put_contents($tmpPath, $bytes);
        $_FILES[$pkg['type']] = ['name' => $pkg['slug'] . '.zip', 'tmp_name' => $tmpPath, 'error' => 0, 'size' => strlen($bytes)];
        $GLOBALS['nexa_package_local_file'] = true;
        $dest = null;
        if ($msSite) $dest = $pkg['type'] === 'theme' ? nexa_site_themes_dir($msSite) : nexa_site_plugins_dir($msSite);
        ob_start(); upload($pkg['type'], $dest); $out = ob_get_clean();
        unset($GLOBALS['nexa_package_local_file']);
        if ($pkg['type'] === 'plugin') updatepluginlist();
        $_SESSION[str_contains((string) $out, 'installed successfully') ? 'success' : 'failure'] = strip_tags((string) $out);
        header('Location: repositories.php?repo=' . $idx); exit;
    }
}

$repos = nexa_repositories();
$selIdx = isset($_GET['repo']) ? (int) $_GET['repo'] : (count($repos) ? 0 : -1);
if ($selIdx >= count($repos)) $selIdx = count($repos) - 1;
$filter = in_array($_GET['f'] ?? '', ['plugin', 'theme'], true) ? (string) $_GET['f'] : 'all';
$fetched = null;
if ($selIdx >= 0 && isset($repos[$selIdx])) $fetched = nexa_repo_fetch($repos[$selIdx]);

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row"><div class="col-lg-12">
    <h1 class="page-header"><i class="fa fa-cloud-download fa-fw"></i> Repositories <small>install plugins &amp; themes from remote catalogs</small></h1>
  </div></div>
  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <div class="alert alert-info">
    Fresh installs come with the <strong>official NexaCMS packages repository</strong> pre-configured (labelled <span class="label label-primary">official</span>; remove it below any time — your list is then fully your own). Nothing is contacted until you browse or install from here. You can also add your own: A repository can be a <strong>NexaCMS site running the Repository Hub plugin</strong>, a <strong>GitHub repo</strong> with a <code>nexa-repo.json</code> file, or any direct link to a repo index JSON.
    <?php if ($msSite): ?>Installing from here follows this subsite's rules: packages become <strong>this site's own copy</strong><?= $msPolicy === 'approval' ? ', after network-admin approval' : '' ?><?= $msPolicy === 'deny' ? ' — and installs are currently <strong>disabled</strong> for this subsite' : '' ?>.
    <?php else: ?>Installing from the main site installs the <strong>shared</strong> copy for every site.<?php endif; ?>
  </div>

  <div class="row">
    <div class="col-md-4">
      <div class="panel panel-default"><div class="panel-heading"><strong>Your repositories</strong></div><div class="panel-body">
        <?php if (!$repos): ?><p class="text-muted">None yet. Add your first repository below.</p><?php endif; ?>
        <?php foreach ($repos as $i => $r): ?>
          <div style="border-bottom:1px solid #f3f4f6;padding:7px 0;">
            <a href="repositories.php?repo=<?= $i ?>"><strong><?= esc_html($r['name']) ?></strong></a>
            <span class="label label-default"><?= esc_html($r['type']) ?></span><?php if (!empty($r['official'])): ?> <span class="label label-primary">official</span><?php endif; ?>
            <div class="text-muted" style="font-size:12px;word-break:break-all;"><?= esc_html($r['type'] === 'github' ? ($r['repo'] . ' @ ' . ($r['branch'] ?? 'main')) : $r['url']) ?></div>
            <form method="post" style="margin-top:4px;" onsubmit="return confirm('Remove this repository? Installed packages are unaffected.');">
              <?= di_csrf_field() ?><input type="hidden" name="repo_action" value="delete"><input type="hidden" name="repo_idx" value="<?= $i ?>">
              <button class="btn btn-xs btn-danger">Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
        <hr>
        <form method="post">
          <?= di_csrf_field() ?><input type="hidden" name="repo_action" value="add">
          <div class="form-group"><label>Display name</label><input name="name" class="form-control" placeholder="My plugin hub"></div>
          <div class="form-group"><label>Type</label>
            <select name="type" class="form-control" id="repoType" onchange="var g=this.value==='github';document.getElementById('repoGh').style.display=g?'block':'none';document.getElementById('repoUrl').style.display=g?'none':'block';">
              <option value="hub">NexaCMS hub site (base URL)</option>
              <option value="github">GitHub repository (owner/repo)</option>
              <option value="index">Direct index JSON URL</option>
            </select></div>
          <div class="form-group" id="repoUrl"><label>Site / index URL</label><input name="url" class="form-control" placeholder="https://hub.example.com  or  https://…/nexa-repo.json"></div>
          <div id="repoGh" style="display:none;">
            <div class="form-group"><label>GitHub owner/repo</label><input name="repo" class="form-control" placeholder="myname/nexacms-packages"></div>
            <div class="form-group"><label>Branch</label><input name="branch" class="form-control" value="main"></div>
            <p class="help-block">The repo must contain a <code>nexa-repo.json</code> index at its root; package <code>download_url</code> values may be paths inside the repo or full URLs (e.g. release assets).</p>
          </div>
          <button class="btn btn-primary"><i class="fa fa-plus"></i> Add Repository</button>
        </form>
      </div></div>
    </div>

    <div class="col-md-8">
      <div class="panel panel-primary"><div class="panel-heading"><strong>Browse</strong><?php if ($fetched): ?> — <?= esc_html($fetched['name']) ?><?php endif; ?></div><div class="panel-body">
        <?php if ($selIdx < 0): ?>
          <p class="text-muted">Add a repository to browse its plugins and themes.</p>
        <?php elseif ($fetched && $fetched['error'] !== ''): ?>
          <div class="alert alert-danger"><?= esc_html($fetched['error']) ?></div>
        <?php elseif ($fetched): ?>
          <div style="margin-bottom:10px;">
            <a class="btn btn-xs <?= $filter === 'all' ? 'btn-primary' : 'btn-default' ?>" href="repositories.php?repo=<?= $selIdx ?>">All</a>
            <a class="btn btn-xs <?= $filter === 'plugin' ? 'btn-primary' : 'btn-default' ?>" href="repositories.php?repo=<?= $selIdx ?>&f=plugin">Plugins</a>
            <a class="btn btn-xs <?= $filter === 'theme' ? 'btn-primary' : 'btn-default' ?>" href="repositories.php?repo=<?= $selIdx ?>&f=theme">Themes</a>
            <span class="text-muted" style="margin-left:8px;"><?= count($fetched['packages']) ?> package(s) in this repository</span>
          </div>
          <?php $shown = 0; foreach ($fetched['packages'] as $p): if ($filter !== 'all' && $p['type'] !== $filter) continue; $shown++; $installed = nexa_repo_installed_version($p['type'], $p['slug']); $isUpdate = $installed !== null && $p['version'] !== '' && version_compare($p['version'], $installed, '>'); ?>
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-bottom:8px;">
              <span class="label label-<?= $p['type'] === 'plugin' ? 'info' : 'primary' ?>"><?= esc_html($p['type']) ?></span>
              <strong><?= esc_html($p['name']) ?></strong> <?php if ($p['version'] !== ''): ?>v<?= esc_html($p['version']) ?><?php endif; ?>
              <?php if ($p['author'] !== ''): ?><span class="text-muted">by <?= esc_html($p['author']) ?></span><?php endif; ?>
              <?php if ($installed !== null): ?><span class="label label-success">Installed<?= $installed !== '' ? ' v' . esc_html($installed) : '' ?></span><?php endif; ?>
              <?php if ($isUpdate): ?><span class="label label-warning">Update available</span><?php endif; ?>
              <div class="text-muted" style="font-size:12px;"><code><?= esc_html($p['slug']) ?></code><?php if ($p['sha256'] !== ''): ?> · sha256 verified on install<?php endif; ?></div>
              <?php if ($p['description'] !== ''): ?><div style="font-size:13px;"><?= esc_html($p['description']) ?></div><?php endif; ?>
              <form method="post" style="margin-top:6px;" onsubmit="return confirm('Install this package from the repository?');">
                <?= di_csrf_field() ?><input type="hidden" name="repo_action" value="install"><input type="hidden" name="repo_idx" value="<?= $selIdx ?>">
                <input type="hidden" name="pkg_type" value="<?= esc_attr($p['type']) ?>"><input type="hidden" name="pkg_slug" value="<?= esc_attr($p['slug']) ?>">
                <button class="btn btn-sm <?= $isUpdate ? 'btn-warning' : 'btn-primary' ?>" <?= ($msSite && $msPolicy === 'deny') ? 'disabled' : '' ?>><i class="fa fa-download"></i> <?= $installed === null ? 'Install' : ($isUpdate ? 'Update to v' . esc_html($p['version']) : 'Reinstall') ?></button>
              </form>
            </div>
          <?php endforeach; ?>
          <?php if (!$shown): ?><p class="text-muted">No packages match this filter.</p><?php endif; ?>
        <?php endif; ?>
      </div></div>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
