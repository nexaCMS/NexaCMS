<?php
/**
 * Nexa SEO — Admin UI. Open via Plugins sidebar or admin.php?plugin_page=nexa-seo
 */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-seo', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-seo') ? 'active' : '',
    'icon' => 'fa fa-search fa-fw', 'label' => ' SEO Settings', 'link' => 'admin.php?plugin_page=nexa-seo',
], 43);
add_action('nexa_admin', 'nexa_seo_admin_render');

function nexa_seo_admin_render(): void {
    if (($_GET['plugin_page']??'')!=='nexa-seo') return;
    try { nexa_seo_admin_render_inner(); } catch (Throwable $e) { echo '<div class="alert alert-danger"><strong>SEO Settings error:</strong> ' . esc_html($e->getMessage()) . '<br><small>If this persists, note the message and your PHP version (' . esc_html(PHP_VERSION) . ') — the plugin requires PHP 8.0+.</small></div>'; }
}
function nexa_seo_admin_render_inner(): void {
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && nexa_verify_csrf()) { foreach(['seo_separator','seo_default_desc','seo_keywords','seo_og_image','seo_twitter','seo_robots'] as $k) set_setting($k, trim((string)($_POST[$k]??''))); echo '<div class="alert alert-success">SEO settings saved.</div>'; }
    echo '<h2><i class="fa fa-search"></i> SEO Settings</h2><p>Sitemap: <a href="'.esc_url(home_url('sitemap.xml')).'" target="_blank">'.esc_html(home_url('sitemap.xml')).'</a></p><form method="post">'.nexa_csrf_field();
    foreach([['seo_separator','Title Separator'],['seo_default_desc','Default Meta Description'],['seo_keywords','Default Keywords'],['seo_og_image','OpenGraph Image URL'],['seo_twitter','Twitter Handle (@name)'],['seo_robots','Robots Directive']] as [$k,$label]) echo '<div class="form-group"><label>'.esc_html($label).'</label><input class="form-control" name="'.esc_attr($k).'" value="'.esc_attr((string)get_setting($k,'')).'"></div>';
    echo '<button class="btn btn-primary">Save SEO Settings</button></form>';
}

