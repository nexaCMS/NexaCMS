<?php
/**  Image Optimizer - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-image-optimizer','class'=>(($_GET['plugin_page']??'')==='nexa-image-optimizer')?'active':'','icon'=>'fa fa-picture-o fa-fw','label'=>' Image Optimizer','link'=>'admin.php?plugin_page=nexa-image-optimizer'], 64);
add_action('nexa_admin','nexa_img_admin_render'); add_action('di_admin','nexa_img_admin_render'); add_action('up_admin','nexa_img_admin_render');
function nexa_img_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-image-optimizer') return; $done=true;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){ $n=0; foreach(nexa_img_scan() as $im) if(nexa_img_resize($im['path'],(int)($_POST['maxw']??1600))) $n++; echo '<div class="alert alert-success">Optimized '.$n.' image(s).</div>'; }
    $imgs=nexa_img_scan(); $total=array_sum(array_column($imgs,'size'));
    echo '<h2><i class="fa fa-picture-o"></i> Image Optimizer</h2><p>'.count($imgs).' image(s), '.round($total/1024/1024,2).' MB total in <code>content/uploads/</code>. '.(function_exists('imagecreatefromjpeg')?'GD available.':'<strong>GD not available on this server - resize disabled.</strong>').'</p><form method="post" class="form-inline">'.nexa_csrf_field().'<label>Max width <input type="number" name="maxw" class="form-control" value="1600" style="width:100px"></label> <button class="btn btn-primary">Resize Oversized Images</button></form><hr><table class="table table-striped"><thead><tr><th>File</th><th>Size</th></tr></thead><tbody>';
    foreach(array_slice($imgs,0,30) as $im) echo '<tr><td>'.esc_html(str_replace((string)($GLOBALS['config']['abs']??''),'',$im['path'])).'</td><td>'.round($im['size']/1024,1).' KB</td></tr>';
    echo '</tbody></table>'; }

