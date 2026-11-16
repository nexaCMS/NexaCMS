<?php
/** Nexa Site Health & Resource Monitor — Admin UI. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name' => 'nexa-health', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-health') ? 'active' : '', 'icon' => 'fa fa-heartbeat fa-fw', 'label' => ' Site Health', 'link' => 'admin.php?plugin_page=nexa-health'], 46);
add_action('nexa_admin', 'nexa_health_admin_render'); add_action('di_admin', 'nexa_health_admin_render'); add_action('up_admin', 'nexa_health_admin_render');
if (function_exists('nexa_health_maybe_snapshot')) nexa_health_maybe_snapshot(); // hourly snapshot during any admin activity

function nexa_health_badge(string $status): string {
    return $status === 'ok' ? '<span class="label label-success">OK</span>' : ($status === 'warn' ? '<span class="label label-warning">Warning</span>' : '<span class="label label-danger">Critical</span>');
}

function nexa_health_sparkline(array $rows, string $key, string $color): string {
    // $rows newest-first; draw oldest → newest. Returns inline SVG (or a note).
    $vals = [];
    foreach (array_reverse($rows) as $r) $vals[] = (float) ($r[$key] ?? 0);
    if (count($vals) < 2) return '<span class="text-muted">Not enough history yet</span>';
    $w = 260; $h = 48; $min = min($vals); $max = max($vals); $span = max(1.0, $max - $min);
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i / (count($vals) - 1) * ($w - 4) + 2, 1) . ',' . round($h - 4 - ($v - $min) / $span * ($h - 8), 1);
    return '<svg width="' . $w . '" height="' . $h . '" style="background:#f7f7f7;border:1px solid #ddd;border-radius:4px"><polyline fill="none" stroke="' . $color . '" stroke-width="2" points="' . implode(' ', $pts) . '"/></svg>';
}

function nexa_health_admin_render(): void {
    static $done = false; if ($done) return; if (($_GET['plugin_page'] ?? '') !== 'nexa-health') return; $done = true;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        if (($_POST['do'] ?? '') === 'snapshot') { nexa_health_snapshot(); echo '<div class="alert alert-success">Snapshot recorded.</div>'; }
        if (($_POST['do'] ?? '') === 'clear') { nexa_health_ensure(); try { foreach (get_table('nexa_health_snapshots')['data'] as $r) delete_records('nexa_health_snapshots', (int) $r['id']); } catch (Throwable $e) {} echo '<div class="alert alert-success">History cleared.</div>'; }
    }
    $root = nexa_health_root();
    $checks = nexa_health_checks();
    $crit = count(array_filter($checks, static fn($x) => $x['status'] === 'crit'));
    $warn = count(array_filter($checks, static fn($x) => $x['status'] === 'warn'));
    $overall = $crit ? ['danger', 'Critical issues need attention'] : ($warn ? ['warning', 'Healthy, with warnings'] : ['success', 'All checks passed']);
    echo '<h2><i class="fa fa-heartbeat"></i> Site Health &amp; Resource Monitor</h2>';
    echo '<div class="alert alert-' . $overall[0] . '"><strong>' . $overall[1] . '</strong> — ' . (count($checks) - $crit - $warn) . ' ok, ' . $warn . ' warning(s), ' . $crit . ' critical. NexaCMS ' . esc_html(defined('NEXACMS_VERSION') ? NEXACMS_VERSION : '?') . ' · PHP ' . esc_html(PHP_VERSION) . ' · Driver: ' . esc_html(nexa_health_driver()) . '</div>';

    echo '<div class="row"><div class="col-lg-7"><div class="panel panel-default"><div class="panel-heading"><i class="fa fa-stethoscope"></i> Health Checks</div><table class="table table-striped" style="margin:0"><tbody>';
    foreach ($checks as $ch) echo '<tr><td style="width:90px">' . nexa_health_badge($ch['status']) . '</td><td><strong>' . esc_html($ch['label']) . '</strong><br><span class="text-muted">' . esc_html($ch['detail']) . '</span></td></tr>';
    echo '</tbody></table></div></div>';

    // Resources
    $siteSize = nexa_health_dir_size($root);
    $parts = [
        'Uploads (content/uploads)' => nexa_health_dir_size($root . '/content/uploads'),
        'Backups (content/backup)' => nexa_health_dir_size($root . '/content/backup'),
        'Page cache (content/cache)' => nexa_health_dir_size($root . '/content/cache'),
        'Database (admin/database)' => nexa_health_dir_size($root . '/admin/database'),
        'Plugins (content/plugins)' => nexa_health_dir_size($root . '/content/plugins'),
        'Themes (content/themes)' => nexa_health_dir_size($root . '/content/themes'),
    ];
    $dbSize = nexa_health_db_size();
    $counts = nexa_health_counts();
    $free = (int) (@disk_free_space($root) ?: 0); $total = (int) (@disk_total_space($root) ?: 0);
    $usedPct = $total ? round(($total - $free) / $total * 100, 1) : 0;
    echo '<div class="col-lg-5"><div class="panel panel-default"><div class="panel-heading"><i class="fa fa-pie-chart"></i> Resources</div><div class="panel-body">';
    echo '<p><strong>Site size:</strong> ' . nexa_health_fmt($siteSize) . ' &nbsp; <strong>Database:</strong> ' . ($dbSize < 0 ? 'server-managed (' . esc_html(nexa_health_driver()) . ')' : nexa_health_fmt($dbSize)) . '<br>';
    echo '<strong>Content:</strong> ' . (int) $counts['posts'] . ' published posts · ' . (int) $counts['users'] . ' users · ' . (int) $counts['plugins_active'] . '/' . (int) $counts['plugins_total'] . ' plugins active · ' . (int) $counts['themes'] . ' themes · <strong>Active theme:</strong> ' . esc_html(function_exists('nexa_active_theme_slug') ? nexa_active_theme_slug() : '?') . '<br>';
    echo '<strong>Memory:</strong> now ' . nexa_health_fmt(memory_get_usage(true)) . ', peak ' . nexa_health_fmt(memory_get_peak_usage(true)) . ' (limit ' . esc_html((string) ini_get('memory_limit')) . ')</p>';
    foreach ($parts as $label => $size) {
        $pct = $siteSize ? min(100, round($size / $siteSize * 100, 1)) : 0;
        echo '<div style="margin-bottom:6px"><span class="text-muted">' . esc_html($label) . '</span> <strong>' . nexa_health_fmt($size) . '</strong><div class="progress" style="height:8px;margin:2px 0 0"><div class="progress-bar" style="width:' . $pct . '%"></div></div></div>';
    }
    echo '<div style="margin-top:10px"><span class="text-muted">Disk</span> <strong>' . nexa_health_fmt($total - $free) . ' used / ' . nexa_health_fmt($total) . '</strong><div class="progress" style="height:10px;margin:2px 0 0"><div class="progress-bar ' . ($usedPct > 90 ? 'progress-bar-danger' : ($usedPct > 75 ? 'progress-bar-warning' : 'progress-bar-success')) . '" style="width:' . $usedPct . '%"></div></div><span class="text-muted">' . $usedPct . '% used · ' . nexa_health_fmt($free) . ' free</span></div>';
    echo '</div></div>';

    // Environment
    $exts = ['zip', 'mbstring', 'pdo_sqlite', 'curl', 'openssl', 'gd', 'intl', 'json'];
    echo '<div class="panel panel-default"><div class="panel-heading"><i class="fa fa-server"></i> Environment</div><table class="table" style="margin:0"><tbody>';
    $rows = [
        'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')', 'Server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'PHP built-in / CLI'),
        'OS' => php_uname('s') . ' ' . php_uname('r'), 'Max execution' => (string) ini_get('max_execution_time') . 's',
        'Upload limit' => (string) ini_get('upload_max_filesize') . ' / POST ' . (string) ini_get('post_max_size'),
        'Database driver' => nexa_health_driver(), 'Site root' => $root,
    ];
    foreach ($rows as $k => $v) echo '<tr><td style="width:140px"><strong>' . esc_html($k) . '</strong></td><td>' . esc_html($v) . '</td></tr>';
    echo '<tr><td><strong>Extensions</strong></td><td>';
    foreach ($exts as $e) echo '<span class="label label-' . (extension_loaded($e) ? 'success' : 'default') . '" style="margin-right:4px">' . esc_html($e) . '</span>';
    echo '</td></tr></tbody></table></div></div></div>';

    // History
    $history = nexa_health_history(30);
    echo '<div class="panel panel-default"><div class="panel-heading"><i class="fa fa-line-chart"></i> History (snapshots at most hourly, during admin activity) ';
    echo '<form method="post" style="display:inline;margin-left:10px">' . nexa_csrf_field() . '<button name="do" value="snapshot" class="btn btn-xs btn-primary">Take Snapshot Now</button></form>';
    if ($history) echo '<form method="post" style="display:inline;margin-left:6px" onsubmit="return confirm(\'Clear snapshot history?\')">' . nexa_csrf_field() . '<button name="do" value="clear" class="btn btn-xs btn-danger">Clear History</button></form>';
    echo '</div><div class="panel-body">';
    if (!$history) {
        echo '<p class="text-muted">No snapshots yet. One is recorded at most once per hour while you use the admin; use “Take Snapshot Now” for the first one.</p>';
    } else {
        echo '<div class="row"><div class="col-md-6"><strong>Site size trend</strong><br>' . nexa_health_sparkline($history, 'site_size', '#337ab7') . '</div>';
        echo '<div class="col-md-6"><strong>Checks trend (warnings+critical)</strong><br>' . nexa_health_sparkline(array_map(static fn($r) => ['bad' => (int) ($r['checks_warn'] ?? 0) + (int) ($r['checks_crit'] ?? 0)], $history), 'bad', '#d9534f') . '</div></div>';
        echo '<table class="table table-striped"><thead><tr><th>When</th><th>Site</th><th>Database</th><th>Disk free</th><th>Mem peak</th><th>Checks</th></tr></thead><tbody>';
        foreach ($history as $r) {
            $pctFree = ((int) ($r['disk_total'] ?? 0)) ? round((int) $r['disk_free'] / (int) $r['disk_total'] * 100, 1) : 0;
            echo '<tr><td>' . esc_html(date('Y-m-d H:i', (int) $r['ts'])) . '</td><td>' . nexa_health_fmt((int) $r['site_size']) . '</td><td>' . ((int) $r['db_size'] < 0 ? '—' : nexa_health_fmt((int) $r['db_size'])) . '</td><td>' . nexa_health_fmt((int) $r['disk_free']) . ' (' . $pctFree . '%)</td><td>' . nexa_health_fmt((int) $r['mem_peak']) . '</td><td>' . (int) $r['checks_ok'] . ' ok · ' . (int) $r['checks_warn'] . ' warn · ' . (int) $r['checks_crit'] . ' crit</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<p class="text-muted" style="margin:0">Read-only live checks — nothing here changes your site. Companion pages: <a href="backups.php">Site Backups</a> (retention &amp; cleanup), <a href="update.php">System Update</a>, <a href="admin.php?plugin_page=nexa-security">Security</a>.</p>';
    echo '</div></div>';
}
