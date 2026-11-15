<?php
/**
 * UPCMS Native Shortcode Engine
 * Clean, lightweight shortcode registration and parsing with zero external dependencies.
 */

$GLOBALS['up_shortcodes'] = $GLOBALS['up_shortcodes'] ?? [];

function add_shortcode(string $tag, callable $callback): void {
    $tag = trim($tag);
    if ($tag !== '') {
        $GLOBALS['up_shortcodes'][$tag] = $callback;
    }
}

function remove_shortcode(string $tag): void {
    unset($GLOBALS['up_shortcodes'][$tag]);
}

function remove_all_shortcodes(): void {
    $GLOBALS['up_shortcodes'] = [];
}

function shortcode_exists(string $tag): bool {
    return isset($GLOBALS['up_shortcodes'][$tag]);
}


/* ==========================================================================
 * Editor shortcode builders
 * --------------------------------------------------------------------------
 * Active plugins can make themselves selectable in Admin -> Edit Post/Page
 * -> "Insert Plugin Shortcode". A plugin registers a declarative builder:
 * the fields it needs plus a shortcode template. Core renders the dropdown,
 * fields and live preview, then inserts the generated shortcode into the
 * visual editor, TinyMCE or the HTML textarea at the cursor.
 *
 * Example:
 * register_editor_shortcode([
 *   'plugin' => 'My Plugin', 'plugin_slug' => 'my-plugin',
 *   'label' => 'Latest items', 'tag' => 'my_items',
 *   'description' => 'Show the newest items.',
 *   'fields' => [
 *     ['name' => 'limit', 'label' => 'Items', 'type' => 'number', 'default' => '5'],
 *     ['name' => 'layout', 'label' => 'Layout', 'type' => 'select',
 *      'options' => ['grid' => 'Grid', 'list' => 'List'], 'default' => 'grid'],
 *   ],
 *   'template' => '[my_items limit="{limit}" layout="{layout}"]',
 * ]);
 * ========================================================================== */
$GLOBALS['nexa_editor_shortcodes'] = $GLOBALS['nexa_editor_shortcodes'] ?? [];

function nexa_editor_shortcode_slug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
    return trim($value, '-_') ?: 'shortcode';
}

function nexa_normalize_editor_shortcode(array $provider): ?array {
    $tag = trim((string) ($provider['tag'] ?? $provider['shortcode_tag'] ?? ''));
    if ($tag === '' && !empty($provider['shortcode']) && is_string($provider['shortcode'])) {
        if (preg_match('/\[\s*([a-zA-Z0-9_-]+)/', $provider['shortcode'], $m)) $tag = $m[1];
    }
    if ($tag === '' || !preg_match('/^[a-z0-9_-]+$/i', $tag)) return null;

    $label = trim((string) ($provider['label'] ?? ('[' . $tag . ']')));
    $plugin = trim((string) ($provider['plugin'] ?? $provider['plugin_name'] ?? 'Plugin'));
    $id = nexa_editor_shortcode_slug((string) ($provider['id'] ?? ($plugin . '-' . $tag . '-' . $label)));

    $allowedTypes = ['text', 'textarea', 'number', 'url', 'email', 'date', 'color', 'select', 'checkbox'];
    $fields = [];
    $seen = [];
    foreach ((array) ($provider['fields'] ?? []) as $field) {
        if (!is_array($field)) continue;
        $name = trim((string) ($field['name'] ?? ''));
        if ($name === '' || isset($seen[$name]) || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) continue;
        $seen[$name] = true;
        $type = strtolower(trim((string) ($field['type'] ?? 'text')));
        if (!in_array($type, $allowedTypes, true)) $type = 'text';
        $options = [];
        foreach ((array) ($field['options'] ?? []) as $key => $option) {
            if (is_array($option)) {
                $ov = (string) ($option['value'] ?? $option['id'] ?? '');
                $ol = (string) ($option['label'] ?? $option['name'] ?? $ov);
            } elseif (is_int($key)) {
                $ov = $ol = (string) $option;
            } else {
                $ov = (string) $key;
                $ol = (string) $option;
            }
            if ($ov !== '') $options[] = ['value' => $ov, 'label' => $ol];
        }
        $fields[] = [
            'name' => $name,
            'label' => trim((string) ($field['label'] ?? ucwords(str_replace(['_', '-'], ' ', $name)))),
            'type' => $type,
            'default' => is_scalar($field['default'] ?? '') ? (string) ($field['default'] ?? '') : '',
            'placeholder' => is_scalar($field['placeholder'] ?? '') ? (string) ($field['placeholder'] ?? '') : '',
            'help' => is_scalar($field['help'] ?? '') ? (string) ($field['help'] ?? '') : '',
            'required' => !empty($field['required']),
            'always' => !empty($field['always']),
            'options' => $options,
            'min' => is_scalar($field['min'] ?? '') ? (string) ($field['min'] ?? '') : '',
            'max' => is_scalar($field['max'] ?? '') ? (string) ($field['max'] ?? '') : '',
            'step' => is_scalar($field['step'] ?? '') ? (string) ($field['step'] ?? '') : '',
            'checked_value' => is_scalar($field['checked_value'] ?? '1') ? (string) ($field['checked_value'] ?? '1') : '1',
        ];
    }

    $content = $provider['content'] ?? false;
    if (is_string($content)) $content = ['label' => $content];
    if ($content === true) $content = [];
    $content = is_array($content) ? [
        'enabled' => true,
        'label' => trim((string) ($content['label'] ?? 'Enclosed content')),
        'default' => is_scalar($content['default'] ?? '') ? (string) ($content['default'] ?? '') : '',
        'placeholder' => is_scalar($content['placeholder'] ?? '') ? (string) ($content['placeholder'] ?? '') : '',
        'help' => is_scalar($content['help'] ?? '') ? (string) ($content['help'] ?? '') : '',
    ] : ['enabled' => false, 'label' => 'Enclosed content', 'default' => '', 'placeholder' => '', 'help' => ''];

    $template = '';
    if (!empty($provider['template']) && is_string($provider['template'])) {
        $template = trim($provider['template']);
    } elseif (!empty($provider['shortcode']) && is_string($provider['shortcode']) && $fields === []) {
        $template = trim($provider['shortcode']);
    } elseif (isset($provider['build']) && is_callable($provider['build'])) {
        // Compatibility for simple PHP builders: call once with defaults to
        // discover the shape. Field-driven generation still needs a template,
        // so plugin authors should prefer 'template' for dynamic fields.
        $defaults = [];
        foreach ($fields as $f) $defaults[$f['name']] = $f['default'];
        try {
            $built = call_user_func($provider['build'], $defaults, $content['default']);
            if (is_string($built) && $built !== '') $template = trim($built);
        } catch (Throwable $e) { /* editor must never fail because of a plugin builder */ }
    }

    return [
        'id' => $id,
        'plugin' => $plugin,
        'plugin_slug' => nexa_editor_shortcode_slug((string) ($provider['plugin_slug'] ?? $provider['plugin'] ?? '')),
        'label' => $label,
        'tag' => $tag,
        'description' => trim((string) ($provider['description'] ?? '')),
        'icon' => trim((string) ($provider['icon'] ?? 'fa fa-puzzle-piece')),
        'fields' => $fields,
        'content' => $content,
        'template' => $template,
        'basic' => !empty($provider['basic']),
        'supported' => empty($provider['basic']),
    ];
}

function register_editor_shortcode(array $provider): void {
    $normalized = nexa_normalize_editor_shortcode($provider);
    if ($normalized === null) return;
    $GLOBALS['nexa_editor_shortcodes'][$normalized['id']] = $normalized;
}

/** Alias with a name that reads naturally beside add_shortcode(). */
function add_editor_shortcode_provider(array $provider): void {
    register_editor_shortcode($provider);
}

function get_editor_shortcodes(): array {
    if (function_exists('nexa_register_core_editor_shortcodes')) {
        try { nexa_register_core_editor_shortcodes(); } catch (Throwable $e) { /* best-effort */ }
    }
    $providers = [];
    foreach ((array) ($GLOBALS['nexa_editor_shortcodes'] ?? []) as $provider) {
        if (is_array($provider)) {
            $normalized = nexa_normalize_editor_shortcode($provider);
            if ($normalized !== null) $providers[$normalized['id']] = $normalized;
        }
    }
    if (function_exists('apply_filters')) {
        $filtered = apply_filters('editor_shortcodes', array_values($providers));
        if (is_array($filtered)) {
            foreach ($filtered as $provider) {
                if (!is_array($provider)) continue;
                $normalized = nexa_normalize_editor_shortcode($provider);
                if ($normalized !== null) $providers[$normalized['id']] = $normalized;
            }
        }
    }
    $providers = array_values($providers);
    usort($providers, static function (array $a, array $b): int {
        return strcasecmp($a['plugin'] . ' ' . $a['label'], $b['plugin'] . ' ' . $b['label']);
    });
    return $providers;
}

/**
 * Data consumed by admin/js/nexa-shortcode-inserter.js. Supported builders
 * come first; every other active registered shortcode is still offered as a
 * basic "[tag] + attributes" inserter so no active plugin is unusable.
 */
function nexa_editor_shortcode_ui_data(): array {
    $supported = get_editor_shortcodes();
    $covered = [];
    foreach ($supported as $provider) $covered[strtolower($provider['tag'])] = true;

    $basic = [];
    foreach (array_keys((array) ($GLOBALS['up_shortcodes'] ?? [])) as $tag) {
        $tag = trim((string) $tag);
        if ($tag === '' || isset($covered[strtolower($tag)])) continue;
        $normalized = nexa_normalize_editor_shortcode([
            'id' => 'basic-' . $tag,
            'plugin' => 'Registered shortcode (basic)',
            'plugin_slug' => 'core-registered',
            'label' => '[' . $tag . ']',
            'tag' => $tag,
            'description' => 'This active plugin registered the shortcode but has not supplied an editor builder. Add any attributes manually.',
            'basic' => true,
            'fields' => [[
                'name' => '_attributes', 'label' => 'Attributes (optional)', 'type' => 'text',
                'placeholder' => 'id="1" limit="5"', 'help' => 'Type attributes exactly as they should appear inside the brackets.',
            ]],
            'content' => ['label' => 'Enclosed content (optional)'],
        ]);
        if ($normalized !== null) $basic[] = $normalized;
    }
    usort($basic, static fn(array $a, array $b): int => strcasecmp($a['tag'], $b['tag']));
    return ['supported' => $supported, 'basic' => $basic];
}

function has_shortcode(string $content, string $tag): bool {
    if (!str_contains($content, '[')) {
        return false;
    }
    if (!shortcode_exists($tag)) {
        return false;
    }
    return (bool) preg_match('/\[' . preg_quote($tag, '/') . '(?:\s|\]|\/)/', $content);
}

function shortcode_atts(array $pairs, $atts): array {
    $atts = (array) $atts;
    $out = [];
    foreach ($pairs as $name => $default) {
        $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }
    return $out;
}

function up_parse_shortcode_attrs(string $text): array {
    $attrs = [];
    $text = trim($text);
    if ($text === '') {
        return $attrs;
    }
    $pattern = '/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s"\']+)|"([^"]*)"|\'([^\']*)\'|(\S+)/';
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            if (!empty($m[1])) {
                $attrs[strtolower($m[1])] = $m[2];
            } elseif (!empty($m[3])) {
                $attrs[strtolower($m[3])] = $m[4];
            } elseif (!empty($m[5])) {
                $attrs[strtolower($m[5])] = $m[6];
            } elseif (isset($m[7]) && $m[7] !== '') {
                $attrs[] = $m[7];
            } elseif (isset($m[8]) && $m[8] !== '') {
                $attrs[] = $m[8];
            } elseif (!empty($m[9])) {
                $attrs[] = $m[9];
            }
        }
    }
    return $attrs;
}

function do_shortcode(?string $content): string {
    if ($content === null || $content === '' || !str_contains($content, '[')) {
        return (string) $content;
    }
    if (empty($GLOBALS['up_shortcodes'])) {
        return $content;
    }

    $tags = array_map(static fn($t) => preg_quote($t, '/'), array_keys($GLOBALS['up_shortcodes']));
    $tagRegexp = implode('|', $tags);

    // Match [[escaped]], [tag attrs]content[/tag], or self-closing [tag attrs /] / [tag attrs]
    $pattern = '/\[(\[?)(' . $tagRegexp . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*(?:\[(?!\/\2\])[^\[]*)*)\[\/\2\])?)(\]?)/s';

    return (string) preg_replace_callback($pattern, static function (array $m): string {
        // Escaped [[shortcode]]
        if ($m[1] === '[' && $m[6] === ']') {
            return substr($m[0], 1, -1);
        }
        $tag = $m[2];
        $attrText = $m[3] ?? '';
        $inner = isset($m[5]) ? do_shortcode($m[5]) : null;
        $attrs = up_parse_shortcode_attrs($attrText);
        $callback = $GLOBALS['up_shortcodes'][$tag];
        return $m[1] . (string) call_user_func($callback, $attrs, $inner, $tag) . $m[6];
    }, $content);
}

function strip_shortcodes(?string $content): string {
    if ($content === null || $content === '' || !str_contains($content, '[')) {
        return (string) $content;
    }
    if (empty($GLOBALS['up_shortcodes'])) {
        return $content;
    }
    $tags = array_map(static fn($t) => preg_quote($t, '/'), array_keys($GLOBALS['up_shortcodes']));
    $tagRegexp = implode('|', $tags);
    $pattern = '/\[(\[?)(' . $tagRegexp . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*(?:\[(?!\/\2\])[^\[]*)*)\[\/\2\])?)(\]?)/s';
    return (string) preg_replace_callback($pattern, static function (array $m): string {
        if ($m[1] === '[' && $m[6] === ']') {
            return substr($m[0], 1, -1);
        }
        return '';
    }, $content);
}
