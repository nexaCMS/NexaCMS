<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php up_head(); ?>
</head>
<body>
  <div id="olv-progress"></div>
  <header class="olv-header">
    <div class="olv-wrap olv-header-in">
      <a class="olv-logo" href="<?= esc_url(home_url()) ?>"><?= esc_html(get_bloginfo('name') ?: 'Olivia') ?></a>
      <button class="olv-toggle" type="button" title="Toggle dark mode" aria-label="Toggle dark mode">&#9789;</button>
      <button class="olv-menu-btn" type="button" aria-label="Toggle navigation">&#9776;</button>
      <?= up_nav_menu(['theme_location' => 'primary', 'container' => 'nav', 'container_id' => 'olv-mainnav', 'container_class' => 'olv-navwrap', 'menu_class' => 'olv-menu', 'echo' => false]) ?>
    </div>
  </header>
  <main class="olv-wrap">
