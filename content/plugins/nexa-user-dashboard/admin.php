<?php
/** Nexa User Dashboard — admin info screen. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-user-dashboard', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-user-dashboard') ? 'active' : '',
    'icon' => 'fa fa-user fa-fw', 'label' => ' User Dashboard', 'link' => 'admin.php?plugin_page=nexa-user-dashboard',
], 48);
add_action('nexa_admin', 'nexa_udash_admin_render');

function nexa_udash_admin_render(): void {
    if (($_GET['plugin_page'] ?? '') !== 'nexa-user-dashboard') return;
    $pid = (string) get_setting('nexa_udash_page_id', '');
    echo '<h2><i class="fa fa-user"></i> Nexa User Dashboard <small>a front-end home for your members</small></h2>';
    echo '<div class="row"><div class="col-md-7"><div class="panel panel-default"><div class="panel-heading">How it works</div><div class="panel-body">'
        . '<p>Members get a complete front-end dashboard — <strong>they never need to visit <code>/admin/</code></strong>:</p>'
        . '<ul><li><strong>Overview</strong> — their post counts and quick actions</li>'
        . '<li><strong>My Posts</strong> — list, edit and delete their own posts/pages</li>'
        . '<li><strong>Write New</strong> — create posts from the front end (subscribers save drafts; authors/editors can publish)</li>'
        . '<li><strong>Profile</strong> — display name, email, website, phone, address</li>'
        . '<li><strong>Password</strong> — change password with current-password verification</li></ul>'
        . '<p>Guests see a login form (and a registration form when <em>Settings → General → “Users can register”</em> is on). Put the dashboard anywhere with the shortcode <code>[user_dashboard]</code>.</p>'
        . ($pid !== '' && is_numeric($pid)
            ? '<div class="alert alert-success" style="margin-bottom:0;">Dashboard page is live: <a href="' . esc_url(post_url((int) $pid)) . '" target="_blank">' . esc_html(post_url((int) $pid)) . '</a> — add it to a menu so members can find it.</div>'
            : '<div class="alert alert-info" style="margin-bottom:0;">The dashboard page is created automatically the next time someone visits the front end (slug <code>dashboard</code>). Or create any page yourself and paste <code>[user_dashboard]</code> into it.</div>')
        . '</div></div></div>'
        . '<div class="col-md-5"><div class="panel panel-default"><div class="panel-heading">Good to know</div><div class="panel-body">'
        . '<ul><li>Members only ever see and edit <strong>their own</strong> content (matched by author name).</li>'
        . '<li>Admins and editors keep using the full admin as usual.</li>'
        . '<li>Consider adding “My Dashboard” to your primary menu with a <code>[menu]</code>-powered page or <strong>Appearance → Menus</strong>.</li>'
        . '<li>The login uses the same accounts and session as the admin — a member who logs in here is recognized across the site (and by Nexa Analytics as a logged-in visitor).</li></ul>'
        . '</div></div></div></div>';
}
