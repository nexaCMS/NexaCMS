  </main>
  <footer class="nova-footer">
    <div class="nova-wrap nova-footer-in">
      <div>
        <strong><?= esc_html(get_bloginfo('name')) ?></strong>
        <p><?= esc_html(theme_setting('footer_text') ?: (get_bloginfo('description') ?: 'Powered by NexaCMS.')) ?></p>
      </div>
      <div>
        <strong>Explore</strong>
        <?= up_nav_menu(['theme_location' => 'primary', 'container' => '', 'menu_class' => 'nova-footmenu', 'echo' => false]) ?>
      </div>
      <div>
        <strong>Site</strong>
        <ul class="nova-footmenu">
          <li><a href="<?= esc_url(home_url('rss.php')) ?>">RSS Feed</a></li>
          <li><a href="<?= esc_url(home_url('admin/')) ?>">Admin Dashboard</a></li>
        </ul>
      </div>
    </div>
    <div class="nova-copy">&copy; <?= date('Y') ?> <?= esc_html(get_bloginfo('name')) ?> &middot; Nova Blog theme for NexaCMS</div>
  </footer>
  <?php up_footer(); up_post(); ?>
</body>
</html>
