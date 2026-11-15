<?php
/**
 * NexaCMS Delete Customer (admin/delete_customer.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$delId = $_POST['del_id'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf() && $delId !== '') {
    delete_records('customers', $delId);
    $_SESSION['info'] = 'Customer removed.';
}
header('Location: customers.php');
exit;
