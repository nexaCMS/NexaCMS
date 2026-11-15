<?php
/**
 * NexaCMS Navigation Menus Manager (admin/menus.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

di_ensure_menus_seeded();
$menus = get_table('menus')['data'];
$selectedMenuId = (string) ($_REQUEST['menu_id'] ?? ($menus[0]['id'] ?? '1'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $act = $_POST['menu_action'] ?? '';
    if ($act === 'add_item') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $link  = trim((string) ($_POST['link'] ?? '#'));
        $order = (string) ((int) ($_POST['order'] ?? 1));
        if ($label !== '') {
            update_table('menu_items', [
                'label'      => $label,
                'link'       => $link !== '' ? $link : '#',
                'title'      => $label,
                'parent'     => (string) ((int) ($_POST['parent'] ?? 0)),
                'menu'       => $selectedMenuId,
                'order'      => $order,
                'created_at' => date('Y-m-d H:i:s'),
            ], 'insert');
            $_SESSION['success'] = 'Menu item added.';
        }
    } elseif ($act === 'create_menu') {
        $name = trim((string) ($_POST['new_menu_name'] ?? ''));
        $loc  = trim((string) ($_POST['new_menu_location'] ?? ''));
        if ($name !== '') {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: ('menu-' . time());
            $newId = update_table('menus', [
                'name' => $slug, 'displayed-text' => $name,
                'created_at' => date('Y-m-d H:i:s'), 'created_by' => 'Admin', 'function' => $loc,
            ], 'insert');
            $_SESSION['success'] = "Alternate menu '{$name}' created." . ($loc !== '' ? " Assigned to location '{$loc}'." : '');
            header('Location: menus.php?menu_id=' . urlencode((string) $newId));
            exit;
        }
    } elseif ($act === 'set_location' && !empty($_POST['menu_id'])) {
        update_table('menus', ['id' => (string) $_POST['menu_id'], 'function' => trim((string) ($_POST['menu_location'] ?? ''))], 'update');
        $_SESSION['success'] = 'Menu location updated. Themes render it via up_nav_menu with that theme_location.';
    } elseif ($act === 'duplicate_menu') {
        $src = null;
        foreach ($menus as $m) { if ((string) $m['id'] === $selectedMenuId) $src = $m; }
        if ($src !== null) {
            $base = (string) ($src['name'] ?? 'menu');
            $newId = update_table('menus', [
                'name' => $base . '-alt', 'displayed-text' => (($src['displayed-text'] ?? $base) . ' (Alternate)'),
                'created_at' => date('Y-m-d H:i:s'), 'created_by' => 'Admin', 'function' => '',
            ], 'insert');
            foreach (get_table('menu_items')['data'] as $it) {
                if ((string) ($it['menu'] ?? '') !== $selectedMenuId) continue;
                $copy = $it; unset($copy['id']); $copy['menu'] = (string) $newId;
                update_table('menu_items', $copy, 'insert');
            }
            $_SESSION['success'] = 'Menu duplicated as an alternate. Assign it a location when ready.';
            header('Location: menus.php?menu_id=' . urlencode((string) $newId));
            exit;
        }
    } elseif ($act === 'delete_menu') {
        if (count($menus) > 1) {
            foreach (get_table('menu_items')['data'] as $it) {
                if ((string) ($it['menu'] ?? '') === $selectedMenuId) delete_records('menu_items', $it['id']);
            }
            delete_records('menus', $selectedMenuId);
            $_SESSION['info'] = 'Menu and its items deleted.';
            header('Location: menus.php');
            exit;
        } else {
            $_SESSION['info'] = 'You cannot delete the only menu.';
        }
    } elseif ($act === 'move_item' && !empty($_POST['item_id'])) {
        $siblings = array_values(array_filter(get_table('menu_items', 'order ASC')['data'],
            static fn($i) => (string) ($i['menu'] ?? '') === $selectedMenuId));
        $idx = null;
        foreach ($siblings as $k => $sib) { if ((string) $sib['id'] === (string) $_POST['item_id']) { $idx = $k; break; } }
        $dir = (($_POST['dir'] ?? 'up') === 'down') ? 1 : -1;
        if ($idx !== null && isset($siblings[$idx + $dir])) {
            $a = $siblings[$idx]; $b = $siblings[$idx + $dir];
            update_table('menu_items', ['id' => $a['id'], 'order' => (string) ($b['order'] ?? ($idx + $dir + 1))], 'update');
            update_table('menu_items', ['id' => $b['id'], 'order' => (string) ($a['order'] ?? ($idx + 1))], 'update');
            $_SESSION['success'] = 'Menu item moved.';
        }
    } elseif ($act === 'delete_item' && !empty($_POST['item_id'])) {
        delete_records('menu_items', $_POST['item_id']);
        $_SESSION['info'] = 'Menu item removed.';
    } elseif ($act === 'save_orders' && !empty($_POST['item_label']) && is_array($_POST['item_label'])) {
        foreach ($_POST['item_label'] as $itemId => $lbl) {
            update_table('menu_items', [
                'id'         => $itemId,
                'label'      => trim((string) $lbl),
                'link'       => trim((string) ($_POST['item_link'][$itemId] ?? '#')),
                'order'      => (string) ((int) ($_POST['item_order'][$itemId] ?? 1)),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'update');
        }
        $_SESSION['success'] = 'Navigation menu saved!';
    }
    header('Location: menus.php?menu_id=' . urlencode($selectedMenuId));
    exit;
}

$allItems = get_table('menu_items', 'order ASC')['data'];
$menuItems = array_values(array_filter($allItems, static fn($i) => (string) ($i['menu'] ?? '') === $selectedMenuId));
$publishedContent = array_values(array_filter(get_table('posts')['data'], static fn($p) => ($p['post_status'] ?? '') === 'published'));

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row">
    <div class="col-lg-12">
      <h1 class="page-header">Navigation Menus</h1>
    </div>
  </div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>

  <?php $selectedMenu = null; foreach ($menus as $mm) { if ((string) $mm['id'] === $selectedMenuId) $selectedMenu = $mm; } ?>
  <div class="panel panel-default">
    <div class="panel-body">
      <form method="get" action="menus.php" class="form-inline" style="display:inline-block;margin-right:10px;">
        <label>Menu:</label>
        <select name="menu_id" class="form-control" onchange="this.form.submit()">
          <?php foreach ($menus as $mm): ?>
            <option value="<?= esc_attr($mm['id']) ?>" <?= (string) $mm['id'] === $selectedMenuId ? 'selected' : '' ?>><?= esc_html($mm['displayed-text'] ?? $mm['name']) ?><?= !empty($mm['function']) ? ' [' . esc_html($mm['function']) . ']' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <form method="post" action="menus.php" class="form-inline" style="display:inline-block;margin-right:10px;">
        <?= di_csrf_field() ?>
        <input type="hidden" name="menu_action" value="set_location">
        <input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">
        <label>Theme location:</label>
        <select name="menu_location" class="form-control">
          <?php foreach (['' => '(none)', 'primary' => 'primary — top navigation', 'footer' => 'footer — footer links', 'sidebar' => 'sidebar — sidebar widget', 'topbar' => 'topbar — utility strip'] as $lv => $ll): ?>
            <option value="<?= esc_attr($lv) ?>" <?= (string) ($selectedMenu['function'] ?? '') === $lv ? 'selected' : '' ?>><?= esc_html($ll) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-default btn-sm">Assign</button>
      </form>
      <form method="post" action="menus.php" style="display:inline-block;margin-right:10px;">
        <?= di_csrf_field() ?>
        <input type="hidden" name="menu_action" value="duplicate_menu">
        <input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">
        <button class="btn btn-default btn-sm"><i class="fa fa-copy"></i> Duplicate as Alternate</button>
      </form>
      <?php if (count($menus) > 1): ?>
      <form method="post" action="menus.php" style="display:inline-block;" onsubmit="return confirm('Delete this menu and all its items?');">
        <?= di_csrf_field() ?>
        <input type="hidden" name="menu_action" value="delete_menu">
        <input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">
        <button class="btn btn-danger btn-sm"><i class="fa fa-trash"></i> Delete Menu</button>
      </form>
      <?php endif; ?>
      <p style="margin-top:10px;"><small class="text-muted"><i class="fa fa-code"></i> Use this menu anywhere in a page/post content:
        <code>[menu id="<?= (int) $selectedMenuId ?>" style="horizontal" level="0"]</code>
        &mdash; styles: <code>horizontal</code> (dropdown), <code>vertical</code>, <code>pills</code>, <code>simple</code>; <code>level="1"</code> = top level only, <code>level="0"</code> = all levels. You can also select by <code>name="<?= esc_attr($selectedMenu['name'] ?? '') ?>"</code> or <code>location="<?= esc_attr($selectedMenu['function'] ?? 'primary') ?>"</code>.</small></p>
      <hr>
      <form method="post" action="menus.php" class="form-inline">
        <?= di_csrf_field() ?>
        <input type="hidden" name="menu_action" value="create_menu">
        <label>Create alternate menu:</label>
        <input type="text" name="new_menu_name" class="form-control" placeholder="e.g. Footer Menu" required>
        <select name="new_menu_location" class="form-control">
          <option value="">Assign later</option><option value="primary">primary</option><option value="footer">footer</option><option value="sidebar">sidebar</option><option value="topbar">topbar</option>
        </select>
        <button class="btn btn-primary btn-sm"><i class="fa fa-plus"></i> Create Menu</button>
        <span class="text-muted"><small>Alternates let you keep several menus (e.g. a holiday promo menu) and switch a theme location between them.</small></span>
      </form>
    </div>
  </div>

  <div class="row">
    <div class="col-md-4">
      <div class="panel panel-default">
        <div class="panel-heading">Add Custom Link</div>
        <div class="panel-body">
          <form method="post" action="menus.php">
            <?= di_csrf_field() ?>
            <input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">
            <input type="hidden" name="menu_action" value="add_item">
            <div class="form-group">
              <label>Navigation Label</label>
              <input type="text" name="label" class="form-control" placeholder="e.g. Contact Us" required>
            </div>
            <div class="form-group">
              <label>URL / Destination</label>
              <input type="text" name="link" class="form-control" placeholder="?p=2 or https://..." required>
            </div>
            <div class="form-group">
              <label>Sort Order</label>
              <input type="number" name="order" class="form-control" value="<?= count($menuItems) + 1 ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-plus"></i> Add to Menu</button>
          </form>
        </div>
      </div>

      <div class="panel panel-default">
        <div class="panel-heading">Quick-Add Published Page / Post</div>
        <div class="panel-body">
          <?php foreach ($publishedContent as $pc): ?>
            <form method="post" action="menus.php" style="margin-bottom:6px;">
              <?= di_csrf_field() ?>
              <input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">
              <input type="hidden" name="menu_action" value="add_item">
              <input type="hidden" name="label" value="<?= esc_attr($pc['post_title']) ?>">
              <input type="hidden" name="link" value="?p=<?= esc_attr($pc['id']) ?>">
              <input type="hidden" name="order" value="<?= count($menuItems) + 1 ?>">
              <button type="submit" class="btn btn-xs btn-default btn-block" style="text-align:left;">
                <i class="fa fa-plus text-primary"></i> <?= esc_html($pc['post_title']) ?> <span class="pull-right text-muted"><?= esc_html($pc['post_type']) ?></span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-md-8">
      <div class="panel panel-primary">
        <div class="panel-heading"><?= esc_html($selectedMenu['displayed-text'] ?? 'Menu') ?> Items<?= !empty($selectedMenu['function']) ? ' <span class="label label-info">' . esc_html($selectedMenu['function']) . '</span>' : '' ?></div>
        <div class="panel-body">
          <?php if (empty($menuItems)): ?>
            <p class="text-muted">No items in this menu yet.</p>
          <?php else: ?>
            <form method="post" action="menus.php">
              <?= di_csrf_field() ?>
              <input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">
              <input type="hidden" name="menu_action" value="save_orders">
              <table class="table table-bordered table-striped">
                <thead>
                  <tr>
                    <th width="80">Order</th>
                    <th>Label</th>
                    <th>Link / URL</th>
                    <th width="90">Remove</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($menuItems as $mi): ?>
                    <tr>
                      <td><input type="number" name="item_order[<?= esc_attr($mi['id']) ?>]" class="form-control input-sm" value="<?= (int) ($mi['order'] ?? 1) ?>"></td>
                      <td><input type="text" name="item_label[<?= esc_attr($mi['id']) ?>]" class="form-control input-sm" value="<?= esc_attr($mi['label'] ?? '') ?>"></td>
                      <td><input type="text" name="item_link[<?= esc_attr($mi['id']) ?>]" class="form-control input-sm" value="<?= esc_attr($mi['link'] ?? '') ?>"></td>
                      <td class="text-center">
                        <button type="button" class="btn btn-xs btn-default" title="Move up" onclick="diMoveMenuItem('<?= esc_attr($mi['id']) ?>','up')"><i class="fa fa-arrow-up"></i></button>
                        <button type="button" class="btn btn-xs btn-default" title="Move down" onclick="diMoveMenuItem('<?= esc_attr($mi['id']) ?>','down')"><i class="fa fa-arrow-down"></i></button>
                        <button type="button" class="btn btn-xs btn-danger" onclick="diDeleteMenuItem('<?= esc_attr($mi['id']) ?>')"><i class="fa fa-trash"></i></button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Menu Structure</button>
            </form>

            <form id="di_del_menu_form" method="post" action="menus.php" style="display:none;">
              <?= di_csrf_field() ?>
              <input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">
              <input type="hidden" name="menu_action" value="delete_item">
              <input type="hidden" name="item_id" id="di_del_item_id" value="">
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function diMoveMenuItem(id, dir) {
  var f = document.createElement('form'); f.method = 'post'; f.action = 'menus.php';
  f.innerHTML = '<?= str_replace(["\r", "\n"], '', di_csrf_field()) ?>'
    + '<input type="hidden" name="menu_id" value="<?= esc_attr($selectedMenuId) ?>">'
    + '<input type="hidden" name="menu_action" value="move_item">'
    + '<input type="hidden" name="item_id" value="' + id + '">'
    + '<input type="hidden" name="dir" value="' + dir + '">';
  document.body.appendChild(f); f.submit();
}
function diDeleteMenuItem(id) {
  if (!confirm('Remove this menu item?')) return;
  document.getElementById('di_del_item_id').value = id;
  document.getElementById('di_del_menu_form').submit();
}
</script>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
