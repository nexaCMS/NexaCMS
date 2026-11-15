<?php
/**  Post Layouts — Admin UI. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-post-layouts','class'=>(($_GET['plugin_page']??'')==='nexa-post-layouts')?'active':'','icon'=>'fa fa-th-large fa-fw','label'=>' Post Layouts','link'=>'admin.php?plugin_page=nexa-post-layouts'], 65);
add_action('nexa_admin','nexa_layouts_admin_render'); add_action('di_admin','nexa_layouts_admin_render'); add_action('up_admin','nexa_layouts_admin_render');

function nexa_layouts_admin_render(): void {
    static $done = false; if ($done) return;
    if (($_GET['plugin_page'] ?? '') !== 'nexa-post-layouts') return;
    $done = true;
    nexa_layouts_ensure();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'save') {
            $row = [
                'name' => trim((string) ($_POST['name'] ?? '')) ?: 'Untitled layout',
                'target' => in_array($_POST['target'] ?? '', ['home', 'search', 'all'], true) ? (string) $_POST['target'] : 'all',
                'columns' => (string) max(0, min(4, (int) ($_POST['columns'] ?? 0))),
                'card_style' => in_array($_POST['card_style'] ?? '', ['theme', 'bordered', 'shadow', 'minimal'], true) ? (string) $_POST['card_style'] : 'theme',
                'before_content' => (string) ($_POST['before_content'] ?? ''),
                'insert_content' => (string) ($_POST['insert_content'] ?? ''),
                'insert_every' => (string) max(0, (int) ($_POST['insert_every'] ?? 0)),
                'insert_max' => (string) max(0, (int) ($_POST['insert_max'] ?? 0)),
                'after_content' => (string) ($_POST['after_content'] ?? ''),
                'active' => !empty($_POST['active']) ? '1' : '0',
            ];
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) { $row['id'] = $id; update_table('post_layouts', $row, 'update'); }
            else { $row['created_at'] = date('Y-m-d H:i:s'); update_table('post_layouts', $row, 'insert'); }
            if (function_exists('nexa_cache_purge')) nexa_cache_purge();
            echo '<div class="alert alert-success">Layout saved.</div>';
        } elseif ($do === 'del' && (int) ($_POST['id'] ?? 0) > 0) {
            delete_records('post_layouts', (int) $_POST['id']);
            if (function_exists('nexa_cache_purge')) nexa_cache_purge();
            echo '<div class="alert alert-success">Layout deleted.</div>';
        } elseif ($do === 'toggle' && (int) ($_POST['id'] ?? 0) > 0) {
            foreach (get_table('post_layouts')['data'] as $r) if ((int) $r['id'] === (int) $_POST['id']) {
                update_table('post_layouts', ['id' => (int) $r['id'], 'active' => ((string) ($r['active'] ?? '0') === '1' ? '0' : '1')], 'update');
            }
            if (function_exists('nexa_cache_purge')) nexa_cache_purge();
            echo '<div class="alert alert-success">Layout updated.</div>';
        }
    }

    $layouts = get_table('post_layouts')['data'];
    $edit = null;
    if (!empty($_GET['layout_edit'])) foreach ($layouts as $r) if ((int) $r['id'] === (int) $_GET['layout_edit']) $edit = $r;

    $themeRoot = dirname(__DIR__, 3) . '/content/themes';
    $supported = [];
    foreach (scandir($themeRoot) ?: [] as $t) {
        if ($t === '.' || $t === '..' || !is_file($themeRoot . '/' . $t . '/blog.php')) continue;
        if (str_contains((string) file_get_contents($themeRoot . '/' . $t . '/blog.php'), 'nexa_post_layout_after_item')) $supported[] = $t;
    }

    echo '<h2><i class="fa fa-th-large"></i> Post Layouts</h2>'
        . '<div class="alert alert-info">Create layouts for your post listings: a block <strong>before</strong> the list, an ad/shortcode block <strong>between posts</strong> (after every Nth post — e.g. an advert after posts 3, 6 and 9), a block <strong>after</strong> the list, plus columns and card styles. Content boxes accept HTML and shortcodes, so ad code and plugin blocks just paste in. '
        . 'Supported by: <strong>' . esc_html(implode(', ', $supported)) . '</strong>. One active layout per placement wins (a Home or Search layout beats an “Everywhere” one).</div>';

    echo '<div class="row"><div class="col-md-5"><div class="panel panel-default"><div class="panel-heading"><strong>' . ($edit ? 'Edit layout' : 'Create a layout') . '</strong></div><div class="panel-body">'
        . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">'
        . '<div class="form-group"><label>Layout name</label><input name="name" class="form-control" required value="' . esc_attr($edit['name'] ?? '') . '" placeholder="Homepage with ads"></div>'
        . '<div class="form-group"><label>Use on</label><select name="target" class="form-control">'
        . '<option value="all"' . (($edit['target'] ?? 'all') === 'all' ? ' selected' : '') . '>Everywhere (blog home + search)</option>'
        . '<option value="home"' . (($edit['target'] ?? '') === 'home' ? ' selected' : '') . '>Blog home / listings only</option>'
        . '<option value="search"' . (($edit['target'] ?? '') === 'search' ? ' selected' : '') . '>Search results only</option></select></div>'
        . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Columns per row</label><select name="columns" class="form-control">'
        . '<option value="0"' . ((int) ($edit['columns'] ?? 0) === 0 ? ' selected' : '') . '>Theme default (1)</option>'
        . '<option value="2"' . ((int) ($edit['columns'] ?? 0) === 2 ? ' selected' : '') . '>2 columns</option>'
        . '<option value="3"' . ((int) ($edit['columns'] ?? 0) === 3 ? ' selected' : '') . '>3 columns</option>'
        . '<option value="4"' . ((int) ($edit['columns'] ?? 0) === 4 ? ' selected' : '') . '>4 columns</option></select></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>Card style</label><select name="card_style" class="form-control">'
        . '<option value="theme"' . (($edit['card_style'] ?? 'theme') === 'theme' ? ' selected' : '') . '>Theme default</option>'
        . '<option value="bordered"' . (($edit['card_style'] ?? '') === 'bordered' ? ' selected' : '') . '>Bordered</option>'
        . '<option value="shadow"' . (($edit['card_style'] ?? '') === 'shadow' ? ' selected' : '') . '>Shadow</option>'
        . '<option value="minimal"' . (($edit['card_style'] ?? '') === 'minimal' ? ' selected' : '') . '>Minimal</option></select></div></div></div>'
        . '<div class="form-group"><label>Block before the list <small class="text-muted">(HTML/shortcodes)</small></label><textarea name="before_content" class="form-control" rows="3" style="font-family:monospace;" placeholder="<div class=\'promo\'>Welcome banner&hellip;</div>">' . esc_textarea($edit['before_content'] ?? '') . '</textarea></div>'
        . '<div class="form-group"><label>Block between posts <small class="text-muted">(your ad code / shortcode)</small></label><textarea name="insert_content" class="form-control" rows="3" style="font-family:monospace;" placeholder="<div class=\'ad-slot\'>Your advert here</div>">' . esc_textarea($edit['insert_content'] ?? '') . '</textarea></div>'
        . '<div class="row"><div class="col-md-6"><div class="form-group"><label>Insert after every &hellip; posts</label><input type="number" name="insert_every" class="form-control" min="0" value="' . (int) ($edit['insert_every'] ?? 3) . '"><p class="help-block">3 = after posts 3, 6, 9&hellip; 0 = never.</p></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>Max insertions per page</label><input type="number" name="insert_max" class="form-control" min="0" value="' . (int) ($edit['insert_max'] ?? 0) . '"><p class="help-block">0 = unlimited.</p></div></div></div>'
        . '<div class="form-group"><label>Block after the list</label><textarea name="after_content" class="form-control" rows="3" style="font-family:monospace;">' . esc_textarea($edit['after_content'] ?? '') . '</textarea></div>'
        . '<label style="font-weight:normal;"><input type="checkbox" name="active" value="1" ' . (($edit['active'] ?? '1') === '1' ? 'checked' : '') . '> Active</label><br>'
        . '<button class="btn btn-primary">' . ($edit ? 'Save Layout' : 'Create Layout') . '</button> '
        . ($edit ? '<a class="btn btn-default" href="admin.php?plugin_page=nexa-post-layouts">Cancel edit</a>' : '')
        . '</form></div></div></div>';

    echo '<div class="col-md-7"><div class="panel panel-default"><div class="panel-heading"><strong>Your layouts</strong> <span class="badge">' . count($layouts) . '</span></div><div class="panel-body">';
    if (!$layouts) echo '<p class="text-muted">No layouts yet. Create one, activate it, then visit your blog home to see it.</p>';
    foreach ($layouts as $r) {
        echo '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-bottom:10px;">'
            . '<strong>' . esc_html($r['name']) . '</strong> '
            . '<span class="label label-info">' . esc_html($r['target']) . '</span> '
            . '<span class="label label-' . ((string) ($r['active'] ?? '0') === '1' ? 'success' : 'default') . '">' . ((string) ($r['active'] ?? '0') === '1' ? 'active' : 'off') . '</span> '
            . '<span class="label label-default">' . (int) ($r['columns'] ?: 1) . ' col</span> '
            . '<span class="label label-default">' . esc_html($r['card_style'] ?? 'theme') . '</span>'
            . '<div class="text-muted" style="font-size:12px;margin:4px 0;">Insert block after every ' . (int) ($r['insert_every'] ?? 0) . ' posts' . ((int) ($r['insert_max'] ?? 0) > 0 ? ', max ' . (int) $r['insert_max'] : '') . '</div>'
            . '<a class="btn btn-xs btn-primary" href="admin.php?plugin_page=nexa-post-layouts&layout_edit=' . (int) $r['id'] . '">Edit</a> '
            . '<form method="post" style="display:inline;">' . nexa_csrf_field() . '<input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="btn btn-xs btn-default">' . ((string) ($r['active'] ?? '0') === '1' ? 'Deactivate' : 'Activate') . '</button></form> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this layout?\');">' . nexa_csrf_field() . '<input type="hidden" name="do" value="del"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="btn btn-xs btn-danger">Delete</button></form>'
            . '</div>';
    }
    echo '</div></div></div></div>';
}
