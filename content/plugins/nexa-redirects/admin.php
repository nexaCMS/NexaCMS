<?php
/**  Redirects & 404s - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-redirects','class'=>(($_GET['plugin_page']??'')==='nexa-redirects')?'active':'','icon'=>'fa fa-random fa-fw','label'=>' Redirects & 404s','link'=>'admin.php?plugin_page=nexa-redirects'], 63);
add_action('nexa_admin','nexa_redirects_admin_render'); add_action('di_admin','nexa_redirects_admin_render'); add_action('up_admin','nexa_redirects_admin_render');
function nexa_redirects_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-redirects') return; $done=true;
    nexa_redirects_ensure();
    $siteId = function_exists('nexa_redirects_site_id') ? nexa_redirects_site_id() : 0;
    $siteName = 'the main site';
    if ($siteId > 0 && function_exists('nexa_current_site') && ($cs = nexa_current_site())) $siteName = 'subsite "' . ($cs['name'] ?? $cs['slug'] ?? '') . '"';
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){
        if(($_POST['do']??'')==='del') query("DELETE FROM redirects WHERE id=? AND site_id=?",[(int)$_POST['id'],(string)$siteId]);
        elseif(($_POST['do']??'')==='settings'){ set_setting('redirects_preserve_params', trim((string)($_POST['preserve_params']??'theme'))); }
        elseif(($_POST['do']??'')==='clear404') query("DELETE FROM log_404 WHERE site_id=?",[(string)$siteId]);
        else query("INSERT INTO redirects (source,target,code,hits,created_at,site_id) VALUES (?,?,?,?,?,?)",['/'.ltrim(trim((string)$_POST['source']),'/'),trim((string)$_POST['target']),(int)$_POST['code'],0,date('Y-m-d H:i:s'),(string)$siteId]);
        echo '<div class="alert alert-success">Redirects updated.</div>';
    }
    echo '<h2><i class="fa fa-random"></i> Redirects</h2>'
       . '<div class="alert alert-info"><strong>Per-site rules:</strong> you are editing redirects for <strong>' . esc_html($siteName) . '</strong>. They apply <em>only</em> to this site — a main-site redirect such as <code>/ &rarr; /portfolio</code> no longer reroutes subsites, and local targets (e.g. <code>/portfolio</code>) always resolve inside the site the visitor is on.</div>'
       . '<form method="post" class="form-inline" style="margin-bottom:14px;">'.nexa_csrf_field().'<input type="hidden" name="do" value="settings"><label>Keep these GET parameters across redirects: <input name="preserve_params" class="form-control" style="min-width:280px;" value="'.esc_attr((string)get_setting('redirects_preserve_params','theme')).'"></label> <button class="btn btn-default">Save</button> <span class="text-muted"><small>comma-separated (e.g. <code>theme,utm_source</code>) or <code>*</code> for all. Parameters already in the target URL always win.</small></span></form><form method="post" class="form-inline">'.nexa_csrf_field().'<input name="source" required class="form-control" placeholder="/old-page"> <input name="target" required class="form-control" placeholder="/?p=2 or https://..."> <select name="code" class="form-control"><option>301</option><option>302</option><option>307</option></select> <button class="btn btn-primary">Add Redirect</button></form><hr><table class="table table-striped"><thead><tr><th>From</th><th>To</th><th>Code</th><th>Hits</th><th></th></tr></thead><tbody>';
    foreach(query("SELECT * FROM redirects WHERE site_id=? ORDER BY id DESC",[(string)$siteId]) as $r) echo '<tr><td>'.esc_html($r['source']).'</td><td>'.esc_html($r['target']).'</td><td>'.esc_html($r['code']).'</td><td>'.(int)$r['hits'].'</td><td><form method="post">'.nexa_csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button name="do" value="del" class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    echo '</tbody></table><h3>404 Log <small class="text-muted">(this site only)</small></h3><form method="post">'.nexa_csrf_field().'<button name="do" value="clear404" class="btn btn-warning btn-sm">Clear 404 log</button></form><table class="table table-striped"><thead><tr><th>Path</th><th>Hits</th><th>Last Seen</th><th>Referer</th></tr></thead><tbody>';
    foreach(query("SELECT * FROM log_404 WHERE site_id=? ORDER BY hits DESC LIMIT 30",[(string)$siteId]) as $r) echo '<tr><td>'.esc_html($r['path']).'</td><td>'.(int)$r['hits'].'</td><td>'.esc_html($r['last_seen']).'</td><td>'.esc_html(mb_substr((string)$r['referer'],0,60)).'</td></tr>';
    echo '</tbody></table>'; }
