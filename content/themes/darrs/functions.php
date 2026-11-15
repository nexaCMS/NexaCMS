<?php
/**
 * DARRS Dream Theme Functions (content/themes/darrs/functions.php)
 */

addHook('up_head', 'darrs_theme_head');
addHook('up_footer', 'darrs_theme_footer');

function dream_title(string $title): string {
    $title = trim($title);
    if ($title === '') return 'Di<span class="logo_colour">Cimes</span>';
    $words = preg_split('/\s+/', $title) ?: [$title];
    $count = count($words);
    if ($count === 1) {
        $half = max(1, (int) ceil(mb_strlen($title) / 2));
        return esc_html(mb_substr($title, 0, $half)) . '<span class="logo_colour">' . esc_html(mb_substr($title, $half)) . '</span>';
    }
    $mid = (int) ceil($count / 2);
    $first = implode(' ', array_slice($words, 0, $mid));
    $rest  = implode(' ', array_slice($words, $mid));
    return esc_html($first) . '<span class="logo_colour"> ' . esc_html($rest) . '</span>';
}

function darrs_theme_head(): void {
    global $head, $settings;
    $title = get_page_title();
    $title = ($title === '') ? 'Home' : $title;
    $siteName = $settings['blogname']['value'] ?? 'NexaCMS';
    $key = get_page_keywords();
    $av = rtrim(get_template_directory_uri(), '/');

    $head = "\n\t<title>" . esc_html("{$title} - {$siteName}") . "</title>\n" . ($head ?? '');
    $head .= '
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="keywords" content="' . esc_attr($key) . '">
    <link rel="stylesheet" href="' . esc_url($av . '/style.css') . '">';
    $accent = theme_setting('accent_color', '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $head .= '<style>.logo_colour{color:' . $accent . '}.read_more{color:' . $accent . '}</style>';
    }
}

function darrs_theme_footer(): void {
    global $footer;
    $footer .= '<div id="footer">Copyright &copy; ' . esc_html(get_bloginfo('name')) . ' ' . date('Y') . ' | Powered by NexaCMS ' . esc_html(DICIMES_VERSION) . ' | <a href="' . esc_url(home_url('admin/')) . '">Admin</a></div></div>';
}
