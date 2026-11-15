<?php
/** Nexa Fake Author — pen-name bylines without a real user account. */

function nexa_fa_ensure(): void {
    if (!table_exists('fake_authors')) {
        create_table('fake_authors', ['name', 'url', 'bio', 'created_at']);
    }
    if (function_exists('ensure_fields')) {
        try { ensure_fields('posts', ['fake_author', 'post_real_author']); } catch (Throwable $e) { /* best-effort */ }
    }
}

function nexa_fa_registry(): array {
    nexa_fa_ensure();
    $rows = get_table('fake_authors')['data'] ?? [];
    usort($rows, static fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $rows;
}

function nexa_fa_register(string $name, string $url = '', string $bio = ''): void {
    $name = trim($name);
    if ($name === '') return;
    nexa_fa_ensure();
    foreach (get_table('fake_authors')['data'] as $r) {
        if (strcasecmp((string) ($r['name'] ?? ''), $name) === 0) return; // already registered
    }
    update_table('fake_authors', ['name' => $name, 'url' => trim($url), 'bio' => trim($bio), 'created_at' => date('Y-m-d H:i:s')], 'insert');
}

/* ---------- save: swap the displayed byline, keep the real author ---------- */
function nexa_fa_save($id = 0): void {
    if (!isset($_POST['nexa_fa_present'])) return; // only when the Author panel was submitted
    $id = (int) $id;
    if ($id <= 0) return;
    nexa_fa_ensure();
    $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [$id], true);
    $post = $rows[0] ?? null;
    if ($post === null) return;

    $chosen = trim((string) ($_POST['fake_author_choice'] ?? ''));
    $newName = trim((string) ($_POST['fake_author_new'] ?? ''));
    if ($newName !== '') {
        $chosen = $newName;
        nexa_fa_register($newName, (string) ($_POST['fake_author_new_url'] ?? ''));
    }

    $sessionName = (string) ($_SESSION['display-name'] ?? 'Administrator');
    $curFake = (string) ($post['fake_author'] ?? '');
    $curReal = (string) ($post['post_real_author'] ?? '');
    $curAuthor = (string) ($post['post_author'] ?? '');

    if ($chosen !== '') {
        // Real author: previously recorded, else whoever is currently credited (if not already a pen name).
        $real = $curReal !== '' ? $curReal : (($curAuthor !== '' && $curAuthor !== $chosen) ? $curAuthor : $sessionName);
        update_table('posts', ['id' => $id, 'post_author' => $chosen, 'fake_author' => $chosen, 'post_real_author' => $real], 'update');
    } else {
        // Back to the real author byline.
        $real = $curReal !== '' ? $curReal : $curAuthor;
        update_table('posts', ['id' => $id, 'post_author' => $real !== '' ? $real : $sessionName, 'fake_author' => '', 'post_real_author' => $real], 'update');
    }
}
add_action('post_saved', 'nexa_fa_save');

/* ---------- editor panel ---------- */
function nexa_fa_fields($item = []): void {
    nexa_fa_ensure();
    $fake = (string) ($item['fake_author'] ?? '');
    $real = (string) ($item['post_real_author'] ?? '');
    $byline = (string) ($item['post_author'] ?? '');
    if ($real === '' && $fake === '') $real = $byline;
    $registry = nexa_fa_registry();
    $inRegistry = false;
    foreach ($registry as $r) if ((string) $r['name'] === $fake) $inRegistry = true;
    ?>
    <div class="panel panel-success">
      <div class="panel-heading"><i class="fa fa-user-secret"></i> Author <small>byline &amp; pen names</small></div>
      <div class="panel-body">
        <input type="hidden" name="nexa_fa_present" value="1">
        <p style="margin-top:0;">
          Current byline: <strong><?= esc_html($byline !== '' ? $byline : '—') ?></strong>
          <?php if ($fake !== ''): ?><span class="label label-success">pen name</span><br>
          <small class="text-muted">Real author (private): <?= esc_html($real) ?></small>
          <?php elseif ($real !== ''): ?><br><small class="text-muted">Real author: <?= esc_html($real) ?></small><?php endif; ?>
        </p>
        <div class="form-group">
          <label>Attribute to pen name</label>
          <select name="fake_author_choice" class="form-control">
            <option value="" <?= $fake === '' ? 'selected' : '' ?>>— Use the real author —</option>
            <?php foreach ($registry as $r): ?>
              <option value="<?= esc_attr((string) $r['name']) ?>" <?= $fake === (string) $r['name'] ? 'selected' : '' ?>><?= esc_html((string) $r['name']) ?></option>
            <?php endforeach; ?>
            <?php if ($fake !== '' && !$inRegistry): ?>
              <option value="<?= esc_attr($fake) ?>" selected><?= esc_html($fake) ?> (unregistered)</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="form-group">
          <label>…or a new pen name</label>
          <input type="text" name="fake_author_new" class="form-control" placeholder="e.g. Staff Writer">
          <input type="url" name="fake_author_new_url" class="form-control" placeholder="Optional profile URL" style="margin-top:6px;">
          <p class="help-block">New names are added to the registry for reuse. A pen name is <strong>not</strong> a user account: it cannot log in, has no password, and keeps real usernames out of bylines, RSS and page source — safer, and perfect for guest contributors.</p>
        </div>
      </div>
    </div>
    <?php
}
add_action('post_edit_sidebar_fields', 'nexa_fa_fields');
