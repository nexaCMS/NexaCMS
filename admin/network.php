<?php
/**
 * NexaCMS Network Admin (admin/network.php) — subsites/multisite control.
 * Always runs against the MAIN site database (the resolver keeps this page
 * in the main context). Only main-site super admins may use it.
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

nexa_multisite_ensure_registry();

$myAssignments = [];
try { foreach (get_table('network_site_users')['data'] as $r) if ((int) ($r['user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)) $myAssignments[] = $r; } catch (Throwable $e) {}
$isNetworkAdmin = function_exists('nexa_is_network_admin') ? nexa_is_network_admin() : (empty($myAssignments) && (in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true) || !empty($_SESSION['nexa_network_admin'])));

// Subsite admins (and anyone else who is not a network admin) must never
// reach subsite management — creating subsites is a network-level power.
// Hard-block every POST (site_save included) and answer 403 on reads.
if (!$isNetworkAdmin) {
    http_response_code(403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { echo '403 Forbidden — only the network administrator can manage subsites.'; exit; }
}

$root = dirname(__DIR__);
$themesList = [];
foreach (scandir($root . '/content/themes') ?: [] as $e) if ($e !== '.' && $e !== '..' && is_dir($root . '/content/themes/' . $e) && is_file($root . '/content/themes/' . $e . '/index.php')) $themesList[] = $e;
sort($themesList);
$pluginsList = [];
foreach (scandir($root . '/content/plugins') ?: [] as $e) if ($e !== '.' && $e !== '..' && is_dir($root . '/content/plugins/' . $e) && is_file($root . '/content/plugins/' . $e . '/functions.php')) $pluginsList[] = $e;
sort($pluginsList);

function nexa_network_seed(array $site, string $adminUser = '', string $adminHash = '', string $theme = ''): string {
    $script = dirname(__DIR__) . '/includes/multisite-seed.php';
    if (!function_exists('shell_exec') || !is_file($script)) return 'Seeding skipped (shell unavailable) — the site initialises itself on first visit.';
    $env = [
        'NEXA_MULTISITE_SITE' => (string) $site['slug'], 'NEXA_SEED_NAME' => (string) $site['name'],
        'NEXA_SEED_ADMIN_USER' => $adminUser, 'NEXA_SEED_ADMIN_HASH' => $adminHash, 'NEXA_SEED_THEME' => $theme,
    ];
    $cmd = '';
    foreach ($env as $k => $v) $cmd .= $k . '=' . escapeshellarg($v) . ' ';
    $cmd .= escapeshellarg(PHP_BINARY ?: 'php') . ' ' . escapeshellarg($script) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    return trim($out) !== '' ? trim($out) : 'Seeded.';
}

/** Read a pending package zip: manifest summary + file listing (for review before approval). */
function nexa_pkg_zip_info(string $path, string $type): array {
    $info = ['entries' => [], 'manifest' => null, 'error' => ''];
    if (!is_file($path)) { $info['error'] = 'Package file is missing.'; return $info; }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { $info['error'] = 'Could not open the .zip archive.'; return $info; }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        $info['entries'][] = ['name' => (string) $st['name'], 'size' => (int) $st['size']];
    }
    $read = static function (string $suffix) use ($zip): ?string {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if ($n === $suffix || str_ends_with($n, '/' . $suffix)) { $c = $zip->getFromIndex($i); return $c === false ? null : (string) $c; }
        }
        return null;
    };
    if ($type === 'plugin') {
        $j = $read('plugin.json');
        if ($j !== null) { $m = json_decode($j, true); if (is_array($m)) $info['manifest'] = ['name' => (string) ($m['name'] ?? ''), 'version' => (string) ($m['version'] ?? ''), 'author' => (string) ($m['author'] ?? ''), 'description' => (string) ($m['description'] ?? '')]; }
    } else {
        $j = $read('theme.json');
        if ($j !== null) { $m = json_decode($j, true); if (is_array($m)) $info['manifest'] = ['name' => (string) ($m['name'] ?? ''), 'version' => (string) ($m['version'] ?? ''), 'author' => (string) ($m['author'] ?? ''), 'description' => (string) ($m['description'] ?? '')]; }
        if ($info['manifest'] === null) {
            $css = $read('style.css');
            if ($css !== null && preg_match('/Theme Name:\s*(.+)/i', $css, $mm)) {
                $info['manifest'] = ['name' => trim($mm[1]), 'version' => preg_match('/Version:\s*([^\s*]+)/i', $css, $vv) ? trim($vv[1]) : '', 'author' => '', 'description' => ''];
            }
        }
    }
    $zip->close();
    return $info;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf() && $isNetworkAdmin) {
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'net_settings') {
        set_setting('multisite_enabled', !empty($_POST['enabled']) ? '1' : '0', 'Multisite Enabled');
        set_setting('multisite_path_mode', in_array($_POST['path_mode'] ?? '', ['sites', 'root'], true) ? (string) $_POST['path_mode'] : 'sites', 'Multisite Subfolder Mode');
        set_setting('multisite_main_host', strtolower(trim((string) ($_POST['main_host'] ?? ''))), 'Multisite Main Host (for wildcard subdomains)');
        $_SESSION['success'] = 'Network settings saved.';
    } elseif ($do === 'switch') {
        $sid = (int) ($_POST['site_id'] ?? 0);
        if ($sid > 0) { $_SESSION['nexa_admin_site_id'] = $sid; $_SESSION['success'] = 'You are now managing that subsite. Use Network → Back to main site to return.'; }
        else { unset($_SESSION['nexa_admin_site_id']); $_SESSION['success'] = 'Back to managing the main site.'; }
        header('Location: index.php'); exit;
    } elseif ($do === 'site_save') {
        $id = (int) ($_POST['site_id'] ?? 0);
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? '';
        $name = trim((string) ($_POST['name'] ?? '')) ?: ucfirst($slug);
        $host = strtolower(trim((string) ($_POST['host'] ?? '')));
        $host = preg_replace('#^https?://#', '', $host) ?? $host; $host = trim($host, '/');
        $errors = [];
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) $errors[] = 'Slug must be lowercase letters, numbers and hyphens.';
        if ($id === 0 && trim((string) ($_POST['admin_username'] ?? '')) !== '' && (string) ($_POST['admin_password'] ?? '') !== '' && function_exists('nexa_password_validate')) {
            foreach (nexa_password_validate((string) $_POST['admin_password'], trim((string) ($_POST['admin_username'] ?? ''))) as $pwErr) $errors[] = 'Subsite admin password: ' . $pwErr;
        }
        if ($host === '' && in_array($slug, nexa_sites_reserved_slugs(), true)) $errors[] = "Slug '{$slug}' is reserved for the main site. Give the subsite an explicit subdomain/host if you really need that name.";
        foreach (nexa_multisite_sites() as $s) if (strcasecmp((string) $s['slug'], $slug) === 0 && (int) $s['id'] !== $id) $errors[] = 'That slug is already used by another subsite.';
        $mode = (string) get_setting('multisite_path_mode', 'sites');
        if (!$errors && (string) get_setting('multisite_enabled', '0') === '1' && $mode === 'root' && $host === '' && nexa_multisite_slug_taken_by_content($slug)) {
            $errors[] = "Slug '{$slug}' clashes with an existing main-site page/post. In root subfolder mode that URL is ambiguous — rename one of them (or use a subdomain, which needs no slug restrictions).";
        }
        if ($errors) {
            $_SESSION['failure'] = implode('<br>', array_map('esc_html', $errors));
        } else {
            $row = [
                'slug' => $slug, 'name' => $name, 'host' => $host,
                'storage' => in_array($_POST['storage'] ?? '', ['separate', 'shared'], true) ? (string) $_POST['storage'] : 'separate',
                'db_name' => trim((string) ($_POST['db_name'] ?? '')),
                'status' => in_array($_POST['status'] ?? '', ['active', 'suspended'], true) ? (string) $_POST['status'] : 'active',
                'theme' => basename((string) ($_POST['theme'] ?? '')),
                'allowed_themes' => implode('|', array_map('strval', (array) ($_POST['allowed_themes'] ?? []))),
                'allowed_plugins' => implode('|', array_map('strval', (array) ($_POST['allowed_plugins'] ?? []))),
                'mandatory_plugins' => implode('|', array_map('strval', (array) ($_POST['mandatory_plugins'] ?? []))),
                'allow_uploads' => in_array($_POST['allow_uploads'] ?? '', ['allow', 'deny', 'approval'], true) ? (string) $_POST['allow_uploads'] : 'deny',
            ];
            if ($row['theme'] === '.' || $row['theme'] === '..') $row['theme'] = '';
            if ($id > 0) { $row['id'] = $id; update_table('network_sites', $row, 'update'); $_SESSION['success'] = "Subsite '{$name}' updated."; }
            else {
                $row['created_at'] = date('Y-m-d H:i:s');
                $id = (int) update_table('network_sites', $row, 'insert');
                $row['id'] = $id;
                // Optional subsite admin: main-site login account + assignment.
                $seedUser = ''; $seedHash = '';
                $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
                if ($adminUsername !== '') {
                    $acct = di_find_user_by_login($adminUsername);
                    if (!$acct && trim((string) ($_POST['admin_password'] ?? '')) !== '') {
                        update_table('users', ['user' => $adminUsername, 'pass' => password_hash((string) $_POST['admin_password'], PASSWORD_DEFAULT), 'displayname' => $name . ' Admin', 'display-name' => $name . ' Admin', 'email' => $adminUsername . '@localhost', 'status' => '1', 'role' => 'admin', 'register' => date('Y-m-d H:i:s')], 'insert');
                        $acct = di_find_user_by_login($adminUsername);
                    }
                    if ($acct) {
                        update_table('network_site_users', ['user_id' => (string) $acct['id'], 'site_id' => (string) $id, 'role' => 'admin', 'created_at' => date('Y-m-d H:i:s')], 'insert');
                        $seedUser = (string) $acct['user']; $seedHash = (string) $acct['pass'];
                    }
                }
                $seedMsg = nexa_network_seed($row, $seedUser, $seedHash, (string) $row['theme']);
                $_SESSION['success'] = "Subsite '{$name}' created. " . esc_html($seedMsg) . ' Visit it at <a href="' . esc_url(nexa_multisite_site_public_url($row)) . '" target="_blank">' . esc_html(nexa_multisite_site_public_url($row)) . '</a>';
            }
        }
    } elseif ($do === 'site_delete') {
        $id = (int) ($_POST['site_id'] ?? 0);
        $site = null; foreach (nexa_multisite_sites() as $s) if ((int) $s['id'] === $id) $site = $s;
        if ($site) {
            foreach (get_table('network_site_users')['data'] as $r) if ((int) $r['site_id'] === $id) delete_records('network_site_users', (int) $r['id']);
            delete_records('network_sites', $id);
            if (!empty($_POST['delete_data'])) {
                if ((string) $site['storage'] === 'separate') {
                    $dir = $root . '/content/sites/' . basename((string) $site['slug']);
                    if (is_dir($dir) && function_exists('nexa_remove_dir')) nexa_remove_dir($dir);
                } else {
                    $prefix = 's' . $id . '_';
                    foreach (list_tables() as $tbl) if (str_starts_with($tbl, $prefix)) drop_table($tbl);
                }
                $pd = $root . '/content/plugins-' . basename((string) $site['slug']);
                if (is_dir($pd) && function_exists('nexa_remove_dir')) nexa_remove_dir($pd);
                $td = $root . '/content/themes-' . basename((string) $site['slug']);
                if (is_dir($td) && function_exists('nexa_remove_dir')) nexa_remove_dir($td);
            }
            if ((int) ($_SESSION['nexa_admin_site_id'] ?? 0) === $id) unset($_SESSION['nexa_admin_site_id']);
            $_SESSION['success'] = 'Subsite deleted' . (!empty($_POST['delete_data']) ? ' (including its data)' : ' (its data was kept)') . '.';
        }
    } elseif ($do === 'assign') {
        $uid = (int) ($_POST['user_id'] ?? 0); $sid = (int) ($_POST['site_id'] ?? 0);
        if ($uid > 0 && $sid > 0) {
            $exists = false; foreach (get_table('network_site_users')['data'] as $r) if ((int) $r['user_id'] === $uid && (int) $r['site_id'] === $sid) $exists = true;
            if (!$exists) update_table('network_site_users', ['user_id' => (string) $uid, 'site_id' => (string) $sid, 'role' => 'admin', 'created_at' => date('Y-m-d H:i:s')], 'insert');
            $_SESSION['success'] = 'User assigned to the subsite. They will be pinned to it when they log in.';
        }
    } elseif ($do === 'unassign') {
        delete_records('network_site_users', (int) ($_POST['assign_id'] ?? 0));
        $_SESSION['success'] = 'Assignment removed.';
    } elseif ($do === 'request_approve' || $do === 'request_reject') {
        $rid = (int) ($_POST['request_id'] ?? 0);
        $req = null; foreach (get_table('network_package_requests')['data'] as $r) if ((int) $r['id'] === $rid) $req = $r;
        if ($req) {
            $path = $root . '/content/network-pending/' . basename((string) $req['file']);
            if ($do === 'request_reject') {
                if (is_file($path)) @unlink($path);
                update_table('network_package_requests', ['id' => $rid, 'status' => 'rejected', 'reviewed_by' => (string) ($_SESSION['name'] ?? 'network admin'), 'reviewed_at' => date('Y-m-d H:i:s')], 'update');
                $_SESSION['success'] = 'Package request rejected and the file deleted.';
            } elseif (is_file($path)) {
                $_FILES[(string) $req['type']] = ['name' => (string) $req['orig_name'], 'tmp_name' => $path, 'error' => 0, 'size' => filesize($path)];
                $GLOBALS['nexa_package_local_file'] = true;
                // An approved package installs into the REQUESTING
                // subsite's own folder (its private copy/version) — for
                // plugins and themes alike. Packages installed from the
                // main site are the shared ones; a package only becomes
                // site-private by being uploaded from that subsite.
                $destOverride = null;
                foreach (nexa_multisite_sites() as $sx) if ((int) $sx['id'] === (int) $req['site_id']) {
                    $destOverride = (string) $req['type'] === 'theme'
                        ? (function_exists('nexa_site_themes_dir') ? nexa_site_themes_dir($sx) : null)
                        : (function_exists('nexa_site_plugins_dir') ? nexa_site_plugins_dir($sx) : null);
                }
                ob_start(); upload((string) $req['type'], $destOverride); $out = ob_get_clean(); unset($GLOBALS['nexa_package_local_file']);
                update_table('network_package_requests', ['id' => $rid, 'status' => str_contains((string) $out, 'installed successfully') ? 'installed' : 'approved-failed', 'reviewed_by' => (string) ($_SESSION['name'] ?? 'network admin'), 'reviewed_at' => date('Y-m-d H:i:s')], 'update');
                $_SESSION['success'] = strip_tags((string) $out);
            }
        }
    } elseif ($do === 'site_plugin_remove' || $do === 'site_theme_remove') {
        // Site copies are created ONLY by uploads from the subsite itself;
        // the network admin's job here is oversight: removing a site copy
        // makes the subsite fall back to the shared network copy (if any).
        $sid = (int) ($_POST['site_id'] ?? 0);
        $isTheme = $do === 'site_theme_remove';
        $pkg = basename((string) ($_POST[$isTheme ? 'theme' : 'plugin'] ?? ''));
        $site = null; foreach (nexa_multisite_sites() as $sx) if ((int) $sx['id'] === $sid) $site = $sx;
        if ($site && $pkg !== '') {
            $sitePkgDir = ($isTheme ? nexa_site_themes_dir($site) : nexa_site_plugins_dir($site)) . '/' . $pkg;
            if (is_dir($sitePkgDir)) nexa_remove_dir($sitePkgDir);
            $_SESSION['success'] = "Removed {$site['name']}'s own copy of {$pkg} — the site now falls back to the shared network copy (if any).";
        }
    }
    header('Location: network.php'); exit;
}

$sites = nexa_multisite_sites();
$edit = null;
if (!empty($_GET['edit'])) foreach ($sites as $s) if ((int) $s['id'] === (int) $_GET['edit']) $edit = $s;
$requests = array_values(array_filter(get_table('network_package_requests')['data'], static fn($r) => ($r['status'] ?? '') === 'pending'));
$history = array_slice(array_reverse(array_values(array_filter(get_table('network_package_requests')['data'], static fn($r) => ($r['status'] ?? '') !== 'pending'))), 0, 10);
$inspectReq = null;
if (!empty($_GET['inspect'])) foreach (get_table('network_package_requests')['data'] as $r) if ((int) $r['id'] === (int) $_GET['inspect']) $inspectReq = $r;
$users = get_table('users')['data'];
$assignments = get_table('network_site_users')['data'];
$managingId = (int) ($_SESSION['nexa_admin_site_id'] ?? 0);

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row"><div class="col-lg-12">
    <h1 class="page-header"><i class="fa fa-sitemap fa-fw"></i> Network Admin <small>subsites / multisite</small></h1>
  </div></div>
  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <?php if (!$isNetworkAdmin): ?>
    <div class="alert alert-danger"><strong>Network Admin is for main-site super admins only.</strong> Your account is assigned to a subsite, so you administer that site from the normal admin screens.</div>
  <?php else: ?>

  <?php if ($managingId > 0): ?>
    <div class="alert alert-warning">You are currently managing subsite #<?= $managingId ?> in this admin session.
      <form method="post" style="display:inline;"><?= di_csrf_field() ?><input type="hidden" name="do" value="switch"><input type="hidden" name="site_id" value="0"><button class="btn btn-xs btn-default">Back to main site</button></form>
    </div>
  <?php endif; ?>

  <div class="panel <?= $requests ? 'panel-danger' : 'panel-default' ?>"><div class="panel-heading"><strong><i class="fa fa-inbox"></i> Package approvals — subsite theme/plugin uploads</strong> <span class="badge"><?= count($requests) ?></span></div><div class="panel-body">
    <?php if (!$requests): ?><p class="text-muted">No theme/plugin packages are waiting for approval. When a subsite admin uploads a package (and their site's upload policy is “needs approval”), it appears here for your review before anything is installed.</p><?php endif; ?>
    <?php foreach ($requests as $r): $zp = $root . '/content/network-pending/' . basename((string) $r['file']); $zi = nexa_pkg_zip_info($zp, (string) $r['type']); ?>
      <div style="border-bottom:1px solid #eee;padding:10px 0;">
        <span class="label label-<?= $r['type'] === 'plugin' ? 'info' : 'primary' ?>"><?= esc_html($r['type']) ?></span>
        <strong><?= esc_html($zi['manifest']['name'] ?? '') !== '' ? esc_html($zi['manifest']['name']) : esc_html($r['orig_name']) ?></strong>
        <?php if (!empty($zi['manifest']['version'])): ?>v<?= esc_html($zi['manifest']['version']) ?><?php endif; ?>
        <?php if (!empty($zi['manifest']['author'])): ?><span class="text-muted">by <?= esc_html($zi['manifest']['author']) ?></span><?php endif; ?>
        <div class="text-muted" style="font-size:12px;">File: <code><?= esc_html($r['orig_name']) ?></code><?= is_file($zp) ? ' (' . number_format(filesize($zp) / 1024, 1) . ' KB, ' . count($zi['entries']) . ' files)' : ' (file missing!)' ?> · from subsite <code><?= esc_html($r['site_slug']) ?></code> · uploaded by <?= esc_html($r['requested_by']) ?> · <?= esc_html($r['created_at']) ?></div>
        <?php if (!empty($zi['manifest']['description'])): ?><div style="font-size:12px;"><?= esc_html($zi['manifest']['description']) ?></div><?php endif; ?>
        <div class="text-muted" style="font-size:12px;">Approving installs it <strong>for subsite <?= esc_html($r['site_slug']) ?> only</strong> — its own private copy and version; other sites keep using the shared network copy.</div>
        <?php if ($zi['error']): ?><div class="text-danger" style="font-size:12px;"><?= esc_html($zi['error']) ?></div><?php endif; ?>
        <div style="margin-top:6px;">
          <a class="btn btn-xs btn-default" href="network.php?inspect=<?= (int) $r['id'] ?>"><i class="fa fa-search"></i> Inspect files</a>
          <form method="post" style="display:inline;" onsubmit="return confirm('Approve and install this package?');"><?= di_csrf_field() ?><input type="hidden" name="do" value="request_approve"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-xs btn-success">Approve &amp; install</button></form>
          <form method="post" style="display:inline;" onsubmit="return confirm('Reject and delete this package?');"><?= di_csrf_field() ?><input type="hidden" name="do" value="request_reject"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-xs btn-danger">Reject</button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div></div>

  <?php if ($inspectReq): $zp = $root . '/content/network-pending/' . basename((string) $inspectReq['file']); $zi = nexa_pkg_zip_info($zp, (string) $inspectReq['type']);
    $preview = ''; $previewName = '';
    if (!empty($_GET['file'])) {
        foreach ($zi['entries'] as $en) if ($en['name'] === (string) $_GET['file']) {
            $ext = strtolower(pathinfo($en['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['php','json','css','js','txt','md','html','htm','xml','ini'], true) && is_file($zp)) {
                $zip2 = new ZipArchive();
                if ($zip2->open($zp) === true) { $c = $zip2->getFromName($en['name']); $zip2->close(); if ($c !== false) { $preview = (string) $c; $previewName = $en['name']; } }
            }
        }
    } ?>
  <div class="panel panel-warning"><div class="panel-heading"><strong><i class="fa fa-search"></i> Inspecting package</strong> — <?= esc_html($inspectReq['orig_name']) ?> (<?= esc_html($inspectReq['type']) ?> from <code><?= esc_html($inspectReq['site_slug']) ?></code>) <a class="btn btn-xs btn-default pull-right" href="network.php">Close</a></div><div class="panel-body">
    <?php if ($zi['error']): ?><div class="alert alert-danger"><?= esc_html($zi['error']) ?></div><?php endif; ?>
    <div class="row"><div class="col-md-5">
      <table class="table table-condensed"><thead><tr><th>File in package</th><th>Size</th><th></th></tr></thead><tbody>
      <?php foreach ($zi['entries'] as $en): ?><tr><td><code><?= esc_html($en['name']) ?></code></td><td><?= number_format($en['size'] / 1024, 1) ?> KB</td>
        <td><?php if (in_array(strtolower(pathinfo($en['name'], PATHINFO_EXTENSION)), ['php','json','css','js','txt','md','html','htm','xml','ini'], true)): ?><a class="btn btn-xs btn-default" href="network.php?inspect=<?= (int) $inspectReq['id'] ?>&amp;file=<?= urlencode($en['name']) ?>">View</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div><div class="col-md-7">
      <?php if ($previewName !== ''): ?><strong><?= esc_html($previewName) ?></strong><pre style="max-height:420px;overflow:auto;font-size:11px;"><?= esc_html(mb_substr($preview, 0, 60000)) ?><?= mb_strlen($preview) > 60000 ? "\n… (truncated)" : '' ?></pre>
      <?php else: ?><p class="text-muted">Pick a text file to preview its contents before you approve. Approving installs it into <code>content/<?= esc_html($inspectReq['type']) ?>s-<?= esc_html($inspectReq['site_slug']) ?>/</code> — a private copy for that subsite only, so it can run its own version; other sites are unaffected.</p><?php endif; ?>
    </div></div>
    <?php if (($inspectReq['status'] ?? '') === 'pending'): ?>
    <form method="post" style="display:inline;" onsubmit="return confirm('Approve and install this package?');"><?= di_csrf_field() ?><input type="hidden" name="do" value="request_approve"><input type="hidden" name="request_id" value="<?= (int) $inspectReq['id'] ?>"><button class="btn btn-success btn-sm">Approve &amp; install</button></form>
    <form method="post" style="display:inline;" onsubmit="return confirm('Reject and delete this package?');"><?= di_csrf_field() ?><input type="hidden" name="do" value="request_reject"><input type="hidden" name="request_id" value="<?= (int) $inspectReq['id'] ?>"><button class="btn btn-danger btn-sm">Reject</button></form>
    <?php else: ?><span class="label label-default">Status: <?= esc_html($inspectReq['status']) ?></span><?php endif; ?>
  </div></div>
  <?php endif; ?>

  <div class="panel panel-primary"><div class="panel-heading"><strong>Network settings</strong></div><div class="panel-body">
    <form method="post"><?= di_csrf_field() ?><input type="hidden" name="do" value="net_settings">
      <label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" <?= get_setting('multisite_enabled', '0') === '1' ? 'checked' : '' ?>> <strong>Enable subsites / multisite</strong> (when off, only the main site exists — exactly as before)</label>
      <div class="row" style="margin-top:10px;">
        <div class="col-md-4"><div class="form-group"><label>Subfolder style</label>
          <select name="path_mode" class="form-control">
            <option value="sites" <?= get_setting('multisite_path_mode', 'sites') === 'sites' ? 'selected' : '' ?>>Prefixed — example.com/sites/shopping/</option>
            <option value="root" <?= get_setting('multisite_path_mode', 'sites') === 'root' ? 'selected' : '' ?>>Root level — example.com/shopping/ (slug clash checks on)</option>
          </select></div></div>
        <div class="col-md-4"><div class="form-group"><label>Main host for wildcard subdomains</label>
          <input name="main_host" class="form-control" value="<?= esc_attr(get_setting('multisite_main_host', '')) ?>" placeholder="example.com">
          <p class="help-block">Enables shopping.example.com style sites. Needs wildcard DNS (*.example.com) on your host; explicit per-subsite hosts below work without it. Subdomain sites need no slug restrictions.</p></div></div>
      </div>
      <button class="btn btn-primary">Save Network Settings</button>
    </form>
  </div></div>

  <div class="row"><div class="col-md-5">
  <div class="panel panel-default"><div class="panel-heading"><strong><?= $edit ? 'Edit subsite' : 'Create a subsite' ?></strong></div><div class="panel-body">
    <form method="post"><?= di_csrf_field() ?><input type="hidden" name="do" value="site_save"><input type="hidden" name="site_id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="form-group"><label>Site name</label><input name="name" class="form-control" required value="<?= esc_attr($edit['name'] ?? '') ?>" placeholder="Shopping"></div>
      <div class="form-group"><label>Slug (URL name)</label><input name="slug" class="form-control" required value="<?= esc_attr($edit['slug'] ?? '') ?>" placeholder="shopping" <?= $edit ? '' : '' ?>><p class="help-block">Used for /sites/&lt;slug&gt;/ or /&lt;slug&gt;/ URLs and for the subdomain label.</p></div>
      <div class="form-group"><label>Custom host / subdomain mapping (optional)</label><input name="host" class="form-control" value="<?= esc_attr($edit['host'] ?? '') ?>" placeholder="shopping.example.com"><p class="help-block">Exact hostname that should open this subsite. Leave blank to use the slug-based URL/subdomain.</p></div>
      <div class="form-group"><label>Data storage</label>
        <select name="storage" class="form-control">
          <option value="separate" <?= ($edit['storage'] ?? 'separate') === 'separate' ? 'selected' : '' ?>>Own database (recommended for file-based drivers) — own settings, posts, pages, users</option>
          <option value="shared" <?= ($edit['storage'] ?? '') === 'shared' ? 'selected' : '' ?>>Shared database with s&lt;id&gt;_ table prefix (CSV/JSON/Array drivers)</option>
        </select><p class="help-block">For SQL drivers, “own database” uses the database name below when given. You choose per subsite.</p></div>
      <div class="form-group"><label>Own database name (SQL only, optional)</label><input name="db_name" class="form-control" value="<?= esc_attr($edit['db_name'] ?? '') ?>"></div>
      <div class="form-group"><label>Status</label>
        <select name="status" class="form-control"><option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="suspended" <?= ($edit['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended (visitors get 503)</option></select></div>
      <div class="form-group"><label>Mandated theme (optional)</label>
        <select name="theme" class="form-control"><option value="">— Subsite admin may choose (from allow-list) —</option>
          <?php foreach ($themesList as $th): ?><option value="<?= esc_attr($th) ?>" <?= ($edit['theme'] ?? '') === $th ? 'selected' : '' ?>><?= esc_html($th) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-group"><label>Allowed themes (none ticked = all allowed)</label><br>
        <?php $at = nexa_multisite_csv_list($edit['allowed_themes'] ?? ''); foreach ($themesList as $th): ?>
          <label class="checkbox-inline"><input type="checkbox" name="allowed_themes[]" value="<?= esc_attr($th) ?>" <?= in_array($th, $at, true) ? 'checked' : '' ?>> <?= esc_html($th) ?></label>
        <?php endforeach; ?>
        <p class="help-block">Subsites may use allowed themes even if they are not the main site's active theme.</p></div>
      <div class="form-group"><label>Allowed plugins (none ticked = all allowed)</label><br>
        <?php $ap = nexa_multisite_csv_list($edit['allowed_plugins'] ?? ''); foreach ($pluginsList as $pl): ?>
          <label class="checkbox-inline"><input type="checkbox" name="allowed_plugins[]" value="<?= esc_attr($pl) ?>" <?= in_array($pl, $ap, true) ? 'checked' : '' ?>> <?= esc_html($pl) ?></label>
        <?php endforeach; ?></div>
      <div class="form-group"><label>Mandatory plugins (always active on this subsite)</label><br>
        <?php $mp = nexa_multisite_csv_list($edit['mandatory_plugins'] ?? ''); foreach ($pluginsList as $pl): ?>
          <label class="checkbox-inline"><input type="checkbox" name="mandatory_plugins[]" value="<?= esc_attr($pl) ?>" <?= in_array($pl, $mp, true) ? 'checked' : '' ?>> <?= esc_html($pl) ?></label>
        <?php endforeach; ?></div>
      <div class="form-group"><label>Theme/plugin uploads by subsite admins</label>
        <select name="allow_uploads" class="form-control">
          <option value="deny" <?= ($edit['allow_uploads'] ?? 'deny') === 'deny' ? 'selected' : '' ?>>Not allowed</option>
          <option value="approval" <?= ($edit['allow_uploads'] ?? '') === 'approval' ? 'selected' : '' ?>>Allowed, but needs network-admin approval before install</option>
          <option value="allow" <?= ($edit['allow_uploads'] ?? '') === 'allow' ? 'selected' : '' ?>>Allowed freely</option>
        </select></div>
      <?php if (!$edit): ?>
      <div class="form-group"><label>Subsite admin username (optional)</label><input name="admin_username" class="form-control" placeholder="shopadmin"><p class="help-block">Creates (or reuses) a main-site login and pins it to this subsite. Set a password if the user is new:</p><input type="password" name="admin_password" class="form-control" placeholder="Password for a new subsite admin"></div>
      <?php endif; ?>
      <button class="btn btn-success"><?= $edit ? 'Save Subsite' : 'Create Subsite' ?></button>
      <?php if ($edit): ?><a class="btn btn-default" href="network.php">Cancel edit</a><?php endif; ?>
    </form>
  </div></div>
  </div>

  <div class="col-md-7">
  <div class="panel panel-default"><div class="panel-heading"><strong>Subsites</strong> <span class="badge"><?= count($sites) ?></span></div><div class="panel-body">
    <?php if (!$sites): ?><p class="text-muted">No subsites yet. Enable multisite above, create one, then visit its URL.</p><?php endif; ?>
    <?php foreach ($sites as $s): $url = nexa_multisite_site_public_url($s); ?>
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-bottom:10px;">
        <strong><?= esc_html($s['name']) ?></strong> <code><?= esc_html($s['slug']) ?></code>
        <span class="label label-<?= ($s['status'] ?? 'active') === 'active' ? 'success' : 'warning' ?>"><?= esc_html($s['status'] ?? 'active') ?></span>
        <span class="label label-info"><?= esc_html($s['storage'] ?? 'separate') ?> storage</span>
        <?php if (!empty($s['theme'])): ?><span class="label label-primary">must use <?= esc_html($s['theme']) ?></span><?php endif; ?>
        <div><a href="<?= esc_attr($url) ?>" target="_blank"><?= esc_html($url) ?></a>
        <?php if (!empty($s['host'])): ?> · host: <code><?= esc_html($s['host']) ?></code><?php endif; ?></div>
        <div class="text-muted" style="font-size:12px;">Uploads: <?= esc_html($s['allow_uploads'] ?? 'deny') ?><?php if (!empty($s['mandatory_plugins'])): ?> · mandatory: <?= esc_html($s['mandatory_plugins']) ?><?php endif; ?></div>
        <?php $copies = function_exists('nexa_site_plugin_slugs') ? nexa_site_plugin_slugs($s) : []; $themeCopies = function_exists('nexa_site_theme_slugs') ? nexa_site_theme_slugs($s) : []; ?>
        <div style="font-size:12px;margin-top:6px;border-top:1px dashed #e5e7eb;padding-top:6px;">
          <strong>Site package copies</strong> <span class="text-muted">(only packages uploaded from this subsite live here — everything else is shared with all sites)</span>
          <?php if (!$copies && !$themeCopies): ?><div class="text-muted">None — this site uses the shared network plugins and themes.</div><?php endif; ?>
          <?php foreach ($copies as $cp): $cm = di_read_plugin_manifest(nexa_site_plugins_dir($s) . '/' . $cp, $cp); $netDirC = nexa_plugins_dir() . '/' . $cp; $netVer = is_dir($netDirC) ? di_read_plugin_manifest($netDirC, $cp)['version'] : null; ?>
            <div style="margin:4px 0;">
              <span class="label label-info">plugin</span> <code><?= esc_html($cp) ?></code> <span class="label label-primary">site v<?= esc_html($cm['version']) ?></span>
              <?php if ($netVer !== null): ?><span class="text-muted">shared: v<?= esc_html($netVer) ?></span><?php else: ?><span class="text-muted">no shared copy (site-only)</span><?php endif; ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Remove this site\'s own copy? It will fall back to the shared copy (if any).');"><?= di_csrf_field() ?><input type="hidden" name="do" value="site_plugin_remove"><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="plugin" value="<?= esc_attr($cp) ?>"><button class="btn btn-xs btn-danger">Remove site copy</button></form>
            </div>
          <?php endforeach; ?>
          <?php foreach ($themeCopies as $cp): $cm = nexa_read_theme_manifest(nexa_site_themes_dir($s) . '/' . $cp, $cp); $netDirC = nexa_themes_dir() . '/' . $cp; $netVer = is_dir($netDirC) ? nexa_read_theme_manifest($netDirC, $cp)['version'] : null; ?>
            <div style="margin:4px 0;">
              <span class="label label-primary">theme</span> <code><?= esc_html($cp) ?></code> <span class="label label-primary">site<?= $cm['version'] !== '' ? ' v' . esc_html($cm['version']) : '' ?></span>
              <?php if (is_dir($netDirC)): ?><span class="text-muted">shared<?= $netVer !== null && $netVer !== '' ? ': v' . esc_html($netVer) : '' ?></span><?php else: ?><span class="text-muted">no shared copy (site-only)</span><?php endif; ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Remove this site\'s own copy? It will fall back to the shared copy (if any).');"><?= di_csrf_field() ?><input type="hidden" name="do" value="site_theme_remove"><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="theme" value="<?= esc_attr($cp) ?>"><button class="btn btn-xs btn-danger">Remove site copy</button></form>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:6px;">
          <a class="btn btn-xs btn-primary" href="network.php?edit=<?= (int) $s['id'] ?>">Edit</a>
          <a class="btn btn-xs btn-default" href="<?= esc_attr($url) ?>" target="_blank">Visit</a>
          <a class="btn btn-xs btn-info" href="<?= esc_attr(nexa_multisite_site_admin_url($s)) ?>" title="This subsite's own admin area — everything edited there belongs to this site only">Subsite Admin</a>
          <form method="post" style="display:inline;"><?= di_csrf_field() ?><input type="hidden" name="do" value="switch"><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>"><button class="btn btn-xs btn-success">Manage in Admin</button></form>
          <form method="post" style="display:inline;" onsubmit="return confirm('Delete this subsite?');"><?= di_csrf_field() ?><input type="hidden" name="do" value="site_delete"><input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>"><label style="font-weight:normal;"><input type="checkbox" name="delete_data" value="1"> also delete its data</label> <button class="btn btn-xs btn-danger">Delete</button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div></div>

  <div class="panel panel-default"><div class="panel-heading"><strong>Subsite admins</strong></div><div class="panel-body">
    <form method="post" class="form-inline"><?= di_csrf_field() ?><input type="hidden" name="do" value="assign">
      <select name="user_id" class="form-control" required><option value="">Choose user…</option>
        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= esc_html(($u['user'] ?? '') . ' (' . ($u['role'] ?? '') . ')') ?></option><?php endforeach; ?></select>
      <select name="site_id" class="form-control" required><option value="">…to subsite</option>
        <?php foreach ($sites as $s): ?><option value="<?= (int) $s['id'] ?>"><?= esc_html($s['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary">Assign</button></form>
    <ul style="margin-top:10px;">
      <?php foreach ($assignments as $a): $su = null; foreach ($users as $u) if ((int) $u['id'] === (int) $a['user_id']) $su = $u; $ss = null; foreach ($sites as $s) if ((int) $s['id'] === (int) $a['site_id']) $ss = $s; ?>
        <li><?= esc_html($su['user'] ?? ('user #' . $a['user_id'])) ?> → <?= esc_html($ss['name'] ?? ('site #' . $a['site_id'])) ?>
          <form method="post" style="display:inline;"><?= di_csrf_field() ?><input type="hidden" name="do" value="unassign"><input type="hidden" name="assign_id" value="<?= (int) $a['id'] ?>"><button class="btn btn-xs btn-danger">Remove</button></form></li>
      <?php endforeach; ?>
    </ul>
  </div></div>

  <?php if ($history): ?>
  <div class="panel panel-default"><div class="panel-heading"><strong>Recent package decisions</strong></div><div class="panel-body">
    <?php foreach ($history as $r): $lbl = ['installed' => 'success', 'rejected' => 'danger', 'approved-failed' => 'warning'][(string) $r['status']] ?? 'default'; ?>
      <div style="border-bottom:1px solid #f3f4f6;padding:6px 0;font-size:13px;">
        <span class="label label-<?= $lbl ?>"><?= esc_html($r['status']) ?></span>
        <strong><?= esc_html($r['type']) ?></strong> <?= esc_html($r['orig_name']) ?> — subsite <code><?= esc_html($r['site_slug']) ?></code>, uploaded by <?= esc_html($r['requested_by']) ?>
        <?php if (!empty($r['reviewed_by'])): ?><span class="text-muted">· decided by <?= esc_html($r['reviewed_by']) ?> <?= esc_html($r['reviewed_at'] ?? '') ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
  </div></div>
  <?php endif; ?>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
