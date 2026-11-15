<?php
/**  Backups+ - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-backup','class'=>(($_GET['plugin_page']??'')==='nexa-backup')?'active':'','icon'=>'fa fa-archive fa-fw','label'=>' Backups+','link'=>'admin.php?plugin_page=nexa-backup'], 55);
add_action('nexa_admin','nexa_backup_admin_render'); add_action('di_admin','nexa_backup_admin_render'); add_action('up_admin','nexa_backup_admin_render');
function nexa_backup_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-backup') return; $done=true;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){ if(($_POST['do']??'')==='create'){ $n=nexa_backup_create(); echo $n?'<div class="alert alert-success">Backup created: '.esc_html($n).'</div>':'<div class="alert alert-danger">Backup failed (ZipArchive missing?).</div>'; } if(($_POST['do']??'')==='del'){ $f=basename((string)$_POST['file']); @unlink(nexa_backup_dir().'/'.$f); echo '<div class="alert alert-success">Backup deleted.</div>'; } }
    echo '<h2><i class="fa fa-archive"></i> Backups+ (database + config)</h2><form method="post">'.nexa_csrf_field().'<button name="do" value="create" class="btn btn-primary"><i class="fa fa-download"></i> Create Backup Now</button></form><hr><table class="table table-striped"><thead><tr><th>File</th><th>Size</th><th>Modified</th><th></th></tr></thead><tbody>';
    foreach(glob(nexa_backup_dir().'/*.zip')?:[] as $f) echo '<tr><td>'.esc_html(basename($f)).'</td><td>'.round(filesize($f)/1024,1).' KB</td><td>'.date('Y-m-d H:i:s',filemtime($f)).'</td><td><form method="post">'.nexa_csrf_field().'<input type="hidden" name="file" value="'.esc_attr(basename($f)).'"><button name="do" value="del" class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    echo '</tbody></table><p class="text-muted">Backups are stored in <code>content/backup/</code> (blocked from direct web access by router.php). For a full migration also copy <code>content/</code>. Core database tools: Admin &rarr; Database &amp; Backups.</p>'; }

