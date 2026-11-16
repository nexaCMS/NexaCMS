<?php
/**
 * NexaCMS Site Backup Manager (admin/backups.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$rootDir   = dirname(__DIR__);
$backupDir = $rootDir . '/content/backup';
@mkdir($backupDir, 0755, true);

if (!empty($_GET['download'])) {
    $fname = basename((string) $_GET['download']);
    $fpath = $backupDir . '/' . $fname;
    if (str_ends_with($fname, '.zip') && is_file($fpath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($fpath));
        readfile($fpath);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $act = $_POST['backup_action'] ?? '';
    if ($act === 'create') {
        $zipName = 'dicimes-backup-' . date('Ymd-His') . '.zip';
        $zipPath = $backupDir . '/' . $zipName;
        $za = new FlxZipArchive();
        if ($za->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $za->addDir($rootDir, 'dicimes', ['backup', '.git', 'node_modules', 'temp_uploads']);
            $za->close();
            $pr = function_exists('nexa_prune_backups') ? nexa_prune_backups($zipName) : ['deleted' => []];
            $_SESSION['success'] = "Backup '{$zipName}' created successfully!"
                . (!empty($pr['deleted']) ? ' Retention cleanup removed ' . count($pr['deleted']) . ' older backup(s).' : '');
        } else {
            $_SESSION['failure'] = 'Could not create backup archive.';
        }
    } elseif ($act === 'retention') {
        $mode = (string) ($_POST['retention_mode'] ?? 'count');
        if (!in_array($mode, ['count', 'days', 'off'], true)) $mode = 'count';
        set_setting('backup_retention_mode', $mode, 'Backup Retention Mode');
        set_setting('backup_keep_count', (string) max(1, min(100, (int) ($_POST['keep_count'] ?? 5))), 'Backup Keep Count');
        set_setting('backup_keep_days', (string) max(1, min(3650, (int) ($_POST['keep_days'] ?? 30))), 'Backup Keep Days');
        $_SESSION['success'] = 'Backup retention settings saved.';
    } elseif ($act === 'cleanup') {
        $pr = function_exists('nexa_prune_backups') ? nexa_prune_backups() : ['deleted' => [], 'kept' => 0];
        $_SESSION['info'] = !empty($pr['deleted'])
            ? 'Cleanup deleted ' . count($pr['deleted']) . ' backup(s): ' . implode(', ', array_map('esc_html', $pr['deleted'])) . ' — ' . (int) $pr['kept'] . ' kept.'
            : 'Cleanup ran: nothing to delete under the current policy (' . (int) ($pr['kept'] ?? 0) . ' backup(s) kept).';
    } elseif ($act === 'delete' && !empty($_POST['file'])) {
        $fname = basename((string) $_POST['file']);
        $fpath = $backupDir . '/' . $fname;
        if (str_ends_with($fname, '.zip') && is_file($fpath)) {
            @unlink($fpath);
            $_SESSION['info'] = "Deleted backup '{$fname}'.";
        }
    }
    header('Location: backups.php');
    exit;
}

$backups = [];
foreach (scandir($backupDir) ?: [] as $f) {
    if (str_ends_with($f, '.zip') && is_file($backupDir . '/' . $f)) {
        $backups[] = [
            'name'  => $f,
            'size'  => filesize($backupDir . '/' . $f),
            'mtime' => filemtime($backupDir . '/' . $f),
        ];
    }
}
usort($backups, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">
        Site Backups
        <form method="post" action="backups.php" style="display:inline;margin-left:10px;">
          <?= di_csrf_field() ?>
          <input type="hidden" name="backup_action" value="create">
          <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-archive"></i> Create New Backup (.zip)</button>
        </form>
      </h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <?php
    $retention  = function_exists('nexa_backup_retention') ? nexa_backup_retention() : ['mode' => 'count', 'keep' => 5, 'days' => 30];
    $totalBytes = 0; foreach ($backups as $bk) $totalBytes += $bk['size'];
  ?>
  <div class="panel panel-default">
    <div class="panel-heading"><i class="fa fa-clock-o"></i> Backup Retention &amp; Cleanup</div>
    <div class="panel-body">
      <p><strong><?= count($backups) ?></strong> backup(s) using <strong><?= number_format($totalBytes / 1048576, 2) ?> MB</strong> in <code>content/backup/</code>.
      The policy below applies automatically whenever a backup is created — a manual Site Backup, a Backups+ archive, or the safety backup made before a system update — and the newest backup is never deleted.</p>
      <form method="post" action="backups.php" class="form-inline" style="margin-bottom:10px;">
        <?= di_csrf_field() ?>
        <input type="hidden" name="backup_action" value="retention">
        <label>Policy:
          <select name="retention_mode" class="form-control">
            <option value="count" <?= $retention['mode'] === 'count' ? 'selected' : '' ?>>Keep newest N backups</option>
            <option value="days" <?= $retention['mode'] === 'days' ? 'selected' : '' ?>>Keep backups newer than N days</option>
            <option value="off" <?= $retention['mode'] === 'off' ? 'selected' : '' ?>>Off — manual delete only</option>
          </select>
        </label>
        <label>Keep <input type="number" name="keep_count" value="<?= (int) $retention['keep'] ?>" min="1" max="100" class="form-control" style="width:80px;"> backups</label>
        <label>or newer than <input type="number" name="keep_days" value="<?= (int) $retention['days'] ?>" min="1" max="3650" class="form-control" style="width:90px;"> days</label>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-save"></i> Save Policy</button>
      </form>
      <form method="post" action="backups.php" onsubmit="return confirm('Delete backups outside the retention policy now? The newest backup is always kept.');">
        <?= di_csrf_field() ?>
        <input type="hidden" name="backup_action" value="cleanup">
        <button type="submit" class="btn btn-warning btn-sm"><i class="fa fa-eraser"></i> Clean Up Now</button>
        <span class="text-muted">…or delete any single backup manually with its Delete button below — that always works, whatever the policy.</span>
      </form>
    </div>
  </div>

  <div class="panel panel-default">
    <div class="panel-heading">Saved Archives in <code>content/backup/</code></div>
    <div class="panel-body">
      <table class="table table-striped table-bordered">
        <thead>
          <tr>
            <th>Archive Filename</th>
            <th width="140">Size</th>
            <th width="190">Created</th>
            <th width="180">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($backups)): ?>
            <tr><td colspan="4" class="text-center text-muted">No backups created yet. Click "Create New Backup" above to snapshot your site and database.</td></tr>
          <?php else: ?>
            <?php foreach ($backups as $bk): ?>
              <tr>
                <td><code><?= esc_html($bk['name']) ?></code></td>
                <td><?= number_format($bk['size'] / 1048576, 2) ?> MB</td>
                <td><?= esc_html(date('Y-m-d H:i:s', $bk['mtime'])) ?></td>
                <td>
                  <a href="backups.php?download=<?= urlencode($bk['name']) ?>" class="btn btn-xs btn-primary"><i class="fa fa-download"></i> Download</a>
                  <form method="post" action="backups.php" style="display:inline;" onsubmit="return confirm('Delete backup <?= esc_attr($bk['name']) ?>?');">
                    <?= di_csrf_field() ?>
                    <input type="hidden" name="backup_action" value="delete">
                    <input type="hidden" name="file" value="<?= esc_attr($bk['name']) ?>">
                    <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
