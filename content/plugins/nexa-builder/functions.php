<?php
function nexa_builder_ensure(): void { if(!table_exists('builder_blocks')) create_table('builder_blocks',['page','btype','content','sort','created_at']); }
nexa_builder_ensure();
add_shortcode('builder', function($a=[]): string { nexa_builder_ensure(); $page=(string)($a['page']??'home'); $rows=query("SELECT * FROM builder_blocks WHERE page=? ORDER BY sort ASC, id ASC",[$page]); if(empty($rows)) return '<p>No builder blocks yet - add some in Admin &rarr; Page Builder.</p>'; $h='<div class="nexa-builder">'; foreach($rows as $r){ $c=(string)$r['content']; switch($r['btype']){ case 'heading': $h.='<h2>'.esc_html($c).'</h2>'; break; case 'image': $h.='<p><img src="'.esc_url($c).'" style="max-width:100%;border-radius:8px" alt=""></p>'; break; case 'button': $p=explode('|',$c,2); $h.='<p><a class="btn btn-primary" href="'.esc_url(trim($p[1]??'#')).'">'.esc_html(trim($p[0])).'</a></p>'; break; case 'divider': $h.='<hr>'; break; default: $h.='<div>'.nl2br(esc_html($c)).'</div>'; } } return $h.'</div>'; });

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    $nexaBuilderPages = ['home' => 'home (default)'];
    if (!empty($GLOBALS['adminarea'])) {
        try {
            if (table_exists('builder_blocks')) {
                foreach (query("SELECT DISTINCT page FROM builder_blocks ORDER BY page ASC") as $row) {
                    $page = trim((string) ($row['page'] ?? ''));
                    if ($page !== '') $nexaBuilderPages[$page] = $page;
                }
            }
        } catch (Throwable $e) { /* builder table may be empty on a fresh site */ }
    }
    register_editor_shortcode([
        'plugin' => 'Nexa Builder', 'plugin_slug' => 'nexa-builder', 'label' => 'Builder block set', 'tag' => 'builder',
        'description' => 'Render a Page Builder block set inside this content.',
        'fields' => [['name' => 'page', 'label' => 'Builder page / block set', 'type' => 'select', 'options' => $nexaBuilderPages, 'default' => 'home', 'always' => true]],
        'template' => '[builder page="{page}"]',
    ]);
    unset($nexaBuilderPages);
}
