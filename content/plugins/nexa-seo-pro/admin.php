<?php
/** Nexa SEO Pro — admin info + content audit screen. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-seo-pro', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-seo-pro') ? 'active' : '',
    'icon' => 'fa fa-line-chart fa-fw', 'label' => ' SEO Pro', 'link' => 'admin.php?plugin_page=nexa-seo-pro',
], 49);
add_action('nexa_admin', 'nexa_seopro_admin_render');

function nexa_seopro_admin_render(): void {
    if (($_GET['plugin_page'] ?? '') !== 'nexa-seo-pro') return;
    echo '<h2><i class="fa fa-line-chart"></i> Nexa SEO Pro <small>advanced per-post SEO</small></h2>';
    echo '<div class="panel panel-default"><div class="panel-heading">How it works</div><div class="panel-body">'
        . '<p>Open any post or page in the editor and you will find an <strong>SEO Pro</strong> panel in the right-hand column. Fill in what you need:</p>'
        . '<ul><li><strong>Meta Title / Description</strong> — exactly what Google shows (with live character counters)</li>'
        . '<li><strong>Focus Keyword(s)</strong> — merged into the keywords tag</li>'
        . '<li><strong>Canonical URL</strong> — point duplicates at the original</li>'
        . '<li><strong>Robots</strong> — e.g. <code>noindex,follow</code> for thank-you or checkout pages</li>'
        . '<li><strong>Open Graph / Twitter Card</strong> — control how links look when shared</li></ul>'
        . '<p>When a post has Pro fields, <strong>SEO Pro outputs its tags and the basic Nexa SEO plugin steps aside for that post</strong> — no duplicates. Posts without Pro fields keep using Nexa SEO as before.</p>'
        . '</div></div>';

    // Audit: published items missing a Pro meta description.
    nexa_seopro_ensure();
    $haveDesc = [];
    foreach (get_table('seo_pro_meta')['data'] as $row) {
        if (trim((string) ($row['meta_desc'] ?? '')) !== '') $haveDesc[(string) $row['post_id']] = true;
    }
    $missing = [];
    foreach (get_table('posts')['data'] as $p) {
        if (($p['post_status'] ?? '') === 'published' && !isset($haveDesc[(string) $p['id']])) $missing[] = $p;
    }
    echo '<div class="panel panel-default"><div class="panel-heading">Content audit — published items without a Pro meta description (' . count($missing) . ')</div><div class="panel-body">';
    if (!$missing) {
        echo '<p class="text-success" style="margin:0;"><i class="fa fa-check"></i> Every published post/page has a meta description. </p>';
    } else {
        echo '<table class="table table-striped" style="margin:0;"><thead><tr><th>Title</th><th>Type</th><th></th></tr></thead><tbody>';
        foreach (array_slice($missing, 0, 25) as $p) {
            echo '<tr><td>' . esc_html((string) $p['post_title']) . '</td><td>' . esc_html((string) ($p['post_type'] ?? 'post')) . '</td>'
                . '<td class="text-right"><a class="btn btn-xs btn-primary" href="edit_post.php?id=' . (int) $p['id'] . '&type=' . esc_attr((string) ($p['post_type'] ?? 'post')) . '">Add SEO</a></td></tr>';
        }
        echo '</tbody></table>';
        if (count($missing) > 25) echo '<p class="text-muted"><small>Showing the first 25.</small></p>';
    }
    echo '</div></div>';
}
