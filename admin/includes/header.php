<?php
/**
 * NexaCMS Admin Header & Navigation (admin/includes/header.php)
 */
if (!defined('CURRENT_PAGE')) {
    define('CURRENT_PAGE', basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

$reqType = strtolower((string) ($_GET['type'] ?? ''));
// Admin assets always load from the REAL /admin folder (absolute URLs):
// inside a subsite admin area (/shopping/admin/...) relative css/js paths
// would resolve under the subsite prefix and break on servers without
// rewrite support for them.
$nxAssetBase = rtrim(baseurl(), '/') . '/admin/';

add_menu_item('nav_left', [
    'name'    => 'new',
    'class'   => 'dropdown',
    'icon'    => 'fa fa-plus fa-fw',
    'label'   => ' New',
    'ulclass' => 'nav navbar-top-links navbar-left dropdown',
    'submenu' => [
        'class' => 'dropdown-menu dropdown-user',
        'item'  => [
            0  => ['label' => ' Post',     'link' => 'edit_post.php?type=post', 'icon' => 'fa fa-file-text-o fa-fw'],
            5  => ['label' => ' Page',     'link' => 'edit_post.php?type=page', 'icon' => 'fa fa-files-o fa-fw'],
            10 => ['label' => ' User',     'link' => 'add_admin.php',           'icon' => 'fa fa-user-plus fa-fw'],
            15 => ['label' => ' Customer', 'link' => 'add_customer.php',        'icon' => 'fa fa-address-card-o fa-fw'],
        ],
    ],
]);

add_menu_item('nav_right', [
    'name'    => 'right nav',
    'class'   => 'dropdown',
    'icon'    => 'fa fa-user fa-fw',
    'icon2'   => 'fa fa-caret-down',
    'label'   => ' ' . esc_html($_SESSION['display-name'] ?? 'Account') . ' ',
    'ulclass' => 'nav navbar-top-links navbar-right dropdown',
    'submenu' => [
        'class' => 'dropdown-menu dropdown-user',
        'item'  => [
            0  => ['label' => ' My Profile', 'link' => 'profile.php',  'icon' => 'fa fa-user fa-fw'],
            1  => ['label' => ' Settings',   'link' => 'settings.php', 'icon' => 'fa fa-gear fa-fw'],
            5  => ['label' => ' Visit Site', 'link' => '../',          'icon' => 'fa fa-external-link fa-fw'],
            10 => ['class' => 'divider'],
            20 => ['label' => ' Logout',     'link' => 'logout.php',   'icon' => 'fa fa-sign-out fa-fw'],
        ],
    ],
], 100);

add_menu_item('dashboard', [
    'name'    => 'dashboard',
    'class'   => (CURRENT_PAGE === 'index.php') ? 'active' : '',
    'icon'    => 'fa fa-dashboard fa-fw',
    'label'   => ' Dashboard',
    'link'    => 'index.php',
    'ulclass' => 'nav',
    'ulid'    => 'side-menu',
], 1);

add_menu_item('dashboard', [
    'name'    => 'post',
    'class'   => ((CURRENT_PAGE === 'posts.php' || CURRENT_PAGE === 'edit_post.php') && ($reqType === '' || $reqType === 'post')) ? 'active' : '',
    'icon'    => 'fa fa-thumb-tack fa-fw',
    'link'    => '#',
    'label'   => ' Posts<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            0 => ['label' => ' All Posts',    'link' => 'posts.php?type=post',     'icon' => 'fa fa-list fa-fw'],
            1 => ['label' => ' Add New Post', 'link' => 'edit_post.php?type=post', 'icon' => 'fa fa-plus fa-fw'],
        ],
    ],
], 10);

add_menu_item('dashboard', [
    'name'    => 'page',
    'class'   => ((CURRENT_PAGE === 'posts.php' || CURRENT_PAGE === 'edit_post.php' || CURRENT_PAGE === 'manage_pages.php' || CURRENT_PAGE === 'add_edit_page.php') && $reqType === 'page') ? 'active' : '',
    'icon'    => 'fa fa-files-o fa-fw',
    'link'    => '#',
    'label'   => ' Pages<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            0 => ['label' => ' All Pages',    'link' => 'posts.php?type=page',     'icon' => 'fa fa-list fa-fw'],
            1 => ['label' => ' Add New Page', 'link' => 'edit_post.php?type=page', 'icon' => 'fa fa-plus fa-fw'],
        ],
    ],
], 20);

add_menu_item('dashboard', [
    'name'    => 'appearance',
    'class'   => in_array(CURRENT_PAGE, ['themes.php', 'theme-install.php', 'menus.php', 'admin-themes.php', 'theme-settings.php', 'theme-customizer.php'], true) ? 'active' : '',
    'icon'    => 'fa fa-paint-brush fa-fw',
    'link'    => '#',
    'label'   => ' Appearance<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            0 => ['label' => ' Themes',           'link' => 'themes.php',        'icon' => 'fa fa-desktop fa-fw'],
            4 => ['label' => ' Theme Settings',   'link' => 'theme-settings.php', 'icon' => 'fa fa-sliders fa-fw'],
            5 => ['label' => ' Live Theme Editor', 'link' => 'theme-customizer.php', 'icon' => 'fa fa-magic fa-fw'],
            3 => ['label' => ' Admin Themes',     'link' => 'admin-themes.php',  'icon' => 'fa fa-paint-brush fa-fw'],
            1 => ['label' => ' Upload Theme',     'link' => 'theme-install.php', 'icon' => 'fa fa-upload fa-fw'],
            6 => ['label' => ' Repositories',     'link' => 'repositories.php?f=theme', 'icon' => 'fa fa-cloud-download fa-fw'],
            2 => ['label' => ' Navigation Menus', 'link' => 'menus.php',         'icon' => 'fa fa-bars fa-fw'],
        ],
    ],
], 30);

if (function_exists('nexa_is_network_admin') ? nexa_is_network_admin() : (in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true) || !empty($_SESSION['nexa_network_admin']))) {
    $nexaPendingPkgs = function_exists('nexa_network_pending_count') ? nexa_network_pending_count() : 0;
    add_menu_item('dashboard', [
        'name'    => 'network',
        'class'   => CURRENT_PAGE === 'network.php' ? 'active' : '',
        'icon'    => 'fa fa-sitemap fa-fw',
        'label'   => ' Network (Subsites)' . ($nexaPendingPkgs > 0 ? ' <span class="badge" style="background:#d9534f;" title="Subsite packages waiting for your approval">' . $nexaPendingPkgs . '</span>' : ''),
        'link'    => 'network.php',
    ], 35);
}
$msSiteNow = function_exists('nexa_current_site') ? nexa_current_site() : null;
if ($msSiteNow) {
    $isNetAdminNow = function_exists('nexa_is_network_admin') ? nexa_is_network_admin() : false;
    add_menu_item('dashboard', [
        'name'    => 'network-context',
        'class'   => '',
        'icon'    => 'fa fa-globe fa-fw',
        'label'   => ' Managing: ' . ($msSiteNow['name'] ?? $msSiteNow['slug']) . (!empty($GLOBALS['nexa_in_site_admin']) ? ' (its own admin)' : ''),
        // Only network admins may open Network Admin; a subsite admin's
        // context chip simply points at their own dashboard.
        'link'    => $isNetAdminNow ? 'network.php' : 'index.php',
    ], 36);
}

add_menu_item('dashboard', [
    'name'    => 'plugins',
    'class'   => in_array(CURRENT_PAGE, ['plugins.php', 'plugin-install.php', 'plugin-editor.php', 'repositories.php', 'admin.php'], true) ? 'active' : '',
    'icon'    => 'fa fa-plug fa-fw',
    'link'    => '#',
    'label'   => ' Plugins<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            0 => ['label' => ' Installed Plugins', 'link' => 'plugins.php',        'icon' => 'fa fa-list fa-fw'],
            1 => ['label' => ' Upload Plugin',     'link' => 'plugin-install.php', 'icon' => 'fa fa-upload fa-fw'],
            2 => ['label' => ' Plugin Editor',     'link' => 'plugin-editor.php',  'icon' => 'fa fa-code fa-fw'],
            3 => ['label' => ' Repositories',      'link' => 'repositories.php',   'icon' => 'fa fa-cloud-download fa-fw'],
        ],
    ],
], 40);

add_menu_item('dashboard', [
    'name'    => 'customers',
    'class'   => in_array(CURRENT_PAGE, ['customers.php', 'add_customer.php', 'edit_customer.php'], true) ? 'active' : '',
    'icon'    => 'fa fa-address-book fa-fw',
    'link'    => '#',
    'label'   => ' Customers<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            0 => ['label' => ' All Customers', 'link' => 'customers.php',    'icon' => 'fa fa-list fa-fw'],
            1 => ['label' => ' Add Customer',  'link' => 'add_customer.php', 'icon' => 'fa fa-plus fa-fw'],
        ],
    ],
], 50);

add_menu_item('dashboard', [
    'name'    => 'users',
    'class'   => in_array(CURRENT_PAGE, ['admin_users.php', 'add_admin.php', 'edit_admin.php', 'profile.php'], true) ? 'active' : '',
    'icon'    => 'fa fa-users fa-fw',
    'link'    => '#',
    'label'   => ' Users<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            0 => ['label' => ' All Users',    'link' => 'admin_users.php', 'icon' => 'fa fa-users fa-fw'],
            1 => ['label' => ' Add New User', 'link' => 'add_admin.php',   'icon' => 'fa fa-plus fa-fw'],
            2 => ['label' => ' My Profile',   'link' => 'profile.php',     'icon' => 'fa fa-user fa-fw'],
        ],
    ],
], 60);

add_menu_item('dashboard', [
    'name'    => 'tools',
    'class'   => in_array(CURRENT_PAGE, ['database.php', 'backups.php', 'update.php'], true) ? 'active' : '',
    'icon'    => 'fa fa-database fa-fw',
    'link'    => '#',
    'label'   => ' Database &amp; Backups<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            0 => ['label' => ' Database Engine', 'link' => 'database.php', 'icon' => 'fa fa-database fa-fw'],
            1 => ['label' => ' Site Backups',    'link' => 'backups.php',  'icon' => 'fa fa-archive fa-fw'],
            2 => ['label' => ' System Update',   'link' => 'update.php',   'icon' => 'fa fa-refresh fa-fw'],
        ],
    ],
], 70);

// Grouped Plugin Screens: every enabled plugin's admin page in ONE place.
// (Plugins also add their own top-level items via the admin hook; this grouped
//  parent guarantees the screens are findable even with 20+ plugins enabled.)
(function (): void {
    global $active_plugins;
    $items = [];
    foreach ((array) ($active_plugins['admin'] ?? []) as $plug) {
        $slug = (string) ($plug['name'] ?? '');
        if ($slug === '') continue;
        $meta = di_read_plugin_manifest(nexa_resolve_plugin_path($slug), $slug);
        $items[] = ['label' => ' ' . ($meta['displayed-name'] ?? $slug), 'link' => 'admin.php?plugin_page=' . urlencode($slug), 'icon' => 'fa fa-plug fa-fw'];
    }
    if ($items) {
        add_menu_item('dashboard', [
            'name'    => 'plugin-screens',
            'class'   => (CURRENT_PAGE === 'admin.php') ? 'active' : '',
            'icon'    => 'fa fa-th-large fa-fw',
            'link'    => '#',
            'label'   => ' Plugin Screens<span class="fa arrow"></span>',
            'submenu' => ['class' => 'nav nav-second-level', 'item' => $items],
        ], 45);
    }
})();

add_menu_item('dashboard', [
    'name'    => 'settings',
    'class'   => (CURRENT_PAGE === 'settings.php') ? 'active' : '',
    'icon'    => 'fa fa-gear fa-fw',
    'link'    => '#',
    'label'   => ' Settings<span class="fa arrow"></span>',
    'submenu' => [
        'class' => 'nav nav-second-level',
        'item'  => [
            10 => ['label' => ' General',    'link' => 'settings.php?page=general'],
            20 => ['label' => ' Writing',    'link' => 'settings.php?page=writing'],
            30 => ['label' => ' Reading',    'link' => 'settings.php?page=reading'],
            40 => ['label' => ' Discussion', 'link' => 'settings.php?page=discussion'],
            50 => ['label' => ' Media',      'link' => 'settings.php?page=media'],
            60 => ['label' => ' Permalinks', 'link' => 'settings.php?page=permalink'],
            70 => ['label' => ' Password Policy', 'link' => 'settings.php?page=passwords'],
            80 => ['label' => ' Sessions & Login', 'link' => 'settings.php?page=sessions'],
        ],
    ],
], 90);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin &mdash; <?= esc_html(get_bloginfo('name')) ?></title>

    <link rel="stylesheet" href="<?= esc_url($nxAssetBase . 'css/bootstrap.min.css') ?>" />
    <link href="<?= esc_url($nxAssetBase . 'js/metisMenu/metisMenu.min.css') ?>" rel="stylesheet">
    <link href="<?= esc_url($nxAssetBase . 'css/sb-admin-2.css') ?>" rel="stylesheet">
    <link href="<?= esc_url($nxAssetBase . 'css/admin.css') ?>" rel="stylesheet">
    <link href="<?= esc_url($nxAssetBase . 'fonts/font-awesome/css/font-awesome.min.css') ?>" rel="stylesheet" type="text/css">
    <?php $nexaAdminTheme = function_exists('nexa_get_admin_theme') ? nexa_get_admin_theme() : 'default'; ?>
    <link href="<?= esc_url($nxAssetBase . 'themes/' . $nexaAdminTheme . '/theme.css') ?>" rel="stylesheet">
    <style>
      .navbar-brand { font-weight: 600; }
      .di-badge-driver { background:#337ab7; color:#fff; border-radius:3px; padding:2px 7px; font-size:11px; text-transform:uppercase; margin-left:6px; }
      #page-wrapper { min-height: calc(100vh - 51px); padding-bottom: 40px; }
      .table > tbody > tr > td { vertical-align: middle; }
    </style>
    <script src="<?= esc_url($nxAssetBase . 'js/jquery.min.js') ?>" type="text/javascript"></script>
    <?php up_head(); ?>
  </head>
  <body class="admin-theme-<?= esc_attr($nexaAdminTheme ?? 'default') ?>">
    <div id="wrapper">
      <?php if (!empty($GLOBALS['nexa_in_site_admin']) && ($msBannerSite = function_exists('nexa_current_site') ? nexa_current_site() : null)): ?>
        <div style="background:#14532d;color:#ecfdf5;padding:8px 16px;font-size:13px;">
          <i class="fa fa-globe fa-fw"></i>
          You are in the <strong><?= esc_html($msBannerSite['name'] ?? $msBannerSite['slug']) ?></strong> admin area
          (<code style="background:rgba(255,255,255,.15);color:#fff;"><?= ($p = trim((string) ($GLOBALS['nexa_site_admin_prefix'] ?? ''), '/')) !== '' ? '/' . esc_html($p) . '/admin' : '/admin on this host' ?></code>) —
          posts, pages, settings and uploads here belong <strong>only</strong> to this subsite.
          <a href="<?= esc_attr(nexa_multisite_site_public_url($msBannerSite)) ?>" target="_blank" style="color:#bbf7d0;text-decoration:underline;">Visit subsite</a>
          <?php if (!empty($_SESSION['nexa_network_admin'])): ?>
            · <a href="<?= esc_attr(rtrim(baseurl(), '/')) ?>/admin/" style="color:#bbf7d0;text-decoration:underline;">Main site admin</a>
          <?php endif; ?>
        </div>
      <?php elseif (($msCtxSite = function_exists('nexa_current_site') ? nexa_current_site() : null)): ?>
        <div style="background:#7c2d12;color:#fff7ed;padding:8px 16px;font-size:13px;">
          <i class="fa fa-globe fa-fw"></i>
          You are managing the <strong><?= esc_html($msCtxSite['name'] ?? $msCtxSite['slug']) ?></strong> subsite from the central admin —
          everything you edit here affects only that subsite.
          <a href="<?= esc_attr(function_exists('nexa_multisite_site_admin_url') ? nexa_multisite_site_admin_url($msCtxSite) : '#') ?>" style="color:#fed7aa;text-decoration:underline;">Open its own admin area</a>
          <?php if (!empty($_SESSION['nexa_network_admin'])): ?>
            · <a href="network.php" style="color:#fed7aa;text-decoration:underline;">Back to main site (Network)</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if (is_user_logged_in()): ?>
        <nav class="navbar navbar-default navbar-static-top" role="navigation" style="margin-bottom:0">
          <div class="navbar-header">
            <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
              <span class="sr-only">Toggle navigation</span>
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="index.php">
              <?= esc_html(get_bloginfo('name')) ?>
              <span class="di-badge-driver"><?= esc_html($GLOBALS['di_db_driver'] ?? ($config['database']['type'] ?? 'csv')) ?></span>
            </a>
          </div>

          <?php display_menu('nav_left'); ?>
          <?php display_menu('nav_right'); ?>

          <div class="navbar-default sidebar" role="navigation">
            <div class="sidebar-nav navbar-collapse">
              <?php display_menu('dashboard'); ?>
            </div>
          </div>
        </nav>
      <?php endif; ?>
