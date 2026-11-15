<?php
/**
 * NexaCMS Posts & Pages Manager (admin/posts.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$type   = strtolower(trim((string) ($_GET['type'] ?? 'post')));
if (!in_array($type, ['post', 'page'], true)) $type = 'post';
$status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$search = trim((string) ($_GET['s'] ?? ''));

$allRows = get_result('posts', 1, ['*'], ['by' => 'id', 'order' => 'DESC'], -1);
$typedRows = array_values(array_filter($allRows, static fn($r) => ($r['post_type'] ?? 'post') === $type));

$counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'trash' => 0];
foreach ($typedRows as $r) {
    $st = strtolower((string) ($r['post_status'] ?? 'draft'));
    if ($st === 'trash') {
        $counts['trash']++;
    } else {
        $counts['all']++;
        if (isset($counts[$st])) $counts[$st]++;
    }
}

$filtered = array_values(array_filter($typedRows, static function ($r) use ($status, $search) {
    $st = strtolower((string) ($r['post_status'] ?? 'draft'));
    if ($status === 'all' && $st === 'trash') return false;
    if ($status !== 'all' && $st !== $status) return false;
    if ($search !== '') {
        $hay = strtolower(($r['post_title'] ?? '') . ' ' . ($r['post_content'] ?? '') . ' ' . ($r['post_author'] ?? ''));
        if (!str_contains($hay, strtolower($search))) return false;
    }
    return true;
}));

$perPage    = 15;
$currPage   = max(1, (int) ($_GET['pg'] ?? 1));
$totalPages = max(1, (int) ceil(count($filtered) / $perPage));
$rows       = array_slice($filtered, ($currPage - 1) * $perPage, $perPage);

$singular = ($type === 'page') ? 'Page' : 'Post';
$plural   = ($type === 'page') ? 'Pages' : 'Posts';

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/posts.php';
include_once __DIR__ . '/includes/footer.php';
