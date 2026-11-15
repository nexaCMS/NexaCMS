<?php
/** Nova Blog — theme functions. Self-contained: no external assets. */
addHook('up_head', 'nova_theme_head');
addHook('up_post', 'nova_theme_scripts');
function nova_theme_uri(): string { return rtrim(get_template_directory_uri(), '/'); }
function nova_theme_head(): void {
    global $head, $settings;
    $pageTitle = get_page_title();
    $siteName  = $settings['blogname']['value'] ?? 'NexaCMS';
    $fullTitle = ($pageTitle !== '') ? "{$pageTitle} - {$siteName}" : $siteName;
    $head = "\n\t<title>" . esc_html($fullTitle) . "</title>\n" . ($head ?? '');
    $head .= '
    <meta name="description" content="' . esc_attr($settings['blogdescription']['value'] ?? '') . '">
    <meta name="keywords" content="' . esc_attr(get_page_keywords()) . '">
    <link href="' . esc_url(nova_theme_uri() . '/style.css') . '" rel="stylesheet">';
    $accent = theme_setting('accent_color', '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $head .= '<style>a{color:' . $accent . '}.' . 'nova' . '-menu li.active a{background:' . $accent . '}.' . 'nova' . '-btn{background:' . $accent . '}.' . 'nova' . '-brand{color:' . $accent . '!important}.' . 'nova' . '-post-title a:hover{color:' . $accent . '}.' . 'nova' . '-hero{background:linear-gradient(120deg,' . $accent . ',' . $accent . ')}</style>';
    }
}
function nova_theme_scripts(): void {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.querySelector(".nova-toggle"),n=document.getElementById("nova-mainnav");if(b&&n){b.addEventListener("click",function(){n.classList.toggle("open");});}});</script>';
}
