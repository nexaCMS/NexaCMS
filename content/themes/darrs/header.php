<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <?php
    global $av;
    $av = rtrim(get_template_directory_uri(), '/');
    up_head();
    ?>
  </head>
  <body>
    <div id="main">
      <div id="header">
        <div id="logo">
          <div id="logo_text">
            <h1><a href="<?= esc_url(home_url()) ?>"><?= dream_title(get_bloginfo('name')) ?></a></h1>
            <h2><?= esc_html(get_bloginfo('description') ?: "Bringing tomorrow's technologies, today") ?></h2>
          </div>
          <?php if (theme_setting('show_search', '1') === '1'): ?><div style="float:right;margin-top:50px;color:#1396d4;">
            <form method="get" action="<?= esc_url(home_url()) ?>">
              <label for="darrs_search">Search:</label>
              <input type="text" class="search_field" name="s" id="darrs_search" value="<?= esc_attr($_GET['s'] ?? $_GET['search'] ?? '') ?>">
              <input type="image" src="<?= esc_url($av . '/search.png') ?>" class="search_submit" style="border:0;margin:0 0 -12px 5px;" alt="Search">
            </form>
          </div><?php endif; ?>
        </div>
        <?= up_nav_menu([
            'container'    => 'div',
            'container_id' => 'menubar',
            'menu_class'   => 'menu',
            'menu_id'      => 'menu',
            'echo'         => false,
        ]) ?>
      </div>
