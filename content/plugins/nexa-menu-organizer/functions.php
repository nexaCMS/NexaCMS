<?php
/** Nexa Menu Organizer - categorizes plugin admin menus in the sidebar. */
function nexa_mo_categories(): array { return ['Content','Marketing & SEO','Commerce','Security & System','Utilities']; }
function nexa_mo_category_for(string $slug): string {
    $map = maybe_unserialize(get_setting('menu_org_map', ''));
    if (is_array($map) && !empty($map[$slug])) return (string) $map[$slug];
    $meta = di_read_plugin_manifest(nexa_resolve_plugin_path($slug), $slug);
    if (!empty($meta['category'])) return (string) $meta['category'];
    $s = strtolower($slug);
    foreach ([['Content',['builder','form','gallery','slider','duplicate','comment','post']],['Marketing & SEO',['seo','analytic','newsletter','ad','sitekit','contact','social']],['Commerce',['shop','store','cart','order','product']],['Security & System',['security','ssl','spam','cache','backup','smtp','redirect','agent','image','organizer']]] as [$cat,$keys])
        foreach ($keys as $k) if (str_contains($s,$k)) return $cat;
    return 'Utilities';
}
function nexa_mo_organize(): void {
    if (empty($GLOBALS['adminarea']) || (string) get_setting('menu_org_enabled','1') !== '1') return;
    global $menu, $active_plugins;
    if (empty($menu['dashboard']) || !is_array($menu['dashboard'])) return;
    $slugs = [];
    foreach ((array)($active_plugins['admin'] ?? []) as $pl) if (!empty($pl['name'])) $slugs[] = (string)$pl['name'];
    $pluginItems = []; $keep = [];
    foreach ($menu['dashboard'] as $idx => $item) {
        $name = (string)($item['name'] ?? '');
        $isPluginScreens = ($name === 'plugin-screens');
        $slugFromLink = '';
        if (!empty($item['link']) && preg_match('/plugin_page=([A-Za-z0-9_\-]+)/', (string)$item['link'], $m)) $slugFromLink = $m[1];
        if ($isPluginScreens) {
            foreach ((array)($item['submenu']['item'] ?? []) as $sub) {
                if (preg_match('/plugin_page=([A-Za-z0-9_\-]+)/', (string)($sub['link'] ?? ''), $m)) $pluginItems[$m[1]] = $sub;
            }
            continue; // folded into categories
        }
        if (in_array($name, $slugs, true) || ($slugFromLink !== '' && in_array($slugFromLink, $slugs, true))) {
            $pluginItems[$slugFromLink !== '' ? $slugFromLink : $name] = $item;
            continue;
        }
        // Unfamiliar plugin? Its menu name won't match a folder slug and its link
        // may be custom. Anything that is not a known CORE menu is treated as
        // plugin-contributed and organized too - nothing stays cluttering the top level.
        $coreNames = ['dashboard','post','page','appearance','plugins','customers','users','tools','settings'];
        if (!in_array($name, $coreNames, true)) {
            $pluginItems[$slugFromLink !== '' ? $slugFromLink : ($name !== '' ? $name : 'plugin-' . $idx)] = $item;
            continue;
        }
        $keep[$idx] = $item;
    }
    if (!$pluginItems) return;
    $icons = ['Content'=>'fa fa-pencil-square-o fa-fw','Marketing & SEO'=>'fa fa-line-chart fa-fw','Commerce'=>'fa fa-shopping-cart fa-fw','Security & System'=>'fa fa-cogs fa-fw','Utilities'=>'fa fa-wrench fa-fw'];
    $byCat = [];
    foreach ($pluginItems as $slug => $item) {
        unset($item['submenu']); // plugin items become leaf links inside their category
        $byCat[nexa_mo_category_for($slug)][] = $item;
    }
    $menu['dashboard'] = $keep;
    $pos = 46;
    foreach (array_unique(array_merge(nexa_mo_categories(), array_keys($byCat))) as $cat) {
        if (empty($byCat[$cat])) continue;
        $menu['dashboard'][$pos] = [
            'name' => 'mo-cat-' . strtolower(preg_replace('/[^a-z]+/i','-',$cat)),
            'class' => '', 'icon' => $icons[$cat] ?? 'fa fa-plug fa-fw', 'link' => '#',
            'label' => ' ' . $cat . ' Plugins<span class="fa arrow"></span>',
            'submenu' => ['class' => 'nav nav-second-level', 'item' => array_values($byCat[$cat])],
        ];
        $pos++;
    }
    ksort($menu['dashboard']);
}
add_action('nexa_head', 'nexa_mo_organize', [], 999); // runs in <head>, after all menus exist, before sidebar renders
