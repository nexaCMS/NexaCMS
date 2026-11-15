<?php
/**
 * NexaCMS Delete User (admin/delete_user.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$delId = $_POST['del_id'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf() && $delId !== '' && (string) $delId !== '1') {
    delete_records('users', $delId);
    $_SESSION['info'] = 'User deleted.';
}
header('Location: admin_users.php');
exit;
