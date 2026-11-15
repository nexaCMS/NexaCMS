<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php up_head(); ?>
</head>
<body>
  <div class="ft-navbar">
    <div class="ft-wrap ft-navbar-in">
      <a class="ft-brand" href="<?= esc_url(home_url()) ?>"><?= esc_html(get_bloginfo('name') ?: 'NexaCMS') ?></a>
      <button class="ft-toggle" type="button" aria-label="Toggle navigation">&#9776;</button>
      <?= up_nav_menu(['theme_location' => 'primary', 'container' => 'nav', 'container_id' => 'ft-mainnav', 'container_class' => 'ft-navwrap', 'menu_class' => 'ft-menu', 'echo' => false]) ?>
    </div>
  </div>
  <main class="ft-wrap">
