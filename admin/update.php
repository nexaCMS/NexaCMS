<?php
/**
 * NexaCMS System Update (admin/update.php)
 *
 * Three ways to update, one safe pipeline (inspect -> compare -> backup -> apply):
 *   1) Place updat.zip in the SITE ROOT, then "Check & Apply" here.
 *   2) Upload updat.zip below (it is stored as the root updat.zip, then applied).
 *   3) Remote URL (e.g. a GitHub release): peek update.json, download updat.zip, apply.
 * config.php, admin/database/, content/uploads/ and content/backup/ are never overwritten.
 * Legacy plugin/theme upload actions (?action=upload-plugin|upload-theme) still work.
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

// System Update rewrites the SHARED codebase, so inside a subsite it is a
// network-admin tool only — a pinned subsite admin must never reach it
// (this also closes the legacy ?action=upload-plugin/theme bypass around
// the network package-approval policy).
$msSiteCtx = function_exists('nexa_current_site') ? nexa_current_site() : null;
if ($msSiteCtx) {
    // Assignments live in the MAIN registry — read them there, not from
    // this subsite's own database (same rule as admin/network.php).
    $myAssign = function_exists('nexa_network_call') ? nexa_network_call(static function () {
        $out = [];
        try { foreach (get_table('network_site_users')['data'] as $r) if ((int) ($r['user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)) $out[] = $r; } catch (Throwable $e) {}
        return $out;
    }) : [];
    $isNetworkAdminHere = empty($myAssign) && (in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true) || !empty($_SESSION['nexa_network_admin']));
    if (!$isNetworkAdminHere) {
        include_once __DIR__ . '/includes/header.php';
        echo '<div id="page-wrapper"><div class="row"><div class="col-lg-12"><h1 class="page-header">System Update</h1></div></div>'
            . '<div class="alert alert-danger"><strong>System Update is managed by the network admin on the main site.</strong> To add a plugin or theme to this subsite, use Plugins → Upload Plugin or Appearance → Upload Theme — packages are installed after network-admin approval (when your site policy requires it).</div></div>';
        include_once __DIR__ . '/includes/footer.php';
        exit;
    }
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
if (in_array($action, ['upload-theme', 'upload-plugin'], true)) {
    include_once __DIR__ . '/includes/header.php';

    echo '<div id="page-wrapper"><div class="row"><div class="col-lg-12"><h1 class="page-header">Package Installer</h1></div></div>';
    if ($action === 'upload-theme') { upload('theme'); echo '<p><a href="themes.php" class="btn btn-default">&larr; Return to Themes</a></p>'; }
    else { upload('plugin'); updatepluginlist(); echo '<p><a href="plugins.php" class="btn btn-default">&larr; Return to Plugins</a></p>'; }
    echo '</div>';
    include_once __DIR__ . '/includes/footer.php';
    exit;
}

$rootDir  = dirname(__DIR__);
$rootZip  = $rootDir . '/updat.zip';
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && nexa_verify_csrf()) {
    $do = (string) ($_POST['do'] ?? '');
    $force = !empty($_POST['force']);
    if ($do === 'upload_zip') {
        if (empty($_FILES['updat']['tmp_name']) || !is_uploaded_file($_FILES['updat']['tmp_name'])) {
            $messages[] = ['danger', 'No valid file uploaded.'];
        } elseif (!str_ends_with(strtolower((string) $_FILES['updat']['name']), '.zip')) {
            $messages[] = ['danger', 'Only a .zip package named updat.zip is accepted.'];
        } elseif (!move_uploaded_file($_FILES['updat']['tmp_name'], $rootZip)) {
            $messages[] = ['danger', 'Could not store the uploaded package in the site root.'];
        } else {
            $info = nexa_update_inspect($rootZip);
            $messages[] = $info
                ? ['success', 'Uploaded updat.zip — package version <strong>' . esc_html($info['version']) . '</strong> (' . (int) $info['files'] . ' files). Review below, then Apply.']
                : ['danger', 'Uploaded, but no version could be read from the package (needs update.json or includes/version.php).'];
        }
    } elseif ($do === 'delete_root') {
        if (is_file($rootZip)) @unlink($rootZip);
        $messages[] = ['info', 'Root updat.zip removed.'];
    } elseif ($do === 'apply_root') {
        if (!is_file($rootZip)) {
            $messages[] = ['danger', 'No updat.zip found in the site root.'];
        } else {
            $res = nexa_apply_update($rootZip, $force);
            if ($res['ok']) {
                // Archive the applied package for audit/re-apply - never just deleted.
                $archiveDir = $rootDir . '/content/backup/applied-updates';
                @mkdir($archiveDir, 0755, true);
                $archiveName = 'updat-' . preg_replace('/[^0-9A-Za-z.\-]/', '', (string) $res['version']) . '-' . date('Ymd-His') . '.zip';
                if (!@rename($rootZip, $archiveDir . '/' . $archiveName)) { @copy($rootZip, $archiveDir . '/' . $archiveName); @unlink($rootZip); }
                $messages[] = ['success', 'Updated to <strong>' . esc_html($res['version']) . '</strong>' . (!empty($res['partial']) ? ' (partial package)' : '') . ' — ' . (int) $res['files'] . ' files written, ' . (int) ($res['deleted'] ?? 0) . ' deleted, ' . (int) $res['backed_up'] . ' overwritten files backed up to <code>content/backup/' . esc_html($res['backup']) . '</code>. Applied package archived as <code>content/backup/applied-updates/' . esc_html($archiveName) . '</code>.'];
            } else {
                $messages[] = ['danger', 'Update failed: ' . $res['error']];
            }
        }
    } elseif ($do === 'save_url') {
        $url = trim((string) ($_POST['update_remote_url'] ?? ''));
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            set_setting('update_remote_url', $url, 'Update Remote URL');
            $messages[] = ['success', 'Remote update URL saved.'];
        } else {
            $messages[] = ['danger', 'URL must start with http:// or https://'];
        }
    } elseif ($do === 'check_remote') {
        $url = trim((string) nexa_update_remote_url());
        if ($url === '') {
            $messages[] = ['danger', 'Save a remote updat.zip URL first.'];
        } else {
            $remote = nexa_remote_update_manifest($url);
            if ($remote) {
                $newer = nexa_update_is_newer($remote['version']);
                $messages[] = [$newer ? 'success' : 'info', 'Remote version <strong>' . esc_html($remote['version']) . '</strong> vs installed <strong>' . NEXACMS_VERSION . '</strong> — ' . ($newer ? 'an update is available! Download it below.' : 'you are up to date.') . ($remote['notes'] ? '<br>' . esc_html($remote['notes']) : '')];
            } else {
                $messages[] = ['warning', 'No update.json found next to the remote updat.zip. Use "Download updat.zip & Inspect" — the version will be read from the downloaded zip.'];
            }
        }
    } elseif ($do === 'download_remote') {
        $url = trim((string) nexa_update_remote_url());
        if ($url === '') {
            $messages[] = ['danger', 'Save a remote updat.zip URL first.'];
        } else {
            $dl = nexa_download_update($url, $rootZip);
            if (!$dl['ok']) {
                $messages[] = ['danger', 'Download failed: ' . $dl['error']];
            } else {
                $info = nexa_update_inspect($rootZip);
                if (empty($dl['verified'])) {
                    $messages[] = ['warning', 'Downloaded WITHOUT checksum verification — the source did not publish a matching SHA256SUMS entry. Only apply packages from sources you trust.'];
                }
                $messages[] = $info
                    ? ['success', 'Downloaded ' . round($dl['size'] / 1024, 1) . ' KB' . (!empty($dl['verified']) ? ' (<i class="fa fa-lock"></i> SHA-256 checksum verified)' : '') . ' — package version <strong>' . esc_html($info['version']) . '</strong> vs installed <strong>' . NEXACMS_VERSION . '</strong>. Review below, then Apply.']
                    : ['danger', 'Downloaded, but no version could be read from the package.'];
            }
        }
    }
}

$rootInfo = is_file($rootZip) ? nexa_update_inspect($rootZip) : null;
$remoteUrl = (string) nexa_update_remote_url();

// Backup inventory (pre-update backups + archived applied packages)
$bkRoot = $rootDir . '/content/backup';
$bkFiles = array_merge(glob($bkRoot . '/*.zip') ?: [], glob($bkRoot . '/applied-updates/*.zip') ?: []);
rsort($bkFiles);
include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row"><div class="col-lg-12"><h1 class="page-header">System Update</h1></div></div>
  <?php include __DIR__ . '/includes/flash_messages.php'; ?>
  <?php foreach ($messages as [$type, $msg]): ?>
    <div class="alert alert-<?= esc_attr($type) ?>"><?= $msg ?></div>
  <?php endforeach; ?>

  <div class="panel panel-primary">
    <div class="panel-heading"><i class="fa fa-info-circle"></i> Versions</div>
    <div class="panel-body">
      Installed version: <strong><?= esc_html(NEXACMS_VERSION) ?></strong> &nbsp;•&nbsp;
      Update package (<code>updat.zip</code> in site root):
      <?php if ($rootInfo): ?>
        <strong><?= esc_html($rootInfo['version']) ?></strong> — <?= (int) $rootInfo['files'] ?> files, <?= round($rootInfo['size'] / 1024, 1) ?> KB —
        <?= !empty($rootInfo['partial']) ? '<span class="label label-info">Partial (changed files only' . ($rootInfo['from'] ? ' from ' . esc_html($rootInfo['from']) : '') . ')</span> ' : '' ?><?= nexa_update_is_newer($rootInfo['version']) ? '<span class="label label-success">Update available</span>' : '<span class="label label-default">Not newer than installed</span>' ?>
        <?php if ($rootInfo['notes']): ?><br><em><?= esc_html($rootInfo['notes']) ?></em><?php endif; ?>
      <?php elseif (is_file($rootZip)): ?>
        <span class="label label-danger">Present, but version unreadable</span>
      <?php else: ?>
        <span class="text-muted">none present</span>
      <?php endif; ?>
      <p class="text-muted" style="margin-top:8px">Applying an update first backs up every file it will overwrite to <code>content/backup/pre-update-*.zip</code>.
      It never overwrites <code>config.php</code>, <code>admin/database/</code>, <code>content/uploads/</code> or <code>content/backup/</code>. The applied <code>updat.zip</code> is archived to <code>content/backup/applied-updates/</code> (never silently deleted). Packages may be <strong>full</strong> or <strong>partial</strong> (changed files only, plus an optional <code>"delete"</code> list in update.json) — both apply the same way.</p>
    </div>
  </div>

  <div class="row">
    <div class="col-md-4">
      <div class="panel panel-default">
        <div class="panel-heading"><strong>1 — updat.zip in Site Root</strong></div>
        <div class="panel-body">
          <p>FTP / file-manager the package to <code><?= esc_html(basename($rootDir)) ?>/updat.zip</code>, then:</p>
          <form method="post"><?= nexa_csrf_field() ?>
            <label><input type="checkbox" name="force" value="1"> Force reinstall (allow same/older version)</label><br>
            <button name="do" value="apply_root" class="btn btn-success" <?= $rootInfo ? '' : 'disabled' ?> onclick="return confirm('Apply this update now? A backup of overwritten files will be made first.')">Check &amp; Apply Root updat.zip</button>
            <?php if (is_file($rootZip)): ?><button name="do" value="delete_root" class="btn btn-default">Delete</button><?php endif; ?>
          </form>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="panel panel-default">
        <div class="panel-heading"><strong>2 — Upload updat.zip</strong></div>
        <div class="panel-body">
          <form method="post" enctype="multipart/form-data"><?= nexa_csrf_field() ?>
            <div class="form-group"><input type="file" name="updat" accept=".zip" class="form-control" required></div>
            <button name="do" value="upload_zip" class="btn btn-primary">Upload &amp; Inspect</button>
          </form>
          <p class="text-muted">The file is stored as the root <code>updat.zip</code> and inspected — then apply it with button 1.</p>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="panel panel-default">
        <div class="panel-heading"><strong>3 — Remote URL (official GitHub releases)</strong></div>
        <div class="panel-body">
          <form method="post"><?= nexa_csrf_field() ?>
            <div class="form-group"><input name="update_remote_url" class="form-control" value="<?= esc_attr($remoteUrl) ?>" placeholder="https://github.com/you/nexacms/releases/latest/download/updat.zip"></div>
            <button name="do" value="save_url" class="btn btn-default">Save URL</button>
            <button name="do" value="check_remote" class="btn btn-info">Check Remote Version</button>
            <button name="do" value="download_remote" class="btn btn-primary">Download updat.zip &amp; Inspect</button>
          </form>
          <p class="text-muted">On new installs this is pre-filled with the <strong>official NexaCMS release source</strong> (GitHub). Change or clear it any time and Save URL — your choice persists. “Check” peeks at <code>update.json</code> next to the zip; Download fetches <code>updat.zip</code> and verifies it against the published <code>SHA256SUMS</code>. Nothing is contacted or installed without your click.</p>
        </div>
      </div>
    </div>
  </div>

  <div class="panel panel-default">
    <div class="panel-heading">Package format for developers</div>
    <div class="panel-body">
      <p>Zip the site-root-relative files (<code>includes/</code>, <code>admin/</code>, <code>content/plugins/…</code>, <code>index.php</code>…). Optionally wrap them in one folder. Add <code>update.json</code> at the package root:</p>
      <pre>{"version": "1.1.0", "notes": "What changed in this release."}</pre>
      <p class="text-muted">Without <code>update.json</code>, the version is read from <code>includes/version.php</code> inside the zip. The update log lives in <code>content/backup/update-log.txt</code>.</p>
    </div>
  </div>
</div>
<?php if (!empty($bkFiles)): ?>
<div class="panel panel-default">
  <div class="panel-heading"><strong><i class="fa fa-archive"></i> Your Backups</strong> <span class="text-muted">— created automatically before every update; download copies for safekeeping</span></div>
  <div class="panel-body" style="padding:0;">
    <table class="table table-striped" style="margin:0;"><thead><tr><th>File</th><th>Size</th><th>Date</th><th></th></tr></thead><tbody>
    <?php foreach (array_slice($bkFiles, 0, 20) as $bf): ?>
      <tr>
        <td><code><?= esc_html(basename($bf)) ?></code><?php if (str_contains($bf, 'applied-updates')): ?> <span class="label label-info">applied package</span><?php endif; ?></td>
        <td><?= esc_html(number_format(filesize($bf) / 1024, 1)) ?> KB</td>
        <td><?= esc_html(date('Y-m-d H:i', filemtime($bf))) ?></td>
        <td style="text-align:right;"><a class="btn btn-xs btn-primary" href="backup-download.php?file=<?= urlencode(basename($bf)) ?>"><i class="fa fa-download"></i> Download</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
