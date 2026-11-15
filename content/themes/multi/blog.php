<?php
global $settings;
$av = rtrim(get_template_directory_uri(), '/');
$isSingleView = isset($_GET['p']) || (!is_home() && !is_search());
$sbPos = $isSingleView ? nexa_sidebar_position('right') : 'right'; // Admin > edit page > "Sidebar on This Page" 
$gmtOffsetSec = (int) round((float) ($settings['gmt_offset']['value'] ?? 0) * 3600);
?>
<section id="blog" class="container">
  <div class="text-center">
    <div class="wow bounceInDown" data-wow-offset="0" data-wow-delay="0.2s">
      <h2><?= is_search() ? esc_html(get_page_title()) : ($isSingleView ? esc_html(get_page_title() ?: get_bloginfo('name')) : 'Latest Updates') ?></h2>
    </div>
    <?php if (get_bloginfo('description') !== ''): ?>
    <div class="wow bounceInDown" data-wow-offset="0" data-wow-delay="0.4s">
      <p><?= esc_html(get_bloginfo('description')) ?></p>
    </div>
    <?php endif; ?>
  </div>

  <div class="blog">
    <div class="row">
      <div class="<?= $sbPos === 'none' ? 'col-md-12' : 'col-md-8' ?><?= $sbPos === 'left' ? ' col-md-push-4' : '' ?><?= $isSingleView ? '' : apply_filters('nexa_post_layout_list_class', '') ?>">
        <?php if (!$isSingleView) do_action('nexa_post_layout_before'); ?>
        <?php if (have_posts()): ?>
          <?php $nexaItemNo = 0; ?>
          <?php while (have_posts()): global $post;
            $rawTs = !empty($post['post_date_gmt']) && is_numeric($post['post_date_gmt'])
                ? ((int) $post['post_date_gmt'] + $gmtOffsetSec)
                : (strtotime((string) ($post['post_date'] ?? 'now')) ?: time());
            $thumb = trim((string) ($post['post_thumb'] ?? ''));
          ?>
            <div class="blog-item nexa-post-item">
              <div class="row">
                <div class="col-xs-12 col-sm-2 text-center">
                  <div class="entry-meta">
                    <span id="publish_date"><?= esc_html(date('d M', $rawTs)) ?></span>
                    <span><i class="fa fa-user"></i> <a href="<?= esc_url(post_url($post['id'])) ?>"><?= esc_html($post['post_author'] ?: 'Admin') ?></a></span>
                    <span><i class="fa fa-comment"></i> <a href="<?= esc_url(post_url($post['id']) . '#Comments') ?>"><?= countcomments($post['id']) ?></a></span>
                    <span><i class="fa fa-heart"></i> <a href="<?= esc_url(post_url($post['id'])) ?>"><?= countlikes($post['id']) ?></a></span>
                  </div>
                </div>

                <div class="col-xs-12 col-sm-10 blog-content">
                  <?php if ($thumb !== ''): ?>
                    <a href="<?= esc_url(post_url($post['id'])) ?>"><img class="img-responsive img-blog" src="<?= esc_url($thumb) ?>" width="100%" alt="<?= esc_attr($post['post_title'] ?? '') ?>" /></a>
                  <?php endif; ?>
                  <h3><a href="<?= esc_url(post_url($post['id'])) ?>" style="color:#333;"><?= esc_html($post['post_title'] ?? '') ?></a></h3>
                  <?php if ($isSingleView || ($post['post_type'] ?? '') === 'error'): ?>
                    <div class="entry-body"><?php the_content(true); ?></div>
                    <?php if (($post['id'] ?? 0) > 0): ?>
                      <div id="Comments" style="margin-top:25px;"><?php up_body(); ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <p><?= ($post['post_excerpt'] ?? '') !== '' ? esc_html($post['post_excerpt']) : di_post_excerpt((string) ($post['post_content'] ?? '')) ?></p>
                    <?php if (($post['id'] ?? 0) > 0): ?>
                      <a class="btn btn-primary readmore" href="<?= esc_url(post_url($post['id'])) ?>">Read More <i class="fa fa-angle-right"></i></a>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php $nexaItemNo++; if (!$isSingleView) do_action('nexa_post_layout_after_item', $nexaItemNo); ?>
          <?php endwhile; ?>
          <?php if (!$isSingleView) do_action('nexa_post_layout_after'); ?>
          <?php if (!$isSingleView) { the_posts_navigation(); } ?>
        <?php else: ?>
          <?php get_template_part('template-parts/content', 'none'); ?>
        <?php endif; ?>
      </div>

      <?php if ($sbPos !== 'none'): ?>
      <aside class="col-md-4<?= $sbPos === 'left' ? ' col-md-pull-8' : '' ?>">
        <div class="widget search">
          <form role="form" method="get" action="<?= esc_url(home_url()) ?>">
            <input type="text" name="s" value="<?= esc_attr($_GET['s'] ?? $_GET['search'] ?? '') ?>" class="form-control search_box" autocomplete="off" placeholder="Search articles...">
          </form>
        </div>

        <div class="widget categories">
          <h3>Recent Posts</h3>
          <div class="row">
            <div class="col-sm-12">
              <ul class="blog_category">
                <?php
                $recent = array_slice(array_values(array_filter(
                    get_result('posts', 1, ['id', 'post_title', 'post_status'], ['by' => 'id', 'order' => 'DESC'], 8),
                    static fn($r) => ($r['post_status'] ?? '') === 'published'
                )), 0, 5);
                foreach ($recent as $rp): ?>
                  <li><a href="<?= esc_url(post_url($rp['id'])) ?>"><?= esc_html($rp['post_title']) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        </div>

        <div class="widget blog_gallery">
          <h3>Gallery</h3>
          <ul class="sidebar-gallery">
            <?php for ($g = 1; $g <= 6; $g++): ?>
              <li><a href="#"><img src="<?= esc_url($av . "/images/blog/gallery{$g}.png") ?>" alt="Gallery <?= $g ?>" /></a></li>
            <?php endfor; ?>
          </ul>
        </div>
      </aside>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php
if (!function_exists('di_post_excerpt')) {
    function di_post_excerpt(string $html): string {
        $plain = trim(strip_tags(do_shortcode($html)));
        return mb_strlen($plain) > 240 ? esc_html(mb_substr($plain, 0, 240) . '...') : esc_html($plain);
    }
}
?>
