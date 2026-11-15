<?php
/**
 * NexaCMS Edit Customer (admin/edit_customer.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$custId = $_REQUEST['customer_id'] ?? $_REQUEST['id'] ?? '';
$found  = get_result('customers', 1, ['*'], ['search' => ['id']], 1, [$custId], true);
if (empty($found[0])) {
    $_SESSION['failure'] = 'Customer not found.';
    header('Location: customers.php');
    exit;
}

$edit = true;
$customer = $found[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $data = [
        'id'            => $customer['id'],
        'f_name'        => trim((string) ($_POST['f_name'] ?? '')),
        'l_name'        => trim((string) ($_POST['l_name'] ?? '')),
        'gender'        => trim((string) ($_POST['gender'] ?? 'male')),
        'phone'         => trim((string) ($_POST['phone'] ?? '')),
        'email'         => trim((string) ($_POST['email'] ?? '')),
        'address'       => trim((string) ($_POST['address'] ?? '')),
        'date_of_birth' => trim((string) ($_POST['date_of_birth'] ?? '')),
        'updated_at'    => date('Y-m-d H:i:s'),
    ];
    update_table('customers', $data, 'update');
    $_SESSION['success'] = 'Customer updated successfully!';
    header('Location: customers.php');
    exit;
}

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/add_customer.php';
include_once __DIR__ . '/includes/footer.php';
