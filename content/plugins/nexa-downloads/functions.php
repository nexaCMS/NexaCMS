<?php
/**
 * Nexa Downloads — protected, tracked downloads with email/member/customer/
 * approval restrictions, countdown timers and admin-granted entitlements.
 * Files are never linked directly: short-lived tokens stream through PHP.
 */

function nexa_dl_now(): string { return date('Y-m-d H:i:s'); }
function nexa_dl_setting(string $key, string $default = ''): string { return (string) get_setting('downloads_' . $key, $default); }
function nexa_dl_clean(string $v, int $max = 500): string { return mb_substr(trim(strip_tags($v)), 0, $max); }
function nexa_dl_email(string $v): string { $v = strtolower(trim($v)); return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : ''; }

function nexa_dl_ensure(): void {
    if (!table_exists('download_items')) create_table('download_items', ['title','description','category','filename','file_path','file_type','file_size','mime','version','shop_product_id','buy_url','price_label','access_mode','require_email','require_approval','wait_seconds','wait_skip_entitled','grant_max_downloads','grant_days','status','download_count','created_at','updated_at']);
    if (!table_exists('download_requests')) create_table('download_requests', ['item_id','user_id','name','email','reason','purchase_ref','status','admin_note','decision_by','created_at','decided_at','grant_id']);
    if (!table_exists('download_entitlements')) create_table('download_entitlements', ['item_id','user_id','email','source','max_downloads','used_downloads','expires_at','status','created_at']);
    if (!table_exists('download_tokens')) create_table('download_tokens', ['item_id','entitlement_id','user_id','email','token_hash','ready_at','expires_at','max_uses','uses','counted','ip','created_at']);
    if (!table_exists('download_logs')) create_table('download_logs', ['item_id','entitlement_id','user_id','email','result','ip','user_agent','referer','bytes','created_at']);
    if (function_exists('ensure_fields')) {
        try {
            ensure_fields('download_items', ['title','description','category','filename','file_path','file_type','file_size','mime','version','shop_product_id','buy_url','price_label','access_mode','require_email','require_approval','wait_seconds','wait_skip_entitled','grant_max_downloads','grant_days','status','download_count','created_at','updated_at']);
            ensure_fields('download_requests', ['item_id','user_id','name','email','reason','purchase_ref','status','admin_note','decision_by','created_at','decided_at','grant_id']);
            ensure_fields('download_entitlements', ['item_id','user_id','email','source','max_downloads','used_downloads','expires_at','status','created_at']);
            ensure_fields('download_tokens', ['item_id','entitlement_id','user_id','email','token_hash','ready_at','expires_at','max_uses','uses','counted','ip','created_at']);
            ensure_fields('download_logs', ['item_id','entitlement_id','user_id','email','result','ip','user_agent','referer','bytes','created_at']);
        } catch (Throwable $e) { /* best effort */ }
    }
}
nexa_dl_ensure();

function nexa_dl_item(int $id): ?array {
    if ($id <= 0) return null;
    $r = query("SELECT * FROM download_items WHERE id = ?", [$id]);
    return $r[0] ?? null;
}

function nexa_dl_items(bool $activeOnly = false): array {
    $rows = get_table('download_items')['data'];
    if ($activeOnly) $rows = array_values(array_filter($rows, static fn($r) => ($r['status'] ?? 'active') === 'active'));
    usort($rows, static fn($a, $b) => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));
    return $rows;
}

function nexa_dl_format_bytes($bytes): string {
    $n = (float) $bytes;
    foreach (['B','KB','MB','GB','TB'] as $u) { if ($n < 1024 || $u === 'TB') return rtrim(rtrim(number_format($n, $n >= 100 ? 0 : 1), '0'), '.') . ' ' . $u; $n /= 1024; }
    return (string) $bytes;
}

function nexa_dl_current_user(): ?array {
    if (!is_user_logged_in()) return null;
    $uid = get_current_user_id();
    if ($uid > 0) { $u = get_user($uid); if (is_array($u)) return $u; }
    if (!empty($_SESSION['name'])) { $u = di_find_user_by_login((string) $_SESSION['name']); if ($u) return $u; }
    return ['id' => (string) $uid, 'user' => (string) ($_SESSION['name'] ?? ''), 'email' => '', 'display-name' => (string) ($_SESSION['display-name'] ?? 'Member')];
}

function nexa_dl_identity(string $providedEmail = ''): array {
    $user = nexa_dl_current_user();
    $accountEmail = $user ? nexa_dl_email((string) ($user['email'] ?? '')) : '';
    $email = nexa_dl_email($providedEmail) ?: $accountEmail;
    if ($email === '' && !empty($_SESSION['nexa_download_email'])) $email = nexa_dl_email((string) $_SESSION['nexa_download_email']);
    return [
        'user_id' => $user ? (int) ($user['id'] ?? 0) : 0,
        'name' => $user ? (function_exists('di_user_display_name') ? di_user_display_name($user) : (string) ($user['user'] ?? 'Member')) : 'Guest',
        'email' => $email,
        'account_email' => $accountEmail,
        'logged_in' => $user !== null,
        'manager' => is_user_logged_in() && (current_user_can('manage_options') || in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin','admin'], true)),
    ];
}

function nexa_dl_log(int $itemId, string $result, array $identity = [], int $entitlementId = 0, int $bytes = 0): void {
    try {
        update_table('download_logs', [
            'item_id' => (string) $itemId, 'entitlement_id' => (string) $entitlementId, 'user_id' => (string) ($identity['user_id'] ?? 0),
            'email' => (string) ($identity['email'] ?? ''), 'result' => $result, 'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250), 'referer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
            'bytes' => (string) $bytes, 'created_at' => nexa_dl_now(),
        ], 'insert');
    } catch (Throwable $e) { /* logging must not break delivery */ }
}

function nexa_dl_find_entitlement(int $itemId, array $identity): ?array {
    $rows = query("SELECT * FROM download_entitlements WHERE item_id = ? AND status = ? ORDER BY id DESC", [(string) $itemId, 'active']);
    $fallback = null;
    foreach ($rows as $r) {
        $userMatch = !empty($identity['user_id']) && (int) ($r['user_id'] ?? 0) === (int) $identity['user_id'];
        $emailMatch = !empty($identity['email']) && strtolower((string) ($r['email'] ?? '')) === strtolower((string) $identity['email']);
        if (!$userMatch && !$emailMatch) continue;
        if (!empty($r['expires_at']) && strtotime((string) $r['expires_at']) < time()) continue;
        if ((int) ($r['max_downloads'] ?? 0) > 0 && (int) ($r['used_downloads'] ?? 0) >= (int) $r['max_downloads']) continue;
        if ($userMatch) return $r;
        $fallback = $fallback ?? $r;
    }
    return $fallback;
}

function nexa_dl_grant(int $itemId, array $identity, string $source, ?array $item = null): array {
    $item = $item ?? nexa_dl_item($itemId);
    $days = $item ? (int) ($item['grant_days'] ?? 0) : 0;
    $id = update_table('download_entitlements', [
        'item_id' => (string) $itemId, 'user_id' => (string) ($identity['user_id'] ?? 0), 'email' => strtolower((string) ($identity['email'] ?? '')),
        'source' => $source, 'max_downloads' => (string) ($item ? (int) ($item['grant_max_downloads'] ?? 0) : 0), 'used_downloads' => '0',
        'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : '', 'status' => 'active', 'created_at' => nexa_dl_now(),
    ], 'insert');
    $r = query("SELECT * FROM download_entitlements WHERE id = ?", [(int) $id]);
    return $r[0] ?? ['id' => (int) $id];
}

/** Verify a Nexa Shop purchase: paid/completed/shipped order for the linked product, matched by checkout email. */
function nexa_dl_shop_entitlement(array $item, array $identity): ?array {
    $pid = (int) ($item['shop_product_id'] ?? 0);
    if ($pid <= 0 || empty($identity['email']) || !table_exists('shop_products') || !table_exists('shop_orders')) return null;
    $product = query("SELECT * FROM shop_products WHERE id = ?", [$pid]);
    if (empty($product)) return null;
    $accepted = array_filter(array_map(static fn($s) => strtolower(trim($s)), explode(',', nexa_dl_setting('shop_statuses', 'paid,completed,shipped'))));
    foreach (get_table('shop_orders')['data'] as $order) {
        $orderEmail = (string) ($order['email'] ?? ($order['customer_email'] ?? ''));
        if (strtolower(trim($orderEmail)) !== strtolower((string) $identity['email'])) continue;
        if (!in_array(strtolower((string) ($order['status'] ?? '')), $accepted, true)) continue;
        foreach (explode(';', (string) ($order['items'] ?? '')) as $part) {
            $part = trim(preg_replace('/^items:\s*/i', '', trim($part)) ?? $part);
            $part = trim(explode('|', $part)[0]);
            $name = trim(preg_replace('/\s+x\d+$/i', '', $part) ?? $part);
            if (strcasecmp($name, (string) $product[0]['name']) === 0) {
                $existing = nexa_dl_find_entitlement((int) $item['id'], $identity);
                if ($existing && ($existing['source'] ?? '') === 'shop') return $existing;
                return nexa_dl_grant((int) $item['id'], $identity, 'shop', $item);
            }
        }
    }
    return null;
}

function nexa_dl_latest_request(int $itemId, array $identity): ?array {
    $rows = query("SELECT * FROM download_requests WHERE item_id = ? ORDER BY id DESC", [(string) $itemId]);
    foreach ($rows as $r) {
        $userMatch = !empty($identity['user_id']) && (int) ($r['user_id'] ?? 0) === (int) $identity['user_id'];
        $emailMatch = !empty($identity['email']) && strtolower((string) ($r['email'] ?? '')) === strtolower((string) $identity['email']);
        if ($userMatch || $emailMatch) return $r;
    }
    return null;
}

function nexa_dl_eligibility(array $item, array $identity): array {
    $base = ['allowed' => false, 'code' => 'denied', 'message' => 'This download is not available.', 'entitlement' => null, 'request' => null];
    if (($item['status'] ?? 'active') !== 'active' && empty($identity['manager'])) return $base;
    if (!empty($identity['manager'])) return ['allowed' => true, 'code' => 'admin', 'message' => 'Admin test access.', 'entitlement' => null, 'request' => null];

    $entitlement = nexa_dl_find_entitlement((int) $item['id'], $identity);
    $request = nexa_dl_latest_request((int) $item['id'], $identity);
    $base['request'] = $request;
    $mode = (string) ($item['access_mode'] ?? 'public');
    $emailRequired = !empty($item['require_email']) && (int) $item['require_email'] === 1;

    if ($entitlement) {
        return ['allowed' => true, 'code' => 'granted', 'message' => 'Your access has been granted.', 'entitlement' => $entitlement, 'request' => $request];
    }
    if ($emailRequired && empty($identity['email'])) {
        return ['allowed' => false, 'code' => 'email_required', 'message' => 'Please provide your email address to download.', 'entitlement' => null, 'request' => $request];
    }
    if (!empty($item['require_approval']) && (int) $item['require_approval'] === 1) {
        $code = $request && ($request['status'] ?? '') === 'pending' ? 'pending' : (($request && ($request['status'] ?? '') === 'denied') ? 'request_denied' : 'approval_required');
        return ['allowed' => false, 'code' => $code, 'message' => $code === 'pending' ? 'Your request is waiting for an admin decision.' : 'This download is released only after an admin approves your request; automatic purchase or membership checks do not override that final decision.', 'entitlement' => null, 'request' => $request];
    }
    if (in_array($mode, ['purchase','members_or_customers'], true) || ($mode === 'purchase')) {
        $shop = nexa_dl_shop_entitlement($item, $identity);
        if ($shop) return ['allowed' => true, 'code' => 'purchased', 'message' => 'Purchase verified.', 'entitlement' => $shop, 'request' => $request];
    }

    $modeAllowed = match ($mode) {
        'public', 'email' => true,
        'logged_in' => !empty($identity['logged_in']),
        'members_or_customers' => !empty($identity['logged_in']),
        default => false,
    };
    if (!$modeAllowed) {
        $code = match ($mode) {
            'logged_in', 'members_or_customers' => !empty($identity['logged_in']) ? 'purchase_required' : 'login_required',
            'purchase' => 'purchase_required',
            'approval' => 'approval_required',
            default => 'denied',
        };
        if ($request && ($request['status'] ?? '') === 'pending') $code = 'pending';
        if ($request && ($request['status'] ?? '') === 'denied') $code = 'request_denied';
        $message = match ($code) {
            'login_required' => 'Please log in to download this file.',
            'purchase_required' => 'This file is for verified customers. Enter the email used at checkout, buy the product, or submit a purchase/access claim for admin verification.',
            'approval_required' => 'This download needs admin approval. Submit a request below and the admin will make the final decision.',
            'pending' => 'Your request is waiting for an admin decision.',
            'request_denied' => 'Your previous request was not approved. You may submit a new request with more detail.',
            default => $base['message'],
        };
        return ['allowed' => false, 'code' => $code, 'message' => $message, 'entitlement' => null, 'request' => $request];
    }
    if (!empty($item['require_approval']) && (int) $item['require_approval'] === 1) {
        $code = $request && ($request['status'] ?? '') === 'pending' ? 'pending' : (($request && ($request['status'] ?? '') === 'denied') ? 'request_denied' : 'approval_required');
        return ['allowed' => false, 'code' => $code, 'message' => $code === 'pending' ? 'Your request is waiting for an admin decision.' : 'This download is released only after an admin approves your request.', 'entitlement' => null, 'request' => $request];
    }
    if ($mode === 'email' && empty($identity['email'])) {
        return ['allowed' => false, 'code' => 'email_required', 'message' => 'Please provide your email address to download.', 'entitlement' => null, 'request' => $request];
    }
    return ['allowed' => true, 'code' => $mode, 'message' => 'You can download this file.', 'entitlement' => null, 'request' => $request];
}

function nexa_dl_wait_seconds(array $item, array $elig, array $identity): int {
    if (!empty($identity['manager'])) return 0;
    if (!empty($elig['entitlement']) && !empty($item['wait_skip_entitled']) && (int) $item['wait_skip_entitled'] === 1) return 0;
    if (($elig['code'] ?? '') === 'purchased' && !empty($item['wait_skip_entitled']) && (int) $item['wait_skip_entitled'] === 1) return 0;
    return max(0, (int) ($item['wait_seconds'] ?? nexa_dl_setting('default_wait', '30')));
}

function nexa_dl_create_token(array $item, array $identity, array $elig): array {
    $wait = nexa_dl_wait_seconds($item, $elig, $identity);
    $raw = bin2hex(random_bytes(24));
    $ttl = max(1, (int) nexa_dl_setting('token_ttl', '30'));
    $expiresAt = time() + max($ttl * 60, $wait + 300);
    $id = update_table('download_tokens', [
        'item_id' => (string) $item['id'], 'entitlement_id' => (string) ($elig['entitlement']['id'] ?? 0), 'user_id' => (string) ($identity['user_id'] ?? 0), 'email' => (string) ($identity['email'] ?? ''),
        'token_hash' => hash('sha256', $raw), 'ready_at' => date('Y-m-d H:i:s', time() + $wait), 'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        'max_uses' => '25', 'uses' => '0', 'counted' => '0', 'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'created_at' => nexa_dl_now(),
    ], 'insert');
    nexa_dl_log((int) $item['id'], $wait > 0 ? 'timer_started' : 'token_created', $identity, (int) ($elig['entitlement']['id'] ?? 0));
    return ['id' => (int) $id, 'raw' => $raw, 'wait' => $wait, 'ready_at' => time() + $wait, 'expires_at' => $expiresAt];
}

function nexa_dl_token_row(int $itemId, string $raw): ?array {
    if ($raw === '') return null;
    $rows = query("SELECT * FROM download_tokens WHERE item_id = ? AND token_hash = ?", [(string) $itemId, hash('sha256', $raw)]);
    return $rows[0] ?? null;
}

function nexa_dl_resolve_file(array $item): ?string {
    if (($item['file_type'] ?? 'local') === 'external') return null;
    $path = trim((string) ($item['file_path'] ?? ''));
    if ($path === '') return null;
    $root = rtrim((string) ($GLOBALS['config']['abs'] ?? dirname(__DIR__, 3)), '/');
    $full = preg_match('#^[A-Za-z]:\\\\#', $path) || str_starts_with($path, '/') ? $path : $root . '/' . ltrim($path, '/');
    $realRoot = realpath($root);
    $real = realpath($full);
    if ($real === false || !is_file($real)) return null;
    if ($realRoot !== false && !str_starts_with($real, $realRoot)) return null;
    $blocked = ['config.php', '.htaccess'];
    if (in_array(strtolower(basename($real)), $blocked, true)) return null;
    if (preg_match('/\.(php|phtml|phar|cgi|pl|asp|aspx|jsp|sh)$/i', $real)) return null;
    if (str_contains(str_replace('\\', '/', $real), '/admin/database/')) return null;
    return $real;
}

function nexa_dl_flash(string $type, string $msg, int $itemId = 0): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
    $_SESSION['nexa_download_flash'] = ['type' => $type, 'message' => $msg, 'item' => $itemId];
}

function nexa_dl_return_url(string $fallback = ''): string {
    $url = trim((string) ($_POST['return_url'] ?? $_GET['return_url'] ?? ''));
    if ($url === '') $url = $fallback !== '' ? $fallback : home_url();
    $host = parse_url($url, PHP_URL_HOST);
    $siteHost = parse_url(home_url(), PHP_URL_HOST);
    if ($host && $siteHost && strcasecmp((string) $host, (string) $siteHost) !== 0) return home_url();
    if (str_starts_with($url, '//')) return home_url();
    return $url;
}

function nexa_dl_append_token(string $url, int $itemId, string $raw): string {
    $parts = parse_url($url);
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $query['nxd_item'] = $itemId; $query['nxd_token'] = $raw;
    $base = ($parts['scheme'] ?? '') !== '' ? ($parts['scheme'] . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')) : '';
    return $base . ($parts['path'] ?? '') . '?' . http_build_query($query) . (!empty($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

function nexa_dl_handle(): void {
    if (!isset($_GET['nexa_download'])) return;
    nexa_dl_ensure();
    $action = preg_replace('/[^a-z_]/', '', strtolower((string) $_GET['nexa_download']));

    if ($action === 'prepare') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !nexa_verify_csrf()) { http_response_code(403); exit('Security check failed.'); }
        $item = nexa_dl_item((int) ($_POST['item_id'] ?? 0));
        $return = nexa_dl_return_url();
        if (!$item) { nexa_dl_flash('danger', 'Download not found.', 0); header('Location: ' . $return); exit; }
        $emailInput = (string) ($_POST['email'] ?? '');
        if ($emailInput !== '') $_SESSION['nexa_download_email'] = nexa_dl_email($emailInput);
        $identity = nexa_dl_identity($emailInput);
        if (!empty($_POST['email']) && nexa_dl_email((string) $_POST['email']) === '') {
            nexa_dl_flash('danger', 'Please enter a valid email address.', (int) $item['id']); header('Location: ' . $return); exit;
        }
        $elig = nexa_dl_eligibility($item, $identity);
        if (empty($elig['allowed'])) {
            nexa_dl_log((int) $item['id'], 'denied_' . $elig['code'], $identity, (int) ($elig['entitlement']['id'] ?? 0));
            nexa_dl_flash('warning', (string) $elig['message'], (int) $item['id']); header('Location: ' . $return); exit;
        }
        $token = nexa_dl_create_token($item, $identity, $elig);
        nexa_dl_flash($token['wait'] > 0 ? 'info' : 'success', $token['wait'] > 0 ? 'Your secure download is being prepared. Please wait for the countdown to finish.' : 'Your secure download link is ready.', (int) $item['id']);
        header('Location: ' . nexa_dl_append_token($return, (int) $item['id'], $token['raw'])); exit;
    }

    if ($action === 'request') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !nexa_verify_csrf()) { http_response_code(403); exit('Security check failed.'); }
        $item = nexa_dl_item((int) ($_POST['item_id'] ?? 0));
        $return = nexa_dl_return_url();
        if (!$item) { nexa_dl_flash('danger', 'Download not found.', 0); header('Location: ' . $return); exit; }
        $identity = nexa_dl_identity((string) ($_POST['email'] ?? ''));
        $name = nexa_dl_clean((string) ($_POST['name'] ?? $identity['name']), 100);
        $email = nexa_dl_email((string) ($_POST['email'] ?? $identity['email']));
        if ($email === '') { nexa_dl_flash('danger', 'An email address is required so the admin can grant and contact you about access.', (int) $item['id']); header('Location: ' . $return); exit; }
        $_SESSION['nexa_download_email'] = $email;
        $identity['email'] = $email; if ($name !== '') $identity['name'] = $name;
        $existing = nexa_dl_latest_request((int) $item['id'], $identity);
        if ($existing && ($existing['status'] ?? '') === 'pending') {
            nexa_dl_flash('info', 'Your request is already waiting for an admin decision.', (int) $item['id']); header('Location: ' . $return); exit;
        }
        $id = update_table('download_requests', [
            'item_id' => (string) $item['id'], 'user_id' => (string) ($identity['user_id'] ?? 0), 'name' => $name, 'email' => $email,
            'reason' => nexa_dl_clean((string) ($_POST['reason'] ?? ''), 1000), 'purchase_ref' => nexa_dl_clean((string) ($_POST['purchase_ref'] ?? ''), 200),
            'status' => 'pending', 'admin_note' => '', 'decision_by' => '', 'created_at' => nexa_dl_now(), 'decided_at' => '', 'grant_id' => '',
        ], 'insert');
        nexa_dl_log((int) $item['id'], 'request_submitted', $identity);
        $adminEmail = nexa_dl_email(nexa_dl_setting('admin_email', (string) get_setting('anim_email', '')));
        if ($adminEmail !== '' && function_exists('nexa_mail')) {
            @nexa_mail($adminEmail, 'Download access request: ' . $item['title'], $name . ' <' . $email . '> requested access to "' . $item['title'] . "\". Review it in Admin > Downloads > Requests.");
        }
        nexa_dl_flash('success', 'Request sent. An admin will make the final decision; approved access will work with this email address' . (!empty($identity['logged_in']) ? ' and your account.' : '.'), (int) $item['id']);
        header('Location: ' . $return); exit;
    }

    if ($action === 'deliver') {
        $item = nexa_dl_item((int) ($_GET['item'] ?? 0));
        $raw = (string) ($_GET['token'] ?? '');
        if (!$item) { http_response_code(404); exit('Download not found.'); }
        $token = nexa_dl_token_row((int) $item['id'], $raw);
        if (!$token) { nexa_dl_log((int) $item['id'], 'denied_bad_token', nexa_dl_identity()); http_response_code(403); exit('This download link is invalid. Please prepare the download again.'); }
        $identity = ['user_id' => (int) ($token['user_id'] ?? 0), 'name' => 'Guest', 'email' => (string) ($token['email'] ?? ''), 'logged_in' => (int) ($token['user_id'] ?? 0) > 0, 'manager' => false, 'account_email' => ''];
        if (strtotime((string) $token['expires_at']) < time()) { nexa_dl_log((int) $item['id'], 'denied_expired', $identity); http_response_code(410); exit('This download link has expired. Please prepare the download again.'); }
        if (strtotime((string) $token['ready_at']) > time()) { nexa_dl_log((int) $item['id'], 'denied_timer', $identity); http_response_code(403); exit('Please wait for the download countdown to finish.'); }
        if ((int) ($token['uses'] ?? 0) >= (int) ($token['max_uses'] ?? 25)) { nexa_dl_log((int) $item['id'], 'denied_token_used', $identity); http_response_code(410); exit('This download link has been used up. Please prepare a new one.'); }

        $entitlement = null;
        if (!empty($token['entitlement_id'])) {
            $rows = query("SELECT * FROM download_entitlements WHERE id = ?", [(int) $token['entitlement_id']]);
            $entitlement = $rows[0] ?? null;
            if (!$entitlement || ($entitlement['status'] ?? '') !== 'active' || (!empty($entitlement['expires_at']) && strtotime((string) $entitlement['expires_at']) < time())) {
                nexa_dl_log((int) $item['id'], 'denied_entitlement', $identity, (int) ($token['entitlement_id'] ?? 0)); http_response_code(403); exit('Your download access is no longer active.');
            }
            if (empty($token['counted']) && (int) ($entitlement['max_downloads'] ?? 0) > 0 && (int) ($entitlement['used_downloads'] ?? 0) >= (int) $entitlement['max_downloads']) {
                nexa_dl_log((int) $item['id'], 'denied_limit', $identity, (int) $entitlement['id']); http_response_code(403); exit('You have reached the download limit for this grant.');
            }
        }

        $isExternal = ($item['file_type'] ?? 'local') === 'external';
        $file = $isExternal ? null : nexa_dl_resolve_file($item);
        if (!$isExternal && $file === null) { http_response_code(404); exit('The file is not available right now. Please contact the site admin.'); }
        if ($isExternal && trim((string) $item['file_path']) === '') { http_response_code(404); exit('The download URL is not configured.'); }

        query("UPDATE download_tokens SET uses = ? WHERE id = ?", [(string) ((int) ($token['uses'] ?? 0) + 1), (int) $token['id']]);
        if (empty($token['counted'])) {
            query("UPDATE download_tokens SET counted = ? WHERE id = ?", ['1', (int) $token['id']]);
            if ($entitlement) query("UPDATE download_entitlements SET used_downloads = ? WHERE id = ?", [(string) ((int) ($entitlement['used_downloads'] ?? 0) + 1), (int) $entitlement['id']]);
            $fresh = nexa_dl_item((int) $item['id']);
            update_table('download_items', ['id' => (int) $item['id'], 'download_count' => (string) ((int) ($fresh['download_count'] ?? 0) + 1)], 'update');
            nexa_dl_log((int) $item['id'], 'delivered', $identity, (int) ($token['entitlement_id'] ?? 0), $isExternal ? 0 : (int) filesize($file));
        } else {
            nexa_dl_log((int) $item['id'], 'delivered_range', $identity, (int) ($token['entitlement_id'] ?? 0), 0);
        }

        if ($isExternal) { header('Location: ' . trim((string) $item['file_path'])); exit; }

        $filename = (string) ($item['filename'] ?: basename($file));
        $size = (int) filesize($file);
        $mime = (string) ($item['mime'] ?? '');
        if ($mime === '' || str_contains($mime, 'text/html')) $mime = function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream';
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');
        $start = 0; $end = $size - 1; $status = 200;
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int) $m[2]); }
            else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
            if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
            $status = 206; http_response_code(206); header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Content-Length: ' . ($end - $start + 1));
        $fh = fopen($file, 'rb');
        if ($fh) {
            fseek($fh, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($fh)) {
                $chunk = fread($fh, (int) min(8192, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk; flush(); $remaining -= strlen($chunk);
            }
            fclose($fh);
        }
        exit;
    }
}

/* ---------------- Front-end rendering ---------------- */

function nexa_dl_head(): void {
    if (!empty($GLOBALS['adminarea'])) return;
    echo '<style>.nxd-card{border:1px solid #e2e8f0;border-radius:14px;padding:18px;background:#fff;box-shadow:0 8px 28px rgba(15,23,42,.07);margin:16px 0}.nxd-card h3{margin-top:0}.nxd-meta{color:#64748b;font-size:13px}.nxd-badge{display:inline-block;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:700}.nxd-actions{margin-top:13px}.nxd-input{width:100%;max-width:430px;padding:9px;border:1px solid #cbd5e1;border-radius:8px;margin:4px 0}.nxd-btn{display:inline-block;background:#6d28d9;color:#fff!important;border:0;border-radius:9px;padding:10px 16px;font-weight:700;cursor:pointer;text-decoration:none}.nxd-btn.secondary{background:#334155}.nxd-btn.disabled{opacity:.45;pointer-events:none}.nxd-alert{border-radius:9px;padding:10px 12px;margin:10px 0}.nxd-alert.success{background:#dcfce7}.nxd-alert.info{background:#dbeafe}.nxd-alert.warning{background:#fef3c7}.nxd-alert.danger{background:#fee2e2}.nxd-count{font-size:22px;font-weight:800;color:#6d28d9}.nxd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}</style>' . "\n";
}

function nexa_dl_flash_html(int $itemId): string {
    if (empty($_SESSION['nexa_download_flash'])) return '';
    $f = $_SESSION['nexa_download_flash'];
    if (!empty($f['item']) && (int) $f['item'] !== $itemId) return '';
    unset($_SESSION['nexa_download_flash']);
    $type = in_array($f['type'] ?? '', ['success','info','warning','danger'], true) ? $f['type'] : 'info';
    return '<div class="nxd-alert ' . esc_attr($type) . '">' . esc_html((string) ($f['message'] ?? '')) . '</div>';
}

function nexa_dl_render_item(array $item, bool $compact = false): string {
    nexa_dl_ensure();
    $id = (int) $item['id'];
    if (($item['status'] ?? 'active') !== 'active' && !nexa_dl_identity()['manager']) return '<div class="nxd-card"><p>This download is not currently available.</p></div>';
    $identity = nexa_dl_identity();
    $elig = nexa_dl_eligibility($item, $identity);
    $return = (string) ($_SERVER['REQUEST_URI'] ?? home_url());
    $token = null; $tokenRaw = '';
    if ((int) ($_GET['nxd_item'] ?? 0) === $id && !empty($_GET['nxd_token'])) {
        $tokenRaw = (string) $_GET['nxd_token']; $token = nexa_dl_token_row($id, $tokenRaw);
        if ($token && strtotime((string) $token['expires_at']) < time()) $token = null;
    }
    $modeLabel = ['public' => 'Free download', 'email' => 'Email required', 'logged_in' => 'Members only', 'purchase' => 'Customers only', 'approval' => 'Admin approval', 'members_or_customers' => 'Members or customers'][(string) ($item['access_mode'] ?? 'public')] ?? 'Download';
    $h = '<div class="nxd-card" id="download-' . $id . '"><h3>' . esc_html((string) $item['title']) . ' <span class="nxd-badge">' . esc_html($modeLabel) . '</span></h3>';
    if (!empty($item['description'])) $h .= '<p>' . nl2br(esc_html((string) $item['description'])) . '</p>';
    $h .= '<p class="nxd-meta">File: ' . esc_html((string) ($item['filename'] ?: 'Download')) . ' · Size: ' . esc_html(nexa_dl_format_bytes($item['file_size'] ?? 0)) . (!empty($item['version']) ? ' · Version ' . esc_html((string) $item['version']) : '') . ' · Downloaded ' . (int) ($item['download_count'] ?? 0) . ' times</p>';
    if (!empty($item['price_label'])) $h .= '<p><strong>' . esc_html((string) $item['price_label']) . '</strong></p>';
    $h .= nexa_dl_flash_html($id);

    if ($token) {
        $ready = strtotime((string) $token['ready_at']); $left = max(0, $ready - time());
        $url = home_url('?nexa_download=deliver&item=' . $id . '&token=' . urlencode($tokenRaw));
        $h .= '<div class="nxd-alert ' . ($left > 0 ? 'info' : 'success') . '"><strong>Secure download link ready.</strong><br>';
        if ($left > 0) $h .= 'Your download starts in <span class="nxd-count" data-nxd-countdown="' . $ready . '"> ' . $left . '</span> seconds.';
        else $h .= 'Click below to download. The link expires soon and download activity is tracked.';
        $h .= '<div class="nxd-actions"><a class="nxd-btn' . ($left > 0 ? ' disabled' : '') . '" data-nxd-download-link href="' . esc_url($url) . '">Download now</a></div></div>';
    } elseif (($elig['code'] ?? '') === 'email_required') {
        $wait = max(0, (int) ($item['wait_seconds'] ?? 0));
        $h .= '<div class="nxd-alert info">' . esc_html((string) $elig['message']) . '</div><form method="post" action="' . esc_url(home_url('?nexa_download=prepare')) . '">' . nexa_csrf_field()
            . '<input type="hidden" name="item_id" value="' . $id . '"><input type="hidden" name="return_url" value="' . esc_attr($return) . '">'
            . '<input class="nxd-input" type="email" name="email" placeholder="Email address" required>'
            . '<div class="nxd-actions"><button class="nxd-btn" type="submit">' . ($wait > 0 ? 'Continue & start ' . $wait . '-second timer' : 'Continue to download') . '</button></div></form>';
    } elseif (!empty($elig['allowed'])) {
        $h .= '<div class="nxd-alert success">' . esc_html((string) $elig['message']) . '</div><form method="post" action="' . esc_url(home_url('?nexa_download=prepare')) . '">' . nexa_csrf_field()
            . '<input type="hidden" name="item_id" value="' . $id . '"><input type="hidden" name="return_url" value="' . esc_attr($return) . '">';
        if (empty($identity['email'])) $h .= '<input class="nxd-input" type="email" name="email" placeholder="Email address" ' . (!empty($item['require_email']) ? 'required' : '') . '>';
        elseif (empty($identity['logged_in'])) $h .= '<input type="hidden" name="email" value="' . esc_attr($identity['email']) . '"><p class="nxd-meta">Email: ' . esc_html($identity['email']) . '</p>';
        $wait = nexa_dl_wait_seconds($item, $elig, $identity);
        $h .= '<div class="nxd-actions"><button class="nxd-btn" type="submit">' . ($wait > 0 ? 'Start ' . $wait . '-second download timer' : 'Prepare download') . '</button></div></form>';
    } else {
        $h .= '<div class="nxd-alert warning">' . esc_html((string) $elig['message']) . '</div>';
        if (($elig['code'] ?? '') === 'login_required') {
            $h .= '<p><a class="nxd-btn" href="' . esc_url(nexa_dl_setting('login_url', '') !== '' ? nexa_dl_setting('login_url') : home_url('dashboard/')) . '">Log in</a></p>';
        }
        if (!empty($item['buy_url'])) $h .= '<p><a class="nxd-btn secondary" href="' . esc_url((string) $item['buy_url']) . '" target="_blank" rel="noopener">Buy / get this download</a></p>';
        $h .= '<details><summary><strong>Check access / request admin approval</strong></summary>'
            . '<form method="post" action="' . esc_url(home_url('?nexa_download=prepare')) . '">' . nexa_csrf_field() . '<input type="hidden" name="item_id" value="' . $id . '"><input type="hidden" name="return_url" value="' . esc_attr($return) . '">'
            . '<input class="nxd-input" type="email" name="email" placeholder="Your account/checkout email" value="' . esc_attr($identity['email']) . '" required><div><button class="nxd-btn secondary" type="submit">Check my access</button></div></form>'
            . '<form method="post" action="' . esc_url(home_url('?nexa_download=request')) . '" style="margin-top:14px;">' . nexa_csrf_field() . '<input type="hidden" name="item_id" value="' . $id . '"><input type="hidden" name="return_url" value="' . esc_attr($return) . '">'
            . '<input class="nxd-input" name="name" placeholder="Your name" value="' . esc_attr($identity['logged_in'] ? $identity['name'] : '') . '" required><br>'
            . '<input class="nxd-input" type="email" name="email" placeholder="Email address" value="' . esc_attr($identity['email']) . '" required><br>'
            . '<input class="nxd-input" name="purchase_ref" placeholder="Order / transaction reference (if purchased)"><br>'
            . '<textarea class="nxd-input" name="reason" rows="3" placeholder="Why do you need this download?"></textarea><br>'
            . '<button class="nxd-btn" type="submit">Send request to admin</button></form></details>';
    }
    $terms = trim(nexa_dl_setting('terms', ''));
    if ($terms !== '' && !$compact) $h .= '<p class="nxd-meta">' . esc_html($terms) . '</p>';
    return $h . '</div>';
}

function nexa_dl_countdown_script(): string {
    static $done = false; if ($done) return ''; $done = true;
    return '<script>(function(){function tick(){document.querySelectorAll("[data-nxd-countdown]").forEach(function(el){var left=Math.max(0,parseInt(el.getAttribute("data-nxd-countdown"),10)-Math.floor(Date.now()/1000));el.textContent=left;if(left<=0){var card=el.closest(".nxd-card");if(card){var a=card.querySelector("[data-nxd-download-link]");if(a)a.classList.remove("disabled");var box=el.closest(".nxd-alert");if(box){box.classList.remove("info");box.classList.add("success")}}}})}tick();setInterval(tick,500)})();</script>';
}

function nexa_dl_shortcode($atts = []): string {
    $id = (int) ($atts['id'] ?? 0);
    $item = nexa_dl_item($id);
    if (!$item) return '<div class="nxd-card"><p>Download not found.</p></div>';
    return nexa_dl_render_item($item) . nexa_dl_countdown_script();
}
function nexa_dl_list_shortcode($atts = []): string {
    $cat = strtolower(trim((string) ($atts['category'] ?? '')));
    $items = nexa_dl_items(true);
    if ($cat !== '') $items = array_values(array_filter($items, static fn($i) => strtolower((string) ($i['category'] ?? '')) === $cat));
    if (!$items) return '<p>No downloads are available yet.</p>';
    $h = '<div class="nxd-grid">';
    foreach ($items as $item) $h .= nexa_dl_render_item($item, true);
    return $h . '</div>' . nexa_dl_countdown_script();
}
function nexa_dl_my_shortcode(): string {
    $identity = nexa_dl_identity();
    if (empty($identity['logged_in'])) return '<div class="nxd-card"><p>Please log in to see your download grants and requests.</p><p><a class="nxd-btn" href="' . esc_url(home_url('dashboard/')) . '">Log in</a></p></div>';
    $grants = query("SELECT * FROM download_entitlements WHERE user_id = ? OR email = ? ORDER BY id DESC", [(string) $identity['user_id'], (string) $identity['email']]);
    $requests = query("SELECT * FROM download_requests WHERE user_id = ? OR email = ? ORDER BY id DESC", [(string) $identity['user_id'], (string) $identity['email']]);
    $h = '<div class="nxd-card"><h3>My download access</h3>';
    if (!$grants && !$requests) $h .= '<p>You have no download grants or requests yet.</p>';
    foreach ($grants as $g) {
        $item = nexa_dl_item((int) $g['item_id']); if (!$item) continue;
        $h .= '<p><strong>' . esc_html($item['title']) . '</strong> — ' . esc_html($g['status']) . ', used ' . (int) $g['used_downloads'] . (($g['max_downloads'] ?? '0') !== '0' ? ' of ' . (int) $g['max_downloads'] : '') . (!empty($g['expires_at']) ? ', expires ' . esc_html($g['expires_at']) : '') . '</p>';
    }
    foreach ($requests as $r) {
        $item = nexa_dl_item((int) $r['item_id']);
        $h .= '<p>Request for <strong>' . esc_html($item['title'] ?? ('#' . $r['item_id'])) . '</strong>: ' . esc_html($r['status']) . (!empty($r['admin_note']) ? ' — ' . esc_html($r['admin_note']) : '') . '</p>';
    }
    return $h . '</div>';
}
if (function_exists('add_shortcode')) {
    add_shortcode('download', 'nexa_dl_shortcode');
    add_shortcode('downloads', 'nexa_dl_list_shortcode');
    add_shortcode('my_downloads', 'nexa_dl_my_shortcode');
}
if (function_exists('register_editor_shortcode')) {
    $dlOptions = [];
    if (!empty($GLOBALS['adminarea'])) foreach (nexa_dl_items() as $item) $dlOptions[] = ['value' => (string) $item['id'], 'label' => $item['title'] . ' (#' . $item['id'] . ')'];
    register_editor_shortcode([
        'plugin' => 'Nexa Downloads', 'plugin_slug' => 'nexa-downloads', 'label' => 'Single protected download', 'tag' => 'download',
        'description' => 'Embed one tracked download with its email/member/customer/approval rules and timer.',
        'fields' => [['name' => 'id', 'label' => 'Download item', 'type' => $dlOptions ? 'select' : 'number', 'options' => $dlOptions, 'default' => $dlOptions ? (string) $dlOptions[0]['value'] : '', 'required' => true, 'min' => '1']],
        'template' => '[download id="{id}"]',
    ]);
    register_editor_shortcode(['plugin' => 'Nexa Downloads', 'plugin_slug' => 'nexa-downloads', 'label' => 'Download library', 'tag' => 'downloads', 'description' => 'Embed all active downloads, optionally filtered by category.', 'fields' => [['name' => 'category', 'label' => 'Category (optional)', 'type' => 'text']], 'template' => '[downloads category="{category}"]']);
    register_editor_shortcode(['plugin' => 'Nexa Downloads', 'plugin_slug' => 'nexa-downloads', 'label' => 'My downloads', 'tag' => 'my_downloads', 'description' => 'Show a logged-in user their grants and request decisions.', 'shortcode' => '[my_downloads]']);
}
