<?php
/** Nexa Ad Server (formerly Micro AD Rotator) - zones, weights, schedules, stats. */
function nexa_ads_ensure(): void {
    if (!table_exists('ads')) create_table('ads', ['name','atype','content','image_url','link_url','zone','weight','status','starts_at','ends_at','impressions','clicks','created_at']);
    if (count(query("SELECT id FROM ads")) === 0) {
        query("INSERT INTO ads (name,atype,content,image_url,link_url,zone,weight,status,starts_at,ends_at,impressions,clicks,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            ['Welcome House Ad','text','Your Ad Here - manage ads in Admin > Ad Server','','','default',1,'active','','',0,0,date('Y-m-d H:i:s')]);
        // Import legacy adlist.txt lines once
        $legacy = __DIR__ . '/adlist.txt';
        if (is_file($legacy)) foreach (array_filter(array_map('trim', file($legacy) ?: [])) as $line) {
            query("INSERT INTO ads (name,atype,content,image_url,link_url,zone,weight,status,starts_at,ends_at,impressions,clicks,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                ['Legacy Ad','html',$line,'','','default',1,'active','','',0,0,date('Y-m-d H:i:s')]);
        }
    }
}
nexa_ads_ensure();

function nexa_ad_pick(string $zone, int $count): array {
    nexa_ads_ensure();
    $today = date('Y-m-d');
    $rows = array_values(array_filter(query("SELECT * FROM ads WHERE status = ? AND zone = ?", ['active', $zone]),
        static fn($r) => (empty($r['starts_at']) || $r['starts_at'] <= $today) && (empty($r['ends_at']) || $r['ends_at'] >= $today)));
    $picked = [];
    while ($rows && count($picked) < $count) {
        $pool = [];
        foreach ($rows as $i => $r) for ($w = 0; $w < max(1, (int)$r['weight']); $w++) $pool[] = $i;
        $idx = $pool[random_int(0, count($pool) - 1)];
        $picked[] = $rows[$idx];
        array_splice($rows, $idx, 1);
    }
    return $picked;
}

function nexa_ad_render(string $zone = 'default', int $count = 1): string {
    $out = '';
    foreach (nexa_ad_pick($zone === '' ? 'default' : $zone, max(1, $count)) as $ad) {
        query("UPDATE ads SET impressions = impressions + 1 WHERE id = ?", [(int)$ad['id']]);
        $click = home_url('?nexa_ad_click=' . (int)$ad['id']);
        if ($ad['atype'] === 'image' && !empty($ad['image_url'])) {
            $img = '<img src="' . esc_url($ad['image_url']) . '" alt="' . esc_attr($ad['name']) . '" style="max-width:100%">';
            $out .= !empty($ad['link_url']) ? '<a href="' . esc_url($click) . '" target="_blank" rel="sponsored noopener">' . $img . '</a>' : $img;
        } elseif ($ad['atype'] === 'html') {
            $out .= '<div class="nexa-ad">' . $ad['content'] . '</div>'; // trusted admin HTML
        } else {
            $text = esc_html($ad['content'] ?: $ad['name']);
            $out .= !empty($ad['link_url']) ? '<a href="' . esc_url($click) . '" target="_blank" rel="sponsored noopener">' . $text . '</a>' : $text;
        }
    }
    return $out;
}

add_shortcode('ad', fn($a = []): string => nexa_ad_render((string)($a['zone'] ?? 'default'), (int)($a['count'] ?? 1)));
add_shortcode('microAd', fn($a = []): string => nexa_ad_render((string)($a['zone'] ?? 'default'), (int)($a['no'] ?? 1)));

function getRandomAD(int $ADnumber = 1): string { return nexa_ad_render('default', $ADnumber); } // legacy API
function showRandomAD(int $ADnumber = 1): void { echo getRandomAD($ADnumber); }

function nexa_ad_click(): void {
    if (!isset($_GET['nexa_ad_click'])) return;
    nexa_ads_ensure();
    $rows = query("SELECT link_url FROM ads WHERE id = ?", [(int)$_GET['nexa_ad_click']]);
    if (!empty($rows)) {
        query("UPDATE ads SET clicks = clicks + 1 WHERE id = ?", [(int)$_GET['nexa_ad_click']]);
        $url = (string)$rows[0]['link_url'];
        if ($url !== '' && (preg_match('#^https?://#i', $url) || str_starts_with($url, '/'))) { header('Location: ' . $url); exit; }
    }
    header('Location: ' . home_url()); exit;
}

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Nexa Ad Server', 'plugin_slug' => 'microADRotator', 'label' => 'Ad zone', 'tag' => 'ad',
        'description' => 'Insert ads from a zone managed in Admin -> Ad Server.',
        'fields' => [
            ['name' => 'zone', 'label' => 'Ad zone', 'type' => 'text', 'default' => 'default', 'always' => true],
            ['name' => 'count', 'label' => 'Ads to show', 'type' => 'number', 'default' => '1', 'min' => '1', 'max' => '10', 'always' => true],
        ],
        'template' => '[ad zone="{zone}" count="{count}"]',
    ]);
}
