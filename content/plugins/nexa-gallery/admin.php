<?php
/**
 * Nexa Gallery — Admin UI. Open via Plugins sidebar or admin.php?plugin_page=nexa-gallery
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-gallery', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-gallery') ? 'active' : '',
    'icon' => 'fa fa-picture-o fa-fw', 'label' => ' Gallery Manager', 'link' => 'admin.php?plugin_page=nexa-gallery',
], 46);
add_action('nexa_admin', 'nexa_gallery_admin_render');

function nexa_gallery_admin_render(): void {
    if (($_GET['plugin_page']??'')!=='nexa-gallery') return; nexa_gallery_ensure();
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()) {
        if (($_POST['do']??'')==='delete') query("DELETE FROM gallery_items WHERE id=?",[(int)($_POST['id']??0)]);
        else {
            $url=trim((string)($_POST['image_url']??''));
            if (!empty($_FILES['image_file']['tmp_name']) && is_uploaded_file($_FILES['image_file']['tmp_name'])) {
                $ext=strtolower(pathinfo((string)$_FILES['image_file']['name'],PATHINFO_EXTENSION)); if(!in_array($ext,['jpg','jpeg','png','gif','webp'],true)) $ext='jpg';
                $dir=rtrim((string)($GLOBALS['config']['abs']??dirname(__DIR__,3)),'/').'/content/uploads/gallery'; @mkdir($dir,0755,true); $fname='img_'.time().'_'.random_int(100,999).'.'.$ext;
                if (move_uploaded_file($_FILES['image_file']['tmp_name'],$dir.'/'.$fname)) $url=home_url('content/uploads/gallery/'.$fname);
            }
            if ($url!=='') query("INSERT INTO gallery_items (title,caption,image_url,sort,status,created_at) VALUES (?,?,?,?,?,?)", [trim((string)($_POST['title']??'Untitled')),trim((string)($_POST['caption']??'')),$url,(int)($_POST['sort']??0),'active',date('Y-m-d H:i:s')]);
        }
        echo '<div class="alert alert-success">Gallery updated.</div>';
    }
    echo '<h2><i class="fa fa-picture-o"></i> Gallery Manager</h2><form method="post" enctype="multipart/form-data" class="form-inline">'.nexa_csrf_field().'<input name="title" class="form-control" placeholder="Title"> <input name="caption" class="form-control" placeholder="Caption"> <input name="sort" type="number" class="form-control" placeholder="Sort" style="width:80px"> <input name="image_url" class="form-control" placeholder="...or Image URL"> <input type="file" name="image_file" class="form-control"> <button class="btn btn-primary">Add Image</button></form><hr><div class="row">';
    foreach(query("SELECT * FROM gallery_items ORDER BY sort ASC, id ASC") as $r) echo '<div class="col-md-3"><div class="thumbnail"><img src="'.esc_url($r['image_url']).'" style="height:140px;object-fit:cover;width:100%"><div class="caption"><strong>'.esc_html($r['title']).'</strong><br><small>Sort: '.esc_html($r['sort']).'</small><form method="post">'.nexa_csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button name="do" value="delete" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete image?\')">Delete</button></form></div></div></div>';
    echo '</div><p>Display anywhere with <code>[gallery]</code>.</p>';
}
