<?php
/**
 * NexaCMS Multi-Database Switcher & Migrator (admin/database.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';
require_once dirname(__DIR__) . '/includes/db-migrator.php';

function di_write_config_file(array $cfg): bool {
    $type = addslashes((string) ($cfg['database']['type'] ?? 'csv'));
    $loc  = addslashes((string) ($cfg['database']['location'] ?? 'admin/database'));
    $name = addslashes((string) ($cfg['database']['name'] ?? 'db'));
    $host = addslashes((string) ($cfg['database']['host'] ?? '127.0.0.1'));
    $port = addslashes((string) ($cfg['database']['port'] ?? ''));
    $user = addslashes((string) ($cfg['database']['user'] ?? 'root'));
    $pass = addslashes((string) ($cfg['database']['pass'] ?? ''));
    $auth = addslashes((string) ($cfg['auth_key'] ?? generateRandomString(16)));
    $sec  = addslashes((string) ($cfg['secret_key'] ?? bin2hex(random_bytes(16))));

    $php = <<<PHP
<?php
/**
 * NexaCMS Configuration (config.php)
 */
\$config = [
    'installed'  => true,
    'is_subsite' => false,
    'database'   => [
        'type'                  => getenv('DICIMES_DB_TYPE') ?: '{$type}',
        'location'              => '{$loc}',
        'name'                  => getenv('DICIMES_DB_NAME') ?: '{$name}',
        'host'                  => getenv('DICIMES_DB_HOST') ?: '{$host}',
        'port'                  => getenv('DICIMES_DB_PORT') ?: '{$port}',
        'user'                  => getenv('DICIMES_DB_USER') ?: '{$user}',
        'pass'                  => getenv('DICIMES_DB_PASS') ?: '{$pass}',
        'file'                  => getenv('DICIMES_SQLITE_FILE') ?: '',
        'auto_migrate_from_csv' => true,
        'auto_create_db'        => true,
    ],
    'abs'        => __DIR__,
    'auth_key'   => '{$auth}',
    'secret_key' => '{$sec}',
];
PHP;
    return file_put_contents(dirname(__DIR__) . '/config.php', $php . "\n") !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $op = $_POST['db_op'] ?? '';
    try {
        if ($op === 'export_csv') {
            $stats = di_export_active_db_to_csv(null, true);
            $_SESSION['success'] = 'Exported ' . count($stats) . ' tables (' . array_sum($stats) . ' total rows) to CSV.';
        } elseif ($op === 'import_csv') {
            $stats = di_migrate_csv_to_active_db(null, true);
            $_SESSION['success'] = 'Imported ' . count($stats) . ' CSV tables (' . array_sum($stats) . ' total rows) into active driver.';
        } elseif ($op === 'switch_driver') {
            $newType = strtolower(trim((string) ($_POST['db_type'] ?? 'csv')));
            if (!in_array($newType, ['csv', 'sqlite', 'json', 'array', 'mysql', 'pgsql'], true)) {
                throw new RuntimeException('Unsupported database type.');
            }
            // Snapshot current data into CSV before switching so auto-migration populates the target driver
            if (($GLOBALS['di_db_driver'] ?? 'csv') !== 'csv') {
                di_export_active_db_to_csv(null, true);
            }
            $newCfg = $config;
            $newCfg['database']['type'] = $newType;
            $newCfg['database']['name'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string) ($_POST['db_name'] ?? 'db'))) ?: 'db';
            $newCfg['database']['host'] = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
            $newCfg['database']['port'] = trim((string) ($_POST['db_port'] ?? ''));
            $newCfg['database']['user'] = trim((string) ($_POST['db_user'] ?? 'root'));
            if (($_POST['db_pass'] ?? '') !== '') {
                $newCfg['database']['pass'] = (string) $_POST['db_pass'];
            }
            di_write_config_file($newCfg);
            $_SESSION['success'] = "Switched active database driver to '{$newType}'.";
        }
    } catch (Throwable $e) {
        $_SESSION['failure'] = 'Database operation error: ' . $e->getMessage();
    }
    header('Location: database.php');
    exit;
}

$coreTables = ['posts', 'users', 'settings', 'plugins', 'menus', 'menu_items', 'customers', 'multi_slider'];
$tableStats = [];
foreach ($coreTables as $tbl) {
    $tableStats[$tbl] = table_exists($tbl) ? count_records($tbl) : 0;
}
$pdoDrivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
$curType = $GLOBALS['di_db_driver'] ?? ($config['database']['type'] ?? 'csv');

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">Database Engine &amp; Migration</h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <div class="row">
    <div class="col-md-6">
      <div class="panel panel-primary">
        <div class="panel-heading">Switch Active Database Backend</div>
        <div class="panel-body">
          <form method="post" action="database.php">
            <?= di_csrf_field() ?>
            <input type="hidden" name="db_op" value="switch_driver">

            <div class="form-group">
              <label>Storage Driver</label>
              <select name="db_type" id="di_db_type_sel" class="form-control" onchange="diToggleSqlCreds()">
                <option value="csv"    <?= $curType === 'csv'    ? 'selected' : '' ?>>CSV Flat-File (admin/database/db/*.csv)</option>
                <option value="sqlite" <?= $curType === 'sqlite' ? 'selected' : '' ?>>SQLite 3 PDO (admin/database/db.sqlite)</option>
                <option value="json"   <?= $curType === 'json'   ? 'selected' : '' ?>>JSON Flat-File (admin/database/db/*.json)</option>
                <option value="array"  <?= $curType === 'array'  ? 'selected' : '' ?>>PHP Array Store (admin/database/db/*.arr.php)</option>
                <option value="mysql"  <?= $curType === 'mysql'  ? 'selected' : '' ?>>MySQL / MariaDB (PDO)</option>
                <option value="pgsql"  <?= $curType === 'pgsql'  ? 'selected' : '' ?>>PostgreSQL (PDO)</option>
              </select>
            </div>

            <div class="form-group">
              <label>Database Name / Folder</label>
              <input type="text" name="db_name" class="form-control" value="<?= esc_attr($config['database']['name'] ?? 'db') ?>">
            </div>

            <div id="di_sql_creds" style="display:<?= in_array($curType, ['mysql', 'pgsql'], true) ? 'block' : 'none' ?>;">
              <div class="row">
                <div class="col-sm-8 form-group">
                  <label>Host</label>
                  <input type="text" name="db_host" class="form-control" value="<?= esc_attr($config['database']['host'] ?? '127.0.0.1') ?>">
                </div>
                <div class="col-sm-4 form-group">
                  <label>Port</label>
                  <input type="text" name="db_port" class="form-control" placeholder="3306 / 5432" value="<?= esc_attr($config['database']['port'] ?? '') ?>">
                </div>
              </div>
              <div class="row">
                <div class="col-sm-6 form-group">
                  <label>Username</label>
                  <input type="text" name="db_user" class="form-control" value="<?= esc_attr($config['database']['user'] ?? 'root') ?>">
                </div>
                <div class="col-sm-6 form-group">
                  <label>Password</label>
                  <input type="password" name="db_pass" class="form-control" placeholder="(unchanged if blank)">
                </div>
              </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fa fa-exchange"></i> Save &amp; Switch Driver</button>
          </form>

          <hr>
          <h4>One-Click Data Sync</h4>
          <form method="post" action="database.php" style="display:inline-block;margin-right:8px;">
            <?= di_csrf_field() ?>
            <input type="hidden" name="db_op" value="export_csv">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-download"></i> Snapshot Active DB &rarr; CSV</button>
          </form>
          <form method="post" action="database.php" style="display:inline-block;" onsubmit="return confirm('Overwrite active DB tables from CSV files?');">
            <?= di_csrf_field() ?>
            <input type="hidden" name="db_op" value="import_csv">
            <button type="submit" class="btn btn-warning btn-sm"><i class="fa fa-upload"></i> Import CSV &rarr; Active DB</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="panel panel-default">
        <div class="panel-heading">Active Driver Status (<code><?= esc_html(strtoupper($curType)) ?></code>)</div>
        <div class="panel-body">
          <p><strong>Available PHP PDO Drivers:</strong> <code><?= esc_html(implode(', ', $pdoDrivers) ?: 'none') ?></code></p>
          <table class="table table-condensed table-bordered table-striped">
            <thead><tr><th>Table</th><th class="text-right">Row Count</th></tr></thead>
            <tbody>
              <?php foreach ($tableStats as $tName => $tCnt): ?>
                <tr>
                  <td><code><?= esc_html($tName) ?></code></td>
                  <td class="text-right"><strong><?= (int) $tCnt ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function diToggleSqlCreds() {
  var v = document.getElementById('di_db_type_sel').value;
  document.getElementById('di_sql_creds').style.display = (v === 'mysql' || v === 'pgsql') ? 'block' : 'none';
}
</script>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
