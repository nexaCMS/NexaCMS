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
            $_SESSION['success'] = "Backup '{$zipName}' created successfully!";
        } else {
            $_SESSION['failure'] = 'Could not create backup archive.';
        }
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
