<?php
/** NexaCMS Backup Download (admin/backup-download.php?file=...) — admin-only, streams zips from content/backup/. */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$name = basename((string) ($_GET['file'] ?? ''));
$root = dirname(__DIR__);
$candidates = [
    $root . '/content/backup/' . $name,
    $root . '/content/backup/applied-updates/' . $name,
];
$path = null;
foreach ($candidates as $c) { if ($name !== '' && is_file($c)) { $path = $c; break; } }
if ($path === null || !preg_match('/\.(zip|json|txt)$/i', $name)) {
    http_response_code(404);
    echo 'Backup file not found.';
    exit;
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
