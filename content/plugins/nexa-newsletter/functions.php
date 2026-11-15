<?php
/** Nexa Newsletter — subscribers + token unsubscribe (SQL query()). */
function nexa_newsletter_ensure(): void { if(!table_exists('newsletter_subs')) create_table('newsletter_subs',['email','name','status','token','created_at','unsubscribed_at']); }
nexa_newsletter_ensure();
function nexa_newsletter_unsubscribe(): void { $t=trim((string)($_GET['nexa_unsubscribe']??'')); if($t==='') return; nexa_newsletter_ensure(); query("UPDATE newsletter_subs SET status=?, unsubscribed_at=? WHERE token=?", ['unsubscribed',date('Y-m-d H:i:s'),$t]); echo '<div style="font-family:sans-serif;max-width:600px;margin:40px auto"><h2>You have been unsubscribed.</h2><p>Sorry to see you go. <a href="'.esc_url(home_url()).'">Return to site</a></p></div>'; exit; }
add_shortcode('newsletter_form', function($a=[]): string {
    nexa_newsletter_ensure(); $msg='';
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && isset($_POST['nexa_newsletter_submit'])) {
        if (!nexa_verify_csrf($_POST['nexa_csrf_token']??null)) $msg='<div class="alert alert-danger">Security check failed.</div>';
        elseif (!empty($_POST['website'])) $msg='<div class="alert alert-success">Thanks for subscribing!</div>';
        else { $email=trim((string)($_POST['n_email']??'')); $name=trim((string)($_POST['n_name']??''));
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $msg='<div class="alert alert-danger">Please enter a valid email.</div>';
            else { $ex=query("SELECT id FROM newsletter_subs WHERE email=?",[$email]); if(!empty($ex)) $msg='<div class="alert alert-info">You are already subscribed.</div>'; else { query("INSERT INTO newsletter_subs (email,name,status,token,created_at,unsubscribed_at) VALUES (?,?,?,?,?,?)", [$email,$name,'subscribed',bin2hex(random_bytes(16)),date('Y-m-d H:i:s'),'']); $msg='<div class="alert alert-success">Thanks for subscribing!</div>'; } }
        }
    }
    return $msg.'<form method="post" class="nexa-newsletter-form">'.nexa_csrf_field().'<p><input type="text" name="n_name" placeholder="Your name" class="form-control"></p><p><input type="email" name="n_email" required placeholder="Email address *" class="form-control"></p><p style="display:none"><input name="website" tabindex="-1"></p><button name="nexa_newsletter_submit" value="1" class="btn btn-primary">Subscribe</button></form>';
});

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Newsletter', 'plugin_slug' => 'nexa-newsletter', 'label' => 'Newsletter signup form', 'tag' => 'newsletter_form',
        'description' => 'Embed the newsletter subscription form.', 'shortcode' => '[newsletter_form]',
    ]);
}
