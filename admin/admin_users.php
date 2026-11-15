<?php
/**
 * NexaCMS Users Manager (admin/admin_users.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

// Legacy default-account remediation: installs made before v1.23.1 seeded
// a known-credential "admin" / "password" super admin next to the wizard
// account. Detect it (only when a real other super admin exists and it is
// not the account you are signed in with) and offer one-click removal.
$defaultRiskUser = null;
foreach (get_table('users')['data'] as $u) {
    if (strtolower((string) ($u['user'] ?? '')) === 'admin' && (int) ($u['id'] ?? 0) !== (int) ($_SESSION['user_id'] ?? 0)
        && !empty($u['pass']) && password_verify('password', (string) $u['pass'])) {
        foreach (get_table('users')['data'] as $o) {
            if ((int) ($o['id'] ?? 0) !== (int) $u['id'] && ($o['role'] ?? '') === 'super_admin') { $defaultRiskUser = $u; break 2; }
        }
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'delete_default_admin' && di_verify_csrf() && $defaultRiskUser) {
    delete_records('users', (int) $defaultRiskUser['id']);
    $_SESSION['success'] = 'The default "admin" account with the factory password has been deleted.';
    header('Location: admin_users.php');
    exit;
}

$search = trim((string) ($_GET['search_string'] ?? $_GET['s'] ?? ''));
$allUsers = get_result('users', 1, ['*'], ['by' => 'id', 'order' => 'ASC'], -1);

if ($search !== '') {
    $allUsers = array_values(array_filter($allUsers, static function ($u) use ($search) {
        $hay = strtolower(($u['user'] ?? '') . ' ' . di_user_display_name($u) . ' ' . ($u['email'] ?? '') . ' ' . ($u['role'] ?? ''));
        return str_contains($hay, strtolower($search));
    }));
}

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/admin_users.php';
include_once __DIR__ . '/includes/footer.php';
