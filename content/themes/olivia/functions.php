<?php
/** Olivia — advanced portfolio theme functions (self-contained; based on the Olivia template design). */
addHook('up_head', 'olivia_theme_head');
addHook('up_post', 'olivia_theme_scripts');
function Olivia_theme_uri(): string { return rtrim(get_template_directory_uri(), '/'); }
function olivia_theme_head(): void {
    global $head, $settings;
    $pageTitle = get_page_title();
    $siteName  = $settings['blogname']['value'] ?? 'NexaCMS';
    $fullTitle = ($pageTitle !== '') ? "{$pageTitle} - {$siteName}" : $siteName;
    $head = "\n\t<title>" . esc_html($fullTitle) . "</title>\n" . ($head ?? '');
    $head .= '
    <meta name="description" content="' . esc_attr($settings['blogdescription']['value'] ?? '') . '">
    <meta name="keywords" content="' . esc_attr(get_page_keywords()) . '">
    <link href="' . esc_url(Olivia_theme_uri() . '/style.css') . '" rel="stylesheet">';
    $accent = theme_setting('accent_color', '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $head .= '<style>:root{--olv-accent:' . $accent . '}</style>';
    }
    if (theme_setting('default_mode', 'light') === 'dark') {
        $head .= '<script>try{if(!localStorage.getItem("olv-mode"))document.documentElement.classList.add("dark");}catch(e){document.documentElement.classList.add("dark");}</script>';
    }
    $head .= '<script>try{if(localStorage.getItem("olv-mode")==="dark")document.documentElement.classList.add("dark");}catch(e){}</script>';
}
function olivia_theme_scripts(): void {
    echo '<script>
document.addEventListener("DOMContentLoaded",function(){
  var t=document.querySelector(".olv-toggle");
  if(t){t.addEventListener("click",function(){var d=document.documentElement.classList.toggle("dark");try{localStorage.setItem("olv-mode",d?"dark":"light");}catch(e){}});}
  var b=document.querySelector(".olv-menu-btn"),n=document.getElementById("olv-mainnav");
  if(b&&n){b.addEventListener("click",function(){n.classList.toggle("open");});}
  var bar=document.getElementById("olv-progress");
  if(bar){window.addEventListener("scroll",function(){var h=document.documentElement;var max=h.scrollHeight-h.clientHeight;bar.style.width=(max>0?(h.scrollTop/max)*100:0)+"%";},{passive:true});}
  var top=document.getElementById("olv-top");
  if(top){top.addEventListener("click",function(){window.scrollTo({top:0,behavior:"smooth"});});}
});
</script>';
}
