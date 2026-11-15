<?php
/**
 * Nexa AI Agent - scoped AI API users.
 * Endpoint: POST {site}/?nexa_ai_api=1   Header: Authorization: Bearer nxai_...
 * Body (JSON): {"action":"list_posts","params":{"limit":5}}
 * The plugin is the master switch: deactivate it in Plugins and the API is gone.
 */
function nexa_ai_ensure(): void {
    if (!table_exists('ai_users')) create_table('ai_users', ['name','key_prefix','key_hash','permissions','rate_hour','rate_day','status','expires_at','last_used','created_at']);
    if (!table_exists('ai_audit')) create_table('ai_audit', ['ai_user','action','detail','ip','created_at']);
}
nexa_ai_ensure();

function nexa_ai_permission_defs(): array {
    return [
        'read'              => 'Read site info, posts, analytics, messages',
        'write_posts'       => 'Create & update posts/pages',
        'delete_posts'      => 'Delete posts/pages',
        'manage_plugins'    => 'List plugins, toggle plugin stages',
        'manage_settings'   => 'Read/update whitelisted settings',
        'moderate_comments' => 'Approve/unapprove/delete comments',
        'shop'              => 'Read products/orders, update order status',
    ];
}

function nexa_ai_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function nexa_ai_audit(string $user, string $action, string $detail = ''): void {
    nexa_ai_ensure();
    query("INSERT INTO ai_audit (ai_user, action, detail, ip, created_at) VALUES (?,?,?,?,?)",
        [$user, $action, mb_substr($detail, 0, 250), (string)($_SERVER['REMOTE_ADDR'] ?? ''), date('Y-m-d H:i:s')]);
}

/** Create an AI user; returns [row, plainKey] - the plain key is shown ONCE. */
function nexa_ai_create_key(string $name, array $perms, int $rateHour, int $rateDay, int $expiresDays = 0): array {
    nexa_ai_ensure();
    $plain  = 'nxai_' . bin2hex(random_bytes(24));
    $prefix = substr($plain, 0, 12);
    $perms  = array_values(array_intersect($perms, array_keys(nexa_ai_permission_defs())));
    if (!in_array('read', $perms, true)) array_unshift($perms, 'read');
    $id = query("INSERT INTO ai_users (name, key_prefix, key_hash, permissions, rate_hour, rate_day, status, expires_at, last_used, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$name, $prefix, hash('sha256', $plain), implode(',', $perms), $rateHour, $rateDay, 'active',
         $expiresDays > 0 ? date('Y-m-d H:i:s', time() + $expiresDays * 86400) : '', '', date('Y-m-d H:i:s')]);
    $row = query("SELECT * FROM ai_users WHERE id = ?", [(int)$id]);
    return [$row[0] ?? [], $plain];
}

function nexa_ai_authenticate(): ?array {
    nexa_ai_ensure();
    $key = '';
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') === 0) $key = trim(substr($auth, 7));
    if ($key === '') $key = trim((string)($_SERVER['HTTP_X_NEXA_AI_KEY'] ?? ''));
    if ($key === '' || !str_starts_with($key, 'nxai_')) return null;
    $rows = query("SELECT * FROM ai_users WHERE key_prefix = ?", [substr($key, 0, 12)]);
    foreach ($rows as $r) {
        if (!hash_equals((string)$r['key_hash'], hash('sha256', $key))) continue;
        if (($r['status'] ?? '') !== 'active') return null;
        if (!empty($r['expires_at']) && strtotime((string)$r['expires_at']) < time()) return null;
        return $r;
    }
    return null;
}

function nexa_ai_handle_api(): void {
    $isApi = isset($_GET['nexa_ai_api']) || str_ends_with((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/ai-api');
    if (!$isApi) return;

    if ((string)get_setting('ai_agent_enabled', '1') !== '1') {
        nexa_ai_json(['ok' => false, 'error' => 'AI Agent is disabled by the site admin (plugin setting). Deactivate the plugin to remove it entirely.'], 403);
    }
    $ai = nexa_ai_authenticate();
    if ($ai === null) {
        nexa_ai_audit('unknown', 'auth_failed');
        nexa_ai_json(['ok' => false, 'error' => 'Invalid, revoked or expired API key. Send: Authorization: Bearer nxai_...'], 401);
    }

    // --- Limits: per-key (0 = fall back to global defaults), plus a global cap
    $hourAgo = date('Y-m-d H:i:s', time() - 3600);
    $dayAgo  = date('Y-m-d H:i:s', time() - 86400);
    $usedHour = (int)(query("SELECT COUNT(*) AS c FROM ai_audit WHERE ai_user = ? AND created_at > ?", [$ai['name'], $hourAgo])[0]['c'] ?? 0);
    $usedDay  = (int)(query("SELECT COUNT(*) AS c FROM ai_audit WHERE ai_user = ? AND created_at > ?", [$ai['name'], $dayAgo])[0]['c'] ?? 0);
    $limHour  = (int)$ai['rate_hour'] > 0 ? (int)$ai['rate_hour'] : max(1, (int)get_setting('ai_default_hour', '60'));
    $limDay   = (int)$ai['rate_day']  > 0 ? (int)$ai['rate_day']  : max(1, (int)get_setting('ai_default_day', '500'));
    $globHour = max(1, (int)get_setting('ai_global_hour', '1000'));
    $globUsed = (int)(query("SELECT COUNT(*) AS c FROM ai_audit WHERE created_at > ?", [$hourAgo])[0]['c'] ?? 0);
    if ($usedHour >= $limHour || $usedDay >= $limDay || $globUsed >= $globHour) {
        nexa_ai_audit($ai['name'], 'rate_limited', "h:{$usedHour}/{$limHour} d:{$usedDay}/{$limDay}");
        nexa_ai_json(['ok' => false, 'error' => 'Rate limit exceeded for this AI user.', 'limits' => ['hour' => $limHour, 'day' => $limDay, 'used_hour' => $usedHour, 'used_day' => $usedDay]], 429);
    }

    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 262144) nexa_ai_json(['ok' => false, 'error' => 'Request body too large (256 KB max).'], 413);
    $req = json_decode($raw, true);
    if (!is_array($req)) { $req = ['action' => (string)($_POST['action'] ?? ''), 'params' => json_decode((string)($_POST['params'] ?? '{}'), true) ?: []]; }
    $action = preg_replace('/[^a-z_]/', '', strtolower((string)($req['action'] ?? '')));
    $p = is_array($req['params'] ?? null) ? $req['params'] : [];
    $perms = array_filter(array_map('trim', explode(',', (string)$ai['permissions'])));
    $need = static function (string $perm) use ($perms, $ai, $action): void {
        if (!in_array($perm, $perms, true)) {
            nexa_ai_audit($ai['name'], $action, 'denied: needs ' . $perm);
            nexa_ai_json(['ok' => false, 'error' => "This AI user lacks the '{$perm}' permission."], 403);
        }
    };
    $done = static function ($data) use ($ai, $action): void {
        query("UPDATE ai_users SET last_used = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int)$ai['id']]);
        nexa_ai_audit($ai['name'], $action, 'ok');
        nexa_ai_json(['ok' => true, 'action' => $action, 'ai_user' => $ai['name']] + (is_array($data) ? $data : ['result' => $data]));
    };

    switch ($action) {
        case 'ping':
            $done(['pong' => true, 'permissions' => $perms, 'limits' => ['per_hour' => $limHour, 'per_day' => $limDay]]);
            break;
        case 'site_info':
            $need('read');
            $done(['site' => get_setting('blogname', 'NexaCMS'), 'version' => NEXACMS_VERSION, 'driver' => $GLOBALS['di_db_driver'] ?? '', 'posts' => count_records('posts')]);
            break;
        case 'list_posts':
            $need('read');
            $limit = max(1, min(100, (int)($p['limit'] ?? 20)));
            $rows = query("SELECT id, post_title, post_type, post_status, post_date FROM posts ORDER BY id DESC LIMIT " . $limit);
            if (!empty($p['type'])) $rows = array_values(array_filter($rows, fn($r) => $r['post_type'] === (string)$p['type']));
            $done(['count' => count($rows), 'posts' => $rows]);
            break;
        case 'get_post':
            $need('read');
            $r = query("SELECT * FROM posts WHERE id = ?", [(int)($p['id'] ?? 0)]);
            $done(['post' => $r[0] ?? null]);
            break;
        case 'create_post':
            $need('write_posts');
            $id = update_post(['post_title' => (string)($p['title'] ?? 'Untitled'), 'post_content' => (string)($p['content'] ?? ''), 'post_excerpt' => (string)($p['excerpt'] ?? ''), 'post_type' => in_array(($p['type'] ?? 'post'), ['post', 'page'], true) ? ($p['type'] ?? 'post') : 'post', 'post_status' => (($p['status'] ?? 'draft') === 'published') ? 'published' : 'draft', 'post_author' => 'AI:' . $ai['name'], 'post_date' => date('Y-m-d H:i:s'), 'post_date_gmt' => (string)time()], 'insert');
            $done(['id' => (int)$id, 'url' => '?p=' . (int)$id]);
            break;
        case 'update_post':
            $need('write_posts');
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0 || empty(query("SELECT id FROM posts WHERE id = ?", [$id]))) nexa_ai_json(['ok' => false, 'error' => 'Post not found.'], 404);
            $args = ['id' => $id];
            foreach (['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status'] as $k => $col) if (isset($p[$k])) $args[$col] = (string)$p[$k];
            update_post($args, 'update');
            $done(['id' => $id, 'updated' => array_keys($args)]);
            break;
        case 'delete_post':
            $need('delete_posts');
            $done(['deleted' => delete_records('posts', (int)($p['id'] ?? 0))]);
            break;
        case 'list_plugins':
            $need('read');
            $done(['plugins' => query("SELECT name, pre, head, body, post FROM plugins ORDER BY name ASC")]);
            break;
        case 'set_plugin_stage':
            $need('manage_plugins');
            $plugin = basename((string)($p['plugin'] ?? ''));
            $stage  = (string)($p['stage'] ?? '');
            if (!in_array($stage, ['pre', 'head', 'body', 'post', 'all'], true)) nexa_ai_json(['ok' => false, 'error' => 'stage must be pre|head|body|post|all'], 422);
            if (!empty($p['on'])) activateplugin($plugin, $stage); else deactivateplugin($plugin, $stage);
            $done(['plugin' => $plugin, 'stage' => $stage, 'on' => !empty($p['on'])]);
            break;
        case 'get_settings':
            $need('manage_settings');
            $out = [];
            foreach (['blogname', 'blogdescription', 'posts_per_page', 'template', 'admin_theme', 'cache_enabled', 'cache_ttl'] as $k) $out[$k] = get_setting($k, '');
            $done(['settings' => $out]);
            break;
        case 'update_setting':
            $need('manage_settings');
            $allow = ['blogname', 'blogdescription', 'posts_per_page', 'cache_enabled', 'cache_ttl', 'seo_default_desc', 'seo_keywords'];
            $key = (string)($p['key'] ?? '');
            if (!in_array($key, $allow, true)) nexa_ai_json(['ok' => false, 'error' => 'Setting not in the AI whitelist: ' . implode(', ', $allow)], 403);
            set_setting($key, (string)($p['value'] ?? ''));
            $done(['key' => $key, 'value' => (string)($p['value'] ?? '')]);
            break;
        case 'list_comments':
            $need('moderate_comments');
            $done(['comments' => table_exists('comments') ? query("SELECT * FROM comments ORDER BY id DESC LIMIT 30") : []]);
            break;
        case 'moderate_comment':
            $need('moderate_comments');
            $id = (int)($p['id'] ?? 0); $do = (string)($p['do'] ?? '');
            if ($do === 'delete') query("DELETE FROM comments WHERE id = ?", [$id]);
            elseif ($do === 'unapprove') query("UPDATE comments SET approved = ? WHERE id = ?", ['0', $id]);
            else query("UPDATE comments SET approved = ? WHERE id = ?", ['1', $id]);
            $done(['id' => $id, 'did' => $do ?: 'approve']);
            break;
        case 'shop_products':
            $need('shop');
            $done(['products' => table_exists('shop_products') ? query("SELECT * FROM shop_products ORDER BY id ASC LIMIT 100") : []]);
            break;
        case 'shop_orders':
            $need('shop');
            $done(['orders' => table_exists('shop_orders') ? query("SELECT * FROM shop_orders ORDER BY id DESC LIMIT 50") : []]);
            break;
        case 'update_order_status':
            $need('shop');
            query("UPDATE shop_orders SET status = ? WHERE id = ?", [(string)($p['status'] ?? 'pending'), (int)($p['id'] ?? 0)]);
            $done(['id' => (int)($p['id'] ?? 0), 'status' => (string)($p['status'] ?? '')]);
            break;
        case 'analytics_summary':
            $need('read');
            $done(['analytics' => table_exists('analytics_hits') ? ['total_hits' => (int)(query("SELECT COUNT(*) AS c FROM analytics_hits")[0]['c'] ?? 0), 'today' => (int)(query("SELECT COUNT(*) AS c FROM analytics_hits WHERE day = ?", [date('Y-m-d')])[0]['c'] ?? 0)] : null]);
            break;
        default:
            nexa_ai_audit($ai['name'], $action ?: 'empty', 'unknown action');
            nexa_ai_json(['ok' => false, 'error' => 'Unknown action.', 'actions' => ['ping','site_info','list_posts','get_post','create_post','update_post','delete_post','list_plugins','set_plugin_stage','get_settings','update_setting','list_comments','moderate_comment','shop_products','shop_orders','update_order_status','analytics_summary']], 422);
    }
}
