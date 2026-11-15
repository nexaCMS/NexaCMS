<?php
/**
 * Router script for PHP Built-in Web Server:
 *   php -S 0.0.0.0:8080 router.php
 */

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$cleanPath = rawurldecode($uriPath);

// Block direct HTTP access to database stores, backup zips, cache files, and config files
if (preg_match('#^/(admin/database|content/backup|content/cache|content/sites|content/uploads/nexa-downloads|config\.php|config_\.php)(/|$)#i', $cleanPath)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '403 Forbidden';
    return true;
}

// Serve sitemap.xml cleanly via index.php?sitemap=xml
if ($cleanPath === '/sitemap.xml') {
    $_GET['sitemap'] = 'xml';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

// Per-subsite admin areas: /<slug>/admin/... and /sites/<slug>/admin/...
// are served by the real /admin, keeping the subsite URL in the browser.
// The multisite resolver (inc.php) validates the slug, pins the site
// context and enforces who may enter; unknown slugs get its 404.
if (preg_match('#^/(?:sites/[a-z0-9][a-z0-9\-]*|[a-z0-9][a-z0-9\-]*)/admin(?:/(.*))?$#i', $cleanPath, $sam)) {
    $adminRel = (string) ($sam[1] ?? '');
    if ($adminRel === '' || str_ends_with($adminRel, '/')) $adminRel .= 'index.php';
    $adminTarget = __DIR__ . '/admin/' . $adminRel;
    if (is_dir($adminTarget)) $adminTarget = rtrim($adminTarget, '/') . '/index.php';
    if (is_file($adminTarget)) {
        if (str_ends_with(strtolower($adminTarget), '.php')) {
            $_SERVER['SCRIPT_NAME'] = '/admin/' . $adminRel;
            $_SERVER['SCRIPT_FILENAME'] = $adminTarget;
            require $adminTarget;
            return true;
        }
        $mime = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject', 'map' => 'application/json', 'json' => 'application/json', 'html' => 'text/html', 'txt' => 'text/plain'];
        $ext = strtolower(pathinfo($adminTarget, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($adminTarget));
        readfile($adminTarget);
        return true;
    }
    http_response_code(404);
    echo '404 Not Found';
    return true;
}

$fullPath = __DIR__ . $cleanPath;

if ($cleanPath !== '/' && is_file($fullPath)) {
    return false;
}

if (is_dir($fullPath)) {
    $dirIndex = rtrim($fullPath, '/') . '/index.php';
    if (is_file($dirIndex)) {
        $_SERVER['SCRIPT_NAME'] = rtrim($cleanPath, '/') . '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $dirIndex;
        require $dirIndex;
        return true;
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
return true;
