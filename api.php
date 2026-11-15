<?php
/**
 * NexaCMS JSON REST API (api.php)
 *
 * Endpoints:
 *   GET /api.php                         -> Site status, version, active driver, counts
 *   GET /api.php?action=posts            -> List published posts (?limit=10&page=1&type=post|page)
 *   GET /api.php?action=post&id=1        -> Single post/page by ID
 *   GET /api.php?action=search&q=term    -> Full-text search across published posts/pages
 *   GET /api.php?action=menus            -> Navigation menus and items
 *   GET /api.php?action=products         -> Storefront products (if nexa-shop table exists)
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

require_once __DIR__ . '/inc.php';
load_active_plugins();

header('Content-Type: application/json; charset=UTF-8');

$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));

switch ($action) {
    case 'posts':
        $type  = trim((string) ($_GET['type'] ?? ''));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
        $pg    = max(1, (int) ($_GET['page'] ?? 1));
        $all   = get_result('posts', 1, ['*'], ['by' => 'id', 'order' => 'DESC'], -1);
        $items = array_values(array_filter($all, static function ($r) use ($type) {
            if (($r['post_status'] ?? '') !== 'published') return false;
            if ($type !== '' && ($r['post_type'] ?? '') !== $type) return false;
            return true;
        }));
        $total = count($items);
        $slice = array_slice($items, ($pg - 1) * $limit, $limit);
        echo json_encode([
            'ok'    => true,
            'page'  => $pg,
            'limit' => $limit,
            'total' => $total,
            'posts' => $slice,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        break;

    case 'post':
        $id = $_GET['id'] ?? $_GET['p'] ?? '';
        $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$id], true);
        if (empty($rows[0]) || ($rows[0]['post_status'] ?? '') !== 'published') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Post not found'], JSON_PRETTY_PRINT);
        } else {
            $postRow = $rows[0];
            $postRow['rendered_content'] = apply_filters('the_content', do_shortcode((string) ($postRow['post_content'] ?? '')));
            echo json_encode(['ok' => true, 'post' => $postRow], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        break;

    case 'search':
        $q = trim((string) ($_GET['q'] ?? $_GET['s'] ?? ''));
        if ($q === '') {
            echo json_encode(['ok' => true, 'query' => '', 'results' => []], JSON_PRETTY_PRINT);
            break;
        }
        $matches = get_result('posts', 1, ['*'], ['by' => 'id', 'order' => 'DESC', 'search' => ['post_title', 'post_content', 'post_excerpt']], 25, [$q], false);
        $pub = array_values(array_filter($matches, static fn($r) => ($r['post_status'] ?? '') === 'published'));
        echo json_encode(['ok' => true, 'query' => $q, 'count' => count($pub), 'results' => $pub], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        break;

    case 'menus':
        echo json_encode([
            'ok'         => true,
            'menus'      => get_table('menus')['data'],
            'menu_items' => get_table('menu_items', 'order ASC')['data'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        break;

    case 'products':
        $products = table_exists('shop_products') ? query("SELECT * FROM shop_products WHERE status = 'active' ORDER BY id ASC") : [];
        echo json_encode(['ok' => true, 'count' => count($products), 'products' => $products], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        break;

    default:
        echo json_encode([
            'ok'          => true,
            'cms'         => NEXACMS_NAME,
            'version'     => NEXACMS_VERSION,
            'site_name'   => get_setting('blogname', 'NexaCMS'),
            'tagline'     => get_setting('blogdescription', ''),
            'home_url'    => home_url(),
            'theme'       => get_setting('template', 'multi'),
            'db_driver'   => $GLOBALS['di_db_driver'] ?? ($config['database']['type'] ?? 'csv'),
            'post_count'  => count_records('posts', 'post_status', 'published'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        break;
}
