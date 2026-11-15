<?php
/** Flip Turn — theme functions (v2.0.0, self-contained: own style.css, no template assets). */
addHook('up_head', 'flipturn_theme_head');
addHook('up_post', 'flipturn_theme_scripts');
function flipturn_theme_uri(): string { return rtrim(get_template_directory_uri(), '/'); }
function flipturn_theme_head(): void {
    global $head, $settings;
    $pageTitle = get_page_title();
    $siteName  = $settings['blogname']['value'] ?? 'NexaCMS';
    $fullTitle = ($pageTitle !== '') ? "{$pageTitle} - {$siteName}" : $siteName;
    $head = "\n\t<title>" . esc_html($fullTitle) . "</title>\n" . ($head ?? '');
    $head .= '
    <meta name="description" content="' . esc_attr($settings['blogdescription']['value'] ?? '') . '">
    <meta name="keywords" content="' . esc_attr(get_page_keywords()) . '">
    <link href="' . esc_url(flipturn_theme_uri() . '/style.css') . '" rel="stylesheet">';
    $accent = theme_setting('accent_color', '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $head .= '<style>.ft-menu li a:hover,.ft-menu li.active a{background:' . $accent . '}.ft-btn{background:' . $accent . '}.ft-navbar{border-bottom-color:' . $accent . '}</style>';
    }
}
function flipturn_theme_scripts(): void {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.querySelector(".ft-toggle"),n=document.getElementById("ft-mainnav");if(b&&n){b.addEventListener("click",function(){n.classList.toggle("open");});}});</script>';
}
