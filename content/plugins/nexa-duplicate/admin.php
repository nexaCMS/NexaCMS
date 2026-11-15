<?php
/**  Duplicate Posts - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-duplicate','class'=>(($_GET['plugin_page']??'')==='nexa-duplicate')?'active':'','icon'=>'fa fa-copy fa-fw','label'=>' Duplicate Posts','link'=>'admin.php?plugin_page=nexa-duplicate'], 59);
add_action('nexa_admin','nexa_duplicate_admin_render'); add_action('di_admin','nexa_duplicate_admin_render'); add_action('up_admin','nexa_duplicate_admin_render');
function nexa_duplicate_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-duplicate') return; $done=true;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){ $row=query("SELECT * FROM posts WHERE id=?",[(int)$_POST['id']]); if(!empty($row)){ $r=$row[0]; unset($r['id']); $r['post_title']=(string)$r['post_title'].' (Copy)'; $r['post_name']=(string)($r['post_name']??'copy').'-copy'; $r['post_status']='draft'; $nid=update_post($r,'insert'); echo '<div class="alert alert-success">Cloned as draft post #'.(int)$nid.' - edit it under Posts.</div>'; } }
    echo '<h2><i class="fa fa-copy"></i> Duplicate Posts &amp; Pages</h2><table class="table table-striped"><thead><tr><th>ID</th><th>Title</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach(query("SELECT id,post_title,post_type,post_status FROM posts ORDER BY id DESC LIMIT 50") as $r) echo '<tr><td>'.(int)$r['id'].'</td><td>'.esc_html($r['post_title']).'</td><td>'.esc_html($r['post_type']).'</td><td>'.esc_html($r['post_status']).'</td><td><form method="post">'.nexa_csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-xs btn-primary">Duplicate as Draft</button></form></td></tr>';
    echo '</tbody></table>'; }

