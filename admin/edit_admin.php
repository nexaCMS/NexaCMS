<?php
/**
 * NexaCMS Edit User (admin/edit_admin.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$userId = $_REQUEST['admin_user_id'] ?? $_REQUEST['id'] ?? '';
$found  = get_result('users', 1, ['*'], ['search' => ['id']], 1, [$userId], true);
if (empty($found[0])) {
    $_SESSION['failure'] = 'User not found.';
    header('Location: admin_users.php');
    exit;
}

$edit = true;
$admin_account = $found[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $dname = trim((string) ($_POST['displayname'] ?? $admin_account['user']));
    $updates = [
        'id'           => $admin_account['id'],
        'displayname'  => $dname,
        'display-name' => $dname,
        'email'        => trim((string) ($_POST['email'] ?? '')),
        'role'         => trim((string) ($_POST['admin_type'] ?? 'subscriber')),
        'phone'        => trim((string) ($_POST['phone'] ?? '')),
    ];
    if (!empty($_POST['passwd'])) {
        $pwErrors = function_exists('nexa_password_validate') ? nexa_password_validate((string) $_POST['passwd'], (string) ($admin_account['user'] ?? '')) : [];
        if ($pwErrors) {
            $_SESSION['failure'] = implode('<br>', array_map('esc_html', $pwErrors));
            header('Location: edit_admin.php?admin_user_id=' . urlencode((string) $userId));
            exit;
        }
        $updates['pass'] = password_hash((string) $_POST['passwd'], PASSWORD_DEFAULT);
    }
    // Per-user idle timeout (Sessions & Login presets; '' = site default).
    if (function_exists('nexa_session_idle_settings') && isset($_POST['idle_timeout_minutes'])) {
        $idleCfg = nexa_session_idle_settings();
        $raw = trim((string) $_POST['idle_timeout_minutes']);
        $val = '';
        if ($raw === '0' && $idleCfg['allow_never']) $val = '0';
        elseif (in_array((int) $raw, nexa_session_idle_presets(), true)) $val = (string) (int) $raw;
        $updates['idle_timeout_minutes'] = $val;
        if (($admin_account['user'] ?? '') === ($_SESSION['name'] ?? '')) $_SESSION['nexa_idle_pref'] = $val;
    }
    update_table('users', $updates, 'update');
    if (($admin_account['user'] ?? '') === ($_SESSION['name'] ?? '')) {
        $_SESSION['display-name'] = $dname;
    }
    $_SESSION['success'] = 'User account updated!';
    header('Location: admin_users.php');
    exit;
}

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/add_admin.php';
include_once __DIR__ . '/includes/footer.php';
