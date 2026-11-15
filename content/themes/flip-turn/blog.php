<?php
$isSingleView = isset($_GET['p']) || (!is_home() && !is_search());
$sidebarPos = nexa_sidebar_position('right'); // per-page Sidebar override wins on single pages
$recent = array_slice(array_values(array_filter(
    get_result('posts', 1, ['id', 'post_title', 'post_status'], ['by' => 'id', 'order' => 'DESC'], 8),
    static fn($r) => ($r['post_status'] ?? '') === 'published'
)), 0, 5);
?>
<?php if (!$isSingleView && is_front_page()): ?>
  <section class="ft-hero">
    <h1><?= esc_html(get_bloginfo('name')) ?></h1>
    <?php if (theme_setting('show_tagline', '1') === '1' && get_bloginfo('description') !== ''): ?>
      <h2><?= esc_html(get_bloginfo('description')) ?></h2>
    <?php endif; ?>
  </section>
<?php endif; ?>
<div class="ft-grid"<?php if ($sidebarPos === 'none'): ?> style="grid-template-columns:1fr;"<?php elseif ($sidebarPos === 'left'): ?> style="grid-template-columns:320px 1fr;"<?php endif; ?>>
  <div class="ft-main">
    <?php if ($isSingleView): ?><h2 class="ft-page-title"><?= esc_html(get_page_title() ?: get_bloginfo('name')) ?></h2><?php endif; ?>
    <?php if (!$isSingleView) do_action('nexa_post_layout_before'); ?>
    <?php if (have_posts()): ?>
      <div class="<?= $isSingleView ? '' : ('ft-cards' . apply_filters('nexa_post_layout_list_class', '')) ?>">
      <?php $nexaItemNo = 0; ?>
      <?php while (have_posts()): global $post;
        $thumb = trim((string) ($post['post_thumb'] ?? ''));
      ?>
        <article class="ft-card nexa-post-item<?= $isSingleView ? ' wide' : '' ?>">
          <?php if ($thumb !== '' && !$isSingleView): ?><a href="<?= esc_url(post_url($post['id'])) ?>"><img class="ft-thumb" src="<?= esc_url($thumb) ?>" alt="<?= esc_attr($post['post_title'] ?? '') ?>"></a><?php endif; ?>
          <div class="ft-card-body">
            <h3 class="ft-post-title"><a href="<?= esc_url(post_url($post['id'])) ?>"><?= esc_html($post['post_title'] ?? '') ?></a></h3>
            <div class="ft-meta"><?= esc_html($post['post_date'] ?? '') ?> &middot; <?= esc_html($post['post_author'] ?: 'Admin') ?> &middot; <?= countcomments($post['id']) ?> comments</div>
            <?php if ($isSingleView || ($post['post_type'] ?? '') === 'error'): ?>
              <div class="ft-entry"><?php the_content(true); ?></div>
              <?php if (($post['id'] ?? 0) > 0): ?><div id="Comments" style="margin-top:22px;"><?php up_body(); ?></div><?php endif; ?>
            <?php else: ?>
              <p><?= ($post['post_excerpt'] ?? '') !== '' ? esc_html($post['post_excerpt']) : esc_html(mb_substr(strip_tags((string) ($post['post_content'] ?? '')), 0, 200)) ?></p>
              <?php if (($post['id'] ?? 0) > 0): ?><a class="ft-btn" href="<?= esc_url(post_url($post['id'])) ?>">View &raquo;</a><?php endif; ?>
            <?php endif; ?>
          </div>
        </article>
      <?php $nexaItemNo++; if (!$isSingleView) do_action('nexa_post_layout_after_item', $nexaItemNo); ?>
      <?php endwhile; ?>
      <?php if (!$isSingleView) do_action('nexa_post_layout_after'); ?>

      </div>
      <?php if (!$isSingleView) { the_posts_navigation(); } ?>
    <?php else: ?>
      <div class="ft-card wide"><div class="ft-card-body"><p>Nothing here yet — check back soon.</p></div></div>
    <?php endif; ?>
  </div>
  <?php if ($sidebarPos !== 'none'): ?>
  <aside class="ft-side"<?php if ($sidebarPos === 'left'): ?> style="order:-1;"<?php endif; ?>>
    <div class="ft-widget">
      <h4>Search</h4>
      <form method="get" action="<?= esc_url(home_url()) ?>"><input type="text" name="s" value="<?= esc_attr($_GET['s'] ?? $_GET['search'] ?? '') ?>" placeholder="Search..." class="ft-search"></form>
    </div>
    <div class="ft-widget">
      <h4>Recent Posts</h4>
      <ul><?php foreach ($recent as $rp): ?><li><a href="<?= esc_url(post_url($rp['id'])) ?>"><?= esc_html($rp['post_title']) ?></a></li><?php endforeach; ?></ul>
    </div>
    <div class="ft-widget">
      <h4>Menu</h4>
      <?= up_nav_menu(['theme_location' => 'primary', 'container' => '', 'menu_class' => 'ft-sidemenu', 'echo' => false]) ?>
    </div>
  </aside>
  <?php endif; ?>
</div>
