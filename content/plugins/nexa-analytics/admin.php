<?php
/** Nexa Analytics — Admin UI: guests vs logged-in members + estimated time on site. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-analytics', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-analytics') ? 'active' : '',
    'icon' => 'fa fa-bar-chart fa-fw', 'label' => ' Analytics', 'link' => 'admin.php?plugin_page=nexa-analytics',
], 45);
add_action('nexa_admin', 'nexa_analytics_admin_render');

function nexa_analytics_admin_render(): void {
    if (($_GET['plugin_page'] ?? '') !== 'nexa-analytics') return;
    nexa_analytics_ensure();
    $c = function ($sql, $p = []) { $r = query($sql, $p); return (int) ($r[0]['c'] ?? 0); };
    $total = $c("SELECT COUNT(*) AS c FROM analytics_hits");
    $today = $c("SELECT COUNT(*) AS c FROM analytics_hits WHERE day=?", [date('Y-m-d')]);
    $uniq = $c("SELECT COUNT(DISTINCT visitor) AS c FROM analytics_hits");
    $guestHits = $c("SELECT COUNT(*) AS c FROM analytics_hits WHERE visitor_type=?", ['guest']);
    $memberHits = $c("SELECT COUNT(*) AS c FROM analytics_hits WHERE visitor_type=?", ['member']);

    // Estimated time on site, per visit session (last hit - first hit).
    $sessions = nexa_analytics_sessions();
    $guestSessions = $memberSessions = 0; $guestSecs = $memberSecs = 0; $engaged = 0; $bounces = 0;
    $perMember = [];
    foreach ($sessions as $s) {
        if ($s['hits'] <= 1) $bounces++; else $engaged++;
        if ($s['type'] === 'member') {
            $memberSessions++; $memberSecs += $s['seconds'];
            $name = $s['member'] !== '' ? $s['member'] : 'Member';
            $perMember[$name] ??= ['sessions' => 0, 'hits' => 0, 'seconds' => 0];
            $perMember[$name]['sessions']++; $perMember[$name]['hits'] += $s['hits']; $perMember[$name]['seconds'] += $s['seconds'];
        } else { $guestSessions++; $guestSecs += $s['seconds']; }
    }
    $allSecs = $guestSecs + $memberSecs; $allSessions = max(1, count($sessions));
    $avgAll = (int) round($allSecs / $allSessions);
    $avgGuest = $guestSessions ? (int) round($guestSecs / $guestSessions) : 0;
    $avgMember = $memberSessions ? (int) round($memberSecs / $memberSessions) : 0;
    $bounceRate = count($sessions) ? round(100 * $bounces / count($sessions)) : 0;

    echo '<h2><i class="fa fa-bar-chart"></i> Analytics Dashboard <small>guests vs logged-in users &amp; time on site</small></h2>';
    echo '<div class="row">';
    foreach ([['Total Pageviews', $total, 'primary'], ['Views Today', $today, 'primary'], ['Unique Visitors', $uniq, 'primary'], ['Visit Sessions', count($sessions), 'primary']] as [$l, $v, $cls]) {
        echo '<div class="col-md-3"><div class="panel panel-' . $cls . '"><div class="panel-heading">' . $l . '</div><div class="panel-body"><h2 style="margin:0">' . (int) $v . '</h2></div></div></div>';
    }
    echo '</div><div class="row">';
    foreach ([
        ['Guest Pageviews', $guestHits . ' <small>(' . $guestSessions . ' sessions)</small>', 'default'],
        ['Logged-in Pageviews', $memberHits . ' <small>(' . $memberSessions . ' sessions)</small>', 'success'],
        ['Est. Avg. Time on Site', nexa_analytics_fmt_duration($avgAll), 'info'],
        ['Bounce Rate (1-page visits)', $bounceRate . '%', 'warning'],
    ] as [$l, $v, $cls]) {
        echo '<div class="col-md-3"><div class="panel panel-' . $cls . '"><div class="panel-heading">' . $l . '</div><div class="panel-body"><h2 style="margin:0">' . $v . '</h2></div></div></div>';
    }
    echo '</div>';
    echo '<p class="text-muted">Estimated time on site = time between the first and last pageview of a visit (30-minute session window). Single-page visits count as under a minute — like all analytics, treat it as a solid estimate, not a stopwatch. Guests avg <strong>' . nexa_analytics_fmt_duration($avgGuest) . '</strong> · Logged-in users avg <strong>' . nexa_analytics_fmt_duration($avgMember) . '</strong>.</p>';

    if ($perMember) {
        echo '<h4>Logged-in Users</h4><table class="table table-striped"><thead><tr><th>User</th><th>Sessions</th><th>Pageviews</th><th>Est. Total Time</th><th>Avg / Session</th></tr></thead><tbody>';
        foreach ($perMember as $name => $m) {
            echo '<tr><td><i class="fa fa-user"></i> ' . esc_html($name) . '</td><td>' . $m['sessions'] . '</td><td>' . $m['hits'] . '</td><td>' . nexa_analytics_fmt_duration($m['seconds']) . '</td><td>' . nexa_analytics_fmt_duration($m['sessions'] ? (int) round($m['seconds'] / $m['sessions']) : 0) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h4>Top Pages</h4><table class="table table-striped"><thead><tr><th>Page</th><th>Views</th></tr></thead><tbody>';
    foreach (query("SELECT page, COUNT(*) AS hits FROM analytics_hits GROUP BY page ORDER BY hits DESC LIMIT 15") as $r) echo '<tr><td>' . esc_html($r['page']) . '</td><td>' . (int) $r['hits'] . '</td></tr>';
    echo '</tbody></table><h4>Recent Hits</h4><table class="table table-striped"><thead><tr><th>When</th><th>Visitor</th><th>Page</th><th>IP</th><th>Referer</th></tr></thead><tbody>';
    foreach (query("SELECT * FROM analytics_hits ORDER BY id DESC LIMIT 25") as $r) {
        $who = (($r['visitor_type'] ?? 'guest') === 'member')
            ? '<span class="label label-success">' . esc_html(($r['member'] ?? '') ?: 'Member') . '</span>'
            : '<span class="label label-default">Guest</span>';
        echo '<tr><td>' . esc_html($r['created_at']) . '</td><td>' . $who . '</td><td>' . esc_html($r['page']) . '</td><td>' . esc_html($r['ip']) . '</td><td>' . esc_html(mb_substr((string) $r['referer'], 0, 60)) . '</td></tr>';
    }
    echo '</tbody></table>';
}
