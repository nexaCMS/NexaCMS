<?php
/**
 * NexaCMS Settings Hub (admin/settings.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$tab = strtolower(trim((string) ($_GET['page'] ?? $_POST['settings_tab'] ?? 'general')));
$validTabs = [
    'general'    => 'General',
    'writing'    => 'Writing',
    'reading'    => 'Reading',
    'discussion' => 'Discussion',
    'media'      => 'Media',
    'permalink'  => 'Permalinks',
    'passwords'  => 'Password Policy',
    'sessions'   => 'Sessions & Login',
];
if (!isset($validTabs[$tab])) {
    $tab = 'general';
}

$tabFields = [
    'general'    => ['blogname', 'blogdescription', 'anim_email', 'users_can_register', 'default_role', 'gmt_offset', 'date_format', 'time_format', 'start_of_week'],
    'writing'    => ['default_category', 'mailserver_url', 'mailserver_port', 'mailserver_login', 'mailserver_pass', 'wysiwyg_editor'],
    'reading'    => ['show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'blog_charset'],
    'discussion' => ['default_comment_status', 'require_name_email', 'comment_registation', 'comments_notify', 'thread_comments', 'show_avatars'],
    'media'      => ['thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h', 'uploads_use_yearmonth_folders'],
    'permalink'  => ['permalink_structure'],
    'passwords'  => ['pw_min_length', 'pw_require_upper', 'pw_require_lower', 'pw_require_digit', 'pw_require_symbol', 'pw_no_username', 'pw_forbid_common', 'pw_prompt_on_login', 'pw_reset_enabled', 'pw_reset_expire_minutes'],
    'sessions'   => ['sess_idle_enabled', 'sess_idle_minutes', 'sess_idle_allow_user', 'sess_idle_allow_never', 'sess_idle_max_minutes'],
];

$checkboxFields = [
    'general'    => ['users_can_register'],
    'writing'    => [],
    'reading'    => [],
    'discussion' => ['require_name_email', 'comment_registation', 'comments_notify', 'thread_comments', 'show_avatars'],
    'media'      => ['uploads_use_yearmonth_folders'],
    'permalink'  => [],
    'passwords'  => ['pw_require_upper', 'pw_require_lower', 'pw_require_digit', 'pw_require_symbol', 'pw_no_username', 'pw_forbid_common', 'pw_prompt_on_login', 'pw_reset_enabled'],
    'sessions'   => ['sess_idle_enabled', 'sess_idle_allow_user', 'sess_idle_allow_never'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $allowed = $tabFields[$tab];
    $payload = [];
    foreach ($allowed as $key) {
        if (in_array($key, $checkboxFields[$tab], true)) {
            $payload[$key] = !empty($_POST[$key]) ? '1' : '0';
        } elseif (isset($_POST[$key])) {
            $payload[$key] = trim((string) $_POST[$key]);
        }
    }
    if ($tab === 'discussion' && empty($_POST['default_comment_status'])) {
        $payload['default_comment_status'] = 'closed';
    }
    // Editor engine: only accept known engines.
    if (isset($payload['wysiwyg_editor']) && !in_array($payload['wysiwyg_editor'], ['nexa', 'tinymce'], true)) {
        $payload['wysiwyg_editor'] = 'nexa';
    }
    // Password policy: minimum length is a bounded integer.
    if ($tab === 'passwords' && isset($payload['pw_min_length'])) {
        $payload['pw_min_length'] = (string) max(4, min(64, (int) $payload['pw_min_length'] ?: 8));
    }
    // Forgot password: bounded reset-link lifetime.
    if ($tab === 'passwords' && isset($payload['pw_reset_expire_minutes'])) {
        $payload['pw_reset_expire_minutes'] = (string) max(5, min(1440, (int) $payload['pw_reset_expire_minutes'] ?: 60));
    }
    // Session idle timeout: bounded minute values.
    if ($tab === 'sessions') {
        if (isset($payload['sess_idle_minutes']))     $payload['sess_idle_minutes']     = (string) max(1, min(10080, (int) $payload['sess_idle_minutes'] ?: 30));
        if (isset($payload['sess_idle_max_minutes'])) $payload['sess_idle_max_minutes'] = (string) max(1, min(10080, (int) $payload['sess_idle_max_minutes'] ?: 1440));
    }
    update_settings($payload, $allowed);
    // Settings (especially permalinks) change how pages/links render.
    foreach (glob(dirname(__DIR__) . '/content/cache/*.html') ?: [] as $cached) @unlink($cached);
    header('Location: settings.php?page=' . urlencode($tab));
    exit;
}

loadsettings();
include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header"><?= esc_html($validTabs[$tab]) ?> Settings</h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <ul class="nav nav-tabs" style="margin-bottom:20px;">
    <?php foreach ($validTabs as $tk => $tl): ?>
      <li class="<?= $tk === $tab ? 'active' : '' ?>">
        <a href="settings.php?page=<?= esc_attr($tk) ?>"><?= esc_html($tl) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="panel panel-default">
    <div class="panel-body">
      <form method="post" action="settings.php?page=<?= esc_attr($tab) ?>">
        <?= di_csrf_field() ?>
        <input type="hidden" name="settings_tab" value="<?= esc_attr($tab) ?>">
        <?php include __DIR__ . "/includes/{$tab}.php"; ?>
        <hr>
        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Changes</button>
      </form>
    </div>
  </div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
