<?php
/**
 * Nexa Post Layouts — named layouts for post listings.
 *
 * Bundled themes fire these hooks inside their blog listings:
 *   do_action('nexa_post_layout_before')            before the loop
 *   do_action('nexa_post_layout_after_item', $n)    after the Nth post card
 *   do_action('nexa_post_layout_after')             after the loop
 *   apply_filters('nexa_post_layout_list_class','') classes for the list
 * Third-party themes can support layouts by firing the same hooks.
 * A layout can: print a block before/after the list, insert a block (ads,
 * shortcodes, HTML) after every Nth post, set columns per row and a card
 * style. Layouts are stored per site on multisite installs.
 */

function nexa_layouts_ensure(): void {
    if (!table_exists('post_layouts')) {
        create_table('post_layouts', ['name', 'target', 'columns', 'card_style', 'before_content', 'insert_content', 'insert_every', 'insert_max', 'after_content', 'active', 'created_at']);
    }
}
nexa_layouts_ensure();

function nexa_layouts_context(): string {
    return (function_exists('is_search') && is_search()) ? 'search' : 'home';
}

/** The active layout for the current listing context (or null). */
function nexa_layouts_current(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    try {
        $ctx = nexa_layouts_context();
        $fallback = null;
        foreach (get_table('post_layouts')['data'] as $row) {
            if ((string) ($row['active'] ?? '0') !== '1') continue;
            if ((string) ($row['target'] ?? 'all') === $ctx) { $cache = $row; return $cache; }
            if ((string) ($row['target'] ?? 'all') === 'all' && $fallback === null) $fallback = $row;
        }
        $cache = $fallback;
    } catch (Throwable $e) { $cache = null; }
    return $cache;
}

function nexa_layouts_block(string $html, string $kind): void {
    $html = trim($html);
    if ($html === '') return;
    echo '<div class="nexa-layout-block nexa-layout-' . esc_attr($kind) . '">' . do_shortcode($html) . '</div>';
}

add_action('nexa_post_layout_before', static function (): void {
    $l = nexa_layouts_current();
    if ($l) nexa_layouts_block((string) ($l['before_content'] ?? ''), 'before');
});

add_action('nexa_post_layout_after_item', static function ($n): void {
    $l = nexa_layouts_current();
    if (!$l) return;
    $every = (int) ($l['insert_every'] ?? 0);
    if ($every < 1) return;
    $n = (int) $n;
    if ($n % $every !== 0) return;
    $max = (int) ($l['insert_max'] ?? 0);
    if ($max > 0 && intdiv($n, $every) > $max) return;
    nexa_layouts_block((string) ($l['insert_content'] ?? ''), 'insert');
});

add_action('nexa_post_layout_after', static function (): void {
    $l = nexa_layouts_current();
    if ($l) nexa_layouts_block((string) ($l['after_content'] ?? ''), 'after');
});

add_filter('nexa_post_layout_list_class', static function ($class) {
    $l = nexa_layouts_current();
    if (!$l) return $class;
    $cols = (int) ($l['columns'] ?? 0);
    $style = (string) ($l['card_style'] ?? 'theme');
    if ($cols < 1 && ($style === '' || $style === 'theme')) return $class;
    $out = ' nexa-post-list';
    if ($cols > 1) $out .= ' nexa-cols-' . $cols;
    if ($style !== '' && $style !== 'theme') $out .= ' nexa-card-' . preg_replace('/[^a-z\-]/', '', $style);
    return $class . $out;
});
