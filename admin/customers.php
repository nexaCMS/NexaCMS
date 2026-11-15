<?php
/**
 * NexaCMS Customers Directory (admin/customers.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$custFields = ['f_name', 'l_name', 'gender', 'email', 'phone', 'address', 'state', 'date_of_birth', 'created_at', 'updated_at'];
if (!table_exists('customers')) {
    create_table('customers', $custFields);
} else {
    ensure_fields('customers', $custFields);
}

$search = trim((string) ($_GET['search_string'] ?? $_GET['s'] ?? ''));
$customers = get_result('customers', 1, ['*'], ['by' => 'id', 'order' => 'DESC'], -1);

if ($search !== '') {
    $customers = array_values(array_filter($customers, static function ($c) use ($search) {
        $hay = strtolower(($c['f_name'] ?? '') . ' ' . ($c['l_name'] ?? '') . ' ' . ($c['email'] ?? '') . ' ' . ($c['phone'] ?? ''));
        return str_contains($hay, strtolower($search));
    }));
}

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/customers.php';
include_once __DIR__ . '/includes/footer.php';
