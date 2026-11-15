<?php
/**  Site Kit (Google) - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-sitekit','class'=>(($_GET['plugin_page']??'')==='nexa-sitekit')?'active':'','icon'=>'fa fa-google fa-fw','label'=>' Site Kit (Google)','link'=>'admin.php?plugin_page=nexa-sitekit'], 53);
add_action('nexa_admin','nexa_sitekit_admin_render'); add_action('di_admin','nexa_sitekit_admin_render'); add_action('up_admin','nexa_sitekit_admin_render');
function nexa_sitekit_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-sitekit') return; $done=true;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){ set_setting('sitekit_ga4',trim((string)$_POST['sitekit_ga4'])); set_setting('sitekit_verify',trim((string)$_POST['sitekit_verify'])); echo '<div class="alert alert-success">Site Kit settings saved.</div>'; }
    echo '<h2><i class="fa fa-google"></i> Site Kit - Google Tools</h2><form method="post">'.nexa_csrf_field().'<div class="form-group"><label>GA4 Measurement ID (G-XXXXXXX)</label><input name="sitekit_ga4" class="form-control" value="'.esc_attr((string)get_setting('sitekit_ga4','')).'"></div><div class="form-group"><label>Search Console verification meta content</label><input name="sitekit_verify" class="form-control" value="'.esc_attr((string)get_setting('sitekit_verify','')).'"></div><button class="btn btn-primary">Save</button></form>';
    if(table_exists('analytics_hits')){ $t=query("SELECT COUNT(*) AS c FROM analytics_hits"); $td=query("SELECT COUNT(*) AS c FROM analytics_hits WHERE day=?",[date('Y-m-d')]); echo '<hr><p>Local Nexa Analytics: <strong>'.(int)($t[0]['c']??0).'</strong> total pageviews, <strong>'.(int)($td[0]['c']??0).'</strong> today. Full detail: Admin &rarr; Analytics.</p>'; }
    echo '<p class="text-muted">Add your GA4 ID and NexaCMS prints the gtag in <code>head</code> on every page - no theme edits.</p>'; }

