<?php
/** Repository Hub — Admin UI (choose what this site publishes as a repository). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name' => 'nexa-repo-hub', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-repo-hub') ? 'active' : '', 'icon' => 'fa fa-cloud-upload fa-fw', 'label' => ' Repository Hub', 'link' => 'admin.php?plugin_page=nexa-repo-hub'], 64);
add_action('nexa_admin', 'nexa_repohub_admin_render'); add_action('di_admin', 'nexa_repohub_admin_render'); add_action('up_admin', 'nexa_repohub_admin_render');

function nexa_repohub_sniff_package(string $tmpPath, string $origName): ?array {
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) return null;
    $pluginJson = null; $themePrefix = null; $styleHead = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if (preg_match('#(^|/)plugin.json$#', $n)) $pluginJson = $n;
        if (preg_match('#(^|/)index.php$#', $n)) $themePrefix = substr($n, 0, -strlen('index.php'));
        if (preg_match('#(^|/)style.css$#', $n) && $styleHead === '') $styleHead = (string) $zip->getFromName($n);
    }
    $zip->close();
    $fileSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', pathinfo($origName, PATHINFO_FILENAME));
    if ($pluginJson !== null) {
        $slug = ($pluginJson === 'plugin.json') ? $fileSlug : explode('/', $pluginJson)[0];
        $ver = '';
        $z2 = new ZipArchive();
        if ($z2->open($tmpPath) === true) { $m = json_decode((string) $z2->getFromName($pluginJson), true); if (is_array($m)) $ver = (string) ($m['version'] ?? ''); $z2->close(); }
        return ['type' => 'plugin', 'slug' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $slug), 'version' => $ver];
    }
    if ($themePrefix !== null && $styleHead !== '') {
        $slug = $themePrefix === '' ? $fileSlug : explode('/', rtrim($themePrefix, '/'))[0];
        $ver = preg_match('/Version:\s*([0-9][^\s*]*)/i', $styleHead, $vm) ? trim($vm[1]) : '';
        return ['type' => 'theme', 'slug' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $slug), 'version' => $ver];
    }
    return null;
}

function nexa_repohub_maybe_publish(string $type, string $slug, string $wantVersion = ''): void {
    $dir = $type === 'plugin' ? nexa_plugins_dir() . '/' . $slug : nexa_themes_dir() . '/' . $slug;
    if ($slug === '' || !is_dir($dir)) return;
    $meta = $type === 'plugin' ? di_read_plugin_manifest($dir, $slug) : nexa_read_theme_manifest($dir, $slug);
    $instV = trim((string) ($meta['version'] ?? ''));
    if ($wantVersion !== '' && $instV !== '' && $instV !== $wantVersion) return; // replace not confirmed yet
    $pub = nexa_repohub_published();
    $key = $type . ':' . $slug;
    if (!in_array($key, $pub, true)) {
        $pub[] = $key;
        set_setting('repohub_published', json_encode($pub), 'Repository Hub Published Packages');
    }
    if (function_exists('updatepluginlist')) updatepluginlist();
    echo '<div class="alert alert-success"><i class="fa fa-cloud-upload"></i> <strong>' . esc_html($slug) . '</strong> is now live in your repository' . ($instV !== '' ? ' (v' . esc_html($instV) . ')' : '') . ' &mdash; other sites see it immediately.</div>';
}

function nexa_repohub_admin_render(): void {
    static $done = false; if ($done) return;
    if (($_GET['plugin_page'] ?? '') !== 'nexa-repo-hub') return;
    $done = true;

    $msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $hubAction = (string) ($_POST['hub_action'] ?? '');
        if (isset($_POST['nexa_pkg_action']) && !$msSite) {
            // Resume/cancel an overwrite confirmation staged by upload().
            $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['nexa_pkg_token'] ?? ''));
            $metaFile = dirname(__DIR__, 3) . '/content/temp_uploads/pending-' . $token . '.json';
            $meta = is_file($metaFile) ? json_decode((string) file_get_contents($metaFile), true) : null;
            $type = is_array($meta) && in_array($meta['type'] ?? '', ['plugin', 'theme'], true) ? (string) $meta['type'] : 'plugin';
            upload($type);
            if (($_POST['nexa_pkg_action'] ?? '') === 'confirm_replace' && is_array($meta)) {
                nexa_repohub_maybe_publish($type, (string) ($meta['slug'] ?? ''));
            }
        } elseif ($hubAction === 'upload_pkg' && !$msSite) {
            if (empty($_FILES['hub_pkg']['tmp_name']) || !is_uploaded_file((string) $_FILES['hub_pkg']['tmp_name'])) {
                echo '<div class="alert alert-danger">No valid .zip archive selected.</div>';
            } else {
                $sniff = nexa_repohub_sniff_package((string) $_FILES['hub_pkg']['tmp_name'], basename((string) $_FILES['hub_pkg']['name']));
                if ($sniff === null) {
                    echo '<div class="alert alert-danger">Could not tell whether that .zip is a NexaCMS plugin (plugin.json) or theme (index.php + style.css). Nothing was installed.</div>';
                } else {
                    $_FILES[$sniff['type']] = $_FILES['hub_pkg'];
                    upload($sniff['type']);
                    nexa_repohub_maybe_publish($sniff['type'], $sniff['slug'], $sniff['version']);
                }
            }
        } elseif ($hubAction === 'settings') {
            set_setting('repohub_name', trim((string) ($_POST['repohub_name'] ?? '')), 'Repository Hub Name');
            $pub = array_values(array_map('strval', (array) ($_POST['published'] ?? [])));
            set_setting('repohub_published', json_encode($pub), 'Repository Hub Published Packages');
            echo '<div class="alert alert-success">Repository Hub settings saved. ' . count($pub) . ' package(s) are now published.</div>';
        }
    }

    $published = nexa_repohub_published();
    $indexUrl = home_url('?nexa_repo=1');
    echo '<h2><i class="fa fa-cloud-upload"></i> Repository Hub</h2>'
       . '<div class="alert alert-info">This site can act as a <strong>plugin/theme repository</strong> for other NexaCMS sites. On another site, go to <strong>Plugins &rarr; Repositories &rarr; Add</strong>, choose <em>NexaCMS hub site</em> and enter this site\'s address: <code>' . esc_html(rtrim(baseurl(), '/')) . '</code><br>Repo index (JSON): <a href="' . esc_url($indexUrl) . '" target="_blank"><code>' . esc_html($indexUrl) . '</code></a>. Only the packages you tick below are served — nothing is published by default.</div>'
       . ($msSite
            ? '<div class="alert alert-warning">You are managing a subsite. A repository is hosted from the <strong>main site</strong> (it serves the shared packages) &mdash; switch to the main site admin to upload and publish packages.</div>'
            : '<div class="panel panel-primary" style="max-width:860px;"><div class="panel-heading"><strong><i class="fa fa-upload"></i> Upload &amp; Publish a Package</strong></div><div class="panel-body">'
              . '<form method="post" enctype="multipart/form-data"><input type="hidden" name="hub_action" value="upload_pkg">'
              . '<div class="form-group"><label>Plugin or theme .zip</label><input type="file" name="hub_pkg" accept=".zip" class="form-control" required></div>'
              . '<button class="btn btn-success"><i class="fa fa-cloud-upload"></i> Upload &amp; Publish</button></form>'
              . '<p class="text-muted" style="margin-top:8px;">The package type is detected automatically. It is installed into the shared folder and <strong>published to your repository immediately</strong> &mdash; no second step. Re-uploading a newer version asks before overwriting (as everywhere), and once confirmed the repository serves the new version at once, flagged as an update on every site that has the old one. Add a <code>screenshot.png</code>/<code>icon.png</code> inside the package to give it artwork.</p></div></div>')
       . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="hub_action" value="settings">'
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
