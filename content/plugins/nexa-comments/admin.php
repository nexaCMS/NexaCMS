<?php
/**
 * Nexa Comments — Admin UI. Open via Plugins sidebar or admin.php?plugin_page=nexa-comments
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-comments', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-comments') ? 'active' : '',
    'icon' => 'fa fa-comments fa-fw', 'label' => ' Comments', 'link' => 'admin.php?plugin_page=nexa-comments',
], 44);
add_action('nexa_admin', 'nexa_comments_admin_render');

function nexa_comments_admin_render(): void {
    if (($_GET['plugin_page']??'')!=='nexa-comments') return; nexa_comments_ensure();
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()) {
        if(isset($_POST['comments_auto_approve'])) { set_setting('comments_auto_approve', (string)($_POST['comments_auto_approve']??'1')); set_setting('comments_banned_words', trim((string)($_POST['comments_banned_words']??''))); set_setting('comments_editor', (string)($_POST['comments_editor']??'plain')); echo '<div class="alert alert-success">Comment settings saved.</div>'; }
        else { $id=(int)($_POST['id']??0); $do=(string)($_POST['do']??''); if($do==='approve') query("UPDATE comments SET approved=? WHERE id=?",['1',$id]); if($do==='unapprove') query("UPDATE comments SET approved=? WHERE id=?",['0',$id]); if($do==='delete') query("DELETE FROM comments WHERE id=?",[$id]); echo '<div class="alert alert-success">Comment updated.</div>'; }
    }
    echo '<h2><i class="fa fa-comments"></i> Comment Moderation</h2><form method="post" class="form-inline">'.nexa_csrf_field().'<label>Auto-approve <select name="comments_auto_approve" class="form-control"><option value="1"'.(((string)get_setting('comments_auto_approve','1')==='1')?' selected':'').'>Yes</option><option value="0"'.(((string)get_setting('comments_auto_approve','1')==='0')?' selected':'').'>No — moderate first</option></select></label> <label>Banned words (comma list) <input name="comments_banned_words" class="form-control" value="'.esc_attr((string)get_setting('comments_banned_words','')).'"></label> <button class="btn btn-primary">Save</button></form><hr>';
    $rows=query("SELECT * FROM comments ORDER BY id DESC");
    echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Date</th><th>Post</th><th>Author</th><th>Comment</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    foreach($rows as $r) echo '<tr><td>'.esc_html($r['dt']??'').'</td><td>#'.esc_html($r['postid']??'').'</td><td>'.esc_html($r['name']??'').'</td><td>'.esc_html(mb_substr((string)($r['body']??''),0,140)).'</td><td>'.(((string)($r['approved']??'')==='1')?'Approved':'Pending').'</td><td><form method="post" style="display:inline">'.nexa_csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button name="do" value="approve" class="btn btn-xs btn-success">Approve</button> <button name="do" value="unapprove" class="btn btn-xs btn-default">Unapprove</button> <button name="do" value="delete" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete comment?\')">Delete</button></form></td></tr>';
    echo '</tbody></table></div>'.(empty($rows)?'<p>No comments yet.</p>':'');
}
