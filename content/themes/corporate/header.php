<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php up_head(); ?>
</head>
<body>
  <div class="%%P%%-topstrip"><div class="%%P%%-wrap"><span><?= esc_html(get_bloginfo('description') ?: 'Welcome') ?></span><span><a href="<?= esc_url(home_url('rss.php')) ?>">RSS</a> &middot; <a href="<?= esc_url(home_url('admin/')) ?>">Admin</a></span></div></div>

  <div class="corp-navbar">
    <div class="corp-wrap corp-navbar-in">
      <a class="corp-brand" href="<?= esc_url(home_url()) ?>"><?= esc_html(get_bloginfo('name') ?: 'NexaCMS') ?></a>
      <button class="corp-toggle" type="button" aria-label="Toggle navigation">&#9776;</button>
      <?= up_nav_menu(['theme_location' => 'primary', 'container' => 'nav', 'container_id' => 'corp-mainnav', 'container_class' => 'corp-navwrap', 'menu_class' => 'corp-menu', 'echo' => false]) ?>
    </div>
  </div>
  <main class="corp-wrap">
