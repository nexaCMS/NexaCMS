<?php
/**
 * NexaCMS Add Customer (admin/add_customer.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$edit = false;
$customer = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $data = [
        'f_name'        => trim((string) ($_POST['f_name'] ?? '')),
        'l_name'        => trim((string) ($_POST['l_name'] ?? '')),
        'gender'        => trim((string) ($_POST['gender'] ?? 'male')),
        'phone'         => trim((string) ($_POST['phone'] ?? '')),
        'email'         => trim((string) ($_POST['email'] ?? '')),
        'address'       => trim((string) ($_POST['address'] ?? '')),
        'date_of_birth' => trim((string) ($_POST['date_of_birth'] ?? '')),
        'created_at'    => date('Y-m-d H:i:s'),
    ];
    update_table('customers', $data, 'insert');
    $_SESSION['success'] = 'Customer added successfully!';
    header('Location: customers.php');
    exit;
}

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/add_customer.php';
include_once __DIR__ . '/includes/footer.php';
