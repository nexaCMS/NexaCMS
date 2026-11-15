<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php up_head(); ?>
</head>
<body>

  <div class="folio-navbar">
    <div class="folio-wrap folio-navbar-in">
      <a class="folio-brand" href="<?= esc_url(home_url()) ?>"><?= esc_html(get_bloginfo('name') ?: 'NexaCMS') ?></a>
      <button class="folio-toggle" type="button" aria-label="Toggle navigation">&#9776;</button>
      <?= up_nav_menu(['theme_location' => 'primary', 'container' => 'nav', 'container_id' => 'folio-mainnav', 'container_class' => 'folio-navwrap', 'menu_class' => 'folio-menu', 'echo' => false]) ?>
    </div>
  </div>
  <main class="folio-wrap">
