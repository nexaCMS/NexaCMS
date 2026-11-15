<?php
/** Nexa Analytics — pageviews, guests vs logged-in members, sessions & estimated time on site. */
function nexa_analytics_ensure(): void {
    if (!table_exists('analytics_hits')) {
        create_table('analytics_hits', ['page','post_id','ip','ua','referer','visitor','day','created_at']);
    }
    if (function_exists('ensure_fields')) {
        ensure_fields('analytics_hits', ['visitor_type','member','session_id']);
    }
}
nexa_analytics_ensure();

/** Who is visiting: a guest, or which logged-in user? */
function nexa_analytics_who(): array {
    global $config;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
    $authKey = (string) ($config['auth_key'] ?? '');
    $flag = 'user_logged_in' . $authKey;
    if (!empty($_SESSION[$flag])) {
        $name = (string) ($_SESSION['display-name'] ?? $_SESSION['name'] ?? ('user #' . ($_SESSION['user_id'] ?? '?')));
        return ['type' => 'member', 'member' => $name !== '' ? $name : 'Member'];
    }
    return ['type' => 'guest', 'member' => ''];
}

/** Sliding 30-minute visit session id (cookie), separate from the login session. */
function nexa_analytics_session_id(): string {
    $sid = (string) ($_COOKIE['nexa_sid'] ?? '');
    if ($sid === '' || !preg_match('/^[a-f0-9]{16}$/', $sid)) {
        $sid = bin2hex(random_bytes(8));
    }
    if (!headers_sent()) {
        @setcookie('nexa_sid', $sid, ['expires' => time() + 1800, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    return $sid;
}

function nexa_analytics_track(): void {
    if (!empty($GLOBALS['adminarea']) || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')) return;
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if (preg_match('#\.(css|js|png|jpe?g|gif|svg|ico|woff2?)(\?|$)#i', $uri)) return;
    nexa_analytics_ensure();
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
    $visitor = $_SESSION['nexa_visitor'] ?? ($_SESSION['nexa_visitor'] = bin2hex(random_bytes(8)));
    $who = nexa_analytics_who();
    $sid = nexa_analytics_session_id();
    try {
        query("INSERT INTO analytics_hits (page,post_id,ip,ua,referer,visitor,day,created_at,visitor_type,member,session_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [mb_substr($uri, 0, 250), (string) ($_GET['p'] ?? ($GLOBALS['nexa_pretty_resolved'] ?? '')), $_SERVER['REMOTE_ADDR'] ?? '', mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 250), $visitor, date('Y-m-d'), date('Y-m-d H:i:s'), $who['type'], $who['member'], $sid]);
    } catch (Throwable $e) {
        // Never let analytics break a page.
    }
}

/** Aggregate sessions from raw hits: [session_id => [hits, seconds, type, member, pages]] */
function nexa_analytics_sessions(int $limit = 5000): array {
    nexa_analytics_ensure();
    $rows = query("SELECT session_id, visitor_type, member, created_at FROM analytics_hits ORDER BY id DESC LIMIT " . $limit);
    $sessions = [];
    foreach ($rows as $r) {
        $sid = (string) ($r['session_id'] ?? '');
        if ($sid === '') $sid = 'legacy-' . ($r['visitor'] ?? 'x');
        $ts = strtotime((string) ($r['created_at'] ?? '')) ?: 0;
        if (!isset($sessions[$sid])) $sessions[$sid] = ['hits' => 0, 'first' => $ts, 'last' => $ts, 'type' => (string) ($r['visitor_type'] ?? 'guest'), 'member' => (string) ($r['member'] ?? '')];
        $sessions[$sid]['hits']++;
        if ($ts && $ts < $sessions[$sid]['first']) $sessions[$sid]['first'] = $ts;
        if ($ts && $ts > $sessions[$sid]['last']) $sessions[$sid]['last'] = $ts;
        if (($r['visitor_type'] ?? '') === 'member') { $sessions[$sid]['type'] = 'member'; $sessions[$sid]['member'] = (string) ($r['member'] ?? $sessions[$sid]['member']); }
    }
    foreach ($sessions as &$s) $s['seconds'] = max(0, (int) $s['last'] - (int) $s['first']);
    unset($s);
    return $sessions;
}

function nexa_analytics_fmt_duration(int $sec): string {
    if ($sec <= 0) return '<1 min';
    if ($sec < 3600) return round($sec / 60, 1) . ' min';
    return round($sec / 3600, 2) . ' h';
}

add_shortcode('site_hits', function ($a = []): string { nexa_analytics_ensure(); $r = query("SELECT COUNT(*) AS c FROM analytics_hits"); return (string) ($r[0]['c'] ?? 0); });
add_shortcode('popular_posts', function ($a = []): string {
    nexa_analytics_ensure();
    $limit = max(1, min(20, (int) ($a['limit'] ?? 5)));
    $rows = query("SELECT post_id, COUNT(*) AS hits FROM analytics_hits WHERE post_id != '' GROUP BY post_id ORDER BY hits DESC LIMIT " . $limit);
    if (empty($rows)) return '<p>No visits tracked yet.</p>';
    $h = '<ul class="nexa-popular">';
    foreach ($rows as $r) {
        $p = query("SELECT post_title FROM posts WHERE id=?", [$r['post_id']]);
        if (empty($p)) continue;
        $h .= '<li><a href="' . esc_url(post_url($r['post_id'])) . '">' . esc_html($p[0]['post_title'] ?: ('Post #' . $r['post_id'])) . '</a> <small>(' . (int) $r['hits'] . ' views)</small></li>';
    }
    return $h . '</ul>';
});

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Analytics', 'plugin_slug' => 'nexa-analytics', 'label' => 'Popular posts', 'tag' => 'popular_posts',
        'description' => 'List the most-viewed posts tracked by Analytics.',
        'fields' => [['name' => 'limit', 'label' => 'Posts to show', 'type' => 'number', 'default' => '5', 'min' => '1', 'max' => '20', 'always' => true]],
        'template' => '[popular_posts limit="{limit}"]',
    ]);
    register_editor_shortcode([
        'plugin' => 'Nexa Analytics', 'plugin_slug' => 'nexa-analytics', 'label' => 'Total site hits', 'tag' => 'site_hits',
        'description' => 'Print the total number of tracked page views.', 'shortcode' => '[site_hits]',
    ]);
}
