<?php
/**
 * Nexa Cache — Admin UI. Open via Plugins sidebar or admin.php?plugin_page=nexa-cache
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-cache', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-cache') ? 'active' : '',
    'icon' => 'fa fa-bolt fa-fw', 'label' => ' Cache', 'link' => 'admin.php?plugin_page=nexa-cache',
], 47);
add_action('nexa_admin', 'nexa_cache_admin_render');

function nexa_cache_admin_render(): void {
    if (($_GET['plugin_page']??'')!=='nexa-cache') return;
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()) {
        if (($_POST['do']??'')==='purge') echo '<div class="alert alert-success">Purged '.nexa_cache_purge().' cached page(s).</div>';
        else { set_setting('cache_enabled',(string)($_POST['cache_enabled']??'1')); set_setting('cache_ttl',(string)max(10,(int)($_POST['cache_ttl']??300))); echo '<div class="alert alert-success">Cache settings saved.</div>'; }
    }
    $files=glob(nexa_cache_dir().'/*.html')?:[]; $size=0; foreach($files as $f) $size+=filesize($f);
    echo '<h2><i class="fa fa-bolt"></i> Speed Cache</h2><p>Cached pages: <strong>'.count($files).'</strong> &bull; Size: <strong>'.round($size/1024,1).' KB</strong> &bull; Folder: <code>content/cache/</code></p><form method="post" class="form-inline">'.nexa_csrf_field().'<label>Enabled <select name="cache_enabled" class="form-control"><option value="1"'.(((string)get_setting('cache_enabled','1')==='1')?' selected':'').'>On</option><option value="0"'.(((string)get_setting('cache_enabled','1')==='0')?' selected':'').'>Off</option></select></label> <label>TTL seconds <input type="number" name="cache_ttl" class="form-control" value="'.esc_attr((string)get_setting('cache_ttl','300')).'"></label> <button class="btn btn-primary">Save</button></form><form method="post" style="margin-top:12px">'.nexa_csrf_field().'<button name="do" value="purge" class="btn btn-danger">Purge All Cache</button></form><p class="text-muted">Cache auto-clears whenever a post/page is saved. Logged-in and POST requests are never cached.</p>';
}
