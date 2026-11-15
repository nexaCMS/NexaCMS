<?php
/**
 * Flip Turn — custom error template (404 / 403 / 500 / 503 & offline).
 * Rendered by nexa_render_error_page() with $GLOBALS['nexa_error'] set.
 * Edit freely: this file belongs to the theme. Themes WITHOUT an error
 * template fall back to the Nexa Error Pages plugin (admin-created pages).
 */
$nxErr = $GLOBALS['nexa_error'] ?? ['status' => 404, 'title' => '404 — Page Not Found', 'message' => 'We could not find the page you requested.'];
$nxAccent = function_exists('theme_setting') ? (string) theme_setting('accent_color', '#3b82c4') : '#3b82c4';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $nxAccent)) $nxAccent = '#3b82c4';
get_header();
?>
<style>
  .nx-err{max-width:780px;margin:56px auto;padding:16px 20px 40px;text-align:center;font-family:inherit;}
  .nx-err-code{font-size:96px;font-weight:800;line-height:1;color:#3b82c4;color:<?= esc_attr($nxAccent) ?>;opacity:.9;letter-spacing:-2px;}
  .nx-err h1{margin:10px 0 12px;font-size:30px;}
  .nx-err p{color:#555;font-size:16px;line-height:1.6;}
  .nx-err .nx-err-actions{margin:26px 0;}
  .nx-err .nx-err-btn{display:inline-block;padding:11px 22px;border-radius:6px;background:<?= esc_attr($nxAccent) ?>;color:#fff !important;text-decoration:none;font-weight:600;}
  .nx-err .nx-err-btn:hover{opacity:.88;}
  .nx-err form{margin:18px auto;max-width:440px;}
  .nx-err-recent{margin-top:30px;text-align:left;max-width:440px;margin-left:auto;margin-right:auto;}
  .nx-err-recent h3{font-size:15px;text-transform:uppercase;letter-spacing:1px;color:#888;text-align:center;}
  .nx-err-recent ul{list-style:none;padding:0;}
  .nx-err-recent li{padding:7px 0;border-bottom:1px solid #eee;}
  .nx-err-recent a{text-decoration:none;}
</style>
<main class="nx-err">
  <div class="nx-err-code"><?= (int) ($nxErr['status'] ?? 404) ?></div>
  <h1><?= esc_html((string) ($nxErr['title'] ?? 'Error')) ?></h1>
  <p><?= esc_html((string) ($nxErr['message'] ?? '')) ?></p>
  <div class="nx-err-actions">
    <a class="nx-err-btn" href="<?= esc_url(home_url()) ?>">&larr; Back to the homepage</a>
  </div>
  <?php if ((int) ($nxErr['status'] ?? 404) === 404): ?>
    <?= get_search_form() ?>
    <?= function_exists('nexa_error_recent_html') ? nexa_error_recent_html() : '' ?>
  <?php endif; ?>
</main>
<?php get_footer(); ?>
