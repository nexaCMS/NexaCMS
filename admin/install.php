<?php
/**
 * NexaCMS Multi-Database Setup Wizard (admin/install.php)
 */
define('NEXACMS_INSTALLING', true);
define('DICIMES_INSTALLING', true);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$rootDir = dirname(__DIR__);
if (is_file($rootDir . '/config.php')) {
    require $rootDir . '/config.php';
} else {
    require $rootDir . '/config_.php';
}
require_once $rootDir . '/includes/version.php';

$alreadyInstalled = !empty($config['installed']);
$isAdminSession   = !empty($_SESSION['user_logged_in' . ($config['auth_key'] ?? '')]);

// Prevent unauthenticated re-installation over an already installed site
if ($alreadyInstalled && !$isAdminSession) {
    header('Location: login.php');
    exit;
}

$step = max(1, min(3, (int) ($_REQUEST['step'] ?? 1)));
$installError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    try {
        $dbType   = strtolower(trim((string) ($_POST['db_type'] ?? 'csv')));
        if (!in_array($dbType, ['csv', 'sqlite', 'json', 'array', 'mysql', 'pgsql'], true)) {
            $dbType = 'csv';
        }
        $dbName   = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string) ($_POST['db_name'] ?? 'db'))) ?: 'db';
        $dbHost   = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
        $dbPort   = trim((string) ($_POST['db_port'] ?? ''));
        $dbUser   = trim((string) ($_POST['db_user'] ?? 'root'));
        $dbPass   = (string) ($_POST['db_pass'] ?? '');
        $siteName = trim((string) ($_POST['site_name'] ?? 'NexaCMS CMS'));
        $tagline  = trim((string) ($_POST['tagline'] ?? 'Fast Multi-Database PHP CMS'));
        $admUser  = trim((string) ($_POST['admin_user'] ?? 'admin'));
        $admEmail = trim((string) ($_POST['admin_email'] ?? 'admin@localhost'));
        $admPass  = (string) ($_POST['admin_pass'] ?? 'password');

        // Password policy (Settings → Password Policy): a fresh install has
        // no saved settings, so the default policy (min 8, no username in
        // password) applies; checked BEFORE anything is written to disk.
        if (function_exists('nexa_password_validate')) {
            $pwPre = nexa_password_validate($admPass, $admUser);
            if ($pwPre) throw new Exception('Admin password: ' . implode(' ', $pwPre));
        } else {
            if (mb_strlen($admPass) < 8) throw new Exception('Admin password: Password must be at least 8 characters long.');
            if (mb_strlen($admUser) >= 3 && stripos($admPass, $admUser) !== false) throw new Exception('Admin password: Password must not contain the username.');
        }

        $authKey   = substr(bin2hex(random_bytes(12)), 0, 16);
        $secretKey = bin2hex(random_bytes(16));

        $cfgContent = <<<PHP
<?php
/**
 * NexaCMS Configuration (config.php)
 */
\$config = [
    'installed'  => true,
    'is_subsite' => false,
    'database'   => [
        'type'                  => getenv('NEXACMS_DB_TYPE') ?: (getenv('DICIMES_DB_TYPE') ?: '{$dbType}'),
        'location'              => 'admin/database',
        'name'                  => getenv('NEXACMS_DB_NAME') ?: '{$dbName}',
        'host'                  => getenv('DICIMES_DB_HOST') ?: '{$dbHost}',
        'port'                  => getenv('DICIMES_DB_PORT') ?: '{$dbPort}',
        'user'                  => getenv('DICIMES_DB_USER') ?: '{$dbUser}',
        'pass'                  => getenv('DICIMES_DB_PASS') ?: '{$dbPass}',
        'file'                  => getenv('DICIMES_SQLITE_FILE') ?: '',
        'auto_migrate_from_csv' => true,
        'auto_create_db'        => true,
    ],
    'abs'        => __DIR__,
    'auth_key'   => '{$authKey}',
    'secret_key' => '{$secretKey}',
];
PHP;
        file_put_contents($rootDir . '/config.php', $cfgContent . "\n");

        // Boot newly configured driver and seed site settings + admin user
        require $rootDir . '/inc.php';
        $pwErrors = function_exists('nexa_password_validate') ? nexa_password_validate($admPass, $admUser) : [];
        if ($pwErrors) {
            throw new Exception('Admin password: ' . implode(' ', $pwErrors));
        }
        set_setting('blogname', $siteName, 'Site Name');
        set_setting('blogdescription', $tagline, 'Tagline');
        set_setting('anim_email', $admEmail, 'Admin Email');

        $existing = di_find_user_by_login($admUser);
        if ($existing !== null) {
            update_table('users', [
                'id'           => $existing['id'],
                'email'        => $admEmail,
                'pass'         => password_hash($admPass, PASSWORD_DEFAULT),
                'role'         => 'super_admin',
                'displayname'  => $admUser,
                'display-name' => $admUser,
            ], 'update');
        } else {
            create_user([
                'user'         => $admUser,
                'pass'         => $admPass,
                'email'        => $admEmail,
                'displayname'  => $admUser,
                'display-name' => $admUser,
                'role'         => 'super_admin',
            ]);
        }

        // The wizard account is the ONLY user an install creates: remove the
        // factory-seeded default if an older bootstrap already made one.
        $legacyDefault = di_find_user_by_login('admin');
        if ($legacyDefault !== null && strtolower((string) $admUser) !== 'admin'
            && !empty($legacyDefault['pass']) && password_verify('password', (string) $legacyDefault['pass'])) {
            delete_records('users', (int) $legacyDefault['id']);
        }

        $_SESSION['user_logged_in' . $authKey] = true;
        $_SESSION['name']         = $admUser;
        $_SESSION['display-name'] = $admUser;
        $_SESSION['admin_type']   = 'super_admin';
    } catch (Throwable $e) {
        $installError = $e->getMessage();
        $step = 2;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexaCMS <?= htmlspecialchars(NEXACMS_VERSION) ?> Installer</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="fonts/font-awesome/css/font-awesome.min.css">
    <style>
      body { background: #f4f6f9; padding-top: 40px; }
      .wizard-box { max-width: 680px; margin: 0 auto 40px; }
    </style>
  </head>
  <body>
    <div class="container wizard-box">
      <div class="panel panel-primary">
        <div class="panel-heading">
          <h3 class="panel-title"><i class="fa fa-cogs"></i> NexaCMS <?= htmlspecialchars(NEXACMS_VERSION) ?> Setup Wizard &mdash; Step <?= $step ?> of 3</h3>
        </div>
        <div class="panel-body">
          <?php if ($installError !== ''): ?>
            <div class="alert alert-danger"><strong>Error:</strong> <?= htmlspecialchars($installError) ?></div>
          <?php endif; ?>

          <?php if ($step === 1): include __DIR__ . '/install/1.php'; ?>
          <?php elseif ($step === 2): include __DIR__ . '/install/2.php'; ?>
          <?php else: include __DIR__ . '/install/final.php'; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </body>
</html>
