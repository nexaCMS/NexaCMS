<?php
/** Nexa Comments — threaded comments + anti-spam + moderation (shares core `comments` table). */
function nexa_comments_ensure(): void { if(!table_exists('comments')) create_table('comments',['postid','avatar','name','url','email','body','dt','approved']); ensure_fields('comments',['parent_id']); }
nexa_comments_ensure();
function nexa_comments_clean_html(string $html): string {
    $clean = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><blockquote><code><pre>');
    $clean = preg_replace('/\s(on\w+|style)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean);
    $clean = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', 'href="#"', $clean);
    return $clean;
}
function nexa_comments_editor_assets(): string {
    static $done = false;
    if ($done || (string) get_setting('comments_editor', 'plain') !== 'tinymce') return '';
    if (!function_exists('nexa_tinymce_available') || !nexa_tinymce_available()) return '';
    $done = true;
    return '<script src="' . esc_url(nexa_tinymce_url()) . '"></script><script src="' . esc_url(home_url('admin/js/nexa-tinymce.js')) . '"></script>';
}
function nexa_comments_render($postId): string {
    nexa_comments_ensure(); $postId=(string)$postId; $out=''; $err='';
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && isset($_POST['nexa_comment_submit'])) {
        if (!nexa_verify_csrf($_POST['nexa_csrf_token']??null)) $err='Security check failed.';
        elseif (!empty($_POST['website']) || (time()-(int)($_POST['ts']??0))<3) $err=''; // spam silently dropped
        else {
            $name=trim((string)($_POST['cm_name']??'')); $email=trim((string)($_POST['cm_email']??'')); $body=trim((string)($_POST['cm_body']??'')); $parent=(int)($_POST['cm_parent']??0);
            $banned=array_filter(array_map('trim', explode(',', (string)get_setting('comments_banned_words',''))));
            foreach($banned as $bw) if($bw!=='' && stripos($body,$bw)!==false){$err='Your comment contains blocked words.';break;}
            if($err==='' && ($name===''||$body==='')) $err='Please add your name and a comment.';
            if($err==='') { query("INSERT INTO comments (postid,parent_id,name,email,body,dt,approved) VALUES (?,?,?,?,?,?,?)", [$postId,$parent,$name,$email,$body,date('Y-m-d H:i:s'), ((string)get_setting('comments_auto_approve','1')==='1'?'1':'0')]); $out='<div class="alert alert-success">Thanks! Your comment was posted.</div>'; }
        }
        if($err!=='') $out='<div class="alert alert-danger">'.esc_html($err).'</div>';
    }
    $rows=query("SELECT * FROM comments WHERE postid=? AND approved=? ORDER BY id ASC", [$postId,'1']);
    $byParent=[]; foreach($rows as $r) $byParent[(string)($r['parent_id']??'0')][]=$r;
    $render=function($pid,$depth) use (&$render,$byParent){ $h=''; foreach($byParent[(string)$pid]??[] as $c){ $h.='<div style="margin:12px 0 12px '.(min($depth,4)*22).'px;padding:10px;border-left:3px solid #337ab7;background:#f9f9f9"><strong>'.esc_html($c['name']??'Guest').'</strong> <small class="text-muted">'.esc_html($c['dt']??'').'</small><p>'.nl2br(esc_html($c['body']??'')).'</p><a href="#nexa-comment-form" onclick="document.getElementById(\'cm_parent\').value='.(int)$c['id'].'">Reply</a></div>'.$render($c['id'],$depth+1);} return $h; };
    return '<div class="nexa-comments"><h4>'.count($rows).' Comment(s)</h4>'.$out.$render('0',0)
      .'<form method="post" id="nexa-comment-form">'.nexa_csrf_field().'<input type="hidden" name="cm_parent" id="cm_parent" value="0"><input type="hidden" name="ts" value="'.time().'">'
      .'<p><input name="cm_name" required placeholder="Your name *" class="form-control"></p><p><input name="cm_email" type="email" placeholder="Email (not published)" class="form-control"></p>'
      .nexa_comments_editor_assets() . '<p style="display:none"><input name="website" tabindex="-1"></p><p><textarea name="cm_body" required rows="4" placeholder="Write a comment..." class="form-control nexa-comment-body"></textarea></p>'
      .'<button name="nexa_comment_submit" value="1" class="btn btn-primary">Post Comment</button></form></div>';
}
add_shortcode('nexa_comments', fn($a=[]): string => nexa_comments_render($a['post'] ?? ($_GET['p'] ?? get_page_id())));
add_filter('the_content', function($content){ global $post; if(!empty($_GET['p']) && ($post['comment_status']??'open')==='open' && ($post['post_type']??'')!=='error') return $content.nexa_comments_render($post['id']??$_GET['p']); return $content; });

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Comments', 'plugin_slug' => 'nexa-comments', 'label' => 'Comment thread', 'tag' => 'nexa_comments',
        'description' => 'Show the comment thread and form for a post. Leave the post ID blank to use the current post.',
        'fields' => [['name' => 'post', 'label' => 'Post ID (optional)', 'type' => 'number', 'min' => '1', 'placeholder' => 'Current post']],
        'template' => '[nexa_comments post="{post}"]',
    ]);
}
