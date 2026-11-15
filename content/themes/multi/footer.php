<?php
$recentFooter = array_slice(array_values(array_filter(
    get_result('posts', 1, ['id', 'post_title', 'post_status'], ['by' => 'id', 'order' => 'DESC'], 6),
    static fn($r) => ($r['post_status'] ?? '') === 'published'
)), 0, 4);
?>
    <footer>
      <div class="footer">
        <div class="container">
          <div class="col-md-4 wow fadeInUp" data-wow-offset="0" data-wow-delay="0.2s">
            <h2><?= esc_html(get_bloginfo('name')) ?></h2>
            <p><?= esc_html(theme_setting('footer_text') ?: (get_bloginfo('description') ?: 'Powered by NexaCMS Multi-Database CMS.')) ?></p>
            <ul class="social-network">
              <li><a href="<?= esc_url(home_url('rss.php')) ?>" title="RSS Feed"><i class="fa fa-rss fa-1x"></i></a></li>
              <li><a href="<?= esc_url(home_url('api.php')) ?>" title="JSON API"><i class="fa fa-code fa-1x"></i></a></li>
            </ul>
          </div>

          <div class="col-md-4 wow fadeInUp" data-wow-offset="0" data-wow-delay="0.4s">
            <h3>RECENT POSTS</h3>
            <ul>
              <?php foreach ($recentFooter as $idx => $fp): ?>
                <li><a href="<?= esc_url(post_url($fp['id'])) ?>" style="color:inherit;"><?= esc_html($fp['post_title']) ?></a></li>
                <?php if ($idx + 1 < count($recentFooter)): ?><hr><?php endif; ?>
              <?php endforeach; ?>
            </ul>
          </div>

          <div class="col-md-4 wow fadeInUp" data-wow-offset="0" data-wow-delay="0.6s">
            <h3>CONTACT &amp; ADMIN</h3>
            <ul>
              <li><i class="fa fa-envelope fa-2x"></i> <?= esc_html(get_setting('anim_email', 'admin@localhost')) ?></li><hr>
              <li><i class="fa fa-cog fa-2x"></i> <a href="<?= esc_url(home_url('admin/')) ?>" style="color:inherit;">Admin Dashboard</a></li>
            </ul>
          </div>
        </div>
      </div>

      <div class="sub-footer">
        <div class="container">
          <div class="row">
            <div class="col-md-6">
              &copy; <?= date('Y') ?> <?= esc_html(get_bloginfo('name')) ?> &mdash; Powered by NexaCMS <?= esc_html(NEXACMS_VERSION) ?>
            </div>
            <div class="col-md-6">
              <ul class="pull-right">
                <li><a href="<?= esc_url(home_url()) ?>">Home</a></li>
                <li><a href="<?= esc_url(home_url('rss.php')) ?>">RSS</a></li>
                <li><a href="<?= esc_url(home_url('admin/')) ?>">Admin</a></li>
              </ul>
            </div>
          </div>
          <div class="pull-right">
            <a href="#" class="scrollup"><i class="fa fa-angle-up fa-3x"></i></a>
          </div>
        </div>
      </div>
    </footer>

    <?php up_footer(); ?>
    <?php up_post(); ?>
  </body>
</html>
