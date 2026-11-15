<?php
/**
 * Nexa Security — Admin UI. Open via Plugins sidebar or admin.php?plugin_page=nexa-security
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-security', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-security') ? 'active' : '',
    'icon' => 'fa fa-shield fa-fw', 'label' => ' Security', 'link' => 'admin.php?plugin_page=nexa-security',
], 48);
add_action('nexa_admin', 'nexa_security_admin_render');

function nexa_security_admin_render(): void {
    if (($_GET['plugin_page']??'')!=='nexa-security') return; nexa_security_ensure();
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()) {
        if (($_POST['do']??'')==='unblock') { query("DELETE FROM login_attempts WHERE ip=?",[(string)($_POST['ip']??'')]); echo '<div class="alert alert-success">IP unblocked.</div>'; }
        else { set_setting('security_max_attempts',(string)max(2,(int)($_POST['security_max_attempts']??5))); set_setting('security_lock_seconds',(string)max(60,(int)($_POST['security_lock_seconds']??900))); echo '<div class="alert alert-success">Security settings saved.</div>'; }
    }
    echo '<h2><i class="fa fa-shield"></i> Security — Login Guard &amp; Audit Log</h2><form method="post" class="form-inline">'.nexa_csrf_field().'<label>Max failed attempts <input type="number" name="security_max_attempts" class="form-control" value="'.esc_attr((string)get_setting('security_max_attempts','5')).'"></label> <label>Lock seconds <input type="number" name="security_lock_seconds" class="form-control" value="'.esc_attr((string)get_setting('security_lock_seconds','900')).'"></label> <button class="btn btn-primary">Save</button></form><h4>Login Attempts / Blocks</h4><table class="table table-striped"><thead><tr><th>IP</th><th>Last User</th><th>Attempts</th><th>Blocked Until</th><th></th></tr></thead><tbody>';
    foreach(query("SELECT * FROM login_attempts ORDER BY last_attempt DESC") as $r) echo '<tr><td>'.esc_html($r['ip']).'</td><td>'.esc_html($r['username']).'</td><td>'.(int)$r['attempts'].'</td><td>'.(((int)($r['blocked_until']??0)>time())?date('Y-m-d H:i:s',(int)$r['blocked_until']):'—').'</td><td><form method="post">'.nexa_csrf_field().'<input type="hidden" name="ip" value="'.esc_attr($r['ip']).'"><button name="do" value="unblock" class="btn btn-xs btn-success">Unblock</button></form></td></tr>';
    echo '</tbody></table><h4>Audit Log (latest 50)</h4><table class="table table-striped"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody>';
    foreach(query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 50") as $r) echo '<tr><td>'.esc_html($r['created_at']).'</td><td>'.esc_html($r['actor']).'</td><td>'.esc_html($r['action']).'</td><td>'.esc_html($r['details']).'</td><td>'.esc_html($r['ip']).'</td></tr>';
    echo '</tbody></table>';
}
