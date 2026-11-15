<?php
$isSingleView = isset($_GET['p']) || (!is_home() && !is_search());
$sidebarPos = nexa_sidebar_position('right'); // per-page Sidebar override wins on single pages
$av = Olivia_theme_uri();
$recent = array_slice(array_values(array_filter(
    get_result('posts', 1, ['id', 'post_title', 'post_status'], ['by' => 'id', 'order' => 'DESC'], 8),
    static fn($r) => ($r['post_status'] ?? '') === 'published'
)), 0, 5);
$cardIdx = 0;
?>
<?php if (!$isSingleView && is_front_page() && theme_setting('show_hero', '1') === '1'): ?>
  <section class="olv-hero">
    <div class="olv-hero-text">
      <p class="olv-eyebrow"><?= esc_html(get_bloginfo('name')) ?></p>
      <h1><?= esc_html(theme_setting('hero_title') ?: 'Designing & building digital experiences') ?></h1>
      <p><?= esc_html(theme_setting('hero_text') ?: (get_bloginfo('description') ?: 'Welcome to my portfolio — selected work, writing and services.')) ?></p>
      <p><a class="olv-btn" href="#work">View Selected Work</a> <a class="olv-btn ghost" href="<?= esc_url(post_url(get_page_id(['post_name' => 'portfolio']) ?: '')) ?>">Full Portfolio</a></p>
    </div>
    <div class="olv-hero-img"><img src="<?= esc_url($av . '/assets/photo.jpg') ?>" alt="<?= esc_attr(get_bloginfo('name')) ?>"></div>
  </section>
  <?php if (theme_setting('about_text') !== ''): ?>
    <section class="olv-about"><h2>About</h2><p><?= esc_html(theme_setting('about_text')) ?></p></section>
  <?php endif; ?>
  <h2 id="work" class="olv-section-title">Selected Work &amp; Latest Writing</h2>
<?php endif; ?>
<div class="olv-grid"<?php if ($sidebarPos === 'none' || !$isSingleView): ?> style="grid-template-columns:1fr;"<?php elseif ($sidebarPos === 'left'): ?> style="grid-template-columns:320px 1fr;"<?php endif; ?>>
  <div class="olv-main">
    <?php if ($isSingleView): ?><h1 class="olv-page-title"><?= esc_html(get_page_title() ?: get_bloginfo('name')) ?></h1><?php endif; ?>
    <?php if (!$isSingleView) do_action('nexa_post_layout_before'); ?>
    <?php if (have_posts()): ?>
      <div class="<?= $isSingleView ? '' : ('olv-cards' . apply_filters('nexa_post_layout_list_class', '')) ?>">
      <?php $nexaItemNo = 0; ?>
      <?php while (have_posts()): global $post;
        $cardIdx++;
        $thumb = trim((string) ($post['post_thumb'] ?? ''));
        if ($thumb === '' && !$isSingleView) $thumb = $av . '/assets/project' . ((($cardIdx - 1) % 6) + 1) . '.png';
      ?>
        <article class="olv-card nexa-post-item<?= $isSingleView ? ' wide' : '' ?>">
          <?php if ($thumb !== '' && !$isSingleView): ?><a href="<?= esc_url(post_url($post['id'])) ?>"><img class="olv-thumb" src="<?= esc_url($thumb) ?>" alt="<?= esc_attr($post['post_title'] ?? '') ?>"></a><?php endif; ?>
          <div class="olv-card-body">
            <h3 class="olv-post-title"><a href="<?= esc_url(post_url($post['id'])) ?>"><?= esc_html($post['post_title'] ?? '') ?></a></h3>
            <div class="olv-meta"><?= esc_html($post['post_date'] ?? '') ?> &middot; <?= esc_html($post['post_author'] ?: 'Admin') ?> &middot; <?= countcomments($post['id']) ?> comments</div>
            <?php if ($isSingleView || ($post['post_type'] ?? '') === 'error'): ?>
              <div class="olv-entry"><?php the_content(true); ?></div>
              <?php if (($post['id'] ?? 0) > 0): ?><div id="Comments" style="margin-top:22px;"><?php up_body(); ?></div><?php endif; ?>
            <?php else: ?>
              <p><?= ($post['post_excerpt'] ?? '') !== '' ? esc_html($post['post_excerpt']) : esc_html(mb_substr(strip_tags((string) ($post['post_content'] ?? '')), 0, 200)) ?></p>
              <?php if (($post['id'] ?? 0) > 0): ?><a class="olv-btn small" href="<?= esc_url(post_url($post['id'])) ?>">View Project &raquo;</a><?php endif; ?>
            <?php endif; ?>
          </div>
        </article>
      <?php $nexaItemNo++; if (!$isSingleView) do_action('nexa_post_layout_after_item', $nexaItemNo); ?>
      <?php endwhile; ?>
      <?php if (!$isSingleView) do_action('nexa_post_layout_after'); ?>

      </div>
      <?php if (!$isSingleView) { the_posts_navigation(); } ?>
    <?php else: ?>
      <div class="olv-card wide"><div class="olv-card-body"><p>Nothing here yet — check back soon.</p></div></div>
    <?php endif; ?>
  </div>
  <?php if ($isSingleView && $sidebarPos !== 'none'): ?>
  <aside class="olv-side"<?php if ($sidebarPos === 'left'): ?> style="order:-1;"<?php endif; ?>>
    <div class="olv-widget">
      <h4>Search</h4>
      <form method="get" action="<?= esc_url(home_url()) ?>"><input type="text" name="s" value="<?= esc_attr($_GET['s'] ?? $_GET['search'] ?? '') ?>" placeholder="Search..." class="olv-search"></form>
    </div>
    <div class="olv-widget">
      <h4>Recent Posts</h4>
      <ul><?php foreach ($recent as $rp): ?><li><a href="<?= esc_url(post_url($rp['id'])) ?>"><?= esc_html($rp['post_title']) ?></a></li><?php endforeach; ?></ul>
    </div>
    <div class="olv-widget">
      <h4>Menu</h4>
      <?= up_nav_menu(['theme_location' => 'primary', 'container' => '', 'menu_class' => 'olv-sidemenu', 'echo' => false]) ?>
    </div>
  </aside>
  <?php endif; ?>
</div>
