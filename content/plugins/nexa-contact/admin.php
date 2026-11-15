<?php
/**
 * Nexa Contact — Admin UI. Open via Plugins sidebar or admin.php?plugin_page=nexa-contact
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-contact', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-contact') ? 'active' : '',
    'icon' => 'fa fa-envelope fa-fw', 'label' => ' Contact Inbox', 'link' => 'admin.php?plugin_page=nexa-contact',
], 42);
add_action('nexa_admin', 'nexa_contact_admin_render');

function nexa_contact_admin_render(): void {
    if (($_GET['plugin_page']??'')!=='nexa-contact') return;
    nexa_contact_ensure();
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()) {
        $id=(int)($_POST['id']??0);
        if (($_POST['do']??'')==='read') query("UPDATE contact_messages SET status=? WHERE id=?", ['read',$id]);
        if (($_POST['do']??'')==='delete') query("DELETE FROM contact_messages WHERE id=?", [$id]);
        echo '<div class="alert alert-success">Inbox updated.</div>';
    }
    $rows=query("SELECT * FROM contact_messages ORDER BY id DESC"); $unread=count(query("SELECT id FROM contact_messages WHERE status=?",['unread']));
    echo '<h2><i class="fa fa-envelope"></i> Contact Inbox <span class="badge">'.(int)$unread.' unread</span></h2><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Date</th><th>From</th><th>Subject</th><th>Message</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    foreach($rows as $r) echo '<tr><td>'.esc_html($r['created_at']??'').'</td><td>'.esc_html(($r['name']??'').' <'.$r['email'].'>').'</td><td>'.esc_html($r['subject']??'').'</td><td>'.esc_html(mb_substr((string)($r['message']??''),0,160)).'</td><td>'.esc_html($r['status']??'').'</td><td><form method="post" style="display:inline">'.nexa_csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button name="do" value="read" class="btn btn-xs btn-success">Mark Read</button> <button name="do" value="delete" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete this message?\')">Delete</button></form></td></tr>';
    echo '</tbody></table></div>'.(empty($rows)?'<p>No messages yet. Add <code>[contact_form]</code> to any page.</p>':'');
}
