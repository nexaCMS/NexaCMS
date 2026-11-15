<?php
/**
 * NexaCMS Frontend Entrypoint (index.php)
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

require_once __DIR__ . '/inc.php';

$theme_dir = get_template_directory();
$theme_dir_url = get_template_directory_uri();

load_active_plugins();
do_action('up_pre');
do_action('pre');

include_once __DIR__ . '/includes/que.php';

$page = get_page_id();

// Posts page (Settings > Reading > Posts Page): visiting that page must show
// the latest-posts listing, not the page's own (usually empty) content.
// The permalink shim marked this request as a single page via $_GET['p'];
// undo that so the loop, is_home() and themes switch to listing mode.
$GLOBALS['nexa_posts_page'] = false;
if (!is_search() && isset($_GET['p'])
    && (string) get_setting('page_for_posts', '') !== ''
    && (string) $page === (string) get_setting('page_for_posts', '')) {
    $GLOBALS['nexa_posts_page'] = $page;
    unset($_GET['p']);
}

if ($page === false && !is_search()) {
    http_response_code(404); // unknown slug/path: real 404 status (themed 404 page still renders)
}

// Custom error pages: ?nexa_error=403|500|503 (used by the bundled
// .htaccess ErrorDocument rules) and themed/plugin 404 pages. When a theme
// template or the Nexa Error Pages plugin renders, it ends the request;
// otherwise the legacy pseudo-post 404 below still applies.
if (!empty($_GET['nexa_error']) && in_array((int) $_GET['nexa_error'], [403, 500, 503], true)) {
    if (nexa_render_error_page((int) $_GET['nexa_error'])) exit;
}
if ($page === false && !is_search()) {
    if (nexa_render_error_page(404)) exit;
}

// Canonical redirect: with pretty permalinks on, ?p=123 -> /post-slug/ (301).
// POST requests (e.g. comment submissions) and search are never redirected.
if (nexa_pretty_permalinks()
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && isset($_GET['p']) && is_numeric($_GET['p'])
    && !is_search()
    && empty($GLOBALS['nexa_pretty_resolved'])
    && $page !== false && $page !== '' && $page !== null
) {
    $target = post_url($page);
    $extra = $_GET; unset($extra['p']);
    if ($extra) $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($extra);
    header('Location: ' . $target, true, 301);
    exit;
}
do_action('up_loaded');

load_theme();
