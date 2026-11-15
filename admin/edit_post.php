<?php
/**
 * NexaCMS Post & Page Editor (admin/edit_post.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

if (isset($_GET['editor']) && in_array($_GET['editor'], ['nexa', 'tinymce'], true)) {
    set_setting('wysiwyg_editor', $_GET['editor'], 'WYSIWYG Editor');
    header('Location: edit_post.php?id=' . urlencode((string) ($_REQUEST['id'] ?? '')) . '&type=' . urlencode((string) ($_REQUEST['type'] ?? 'post')));
    exit;
}

$id   = $_REQUEST['id'] ?? $_REQUEST['page_id'] ?? '';
$type = strtolower(trim((string) ($_REQUEST['type'] ?? 'post')));
if (!in_array($type, ['post', 'page'], true)) $type = 'post';

$editing = false;
$item = [
    'id'             => '',
    'post_title'     => '',
    'post_alias'     => '',
    'post_name'      => '',
    'post_content'   => '',
    'post_excerpt'   => '',
    'post_keywords'  => '',
    'post_thumb'     => '',
    'post_layout'    => '',
    'post_status'    => 'published',
    'comment_status' => get_setting('default_comment_status', 'open'),
    'post_type'      => $type,
    'menu_order'     => '0',
    'post_author'    => $_SESSION['display-name'] ?? 'Administrator',
];

if ($id !== '') {
    $found = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$id], true);
    if (!empty($found[0])) {
        $item = array_merge($item, $found[0]);
        $type = $item['post_type'] ?: $type;
        $editing = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $title = trim((string) ($_POST['post_title'] ?? ''));
    if ($title === '') $title = '(Untitled)';

    $rawSlug = trim((string) ($_POST['post_alias'] ?? ''));
    if ($rawSlug === '') $rawSlug = $title;
    $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $rawSlug), '-')) ?: ('item-' . time());

    // Subsites (root subfolder mode): a main-site page/post may not take a
    // subsite's slug, or the URL would be ambiguous.
    if (function_exists('nexa_current_site') && !nexa_current_site()
        && (string) get_setting('multisite_enabled', '0') === '1'
        && (string) get_setting('multisite_path_mode', 'sites') === 'root'
        && function_exists('nexa_multisite_site_by_slug') && nexa_multisite_site_by_slug($slug)) {
        $_SESSION['failure'] = "The slug '{$slug}' is reserved by a subsite. Choose a different URL slug for this {$type}.";
        header('Location: edit_post.php?' . ($editing ? 'id=' . urlencode((string) $item['id']) . '&' : '') . 'type=' . urlencode($type));
        exit;
    }

    $thumbUrl = trim((string) ($_POST['post_thumb'] ?? ''));
    if (!empty($_FILES['thumb_upload']['tmp_name']) && is_uploaded_file($_FILES['thumb_upload']['tmp_name'])) {
        $ext = strtolower(pathinfo((string) $_FILES['thumb_upload']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $subDir = get_setting('uploads_use_yearmonth_folders', '1') === '1' ? ('/' . date('Y/m')) : '';
            $relDir = 'content/uploads' . $subDir;
            $absDir = dirname(__DIR__) . '/' . $relDir;
            @mkdir($absDir, 0755, true);
            $fname = $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
            if (move_uploaded_file($_FILES['thumb_upload']['tmp_name'], $absDir . '/' . $fname)) {
                $thumbUrl = $relDir . '/' . $fname;
            }
        }
    }

    $postType = in_array($_POST['post_type'] ?? $type, ['post', 'page'], true) ? $_POST['post_type'] : $type;
    $payload = [
        'post_title'     => $title,
        'post_alias'     => $slug,
        'post_name'      => $slug,
        'post_content'   => (string) ($_POST['post_content'] ?? ''),
        'post_excerpt'   => trim((string) ($_POST['post_excerpt'] ?? '')),
        'post_keywords'  => trim((string) ($_POST['post_keywords'] ?? '')),
        'post_thumb'     => $thumbUrl,
        'post_status'    => in_array($_POST['post_status'] ?? 'published', ['published', 'draft'], true) ? $_POST['post_status'] : 'published',
        'comment_status' => ($_POST['comment_status'] ?? 'open') === 'closed' ? 'closed' : 'open',
        'post_layout'    => in_array($_POST['post_layout'] ?? '', ['right', 'left', 'none'], true) ? (string) $_POST['post_layout'] : '',
        'post_type'      => $postType,
        'menu_order'     => (string) ((int) ($_POST['menu_order'] ?? 0)),
        'post_modified'  => date('Y-m-d H:i:s'),
    ];

    if ($editing) {
        $payload['id'] = $item['id'];
        update_post($payload, 'update');
        $savedId = $item['id'];
        $_SESSION['success'] = ucfirst($postType) . ' updated successfully!';
    } else {
        $payload['post_author']   = $_SESSION['display-name'] ?? 'Administrator';
        $payload['post_date']     = date('Y-m-d H:i:s');
        $payload['post_date_gmt'] = (string) time();
        $savedId = update_post($payload, 'insert');
        $_SESSION['success'] = ucfirst($postType) . ' created successfully!';
    }

    header('Location: edit_post.php?id=' . urlencode((string) $savedId) . '&type=' . urlencode($postType));
    exit;
}

$singular = ($type === 'page') ? 'Page' : 'Post';
include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/edit_post.php';
include_once __DIR__ . '/includes/footer.php';
