<?php
/** Repository Hub — Admin UI (choose what this site publishes as a repository). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name' => 'nexa-repo-hub', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-repo-hub') ? 'active' : '', 'icon' => 'fa fa-cloud-upload fa-fw', 'label' => ' Repository Hub', 'link' => 'admin.php?plugin_page=nexa-repo-hub'], 64);
add_action('nexa_admin', 'nexa_repohub_admin_render'); add_action('di_admin', 'nexa_repohub_admin_render'); add_action('up_admin', 'nexa_repohub_admin_render');

function nexa_repohub_admin_render(): void {
    static $done = false; if ($done) return;
    if (($_GET['plugin_page'] ?? '') !== 'nexa-repo-hub') return;
    $done = true;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        set_setting('repohub_name', trim((string) ($_POST['repohub_name'] ?? '')), 'Repository Hub Name');
        $pub = array_values(array_map('strval', (array) ($_POST['published'] ?? [])));
        set_setting('repohub_published', json_encode($pub), 'Repository Hub Published Packages');
        echo '<div class="alert alert-success">Repository Hub settings saved. ' . count($pub) . ' package(s) are now published.</div>';
    }

    $published = nexa_repohub_published();
    $indexUrl = home_url('?nexa_repo=1');
    echo '<h2><i class="fa fa-cloud-upload"></i> Repository Hub</h2>'
       . '<div class="alert alert-info">This site can act as a <strong>plugin/theme repository</strong> for other NexaCMS sites. On another site, go to <strong>Plugins &rarr; Repositories &rarr; Add</strong>, choose <em>NexaCMS hub site</em> and enter this site\'s address: <code>' . esc_html(rtrim(baseurl(), '/')) . '</code><br>Repo index (JSON): <a href="' . esc_url($indexUrl) . '" target="_blank"><code>' . esc_html($indexUrl) . '</code></a>. Only the packages you tick below are served — nothing is published by default.</div>'
       . '<form method="post">' . nexa_csrf_field()
       . '<div class="form-group" style="max-width:520px;"><label>Repository display name</label><input name="repohub_name" class="form-control" value="' . esc_attr((string) get_setting('repohub_name', '')) . '" placeholder="' . esc_attr(get_bloginfo('name') . ' Repository') . '"></div>'
       . '<table class="table table-striped" style="max-width:860px;"><thead><tr><th></th><th>Package</th><th>Type</th><th>Version</th><th>Download URL</th></tr></thead><tbody>';
    foreach (nexa_repohub_all_packages() as $p) {
        $key = $p['type'] . ':' . $p['slug'];
        $dl = home_url('?nexa_repo=download&type=' . $p['type'] . '&slug=' . urlencode($p['slug']));
        echo '<tr><td><input type="checkbox" name="published[]" value="' . esc_attr($key) . '"' . (in_array($key, $published, true) ? ' checked' : '') . '></td>'
           . '<td><strong>' . esc_html($p['name']) . '</strong> <code>' . esc_html($p['slug']) . '</code></td>'
           . '<td>' . esc_html($p['type']) . '</td><td>' . esc_html($p['version']) . '</td>'
           . '<td style="font-size:11px;word-break:break-all;"><code>' . esc_html($dl) . '</code></td></tr>';
    }
    echo '</tbody></table><button class="btn btn-primary"><i class="fa fa-save"></i> Save Repository Settings</button></form>'
       . '<p class="text-muted" style="margin-top:10px;">Published packages are zipped on the fly from this install\'s shared <code>content/plugins</code> / <code>content/themes</code> folders, so updating a plugin here updates what the repository serves. Per-subsite private copies are never served.</p>';
}
