<?php
/**
 * Nexa Newsletter — Admin UI. Open via Plugins sidebar or admin.php?plugin_page=nexa-newsletter
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-newsletter', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-newsletter') ? 'active' : '',
    'icon' => 'fa fa-paper-plane fa-fw', 'label' => ' Newsletter', 'link' => 'admin.php?plugin_page=nexa-newsletter',
], 49);
add_action('nexa_admin', 'nexa_newsletter_admin_render');

function nexa_newsletter_admin_render(): void {
    if (($_GET['plugin_page']??'')!=='nexa-newsletter') return; nexa_newsletter_ensure();
    if (($_GET['export']??'')==='csv') { header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="subscribers.csv"'); $o=fopen('php://output','w'); fputcsv($o,['id','email','name','status','created_at']); foreach(query("SELECT * FROM newsletter_subs ORDER BY id ASC") as $r) fputcsv($o,[$r['id'],$r['email'],$r['name'],$r['status'],$r['created_at']]); fclose($o); exit; }
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()) { query("DELETE FROM newsletter_subs WHERE id=?",[(int)($_POST['id']??0)]); echo '<div class="alert alert-success">Subscriber removed.</div>'; }
    $active=count(query("SELECT id FROM newsletter_subs WHERE status=?",['subscribed']));
    echo '<h2><i class="fa fa-paper-plane"></i> Newsletter Subscribers <span class="badge">'.$active.' active</span></h2><p><a class="btn btn-success" href="admin.php?plugin_page=nexa-newsletter&export=csv"><i class="fa fa-download"></i> Export CSV</a></p><table class="table table-striped"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Joined</th><th>Unsubscribe Link</th><th></th></tr></thead><tbody>';
    foreach(query("SELECT * FROM newsletter_subs ORDER BY id DESC") as $r) echo '<tr><td>'.esc_html($r['email']).'</td><td>'.esc_html($r['name']).'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html($r['created_at']).'</td><td><small>'.esc_html(home_url('?nexa_unsubscribe='.$r['token'])).'</small></td><td><form method="post">'.nexa_csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-xs btn-danger" onclick="return confirm(\'Remove subscriber?\')">Delete</button></form></td></tr>';
    echo '</tbody></table><p>Signup form shortcode: <code>[newsletter_form]</code></p>';
}
