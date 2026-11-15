<?php
/**  Anti-Spam - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'nexa-antispam','class'=>(($_GET['plugin_page']??'')==='nexa-antispam')?'active':'','icon'=>'fa fa-ban fa-fw','label'=>' Anti-Spam','link'=>'admin.php?plugin_page=nexa-antispam'], 54);
add_action('nexa_admin','nexa_antispam_admin_render'); add_action('di_admin','nexa_antispam_admin_render'); add_action('up_admin','nexa_antispam_admin_render');
function nexa_antispam_admin_render(): void { static $done=false; if($done) return; if(($_GET['plugin_page']??'')!=='nexa-antispam') return; $done=true;
    nexa_antispam_ensure();
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()){ if(($_POST['do']??'')==='clear') query("DELETE FROM spam_log"); else set_setting('antispam_words',trim((string)$_POST['antispam_words'])); echo '<div class="alert alert-success">Anti-spam updated.</div>'; }
    echo '<h2><i class="fa fa-ban"></i> Anti-Spam Shield</h2><form method="post">'.nexa_csrf_field().'<div class="form-group"><label>Banned words (comma list)</label><input name="antispam_words" class="form-control" value="'.esc_attr((string)get_setting('antispam_words','viagra,casino,crypto giveaway,free money')).'"></div><button class="btn btn-primary">Save</button></form><hr><form method="post">'.nexa_csrf_field().'<button name="do" value="clear" class="btn btn-warning btn-sm">Clear spam log</button></form><table class="table table-striped"><thead><tr><th>When</th><th>IP</th><th>Reason</th><th>Content</th></tr></thead><tbody>';
    foreach(query("SELECT * FROM spam_log ORDER BY id DESC LIMIT 50") as $r) echo '<tr><td>'.esc_html($r['created_at']).'</td><td>'.esc_html($r['ip']).'</td><td>'.esc_html($r['reason']).'</td><td>'.esc_html(mb_substr((string)$r['content'],0,120)).'</td></tr>';
    echo '</tbody></table>'; }

