<?php
/**
 * Nexa Site Health & Resource Monitor — live health checks, resource usage
 * (disk / database / memory / content), and a lightweight snapshot history
 * (at most one snapshot per hour, recorded during admin activity) so trends
 * are visible without cron. Admin-only UI; nothing renders on the frontend.
 */

function nexa_health_ensure(): void {
    if (!table_exists('nexa_health_snapshots')) {
        create_table('nexa_health_snapshots', ['ts', 'site_size', 'disk_free', 'disk_total', 'db_size', 'mem_peak', 'checks_ok', 'checks_warn', 'checks_crit']);
    }
}

function nexa_health_root(): string {
    return rtrim((string) ($GLOBALS['config']['abs'] ?? dirname(__DIR__, 3)), '/');
}

function nexa_health_driver(): string {
    return strtolower((string) ($GLOBALS['config']['database']['type'] ?? 'csv'));
}

/** Recursive directory size in bytes (capped walk, symlinks not followed). */
function nexa_health_dir_size(string $dir, int $cap = 200000): int {
    if (!is_dir($dir)) return 0;
    $total = 0; $seen = 0;
    $stack = [$dir];
    while ($stack) {
        $d = array_pop($stack);
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            if (++$seen > $cap) return $total;
            $p = $d . '/' . $f;
            if (is_dir($p)) { if (!is_link($p)) $stack[] = $p; }
            elseif (is_file($p)) $total += (int) @filesize($p);
        }
    }
    return $total;
}

/** Database footprint in bytes, or -1 when the server manages it (MySQL/PgSQL). */
function nexa_health_db_size(): int {
    $root = nexa_health_root();
    $driver = nexa_health_driver();
    if ($driver === 'sqlite' || $driver === 'sqlite3') {
        $n = 0;
        foreach (glob($root . '/admin/database/*.sqlite*') ?: [] as $f) $n += (int) @filesize($f);
        return $n;
    }
    if (in_array($driver, ['mysql', 'mysqli', 'pgsql', 'postgres', 'postgresql'], true)) return -1;
    return nexa_health_dir_size($root . '/admin/database'); // csv / json / array stores
}

function nexa_health_fmt(int $bytes): string {
    if ($bytes < 0) return '—';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function nexa_health_counts(): array {
    $posts = 0; $users = 0;
    try { foreach (get_table('posts')['data'] as $r) if (($r['post_status'] ?? 'published') === 'published') $posts++; } catch (Throwable $e) {}
    try { $users = count(get_table('users')['data']); } catch (Throwable $e) {}
    $pluginsActive = 0; $pluginsTotal = 0;
    try { foreach (get_table('plugins')['data'] as $r) { $pluginsTotal++; if (($r['pre'] ?? '') === '1' || ($r['head'] ?? '') === '1') $pluginsActive++; } } catch (Throwable $e) {}
    $themes = 0;
    foreach (glob(nexa_health_root() . '/content/themes/*', GLOB_ONLYDIR) ?: [] as $d) $themes++;
    return ['posts' => $posts, 'users' => $users, 'plugins_active' => $pluginsActive, 'plugins_total' => $pluginsTotal, 'themes' => $themes];
}

/**
 * The health checklist. Each: ['status' => ok|warn|crit, 'label', 'detail'].
 * Deliberately cheap and read-only so the page can run on every view.
 */
function nexa_health_checks(): array {
    $root = nexa_health_root();
    $c = [];
    $add = static function (string $status, string $label, string $detail) use (&$c): void { $c[] = ['status' => $status, 'label' => $label, 'detail' => $detail]; };

    // PHP runtime
    $add(version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'crit', 'PHP version', PHP_VERSION . ' (NexaCMS needs 8.1+; developed on 8.4)');
    $missing = [];
    foreach (['zip', 'mbstring'] as $ext) if (!extension_loaded($ext)) $missing[] = $ext;
    $add($missing ? 'crit' : 'ok', 'Required extensions (zip, mbstring)', $missing ? 'Missing: ' . implode(', ', $missing) : 'All present');
    $driver = nexa_health_driver();
    if (($driver === 'sqlite' || $driver === 'sqlite3') && !extension_loaded('pdo_sqlite')) $add('crit', 'PDO SQLite', 'SQLite driver selected but pdo_sqlite is not loaded');
    elseif (extension_loaded('pdo_sqlite')) $add('ok', 'PDO SQLite', 'Available');
    foreach (['curl' => 'outbound HTTP (updates, APIs)', 'openssl' => 'HTTPS & mail encryption', 'gd' => 'image processing'] as $ext => $why) {
        if (!extension_loaded($ext)) $add('warn', 'Extension: ' . $ext, 'Not loaded — used for ' . $why);
    }

    // Disk
    $free = @disk_free_space($root); $total = @disk_total_space($root);
    if ($free !== false && $total) {
        $pct = $free / $total * 100;
        $add($pct < 5 ? 'crit' : ($pct < 15 ? 'warn' : 'ok'), 'Free disk space', nexa_health_fmt((int) $free) . ' free of ' . nexa_health_fmt((int) $total) . ' (' . round($pct, 1) . '%)');
    }

    // Backups
    $backups = function_exists('nexa_backup_files') ? nexa_backup_files() : [];
    if (!$backups) {
        $add('warn', 'Backups', 'No backup found in content/backup/ — create one in Admin → Site Backups');
    } else {
        $ageDays = (time() - (int) $backups[0]['mtime']) / 86400;
        $size = 0; foreach ($backups as $b) $size += (int) $b['size'];
        $add($ageDays > 30 ? 'warn' : 'ok', 'Latest backup', basename($backups[0]['name']) . ' — ' . ($ageDays < 1 ? 'today' : round($ageDays, 1) . ' days old') . ', ' . count($backups) . ' backup(s), ' . nexa_health_fmt($size) . ' total');
        $policy = function_exists('nexa_backup_retention') ? nexa_backup_retention() : ['mode' => 'count', 'keep' => 5, 'days' => 30];
        $add($policy['mode'] === 'off' && count($backups) > 20 ? 'warn' : 'ok', 'Backup retention',
            $policy['mode'] === 'off' ? 'Off (manual delete only)' : ($policy['mode'] === 'count' ? 'Keeping newest ' . (int) $policy['keep'] : 'Keeping newer than ' . (int) $policy['days'] . ' days'));
    }

    // Protected data
    $ht = @file_get_contents($root . '/.htaccess') ?: '';
    $add(str_contains($ht, 'admin/database') ? 'ok' : 'warn', 'Database web protection', str_contains($ht, 'admin/database') ? 'Root .htaccess blocks admin/database/' : 'No .htaccess rule for admin/database/ — on Apache add one; on nginx add a location deny (the PHP built-in server is covered by router.php)');

    // Config permissions
    $cfgFile = $root . '/config.php';
    if (is_file($cfgFile)) {
        $perms = fileperms($cfgFile) & 0777;
        $add(($perms & 0002) ? 'crit' : 'ok', 'config.php permissions', sprintf('%o', $perms) . (($perms & 0002) ? ' — world-writable! chmod 640 recommended' : ''));
    }

    // HTTPS
    $home = function_exists('home_url') ? (string) home_url('') : '';
    if (str_starts_with($home, 'https://')) $add('ok', 'HTTPS', 'Site URL uses HTTPS');
    elseif (!str_contains($home, '127.0.0.1') && !str_contains($home, 'localhost')) $add('warn', 'HTTPS', 'Site URL is plain HTTP (' . esc_html($home) . ') — put the site behind TLS');

    // Debug exposure
    $add(ini_get('display_errors') ? 'warn' : 'ok', 'PHP display_errors', ini_get('display_errors') ? 'On — errors may leak details to visitors; turn off in production' : 'Off');

    // Memory limit
    $limit = (string) ini_get('memory_limit');
    $bytes = (int) $limit;
    if (str_ends_with(strtolower($limit), 'g')) $bytes *= 1073741824; elseif (str_ends_with(strtolower($limit), 'm')) $bytes *= 1048576; elseif (str_ends_with(strtolower($limit), 'k')) $bytes *= 1024;
    $add(($bytes > 0 && $bytes < 128 * 1048576) ? 'warn' : 'ok', 'PHP memory limit', $limit === '-1' ? 'Unlimited' : $limit);

    // Cache + staging leftovers
    $cacheSize = nexa_health_dir_size($root . '/content/cache');
    $add($cacheSize > 50 * 1048576 ? 'warn' : 'ok', 'Page cache size', nexa_health_fmt($cacheSize) . ' in content/cache/');
    $staged = glob($root . '/content/temp_uploads/*') ?: [];
    $add($staged ? 'warn' : 'ok', 'Staged uploads', $staged ? count($staged) . ' leftover file(s) in content/temp_uploads/ (abandoned install/update staging)' : 'Clean');

    // Version
    $add('ok', 'NexaCMS version', (defined('NEXACMS_VERSION') ? NEXACMS_VERSION : '?') . ' — check Admin → System Update for newer releases');

    return $c;
}

function nexa_health_snapshot(): void {
    nexa_health_ensure();
    $root = nexa_health_root();
    $checks = nexa_health_checks();
    $ok = $warn = $crit = 0;
    foreach ($checks as $ch) { if ($ch['status'] === 'ok') $ok++; elseif ($ch['status'] === 'warn') $warn++; else $crit++; }
    update_table('nexa_health_snapshots', [
        'ts' => (string) time(),
        'site_size' => (string) nexa_health_dir_size($root),
        'disk_free' => (string) (int) (@disk_free_space($root) ?: 0),
        'disk_total' => (string) (int) (@disk_total_space($root) ?: 0),
        'db_size' => (string) nexa_health_db_size(),
        'mem_peak' => (string) memory_get_peak_usage(true),
        'checks_ok' => (string) $ok, 'checks_warn' => (string) $warn, 'checks_crit' => (string) $crit,
    ], 'insert');
    // Keep the newest 200 snapshots.
    try {
        $rows = get_table('nexa_health_snapshots')['data'];
        if (count($rows) > 200) {
            usort($rows, static fn($a, $b) => (int) ($a['ts'] ?? 0) <=> (int) ($b['ts'] ?? 0));
            foreach (array_slice($rows, 0, count($rows) - 200) as $r) delete_records('nexa_health_snapshots', (int) $r['id']);
        }
    } catch (Throwable $e) {}
    set_setting('nexahealth_last_snapshot', (string) time());
}

/** Throttled recorder: at most one snapshot per hour, only in the admin. */
function nexa_health_maybe_snapshot(): void {
    if (empty($GLOBALS['adminarea'])) return;
    $last = (int) get_setting('nexahealth_last_snapshot', '0');
    if (time() - $last >= 3600) nexa_health_snapshot();
}

function nexa_health_history(int $limit = 30): array {
    nexa_health_ensure();
    try { $rows = get_table('nexa_health_snapshots')['data']; } catch (Throwable $e) { return []; }
    usort($rows, static fn($a, $b) => (int) ($b['ts'] ?? 0) <=> (int) ($a['ts'] ?? 0));
    return array_slice($rows, 0, $limit);
}
