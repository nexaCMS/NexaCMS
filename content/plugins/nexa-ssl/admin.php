<?php
/**  SSL & Headers - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-ssl','class'=>(($_GET['plugin_page']??'')==='nexa-ssl')?'active':'','icon'=>'fa fa-lock fa-fw','label'=>' SSL & Headers','link'=>'admin.php?plugin_page=nexa-ssl'], 57);
add_action('nexa_admin','nexa_ssl_admin_render'); add_action('di_admin','nexa_ssl_admin_render'); add_action('up_admin','nexa_ssl_admin_render');
function nexa_ssl_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-ssl') return; $done=true;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){ set_setting('ssl_force',(string)($_POST['ssl_force']??'0')); echo '<div class="alert alert-success">SSL settings saved.</div>'; }
    $detected=((($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https')||!empty($_SERVER['HTTPS']))?'HTTPS detected':'HTTP (or behind proxy - enable Force HTTPS only after your certificate works)';
    echo '<h2><i class="fa fa-lock"></i> SSL &amp; Security Headers</h2><p>Status: <strong>'.esc_html($detected).'</strong></p><form method="post">'.nexa_csrf_field().'<label><input type="checkbox" name="ssl_force" value="1"'.(((string)get_setting('ssl_force','0')==='1')?' checked':'').'> Force HTTPS (301 redirect, GET only, localhost exempt)</label><br><button class="btn btn-primary">Save</button></form><p class="text-muted">Security headers (nosniff, frame, referrer) are always sent by this plugin when enabled.</p>'; }

