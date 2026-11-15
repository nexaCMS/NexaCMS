<?php
$isSingleView = isset($_GET['p']) || (!is_home() && !is_search());
$recent = array_slice(array_values(array_filter(
    get_result('posts', 1, ['id', 'post_title', 'post_status'], ['by' => 'id', 'order' => 'DESC'], 8),
    static fn($r) => ($r['post_status'] ?? '') === 'published'
)), 0, 5);
$sidebarPos = nexa_sidebar_position('right'); // per-page Sidebar override wins on single pages
?>
<?php if (!$isSingleView && is_front_page() && theme_setting('show_hero', '1') === '1'): ?>
  <section class="folio-hero">
    <h1><?= esc_html(theme_setting('hero_title') ?: get_bloginfo('name')) ?></h1>
    <p><?= esc_html(theme_setting('hero_text') ?: (get_bloginfo('description') ?: 'Welcome to our site — powered by NexaCMS.')) ?></p>
  </section>
<?php endif; ?>
<div class="folio-grid"<?php if ($sidebarPos === 'none'): ?> style="grid-template-columns:1fr;"<?php elseif ($sidebarPos === 'left'): ?> style="grid-template-columns:320px 1fr;"<?php endif; ?>>
  <div class="folio-main<?= $isSingleView ? '' : apply_filters('nexa_post_layout_list_class', '') ?>">
    <h2 class="folio-page-title"><?= is_search() ? esc_html(get_page_title()) : ($isSingleView ? esc_html(get_page_title() ?: get_bloginfo('name')) : 'Latest Updates') ?></h2>
    <?php if (!$isSingleView) do_action('nexa_post_layout_before'); ?>
    <?php if (have_posts()): ?>
      <?php $nexaItemNo = 0; ?>
      <?php while (have_posts()): global $post;
        $thumb = trim((string) ($post['post_thumb'] ?? ''));
      ?>
        <article class="folio-card nexa-post-item">
          <?php if ($thumb !== '' && !$isSingleView): ?><a href="<?= esc_url(post_url($post['id'])) ?>"><img class="folio-thumb" src="<?= esc_url($thumb) ?>" alt="<?= esc_attr($post['post_title'] ?? '') ?>"></a><?php endif; ?>
          <div class="folio-card-body">
            <h3 class="folio-post-title"><a href="<?= esc_url(post_url($post['id'])) ?>"><?= esc_html($post['post_title'] ?? '') ?></a></h3>
            <div class="folio-meta"><?= esc_html($post['post_date'] ?? '') ?> &middot; <?= esc_html($post['post_author'] ?: 'Admin') ?> &middot; <?= countcomments($post['id']) ?> comments</div>
            <?php if ($isSingleView || ($post['post_type'] ?? '') === 'error'): ?>
              <div class="folio-entry"><?php the_content(true); ?></div>
              <?php if (($post['id'] ?? 0) > 0): ?><div id="Comments" style="margin-top:22px;"><?php up_body(); ?></div><?php endif; ?>
            <?php else: ?>
              <p><?= ($post['post_excerpt'] ?? '') !== '' ? esc_html($post['post_excerpt']) : esc_html(mb_substr(strip_tags((string) ($post['post_content'] ?? '')), 0, 220)) ?></p>
              <?php if (($post['id'] ?? 0) > 0): ?><a class="folio-btn" href="<?= esc_url(post_url($post['id'])) ?>">Read More &raquo;</a><?php endif; ?>
            <?php endif; ?>
          </div>
        </article>
      <?php $nexaItemNo++; if (!$isSingleView) do_action('nexa_post_layout_after_item', $nexaItemNo); ?>
      <?php endwhile; ?>
      <?php if (!$isSingleView) do_action('nexa_post_layout_after'); ?>

      <?php if (!$isSingleView) { the_posts_navigation(); } ?>
    <?php else: ?>
      <div class="folio-card"><div class="folio-card-body"><p>Nothing here yet — check back soon.</p></div></div>
    <?php endif; ?>
  </div>
  <?php if ($sidebarPos !== 'none'): ?><aside class="folio-side"<?php if ($sidebarPos === 'left'): ?> style="order:-1;"<?php endif; ?>>
    <div class="folio-widget">
      <h4>Search</h4>
      <form method="get" action="<?= esc_url(home_url()) ?>"><input type="text" name="s" value="<?= esc_attr($_GET['s'] ?? $_GET['search'] ?? '') ?>" placeholder="Search..." class="folio-search"></form>
    </div>
    <div class="folio-widget">
      <h4>Recent Posts</h4>
      <ul><?php foreach ($recent as $rp): ?><li><a href="<?= esc_url(post_url($rp['id'])) ?>"><?= esc_html($rp['post_title']) ?></a></li><?php endforeach; ?></ul>
    </div>
    <div class="folio-widget">
      <h4>Menu</h4>
      <?= up_nav_menu(['theme_location' => 'primary', 'container' => '', 'menu_class' => 'folio-sidemenu', 'echo' => false]) ?>
    </div>
  </aside><?php endif; ?>
</div>
