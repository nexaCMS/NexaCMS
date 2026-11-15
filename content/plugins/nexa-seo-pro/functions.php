<?php
/** Nexa SEO Pro — advanced per-post/page SEO fields, editor panel and precise tag output. */

function nexa_seopro_ensure(): void {
    if (!table_exists('seo_pro_meta')) {
        create_table('seo_pro_meta', ['post_id', 'meta_title', 'meta_desc', 'focus_kw', 'canonical', 'robots', 'og_title', 'og_desc', 'og_image', 'tw_card', 'updated_at']);
    }
}

function nexa_seopro_meta(int $postId): ?array {
    if ($postId <= 0) return null;
    nexa_seopro_ensure();
    $rows = get_result('seo_pro_meta', 1, ['*'], ['search' => ['post_id']], 1, [$postId], true);
    return $rows[0] ?? null;
}

/** Meta row for the page/post currently being viewed, if it has any Pro field set. */
function nexa_seopro_meta_for_current(): ?array {
    $post = function_exists('nexa_current_singular_post') ? nexa_current_singular_post() : null;
    if ($post === null && isset($_GET['p']) && is_numeric($_GET['p'])) {
        $rows = get_result('posts', 1, ['*'], ['search' => ['id']], 1, [(int) $_GET['p']], true);
        $post = $rows[0] ?? null;
    }
    if ($post === null || empty($post['id'])) return null;
    $meta = nexa_seopro_meta((int) $post['id']);
    if ($meta === null) return null;
    foreach (['meta_title', 'meta_desc', 'focus_kw', 'canonical', 'robots', 'og_title', 'og_desc', 'og_image', 'tw_card'] as $f) {
        if (trim((string) ($meta[$f] ?? '')) !== '') {
            $meta['_post'] = $post;
            return $meta;
        }
    }
    return null;
}

/* ---------- front-end tag output (basic Nexa SEO steps aside when this returns tags) ---------- */
function nexa_seopro_head(): void {
    $meta = nexa_seopro_meta_for_current();
    if ($meta === null) return;
    $post = $meta['_post'];
    // Meta title replaces the theme-generated <title> in the head buffer.
    if (trim((string) $meta['meta_title']) !== '' && isset($GLOBALS['head'])) {
        $newTitle = '<title>' . esc_html(trim((string) $meta['meta_title'])) . '</title>';
        $GLOBALS['head'] = preg_replace('/<title>.*?<\/title>/s', $newTitle, (string) $GLOBALS['head'], 1) ?? $GLOBALS['head'];
    }
    $desc = trim((string) $meta['meta_desc'])
        ?: (trim((string) ($post['post_excerpt'] ?? '')) ?: mb_substr(strip_tags((string) ($post['post_content'] ?? '')), 0, 160));
    $title = trim((string) $meta['meta_title']) ?: (string) ($post['post_title'] ?? '');
    $url = trim((string) $meta['canonical']) ?: (function_exists('url') ? url() : home_url());
    $robots = trim((string) $meta['robots']) ?: (string) get_setting('seo_robots', 'index,follow');
    $kwParts = array_filter(array_map('trim', explode(',', (string) ($meta['focus_kw'] ?? '') . ',' . (string) ($post['post_keywords'] ?? ''))));
    $kw = implode(', ', array_unique($kwParts));
    $ogTitle = trim((string) $meta['og_title']) ?: $title;
    $ogDesc = trim((string) $meta['og_desc']) ?: $desc;
    $ogImg = trim((string) $meta['og_image']) ?: (trim((string) ($post['post_thumb'] ?? '')) ?: (string) get_setting('seo_og_image', ''));
    if ($ogImg !== '' && !str_starts_with($ogImg, 'http')) $ogImg = home_url($ogImg);
    $card = trim((string) $meta['tw_card']) ?: 'summary_large_image';
    $site = (string) get_setting('blogname', 'NexaCMS');

    echo "\n<!-- Nexa SEO Pro -->\n";
    if ($desc !== '') echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
    if ($kw !== '') echo '<meta name="keywords" content="' . esc_attr($kw) . '">' . "\n";
    echo '<meta name="robots" content="' . esc_attr($robots) . '">' . "\n";
    echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:type" content="article"><meta property="og:site_name" content="' . esc_attr($site) . '">'
        . '<meta property="og:title" content="' . esc_attr($ogTitle) . '"><meta property="og:description" content="' . esc_attr($ogDesc) . '">'
        . '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    if ($ogImg !== '') echo '<meta property="og:image" content="' . esc_url($ogImg) . '">' . "\n";
    echo '<meta name="twitter:card" content="' . esc_attr($card) . '"><meta name="twitter:title" content="' . esc_attr($ogTitle) . '">'
        . '<meta name="twitter:description" content="' . esc_attr($ogDesc) . '">' . "\n<!-- /Nexa SEO Pro -->\n";
}
add_action('nexa_head', 'nexa_seopro_head');

/* ---------- save (post_saved fires from update_post() with the new/existing id) ---------- */
function nexa_seopro_save($id = 0): void {
    if (!isset($_POST['nexa_seopro_present'])) return; // only when the SEO panel was submitted
    $id = (int) $id;
    if ($id <= 0) return;
    nexa_seopro_ensure();
    $robotsOk = ['', 'index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];
    $robots = (string) ($_POST['seo_robots'] ?? '');
    $data = [
        'post_id' => (string) $id,
        'meta_title' => trim((string) ($_POST['seo_meta_title'] ?? '')),
        'meta_desc' => trim((string) ($_POST['seo_meta_desc'] ?? '')),
        'focus_kw' => trim((string) ($_POST['seo_focus_kw'] ?? '')),
        'canonical' => trim((string) ($_POST['seo_canonical'] ?? '')),
        'robots' => in_array($robots, $robotsOk, true) ? $robots : '',
        'og_title' => trim((string) ($_POST['seo_og_title'] ?? '')),
        'og_desc' => trim((string) ($_POST['seo_og_desc'] ?? '')),
        'og_image' => trim((string) ($_POST['seo_og_image'] ?? '')),
        'tw_card' => in_array(($_POST['seo_tw_card'] ?? ''), ['', 'summary', 'summary_large_image'], true) ? (string) $_POST['seo_tw_card'] : '',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $existing = nexa_seopro_meta($id);
    if ($existing !== null) {
        $data['id'] = $existing['id'];
        update_table('seo_pro_meta', $data, 'update');
    } else {
        update_table('seo_pro_meta', $data, 'insert');
    }
}
add_action('post_saved', 'nexa_seopro_save');

/* ---------- editor panel (hooked into the post editor sidebar) ---------- */
function nexa_seopro_fields($item = []): void {
    $id = (int) ($item['id'] ?? 0);
    $m = $id > 0 ? (nexa_seopro_meta($id) ?? []) : [];
    $v = static fn(string $k): string => (string) ($m[$k] ?? '');
    $robots = $v('robots');
    $card = $v('tw_card');
    ?>
    <div class="panel panel-info">
      <div class="panel-heading"><i class="fa fa-line-chart"></i> SEO Pro <small>advanced meta for this <?= esc_html(strtolower($item['post_type'] ?? 'post')) ?></small></div>
      <div class="panel-body">
        <input type="hidden" name="nexa_seopro_present" value="1">
        <div class="form-group">
          <label>Meta Title <small class="text-muted">(<span class="seo-count" data-for="seo_meta_title">0</span>/60)</small></label>
          <input type="text" id="seo_meta_title" name="seo_meta_title" class="form-control seo-counted" placeholder="Leave empty to use the post title" value="<?= esc_attr($v('meta_title')) ?>">
        </div>
        <div class="form-group">
          <label>Meta Description <small class="text-muted">(<span class="seo-count" data-for="seo_meta_desc">0</span>/160)</small></label>
          <textarea id="seo_meta_desc" name="seo_meta_desc" class="form-control seo-counted" rows="3" placeholder="One or two sentences that make people click in Google..."><?= esc_textarea($v('meta_desc')) ?></textarea>
        </div>
        <div class="form-group">
          <label>Focus Keyword(s)</label>
          <input type="text" name="seo_focus_kw" class="form-control" placeholder="e.g. customer experience Jamaica" value="<?= esc_attr($v('focus_kw')) ?>">
          <p class="help-block">Merged into the keywords tag ahead of the post keywords.</p>
        </div>
        <div class="form-group">
          <label>Canonical URL <small class="text-muted">(optional)</small></label>
          <input type="url" name="seo_canonical" class="form-control" placeholder="https://example.com/original-page/" value="<?= esc_attr($v('canonical')) ?>">
        </div>
        <div class="form-group">
          <label>Robots</label>
          <select name="seo_robots" class="form-control">
            <option value="" <?= $robots === '' ? 'selected' : '' ?>>Site default</option>
            <option value="index,follow" <?= $robots === 'index,follow' ? 'selected' : '' ?>>index, follow</option>
            <option value="noindex,follow" <?= $robots === 'noindex,follow' ? 'selected' : '' ?>>noindex, follow (hide from Google, keep links)</option>
            <option value="index,nofollow" <?= $robots === 'index,nofollow' ? 'selected' : '' ?>>index, nofollow</option>
            <option value="noindex,nofollow" <?= $robots === 'noindex,nofollow' ? 'selected' : '' ?>>noindex, nofollow (fully hidden)</option>
          </select>
        </div>
        <hr>
        <div class="form-group">
          <label>Social Title (Open Graph) <small class="text-muted">(optional)</small></label>
          <input type="text" name="seo_og_title" class="form-control" placeholder="Defaults to the meta title" value="<?= esc_attr($v('og_title')) ?>">
        </div>
        <div class="form-group">
          <label>Social Description <small class="text-muted">(optional)</small></label>
          <textarea name="seo_og_desc" class="form-control" rows="2" placeholder="Defaults to the meta description"><?= esc_textarea($v('og_desc')) ?></textarea>
        </div>
        <div class="form-group">
          <label>Social Image URL <small class="text-muted">(optional)</small></label>
          <input type="text" name="seo_og_image" class="form-control" placeholder="Defaults to the featured image" value="<?= esc_attr($v('og_image')) ?>">
        </div>
        <div class="form-group">
          <label>Twitter Card</label>
          <select name="seo_tw_card" class="form-control">
            <option value="" <?= $card === '' ? 'selected' : '' ?>>Large image (default)</option>
            <option value="summary" <?= $card === 'summary' ? 'selected' : '' ?>>Summary (small image)</option>
            <option value="summary_large_image" <?= $card === 'summary_large_image' ? 'selected' : '' ?>>Summary with large image</option>
          </select>
        </div>
        <p class="help-block" style="margin-bottom:0;">Provided by <strong>Nexa SEO Pro</strong>. When any field here is set, these tags replace the basic Nexa SEO output for this page/post.</p>
      </div>
    </div>
    <script>
    (function () {
      function upd(el) {
        var s = document.querySelector('.seo-count[data-for="' + el.id + '"]');
        if (s) s.textContent = el.value.length;
      }
      document.querySelectorAll('.seo-counted').forEach(function (el) {
        upd(el); el.addEventListener('input', function () { upd(el); });
      });
    })();
    </script>
    <?php
}
add_action('post_edit_sidebar_fields', 'nexa_seopro_fields');
