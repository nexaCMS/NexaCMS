<?php
/** Nexa Menu Organizer - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name' => 'nexa-menu-organizer', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-menu-organizer') ? 'active' : '', 'icon' => 'fa fa-sitemap fa-fw', 'label' => ' Menu Organizer', 'link' => 'admin.php?plugin_page=nexa-menu-organizer'], 70);
add_action('nexa_admin', 'nexa_mo_admin_render'); add_action('di_admin', 'nexa_mo_admin_render'); add_action('up_admin', 'nexa_mo_admin_render');
function nexa_mo_admin_render(): void {
    static $done = false; if ($done) return;
    if (($_GET['plugin_page'] ?? '') !== 'nexa-menu-organizer') return;
    $done = true;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        set_setting('menu_org_enabled', (string)($_POST['menu_org_enabled'] ?? '1'));
        $map = [];
        foreach ((array)($_POST['cat'] ?? []) as $slug => $cat) if (in_array($cat, nexa_mo_categories(), true)) $map[basename((string)$slug)] = $cat;
        set_setting('menu_org_map', maybe_serialize($map));
        echo '<div class="alert alert-success">Menu organization saved. Reload any admin page to see the new sidebar.</div>';
    }
    $enabled = (string) get_setting('menu_org_enabled','1') === '1';
    echo '<h2><i class="fa fa-sitemap"></i> Menu Organizer</h2><p>Plugin menus are grouped into category parents in the sidebar (core menus are untouched). Assign each plugin a category:</p>';
    echo '<form method="post">' . nexa_csrf_field() . '<div class="form-group"><label><input type="checkbox" name="menu_org_enabled" value="1"' . ($enabled ? ' checked' : '') . '> Enable menu organization</label></div>';
    echo '<table class="table table-striped"><thead><tr><th>Plugin</th><th>Detected Category</th><th>Assign Category</th></tr></thead><tbody>';
    updatepluginlist();
    foreach (get_table('plugins')['data'] as $r) {
        if (empty($r['name'])) continue;
        $slug = (string)$r['name']; $cur = nexa_mo_category_for($slug);
        echo '<tr><td><strong>' . esc_html($r['displayed-name'] ?: $slug) . '</strong><br><small>' . esc_html($slug) . '</small></td><td>' . esc_html($cur) . '</td><td><select name="cat[' . esc_attr($slug) . ']" class="form-control">';
        foreach (array_unique(array_merge(nexa_mo_categories(), [$cur])) as $c) echo '<option' . ($c === $cur ? ' selected' : '') . '>' . esc_html($c) . '</option>';
        echo '</select></td></tr>';
    }
    echo '</tbody></table><button class="btn btn-primary">Save Organization</button></form>';
}
