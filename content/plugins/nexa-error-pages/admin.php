<?php
/**  Error Pages — Admin UI. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-error-pages','class'=>(($_GET['plugin_page']??'')==='nexa-error-pages')?'active':'','icon'=>'fa fa-exclamation-triangle fa-fw','label'=>' Error Pages','link'=>'admin.php?plugin_page=nexa-error-pages'], 64);
add_action('nexa_admin','nexa_errp_admin_render'); add_action('di_admin','nexa_errp_admin_render'); add_action('up_admin','nexa_errp_admin_render');

function nexa_errp_admin_render(): void {
    static $done = false; if ($done) return;
    if (($_GET['plugin_page'] ?? '') !== 'nexa-error-pages') return;
    $done = true;

    $statuses = [404 => '404 — Not Found', 403 => '403 — Forbidden', 500 => '500 — Server Error', 503 => 'Offline / Maintenance (503)'];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf() && ($_POST['do'] ?? '') === 'save_errp') {
        set_setting('errp_offline_enabled', !empty($_POST['offline_enabled']) ? '1' : '0', 'Offline Mode Enabled');
        set_setting('errp_offline_retry', (string) max(60, (int) ($_POST['offline_retry'] ?? 3600)), 'Offline Retry-After Seconds');
        foreach ($statuses as $code => $label) {
            $k = nexa_errp_key($code);
            set_setting('errp_' . $k . '_title', trim((string) ($_POST['title_' . $k] ?? '')), 'Error ' . $label . ' Title');
            set_setting('errp_' . $k . '_message', trim((string) ($_POST['message_' . $k] ?? '')), 'Error ' . $label . ' Message');
            set_setting('errp_' . $k . '_html', trim((string) ($_POST['html_' . $k] ?? '')), 'Error ' . $label . ' Custom HTML');
            set_setting('errp_' . $k . '_search', !empty($_POST['search_' . $k]) ? '1' : '0', 'Error ' . $label . ' Show Search');
            set_setting('errp_' . $k . '_recent', !empty($_POST['recent_' . $k]) ? '1' : '0', 'Error ' . $label . ' Show Recent Posts');
        }
        if (function_exists('nexa_cache_purge')) nexa_cache_purge();
        echo '<div class="alert alert-success">Error pages saved.</div>';
    }

    $activeTheme = function_exists('nexa_active_theme_slug') ? nexa_active_theme_slug() : (string) get_setting('template', 'multi');
    $themeRoot = dirname(__DIR__, 3) . '/content/themes';
    $themeRows = [];
    foreach (scandir($themeRoot) ?: [] as $t) {
        if ($t === '.' || $t === '..' || !is_dir($themeRoot . '/' . $t)) continue;
        $files = [];
        foreach (['404.php', '403.php', '500.php', '503.php', 'offline.php', 'error.php'] as $f) if (is_file($themeRoot . '/' . $t . '/' . $f)) $files[] = $f;
        $themeRows[$t] = $files;
    }
    $activeHas = !empty($themeRows[$activeTheme]);

    echo '<h2><i class="fa fa-exclamation-triangle"></i> Custom Error Pages</h2>';
    echo '<div class="alert alert-info"><strong>How it works:</strong> if the active theme ships its own error templates (<code>404.php</code>, <code>error.php</code>&hellip;), those designs are used. For themes <em>without</em> them, the pages you create here are shown instead, inside the theme\'s header and footer. '
        . ($activeHas ? 'Your active theme (<strong>' . esc_html($activeTheme) . '</strong>) has its own error templates, so these settings will apply when you switch to a theme without them (and to its offline page if it lacks <code>offline.php</code>/<code>503.php</code>).' : 'Your active theme (<strong>' . esc_html($activeTheme) . '</strong>) has <strong>no</strong> error templates — the pages below are live on your site now.')
        . '</div>';

    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Theme error-template support</strong></div><div class="panel-body"><table class="table table-striped"><thead><tr><th>Theme</th><th>Error templates found</th></tr></thead><tbody>';
    foreach ($themeRows as $t => $files) {
        echo '<tr' . ($t === $activeTheme ? ' class="success"' : '') . '><td>' . esc_html($t) . ($t === $activeTheme ? ' <span class="label label-success">active</span>' : '') . '</td><td>' . ($files ? esc_html(implode(', ', $files)) : '<span class="text-muted">none — uses these plugin pages</span>') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="save_errp">';

    echo '<div class="panel panel-warning"><div class="panel-heading"><strong><i class="fa fa-power-off"></i> Offline / maintenance mode</strong></div><div class="panel-body">'
        . '<label style="font-weight:normal;"><input type="checkbox" name="offline_enabled" value="1" ' . ((string) get_setting('errp_offline_enabled', '0') === '1' ? 'checked' : '') . '> <strong>Take the site offline</strong> — visitors see the offline page below (HTTP 503). Signed-in admins keep full access, and the admin login always stays reachable.</label>'
        . '<div class="form-inline" style="margin-top:8px;"><label>Ask search engines to retry after <input type="number" name="offline_retry" class="form-control" style="width:110px;" min="60" step="60" value="' . (int) get_setting('errp_offline_retry', '3600') . '"> seconds</label> '
        . '<a class="btn btn-default btn-sm" target="_blank" href="' . esc_attr(home_url('?nexa_error=503')) . '">Preview offline page</a></div>'
        . '</div></div>';

    foreach ($statuses as $code => $label) {
        $k = nexa_errp_key($code);
        $d = nexa_errp_defaults($code);
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . esc_html($label) . '</strong> '
            . '<a class="btn btn-xs btn-default pull-right" target="_blank" href="' . esc_attr($code === 404 ? home_url('this-page-does-not-exist-' . bin2hex(random_bytes(3))) : home_url('?nexa_error=' . $code)) . '">Preview</a></div><div class="panel-body">'
            . '<div class="form-group"><label>Title</label><input name="title_' . $k . '" class="form-control" value="' . esc_attr((string) get_setting('errp_' . $k . '_title', '')) . '" placeholder="' . esc_attr($d['title']) . '"></div>'
            . '<div class="form-group"><label>Message</label><textarea name="message_' . $k . '" class="form-control" rows="3" placeholder="' . esc_attr($d['message']) . '">' . esc_textarea((string) get_setting('errp_' . $k . '_message', '')) . '</textarea></div>'
            . '<div class="form-group"><label>Custom HTML / shortcodes <small class="text-muted">(optional — replaces the message; e.g. an image, a contact form shortcode, or a signup form)</small></label><textarea name="html_' . $k . '" class="form-control" rows="4" style="font-family:monospace;">' . esc_textarea((string) get_setting('errp_' . $k . '_html', '')) . '</textarea></div>';
        if ($code === 404) {
            echo '<label style="font-weight:normal;margin-right:16px;"><input type="checkbox" name="search_' . $k . '" value="1" ' . ((string) get_setting('errp_' . $k . '_search', '1') === '1' ? 'checked' : '') . '> Show a search box</label>'
                . '<label style="font-weight:normal;"><input type="checkbox" name="recent_' . $k . '" value="1" ' . ((string) get_setting('errp_' . $k . '_recent', '1') === '1' ? 'checked' : '') . '> Show recent posts</label>';
        }
        echo '</div></div>';
    }

    echo '<button class="btn btn-primary">Save Error Pages</button></form>';
    echo '<p class="text-muted" style="margin-top:14px;">Tip: themes with their own templates can be customised in <strong>Appearance &gt; Live Theme Editor</strong> or by editing the theme\'s <code>error.php</code>. On Apache, the bundled <code>.htaccess</code> already routes real 403/500 errors to these pages.</p>';
}
