<?php
/**  Hero Slider - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-slider','class'=>(($_GET['plugin_page']??'')==='nexa-slider')?'active':'','icon'=>'fa fa-sliders fa-fw','label'=>' Hero Slider','link'=>'admin.php?plugin_page=nexa-slider'], 65);
add_action('nexa_admin','nexa_slider_admin_render'); add_action('di_admin','nexa_slider_admin_render'); add_action('up_admin','nexa_slider_admin_render');
function nexa_slider_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-slider') return; $done=true;
    echo '<h2><i class="fa fa-sliders"></i> Hero Slider</h2><p>Place <code>[slider]</code> in any post/page. Slides come from <strong>Gallery Manager</strong> images first, then the theme <code>multi_slider</code> table.</p><p><a class="btn btn-primary" href="admin.php?plugin_page=nexa-gallery">Manage Slide Images</a></p>'; }

