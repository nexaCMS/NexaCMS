<?php
/** Corporate Pro — theme functions. Self-contained: no external assets. */
addHook('up_head', 'corp_theme_head');
addHook('up_post', 'corp_theme_scripts');
function corp_theme_uri(): string { return rtrim(get_template_directory_uri(), '/'); }
function corp_theme_head(): void {
    global $head, $settings;
    $pageTitle = get_page_title();
    $siteName  = $settings['blogname']['value'] ?? 'NexaCMS';
    $fullTitle = ($pageTitle !== '') ? "{$pageTitle} - {$siteName}" : $siteName;
    $head = "\n\t<title>" . esc_html($fullTitle) . "</title>\n" . ($head ?? '');
    $head .= '
    <meta name="description" content="' . esc_attr($settings['blogdescription']['value'] ?? '') . '">
    <meta name="keywords" content="' . esc_attr(get_page_keywords()) . '">
    <link href="' . esc_url(corp_theme_uri() . '/style.css') . '" rel="stylesheet">';
    $accent = theme_setting('accent_color', '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $head .= '<style>a{color:' . $accent . '}.' . 'corp' . '-menu li.active a{background:' . $accent . '}.' . 'corp' . '-btn{background:' . $accent . '}.' . 'corp' . '-brand{color:' . $accent . '!important}.' . 'corp' . '-post-title a:hover{color:' . $accent . '}.' . 'corp' . '-hero{background:linear-gradient(120deg,' . $accent . ',' . $accent . ')}</style>';
    }
}
function corp_theme_scripts(): void {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.querySelector(".corp-toggle"),n=document.getElementById("corp-mainnav");if(b&&n){b.addEventListener("click",function(){n.classList.toggle("open");});}});</script>';
}
