<?php
/**
 * Nexa Redirects — site-aware 301/302 redirects + 404 log.
 *
 * Every rule (and every 404 log row) belongs to ONE site: site_id 0 is the
 * main site, any other value is a subsite's network registry id. A rule
 * created on the main site therefore never fires on a subsite (e.g. a main
 * "/" -> "/portfolio" redirect must not reroute subsite homepages), and a
 * subsite's rules never leak back onto the main site — even when sites share
 * database tables. Local targets ("/portfolio") are resolved through
 * home_url() at apply time, so on a subsite they stay inside that subsite.
 */

function nexa_redirects_site_id(): int {
    if (function_exists('nexa_current_site')) {
        $site = nexa_current_site();
        if ($site) return (int) ($site['id'] ?? 0);
    }
    return 0; // main site
}

function nexa_redirects_ensure(): void {
    if (!table_exists('redirects')) create_table('redirects', ['source', 'target', 'code', 'hits', 'created_at', 'site_id']);
    else ensure_fields('redirects', ['site_id']);
    if (!table_exists('log_404')) create_table('log_404', ['path', 'referer', 'hits', 'last_seen', 'site_id']);
    else ensure_fields('log_404', ['site_id']);
    // Rules/log rows written before per-site scoping belonged to whichever
    // site created them; the historical default is the main site (0).
    foreach (get_table('redirects')['data'] as $r) {
        if (!isset($r['site_id']) || $r['site_id'] === '') update_table('redirects', ['id' => $r['id'], 'site_id' => '0'], 'update');
    }
}
nexa_redirects_ensure();

function nexa_redirects_apply(): void {
    if (!empty($GLOBALS['adminarea'])) return;
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $siteId = nexa_redirects_site_id();
    $r = query("SELECT * FROM redirects WHERE source=? AND site_id=?", [$path, (string) $siteId]);
    if (empty($r)) {
        // Older rows may store the id as an int-like string without padding;
        // also try rows whose site_id matches loosely via full scan fallback.
        foreach (get_table('redirects')['data'] as $row) {
            if ((string) ($row['source'] ?? '') === $path && (int) ($row['site_id'] ?? 0) === $siteId) { $r = [$row]; break; }
        }
    }
    if (!empty($r)) {
        query("UPDATE redirects SET hits=hits+1 WHERE id=?", [(int) $r[0]['id']]);
        header('Location: ' . nexa_redirects_target_with_params(nexa_redirects_resolve_target((string) $r[0]['target'])), true, in_array((int) $r[0]['code'], [301, 302, 307], true) ? (int) $r[0]['code'] : 301);
        exit;
    }
    if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') && get_page_id() === false && $path !== '/' && !preg_match('#\.(css|js|png|jpe?g|gif|svg|ico|php)(\?|$)#i', $path)) {
        $ex = query("SELECT id,hits FROM log_404 WHERE path=? AND site_id=?", [$path, (string) $siteId]);
        if (empty($ex)) query("INSERT INTO log_404 (path,referer,hits,last_seen,site_id) VALUES (?,?,?,?,?)", [$path, (string) ($_SERVER['HTTP_REFERER'] ?? ''), 1, date('Y-m-d H:i:s'), (string) $siteId]);
        else query("UPDATE log_404 SET hits=hits+1, last_seen=? WHERE id=?", [date('Y-m-d H:i:s'), (int) $ex[0]['id']]);
    }
}

/**
 * Resolve a stored target for the site currently being visited: absolute
 * external URLs pass through untouched; local paths ("/portfolio",
 * "?p=2") are made site-local through home_url(), so a subsite rule can
 * never bounce its visitors onto the main site (or another subsite).
 */
function nexa_redirects_resolve_target(string $target): string {
    $target = trim($target);
    if ($target === '') return $target;
    if (preg_match('#^https?://#i', $target) || str_starts_with($target, '//')) return $target;
    if (!function_exists('nexa_current_site') || !nexa_current_site()) return $target;
    if (!str_starts_with($target, '/') && !str_starts_with($target, '?')) return $target;
    return home_url($target);
}

/**
 * Carry selected incoming GET parameters across the redirect (e.g. theme
 * previews must survive a redirect). The preserved list lives in the
 * redirects_preserve_params setting (comma-separated, "*" = all); a param
 * already present in the target URL is never overwritten.
 */
function nexa_redirects_target_with_params(string $target): string {
    $preserve = array_values(array_filter(array_map('trim', explode(',', (string) get_setting('redirects_preserve_params', 'theme')))));
    if (!$preserve || empty($_GET)) return $target;
    $keepAll = in_array('*', $preserve, true);
    $parts = parse_url($target);
    if ($parts === false) return $target;
    $q = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $q);
    $changed = false;
    foreach ($_GET as $k => $v) {
        if (!is_scalar($v)) continue;
        if (($keepAll || in_array((string) $k, $preserve, true)) && !array_key_exists($k, $q)) {
            $q[$k] = $v;
            $changed = true;
        }
    }
    if (!$changed) return $target;
    $u = '';
    if (!empty($parts['scheme'])) $u .= $parts['scheme'] . '://';
    if (!empty($parts['host'])) {
        $u .= $parts['host'];
        if (!empty($parts['port'])) $u .= ':' . $parts['port'];
    }
    $u .= (string) ($parts['path'] ?? '');
    $qs = http_build_query($q);
    if ($qs !== '') $u .= '?' . $qs;
    if (!empty($parts['fragment'])) $u .= '#' . $parts['fragment'];
    return $u;
}
