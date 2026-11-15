<?php
/** Nexa Contact — [contact_form] + Admin Inbox (SQL query() powered). */
function nexa_contact_ensure(): void {
    if (!table_exists('contact_messages')) create_table('contact_messages', ['name','email','subject','message','status','ip','created_at']);
}
nexa_contact_ensure();
add_shortcode('contact_form', function($atts=[]): string {
    nexa_contact_ensure();
    $msg='';
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && isset($_POST['nexa_contact_submit'])) {
        if (!nexa_verify_csrf($_POST['nexa_csrf_token']??null)) $msg='<div class="alert alert-danger">Security check failed. Please retry.</div>';
        elseif (!empty($_POST['website'])) $msg='<div class="alert alert-success">Thank you! Your message has been received.</div>'; // honeypot: pretend success
        else {
            $name=trim((string)($_POST['c_name']??'')); $email=trim((string)($_POST['c_email']??'')); $sub=trim((string)($_POST['c_subject']??'Website Contact')); $body=trim((string)($_POST['c_message']??''));
            if ($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$body==='') $msg='<div class="alert alert-danger">Please add your name, a valid email and a message.</div>';
            else {
                query("INSERT INTO contact_messages (name,email,subject,message,status,ip,created_at) VALUES (?,?,?,?,?,?,?)", [$name,$email,$sub,$body,'unread',$_SERVER['REMOTE_ADDR']??'',date('Y-m-d H:i:s')]);
                do_action('nexa_contact_received', $email);
                $msg='<div class="alert alert-success">Thank you, '.esc_html($name).'! Your message has been sent to our inbox.</div>';
            }
        }
    }
    return $msg.'<form method="post" class="nexa-contact-form">'.nexa_csrf_field()
      .'<p><label>Your Name *<br><input type="text" name="c_name" required class="form-control" style="width:100%"></label></p>'
      .'<p><label>Email Address *<br><input type="email" name="c_email" required class="form-control" style="width:100%"></label></p>'
      .'<p><label>Subject<br><input type="text" name="c_subject" class="form-control" style="width:100%"></label></p>'
      .'<p><label>Message *<br><textarea name="c_message" rows="5" required class="form-control" style="width:100%"></textarea></label></p>'
      .'<p style="display:none"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>'
      .'<p><button type="submit" name="nexa_contact_submit" value="1" class="btn btn-primary">Send Message</button></p></form>';
});

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Contact', 'plugin_slug' => 'nexa-contact', 'label' => 'Contact form', 'tag' => 'contact_form',
        'description' => 'Embed the contact form; submissions land in the Contact Inbox.', 'shortcode' => '[contact_form]',
    ]);
}
