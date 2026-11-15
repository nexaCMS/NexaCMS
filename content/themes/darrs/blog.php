<?php
global $av;
$av = rtrim(get_template_directory_uri(), '/');
$isSingleView = isset($_GET['p']) || (!is_home() && !is_search());
$recent = array_slice(array_values(array_filter(
    get_result('posts', 1, ['id', 'post_title', 'post_date', 'post_excerpt', 'post_status'], ['by' => 'id', 'order' => 'DESC'], 6),
    static fn($r) => ($r['post_status'] ?? '') === 'published'
)), 0, 3);
?>
<div id="site_content">
  <?php $sbPos = $isSingleView ? nexa_sidebar_position('right') : 'right'; ?>
  <?php if ($sbPos !== 'none'): ?>
  <div class="sidebar">
    <h3>Latest News</h3>
    <?php foreach ($recent as $rp): ?>
      <h4><?= esc_html($rp['post_title']) ?></h4>
      <h5><?= esc_html($rp['post_date'] ?? '') ?></h5>
      <p><?= esc_html(mb_substr((string) ($rp['post_excerpt'] ?? ''), 0, 110)) ?><br /><a href="<?= esc_url(post_url($rp['id'])) ?>" class="read_more">Read more</a></p>
    <?php endforeach; ?>

    <h3>Quick Links</h3>
    <ul>
      <li><a href="<?= esc_url(home_url()) ?>">Home</a></li>
      <li><a href="<?= esc_url(home_url('rss.php')) ?>">RSS Feed</a></li>
      <li><a href="<?= esc_url(home_url('admin/')) ?>">Admin Panel</a></li>
    </ul>

    <h3>Search</h3>
    <form method="get" action="<?= esc_url(home_url()) ?>" id="search_form">
      <p>
        <input class="search" type="text" name="s" placeholder="Enter keywords..." value="<?= esc_attr($_GET['s'] ?? '') ?>" />
        <input type="image" style="border:0;margin:0 0 -9px 5px;" src="<?= esc_url($av . '/search.png') ?>" alt="Search" title="Search" />
      </p>
    </form>
  </div>
  <?php endif; ?>

  <div id="content"<?php if (!$isSingleView): ?> class="<?= esc_attr(trim((string) apply_filters('nexa_post_layout_list_class', ''))) ?>"<?php endif; ?><?= $sbPos === 'none' ? ' style="float:none;width:auto;"' : '' ?>>
    <?php if (!$isSingleView) do_action('nexa_post_layout_before'); ?>
    <?php if (have_posts()): ?>
      <?php $nexaItemNo = 0; ?>
      <?php while (have_posts()): global $post; ?>
        <div id="blog" class="nexa-post-item" style="margin-bottom:24px;">
          <h2><a href="<?= esc_url(post_url($post['id'])) ?>"><?= esc_html($post['post_title'] ?? '') ?></a></h2>
          <?php if ($isSingleView || ($post['post_type'] ?? '') === 'error'): ?>
            <div><?php the_content(true); ?></div>
            <?php if (($post['id'] ?? 0) > 0): ?>
              <div id="Comments" style="margin-top:20px;"><?php up_body(); ?></div>
            <?php endif; ?>
          <?php else: ?>
            <p><?= ($post['post_excerpt'] ?? '') !== '' ? esc_html($post['post_excerpt']) : esc_html(mb_substr(strip_tags((string) ($post['post_content'] ?? '')), 0, 240)) ?></p>
            <?php if (($post['id'] ?? 0) > 0): ?>
              <a class="read_more" href="<?= esc_url(post_url($post['id'])) ?>">Read More &raquo;</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php $nexaItemNo++; if (!$isSingleView) do_action('nexa_post_layout_after_item', $nexaItemNo); ?>
      <?php endwhile; ?>
      <?php if (!$isSingleView) do_action('nexa_post_layout_after'); ?>

      <?php if (!$isSingleView) { the_posts_navigation(); } ?>
    <?php else: ?>
      <?php get_template_part('template-parts/content', 'none'); ?>
    <?php endif; ?>
  </div>
</div>
