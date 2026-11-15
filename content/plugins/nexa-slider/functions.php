<?php
add_shortcode('slider', function($a=[]): string { $rows=[]; if(table_exists('gallery_items')) $rows=query("SELECT image_url AS src, title AS alt FROM gallery_items WHERE status=? ORDER BY sort ASC, id ASC LIMIT 8",['active']); if(empty($rows) && table_exists('multi_slider')) $rows=query("SELECT image AS src, title AS alt FROM multi_slider LIMIT 8"); if(empty($rows)) return '<p>No slider images yet - add gallery images first (Admin &rarr; Gallery Manager).</p>'; $id='nexaSlider'.random_int(100,999); $h='<div id="'.$id.'" style="position:relative;overflow:hidden;border-radius:8px;max-height:420px">'; foreach($rows as $i=>$r) if(!empty($r['src'])) $h.='<img src="'.esc_url($r['src']).'" alt="'.esc_attr($r['alt']??'').'" style="width:100%;height:420px;object-fit:cover;display:'.($i===0?'block':'none').'">'; return $h.'</div><script>(function(){var s=document.getElementById("'.$id.'"),imgs=s.querySelectorAll("img"),i=0;setInterval(function(){imgs[i].style.display="none";i=(i+1)%imgs.length;imgs[i].style.display="block";},3500);})();</script>'; });

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Slider', 'plugin_slug' => 'nexa-slider', 'label' => 'Image slider', 'tag' => 'slider',
        'description' => 'Embed the auto-playing slider built from active gallery images.', 'shortcode' => '[slider]',
    ]);
}
