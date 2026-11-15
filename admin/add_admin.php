<?php
/**
 * NexaCMS Add User (admin/add_admin.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$edit = false;
$admin_account = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $uname = trim((string) ($_POST['user_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['passwd'] ?? '');
    $dname = trim((string) ($_POST['displayname'] ?? $uname));
    $role  = trim((string) ($_POST['admin_type'] ?? 'admin'));

    if ($uname === '' || $pass === '') {
        $_SESSION['failure'] = 'Username and password are required.';
    } elseif (di_find_user_by_login($uname) !== null) {
        $_SESSION['failure'] = "Username '{$uname}' already exists.";
    } else {
        $created = create_user([
            'user'         => $uname,
            'pass'         => $pass,
            'email'        => $email,
            'displayname'  => $dname,
            'display-name' => $dname,
            'role'         => $role,
            'phone'        => trim((string) ($_POST['phone'] ?? '')),
        ]);
        if (!$created) {
            $pwErrors = function_exists('nexa_password_last_errors') ? nexa_password_last_errors() : [];
            $_SESSION['failure'] = $pwErrors ? implode('<br>', array_map('esc_html', $pwErrors)) : 'Could not create that user.';
        } else {
            $_SESSION['success'] = "User '{$uname}' created successfully!";
            header('Location: admin_users.php');
            exit;
        }
    }
}

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/add_admin.php';
include_once __DIR__ . '/includes/footer.php';
