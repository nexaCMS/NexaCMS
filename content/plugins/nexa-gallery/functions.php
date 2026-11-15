<?php
/** Nexa Gallery — [gallery] responsive grid + lightbox (SQL query() powered). */
function nexa_gallery_ensure(): void { if(!table_exists('gallery_items')) create_table('gallery_items',['title','caption','image_url','sort','status','created_at']); }
nexa_gallery_ensure();
function nexa_gallery_head(): void { echo '<style>.nexa-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.nexa-gallery figure{margin:0}.nexa-gallery img{width:100%;height:170px;object-fit:cover;border-radius:6px;cursor:pointer}.nexa-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.88);display:none;align-items:center;justify-content:center;z-index:9999}.nexa-lightbox img{max-width:92vw;max-height:88vh;border-radius:8px}.nexa-lightbox.open{display:flex}.nexa-lightbox span{position:absolute;top:16px;right:24px;color:#fff;font-size:34px;cursor:pointer}</style>'."\n"; }
add_action('nexa_head','nexa_gallery_head');
function nexa_gallery_post(): void { echo '<div class="nexa-lightbox" id="nexaLightbox" onclick="this.classList.remove(\'open\')"><span>&times;</span><img alt="Gallery preview"></div><script>document.querySelectorAll(".nexa-gallery img").forEach(function(i){i.addEventListener("click",function(){var b=document.getElementById("nexaLightbox");b.querySelector("img").src=i.dataset.full||i.src;b.classList.add("open");});});</script>'."\n"; }
add_action('nexa_post','nexa_gallery_post');
add_shortcode('gallery', function($a=[]): string { nexa_gallery_ensure(); $rows=query("SELECT * FROM gallery_items WHERE status=? ORDER BY sort ASC, id ASC",['active']); if(empty($rows)) return '<p>No gallery images yet — add some in Admin &rarr; Gallery Manager.</p>'; $h='<div class="nexa-gallery">'; foreach($rows as $r) $h.='<figure><img src="'.esc_url($r['image_url']).'" data-full="'.esc_attr($r['image_url']).'" alt="'.esc_attr($r['title']??'').'" loading="lazy"><figcaption>'.esc_html($r['title']??'').'</figcaption></figure>'; return $h.'</div>'; });

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Gallery', 'plugin_slug' => 'nexa-gallery', 'label' => 'Image gallery', 'tag' => 'gallery',
        'description' => 'Embed the responsive gallery grid with lightbox.', 'shortcode' => '[gallery]',
    ]);
}
