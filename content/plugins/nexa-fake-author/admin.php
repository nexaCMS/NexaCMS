<?php
/** Nexa Fake Author — pen-name registry + usage overview. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-fake-author', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-fake-author') ? 'active' : '',
    'icon' => 'fa fa-user-secret fa-fw', 'label' => ' Fake Authors', 'link' => 'admin.php?plugin_page=nexa-fake-author',
], 50);
add_action('nexa_admin', 'nexa_fa_admin_render');

function nexa_fa_admin_render(): void {
    if (($_GET['plugin_page'] ?? '') !== 'nexa-fake-author') return;
    nexa_fa_ensure();
    echo '<h2><i class="fa fa-user-secret"></i> Nexa Fake Author <small>pen names without user accounts</small></h2>';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'add') {
            nexa_fa_register((string) ($_POST['name'] ?? ''), (string) ($_POST['url'] ?? ''), (string) ($_POST['bio'] ?? ''));
            echo '<div class="alert alert-success">Pen name saved.</div>';
        } elseif ($do === 'del' && !empty($_POST['id'])) {
            delete_records('fake_authors', (int) $_POST['id']);
            echo '<div class="alert alert-info">Pen name removed from the registry. Posts already using it keep the byline text (edit a post to change it).</div>';
        }
    }

    echo '<div class="row"><div class="col-md-5"><div class="panel panel-default"><div class="panel-heading">Add a pen name</div><div class="panel-body">'
        . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="add">'
        . '<div class="form-group"><label>Name (shown in bylines)</label><input type="text" name="name" class="form-control" placeholder="e.g. Staff Writer" required></div>'
        . '<div class="form-group"><label>Profile URL (optional)</label><input type="url" name="url" class="form-control" placeholder="https://..."></div>'
        . '<div class="form-group"><label>Bio (optional)</label><textarea name="bio" class="form-control" rows="2"></textarea></div>'
        . '<button class="btn btn-primary"><i class="fa fa-plus"></i> Add Pen Name</button></form>'
        . '<hr><p class="text-muted" style="margin:0;"><small><i class="fa fa-shield"></i> <strong>Why fake authors?</strong> A pen name has no login, no password and no account to brute-force — and your real usernames never appear in bylines, RSS feeds or page source. Assign one from the <strong>Author</strong> panel while editing any post or page.</small></p>'
        . '</div></div></div>';

    // Registry with usage counts
    $counts = [];
    foreach (get_table('posts')['data'] as $p) {
        $f = (string) ($p['fake_author'] ?? '');
        if ($f !== '') $counts[$f] = ($counts[$f] ?? 0) + 1;
    }
    echo '<div class="col-md-7"><div class="panel panel-default"><div class="panel-heading">Pen-name registry</div><div class="panel-body">';
    $reg = nexa_fa_registry();
    if (!$reg) {
        echo '<p class="text-muted" style="margin:0;">No pen names yet — add one, or type a new name in the Author panel of any post.</p>';
    } else {
        echo '<table class="table table-striped" style="margin:0;"><thead><tr><th>Name</th><th>URL</th><th>Posts</th><th></th></tr></thead><tbody>';
        foreach ($reg as $r) {
            echo '<tr><td><strong>' . esc_html((string) $r['name']) . '</strong>' . (($r['bio'] ?? '') !== '' ? '<br><small class="text-muted">' . esc_html((string) $r['bio']) . '</small>' : '') . '</td>'
                . '<td>' . (($r['url'] ?? '') !== '' ? '<a href="' . esc_url((string) $r['url']) . '" target="_blank">' . esc_html((string) $r['url']) . '</a>' : '<span class="text-muted">—</span>') . '</td>'
                . '<td>' . (int) ($counts[(string) $r['name']] ?? 0) . '</td>'
                . '<td class="text-right"><form method="post" style="display:inline;" onsubmit="return confirm(\'Remove this pen name from the registry?\');">' . nexa_csrf_field()
                . '<input type="hidden" name="do" value="del"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button></form></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div></div></div>';

    // Usage list
    $using = array_values(array_filter(get_table('posts')['data'], static fn($p) => (string) ($p['fake_author'] ?? '') !== ''));
    echo '<div class="panel panel-default"><div class="panel-heading">Posts &amp; pages using a pen name (' . count($using) . ')</div><div class="panel-body">';
    if (!$using) {
        echo '<p class="text-muted" style="margin:0;">None yet.</p>';
    } else {
        echo '<table class="table table-striped" style="margin:0;"><thead><tr><th>Title</th><th>Pen name (public)</th><th>Real author (private)</th><th></th></tr></thead><tbody>';
        foreach (array_slice($using, 0, 25) as $p) {
            echo '<tr><td>' . esc_html((string) $p['post_title']) . '</td><td><span class="label label-success">' . esc_html((string) $p['fake_author']) . '</span></td>'
                . '<td>' . esc_html((string) ($p['post_real_author'] ?? '')) . '</td>'
                . '<td class="text-right"><a class="btn btn-xs btn-primary" href="edit_post.php?id=' . (int) $p['id'] . '&type=' . esc_attr((string) ($p['post_type'] ?? 'post')) . '">Edit</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
}
