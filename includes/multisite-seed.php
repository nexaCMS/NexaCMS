<?php
/**
 * Subsite seeder (CLI only). Invoked by Admin > Network when a subsite is
 * created or a subsite admin is assigned:
 *   NEXA_MULTISITE_SITE=<slug> NEXA_SEED_NAME="Shop" \
 *   NEXA_SEED_ADMIN_USER=shopadmin NEXA_SEED_ADMIN_HASH='$2y$...' \
 *   NEXA_SEED_THEME=olivia php includes/multisite-seed.php
 * Boots NexaCMS inside the subsite's own storage so settings, starter
 * content, menus and the site admin account are created there — never in
 * the main site's tables.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__) . '/inc.php';

$site = function_exists('nexa_current_site') ? nexa_current_site() : null;
if (!$site) { fwrite(STDERR, "Subsite not resolved (is multisite enabled and the slug correct?)\n"); exit(2); }

$name = trim((string) getenv('NEXA_SEED_NAME')) ?: (string) ($site['name'] ?? 'Subsite');
set_setting('blogname', $name, 'Site Name');
set_setting('blogdescription', $name . ' — powered by NexaCMS', 'Tagline');

$theme = basename((string) getenv('NEXA_SEED_THEME'));
if ($theme !== '' && is_dir(dirname(__DIR__) . '/content/themes/' . $theme)) {
    set_setting('template', $theme, 'Current Theme');
}

$adminUser = trim((string) getenv('NEXA_SEED_ADMIN_USER'));
$adminHash = trim((string) getenv('NEXA_SEED_ADMIN_HASH'));
if ($adminUser !== '' && $adminHash !== '') {
    di_ensure_users_table();
    $existing = di_find_user_by_login($adminUser);
    $row = [
        'user' => $adminUser, 'pass' => $adminHash, 'displayname' => $name . ' Admin', 'display-name' => $name . ' Admin',
        'fullname' => $name . ' Administrator', 'email' => $adminUser . '@localhost', 'status' => '1', 'role' => 'super_admin',
        'register' => date('Y-m-d H:i:s'),
    ];
    if ($existing) { $row['id'] = (int) $existing['id']; update_table('users', $row, 'update'); }
    else update_table('users', $row, 'insert');
}

echo json_encode(['ok' => true, 'site' => $site['slug'], 'name' => $name]) . "\n";
