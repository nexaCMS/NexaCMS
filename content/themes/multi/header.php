<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php up_head(); ?>
  </head>
  <body>
    <header>
      <nav class="navbar navbar-default navbar-static-top" role="navigation">
        <div class="navigation">
          <div class="container">
            <div class="navbar-header">
              <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target=".navbar-collapse.collapse">
                <span class="sr-only">Toggle navigation</span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
              </button>
              <div class="navbar-brand">
                <?php $brand = get_bloginfo('name') ?: 'NexaCMS'; ?>
                <a href="<?= esc_url(home_url()) ?>"><h1><span><?= esc_html(mb_substr($brand, 0, 1)) ?></span><?= esc_html(mb_substr($brand, 1)) ?></h1></a>
              </div>
            </div>

            <div class="navbar-collapse collapse">
              <div class="menu">
                <?= up_nav_menu(['theme_location' => 'primary', 'menu_class' => 'nav nav-tabs', 'container_class' => 'menu']) ?>
              </div>
            </div>
          </div>
        </div>
      </nav>
    </header>
    <?php if (is_front_page() && sliderenabled()) { showslider(); } ?>
