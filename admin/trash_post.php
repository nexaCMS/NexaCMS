<?php
/**
 * NexaCMS Trash / Restore / Delete Handler (admin/trash_post.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$id     = $_POST['id'] ?? $_GET['id'] ?? '';
$type   = strtolower(trim((string) ($_REQUEST['type'] ?? 'post')));
$action = strtolower(trim((string) ($_POST['action'] ?? 'trash')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf() && $id !== '') {
    if ($action === 'delete') {
        delete_records('posts', $id);
        $_SESSION['success'] = 'Item permanently deleted.';
    } elseif ($action === 'restore') {
        update_table('posts', ['id' => $id, 'post_status' => 'published'], 'update');
        $_SESSION['success'] = 'Item restored from Trash.';
    } else {
        update_table('posts', ['id' => $id, 'post_status' => 'trash'], 'update');
        $_SESSION['info'] = 'Item moved to Trash.';
    }
}

header('Location: posts.php?type=' . urlencode(in_array($type, ['post', 'page'], true) ? $type : 'post'));
exit;
