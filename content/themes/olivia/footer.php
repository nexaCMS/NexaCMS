  </main>
  <footer class="olv-footer">
    <div class="olv-wrap olv-footer-in">
      <div>
        <strong class="olv-logo"><?= esc_html(get_bloginfo('name')) ?></strong>
        <p><?= esc_html(theme_setting('footer_text') ?: (get_bloginfo('description') ?: 'Powered by NexaCMS.')) ?></p>
      </div>
      <div>
        <strong>Explore</strong>
        <?php
        // Alternate "footer" menu if one is assigned (Menus > Theme location), else the primary menu.
        $footerMenu = up_nav_menu(['theme_location' => 'footer', 'container' => '', 'menu_class' => 'olv-footmenu', 'echo' => false]);
        echo $footerMenu !== '' ? $footerMenu : up_nav_menu(['theme_location' => 'primary', 'container' => '', 'menu_class' => 'olv-footmenu', 'echo' => false]);
        ?>
      </div>
      <div>
        <strong>Site</strong>
        <ul class="olv-footmenu">
          <li><a href="<?= esc_url(home_url('rss.php')) ?>">RSS Feed</a></li>
          <li><a href="<?= esc_url(home_url('sitemap.xml')) ?>">Sitemap</a></li>
          <li><a href="<?= esc_url(home_url('admin/')) ?>">Admin Dashboard</a></li>
        </ul>
      </div>
    </div>
    <div class="olv-copy">&copy; <?= date('Y') ?> <?= esc_html(get_bloginfo('name')) ?> &middot; Olivia theme for NexaCMS</div>
    <button id="olv-top" type="button" title="Back to top" aria-label="Back to top">&uarr;</button>
  </footer>
  <?php up_footer(); up_post(); ?>
</body>
</html>
