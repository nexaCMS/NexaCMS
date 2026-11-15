<?php
/** Nexa SEO — meta tags, canonical, OG/Twitter cards + XML sitemap. */
function nexa_seo_head(): void {
    global $post;
    // Nexa SEO Pro provides richer per-post tags when it has meta for this post.
    if (function_exists('nexa_seopro_meta_for_current') && nexa_seopro_meta_for_current()) return;
    $desc = trim((string)($post['post_excerpt'] ?? '')) ?: (string)get_setting('seo_default_desc', get_setting('blogdescription',''));
    if ($desc==='' && !empty($post['post_content'])) $desc = mb_substr(strip_tags((string)$post['post_content']),0,160);
    $title = trim((string)($post['post_title'] ?? '')) ?: (string)get_setting('blogname','NexaCMS');
    $sep = (string)get_setting('seo_separator',' | '); $url = function_exists('url') ? url() : home_url();
    $img = (string)get_setting('seo_og_image',''); $tw = ltrim((string)get_setting('seo_twitter',''),'@');
    echo "\n<!-- Nexa SEO -->\n";
    if ($desc!=='') echo '<meta name="description" content="'.esc_attr($desc).'">'."\n";
    $kw=trim((string)($post['post_keywords']??''))?:(string)get_setting('seo_keywords',''); if($kw!=='') echo '<meta name="keywords" content="'.esc_attr($kw).'">'."\n";
    echo '<meta name="robots" content="'.esc_attr((string)get_setting('seo_robots','index,follow')).'">'."\n";
    echo '<link rel="canonical" href="'.esc_url($url).'">'."\n";
    echo '<meta property="og:type" content="article"><meta property="og:site_name" content="'.esc_attr((string)get_setting('blogname','NexaCMS')).'"><meta property="og:title" content="'.esc_attr($title.$sep.get_setting('blogname','NexaCMS')).'"><meta property="og:description" content="'.esc_attr($desc).'"><meta property="og:url" content="'.esc_url($url).'">'."\n";
    if($img!=='') echo '<meta property="og:image" content="'.esc_url($img).'">'."\n";
    echo '<meta name="twitter:card" content="summary_large_image">'.($tw!==''?'<meta name="twitter:site" content="@'.esc_attr($tw).'">':'').'<meta name="twitter:title" content="'.esc_attr($title).'"><meta name="twitter:description" content="'.esc_attr($desc).'">'."\n<!-- /Nexa SEO -->\n";
}
add_action('nexa_head','nexa_seo_head');
function nexa_seo_sitemap(): void {
    if ((($_GET['sitemap']??'')!=='xml') && !str_ends_with((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH), 'sitemap.xml')) return;
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    echo '<url><loc>'.esc_url(home_url()).'</loc><changefreq>daily</changefreq><priority>1.0</priority></url>'."\n";
    try {
        foreach (query("SELECT id FROM posts WHERE post_status = ? ORDER BY id ASC", ['published']) as $r) echo '<url><loc>'.esc_url(post_url($r['id'])).'</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>'."\n";
    } catch (Throwable $e) { /* sitemap must never take the site down */ }
    echo '</urlset>'; exit;
}
