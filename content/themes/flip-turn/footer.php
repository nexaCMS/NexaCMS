  </main>
  <footer class="ft-footer">
    <div class="ft-wrap ft-footer-in">
      <div>
        <strong><?= esc_html(get_bloginfo('name')) ?></strong>
        <p><?= esc_html(theme_setting('footer_text') ?: (get_bloginfo('description') ?: 'Powered by NexaCMS.')) ?></p>
      </div>
      <div>
        <strong>Explore</strong>
        <?= up_nav_menu(['theme_location' => 'primary', 'container' => '', 'menu_class' => 'ft-footmenu', 'echo' => false]) ?>
      </div>
      <div>
        <strong>Site</strong>
        <ul class="ft-footmenu">
          <li><a href="<?= esc_url(home_url('rss.php')) ?>">RSS Feed</a></li>
          <li><a href="<?= esc_url(home_url('admin/')) ?>">Admin Dashboard</a></li>
        </ul>
      </div>
    </div>
    <div class="ft-copy">&copy; <?= date('Y') ?> <?= esc_html(get_bloginfo('name')) ?> &middot; Flip Turn theme for NexaCMS</div>
  </footer>
  <?php up_footer(); up_post(); ?>
</body>
</html>
