<?php
/**
 * Multi Theme Functions (content/themes/multi/functions.php)
 */

addHook('up_head', 'multi_theme_head');
addHook('up_post', 'multi_theme_scripts');

function multi_theme_uri(): string {
    return rtrim(get_template_directory_uri(), '/');
}

function multi_theme_head(): void {
    global $head, $settings;
    $pageTitle = get_page_title();
    $siteName  = $settings['blogname']['value'] ?? 'NexaCMS';
    $fullTitle = ($pageTitle !== '') ? "{$pageTitle} - {$siteName}" : $siteName;
    $key       = get_page_keywords();
    $desc      = $settings['blogdescription']['value'] ?? '';
    $av        = multi_theme_uri();

    $head = "\n\t<title>" . esc_html($fullTitle) . "</title>\n" . ($head ?? '');
    $head .= '
    <meta name="keywords" content="' . esc_attr($key) . '">
    <meta name="description" content="' . esc_attr($desc) . '">
    <link href="' . esc_url($av . '/css/bootstrap.min.css') . '" rel="stylesheet">
    <link rel="stylesheet" href="' . esc_url($av . '/css/font-awesome.min.css') . '">
    <link rel="stylesheet" href="' . esc_url($av . '/css/jquery.bxslider.css') . '">
    <link rel="stylesheet" type="text/css" href="' . esc_url($av . '/css/isotope.css') . '" media="screen" />
    <link rel="stylesheet" href="' . esc_url($av . '/css/animate.css') . '">
    <link rel="stylesheet" href="' . esc_url($av . '/js/fancybox/jquery.fancybox.css') . '" type="text/css" media="screen" />
    <link href="' . esc_url($av . '/css/prettyPhoto.css') . '" rel="stylesheet" />
    <link href="' . esc_url($av . '/css/style.css') . '" rel="stylesheet" />';
}

function multi_theme_scripts(): void {
    $av = multi_theme_uri();
    echo '
    <script src="' . esc_url($av . '/js/jquery-2.1.1.min.js') . '"></script>
    <script src="' . esc_url($av . '/js/bootstrap.min.js') . '"></script>
    <script src="' . esc_url($av . '/js/wow.min.js') . '"></script>
    <script src="' . esc_url($av . '/js/fancybox/jquery.fancybox.pack.js') . '"></script>
    <script src="' . esc_url($av . '/js/jquery.easing.1.3.js') . '"></script>
    <script src="' . esc_url($av . '/js/jquery.bxslider.min.js') . '"></script>
    <script src="' . esc_url($av . '/js/jquery.prettyPhoto.js') . '"></script>
    <script src="' . esc_url($av . '/js/jquery.isotope.min.js') . '"></script>
    <script src="' . esc_url($av . '/js/functions.js') . '"></script>
    <script>if (typeof WOW !== "undefined") { new WOW({}).init(); }</script>';
}

function sliderenabled(): bool {
    global $settings;
    if (isset($settings['multi_slider']['value'])) {
        return (string) $settings['multi_slider']['value'] === '1';
    }
    set_setting('multi_slider', '1', 'Show Theme Hero Slider');
    return true;
}

function showslider(): void {
    $av = multi_theme_uri();
    if (!table_exists('multi_slider') || count_records('multi_slider') === 0) {
        create_table('multi_slider', ['src', 'alt']);
        multi_update_table('multi_slider', [
            ['src' => 'content/themes/multi/images/slider/1.jpg', 'alt' => 'Slide 1'],
            ['src' => 'content/themes/multi/images/slider/2.jpg', 'alt' => 'Slide 2'],
            ['src' => 'content/themes/multi/images/slider/3.jpg', 'alt' => 'Slide 3'],
        ], 'insert');
    }
    $slides = get_result('multi_slider', 1, ['src', 'alt'], ['by' => 'id', 'order' => 'ASC'], 10);
    if (empty($slides)) return;
    ?>
    <div class="slider">
        <div class="img-responsive">
            <ul class="bxslider">
                <?php foreach ($slides as $s):
                    $rawSrc = trim((string) ($s['src'] ?? ''));
                    // Rewrite legacy localhost slider URLs or relative theme paths cleanly
                    if (preg_match('#/content/themes/multi/images/slider/([^/]+)$#i', $rawSrc, $m)) {
                        $imgUrl = $av . '/images/slider/' . $m[1];
                    } elseif (str_starts_with($rawSrc, 'http://') || str_starts_with($rawSrc, 'https://')) {
                        $imgUrl = $rawSrc;
                    } else {
                        $imgUrl = home_url($rawSrc);
                    }
                ?>
                    <li><img src="<?= esc_url($imgUrl) ?>" alt="<?= esc_attr($s['alt'] ?? '') ?>" /></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}
