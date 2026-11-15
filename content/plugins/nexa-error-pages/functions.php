<?php
/**
 * Nexa Error Pages — custom error pages for themes without templates,
 * plus an offline / maintenance mode.
 *
 * Precedence (handled by core nexa_render_error_page()):
 *   1. the active theme's own <status>.php / error.php template
 *   2. THIS plugin's admin-created content (the filter below)
 *   3. the legacy built-in fallback
 * Settings are per-site (subsites each get their own error pages).
 */

function nexa_errp_defaults(int $status): array {
    return match ($status) {
        403 => ['title' => '403 — Access Forbidden', 'message' => 'You do not have permission to view this page. If you believe this is a mistake, please contact us.'],
        500 => ['title' => '500 — Something Went Wrong', 'message' => 'Our server ran into a problem. Please try again in a few minutes — if it keeps happening, let us know.'],
        503 => ['title' => 'We Will Be Right Back', 'message' => 'We are performing a little maintenance and will be back online shortly. Thanks for your patience!'],
        default => ['title' => '404 — Page Not Found', 'message' => 'Sorry, we could not find that page. It may have been moved or deleted. Try a search, or use the button below to return home.'],
    };
}

function nexa_errp_key(int $status): string {
    return $status === 503 ? 'offline' : (string) $status;
}

/** Supplies admin-created error content to the core renderer. */
function nexa_errp_custom($custom, int $status) {
    if ($custom !== null) return $custom;
    $k = nexa_errp_key($status);
    $d = nexa_errp_defaults($status);
    $title = trim((string) get_setting('errp_' . $k . '_title', ''));
    $message = trim((string) get_setting('errp_' . $k . '_message', ''));
    $html = trim((string) get_setting('errp_' . $k . '_html', ''));
    if ($title === '' && $message === '' && $html === '') {
        // Nothing customised for this status: still offer designed defaults
        // so themeless themes get a proper page instead of the legacy one.
        $title = $d['title']; $message = $d['message'];
    }
    return [
        'title' => $title !== '' ? $title : $d['title'],
        'message' => $message !== '' ? $message : $d['message'],
        'html' => $html,
        'show_search' => (string) get_setting('errp_' . $k . '_search', '1') === '1',
        'show_recent' => (string) get_setting('errp_' . $k . '_recent', '1') === '1',
        'retry_after' => max(60, (int) get_setting('errp_offline_retry', '3600')),
    ];
}
add_filter('nexa_error_page_custom', 'nexa_errp_custom', 10);

/**
 * Offline / maintenance mode: visitors get the 503 page (theme offline.php
 * or the custom content above); signed-in admins keep full access so they
 * can work on the site. Runs on the frontend 'pre' hook.
 */
function nexa_errp_offline_guard(): void {
    if ((string) get_setting('errp_offline_enabled', '0') !== '1') return;
    if (!empty($GLOBALS['adminarea'])) return;
    $isAdminUser = !empty($_SESSION['user_id'])
        && in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true);
    if ($isAdminUser) return;
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (str_contains($path, '/admin/') || str_ends_with($path, '/admin')) return; // never lock anyone out of logging in
    if (function_exists('nexa_render_error_page') && nexa_render_error_page(503)) exit;
}
