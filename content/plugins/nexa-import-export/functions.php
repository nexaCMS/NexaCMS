<?php
/** Nexa Import / Export — portable JSON export/import for posts & pages. */

function nexa_ie_whitelist_fields(): array {
    return ['post_author','post_date','post_date_gmt','post_content','post_keywords','post_desc','post_description','post_alias','post_title','post_thumb','post_excerpt','post_status','comment_status','post_password','post_name','post_format','post_parent','menu_order','post_type'];
}

function nexa_ie_collect(array $types, string $status = 'all'): array {
    $t = get_table('posts', 'id ASC');
    $items = [];
    foreach (($t['data'] ?? []) as $row) {
        if (!in_array((string) ($row['post_type'] ?? 'post'), $types, true)) continue;
        if ($status !== 'all' && (string) ($row['post_status'] ?? '') !== $status) continue;
        $item = [];
        foreach (nexa_ie_whitelist_fields() as $f) $item[$f] = (string) ($row[$f] ?? '');
        $item['nexa_id'] = (string) ($row['id'] ?? '');
        $items[] = $item;
    }
    return $items;
}

function nexa_ie_build_export(array $types, string $status = 'all'): array {
    return [
        'nexacms_export' => 1,
        'format_version' => 1,
        'exported_at'    => date('Y-m-d H:i:s'),
        'site'           => home_url(),
        'generator'      => 'Nexa Import / Export plugin 1.0.0',
        'items'          => nexa_ie_collect($types, $status),
    ];
}

/** Write an export file into content/temp_uploads and return [path, url, count]. */
function nexa_ie_write_export(array $types, string $status = 'all'): array {
    global $config;
    $root = rtrim((string) ($config['abs'] ?? dirname(__DIR__, 3)), '/');
    $dir = $root . '/content/temp_uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $data = nexa_ie_build_export($types, $status);
    $name = 'nexa-export-' . date('Ymd-His') . '.json';
    file_put_contents($dir . '/' . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return ['file' => $dir . '/' . $name, 'url' => home_url('content/temp_uploads/' . $name), 'count' => count($data['items']), 'name' => $name];
}

function nexa_ie_clean_item(array $raw): ?array {
    $item = [];
    foreach (nexa_ie_whitelist_fields() as $f) {
        if (isset($raw[$f])) $item[$f] = (string) $raw[$f];
    }
    if (trim((string) ($item['post_title'] ?? '')) === '' && trim((string) ($item['post_content'] ?? '')) === '') return null;
    $type = (string) ($item['post_type'] ?? 'post');
    if (!in_array($type, ['post', 'page'], true)) $type = 'post';
    $item['post_type'] = $type;
    $status = (string) ($item['post_status'] ?? 'published');
    $item['post_status'] = in_array($status, ['published', 'draft'], true) ? $status : 'draft';
    if (($item['post_name'] ?? '') === '') {
        $item['post_name'] = function_exists('nexa_sanitize_slug')
            ? nexa_sanitize_slug((string) ($item['post_title'] ?? 'post'), 'post')
            : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) ($item['post_title'] ?? 'post')), '-'));
    }
    if (($item['post_date'] ?? '') === '') $item['post_date'] = date('Y-m-d H:i:s');
    if (($item['post_date_gmt'] ?? '') === '' || !is_numeric($item['post_date_gmt'])) $item['post_date_gmt'] = (string) (strtotime($item['post_date']) ?: time());
    if (($item['comment_status'] ?? '') === '') $item['comment_status'] = 'open';
    return $item;
}

/** Import items. $mode: skip (keep existing), overwrite (update match), duplicate (always create). */
function nexa_ie_import(array $items, string $mode = 'skip', bool $dryRun = false): array {
    $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'log' => []];
    $existing = [];
    $t = get_table('posts');
    foreach (($t['data'] ?? []) as $row) {
        $existing[($row['post_type'] ?? 'post') . '|' . ($row['post_name'] ?? '')] = $row;
    }
    foreach ($items as $raw) {
        if (!is_array($raw)) { $report['errors'][] = 'Skipped a malformed item.'; continue; }
        $item = nexa_ie_clean_item($raw);
        if ($item === null) { $report['skipped']++; $report['log'][] = 'Skipped empty item.'; continue; }
        $key = $item['post_type'] . '|' . $item['post_name'];
        $match = $existing[$key] ?? null;
        if ($match !== null && $mode === 'skip') {
            $report['skipped']++; $report['log'][] = "Skipped existing {$item['post_type']} '{$item['post_title']}' (/{$item['post_name']}/).";
            continue;
        }
        if ($dryRun) {
            if ($match !== null && $mode === 'overwrite') { $report['updated']++; $report['log'][] = "Would update '{$item['post_title']}'."; }
            else { $report['created']++; $report['log'][] = "Would create '{$item['post_title']}' (/{$item['post_name']}/)."; }
            continue;
        }
        try {
            if ($match !== null && $mode === 'overwrite') {
                $item['id'] = $match['id'];
                update_table('posts', $item, 'update');
                $report['updated']++; $report['log'][] = "Updated '{$item['post_title']}' (/{$item['post_name']}/).";
            } else {
                if ($match !== null && $mode === 'duplicate') {
                    $item['post_name'] .= '-copy';
                    $item['post_title'] .= ' (Copy)';
                }
                unset($item['id']);
                $newId = update_table('posts', $item, 'insert');
                if ($newId) update_table('posts', ['id' => $newId, 'guid' => '?p=' . $newId], 'update');
                $report['created']++; $report['log'][] = "Created '{$item['post_title']}' (/{$item['post_name']}/) as #{$newId}.";
            }
        } catch (Throwable $e) {
            $report['errors'][] = "Failed '{$item['post_title']}': " . $e->getMessage();
        }
    }
    if (!$dryRun && function_exists('nexa_reset_loop')) nexa_reset_loop();
    return $report;
}

/* ---- Full exports with media (images, audio, video, any uploaded file) ---- */

/** Local uploads path for a URL, or null if it is not a site media file. */
function nexa_ie_local_path(string $url): ?string {
    $url = html_entity_decode(trim($url), ENT_QUOTES);
    if ($url === '' || str_starts_with($url, 'data:')) return null;
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $pos = strpos($path, 'content/uploads/');
    if ($pos === false) return null;
    $rel = substr($path, $pos);
    return preg_match('#^content/uploads/[A-Za-z0-9/._-]+$#', $rel) && !str_contains($rel, '..') ? $rel : null;
}

/** All media referenced by an item: map of original URL => local relative path. */
function nexa_ie_item_media(array $item): array {
    $found = [];
    $haystacks = [(string) ($item['post_content'] ?? ''), (string) ($item['post_thumb'] ?? '')];
    foreach ($haystacks as $h) {
        if (preg_match_all('/(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $h, $m)) {
            foreach ($m[1] as $u) { $rel = nexa_ie_local_path($u); if ($rel) $found[$u] = $rel; }
        }
        $rel = nexa_ie_local_path($h);
        if ($rel) $found[$h] = $rel;
    }
    return $found;
}

/** FULL export: export.json + every referenced media file, in one ZIP. */
function nexa_ie_write_export_zip(array $types, string $status = 'all'): array {
    global $config;
    $root = rtrim((string) ($config['abs'] ?? dirname(__DIR__, 3)), '/');
    $dir = $root . '/content/temp_uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $data = nexa_ie_build_export($types, $status);
    $media = [];
    foreach ($data['items'] as $item) {
        foreach (nexa_ie_item_media($item) as $url => $rel) $media[$url] = $rel;
    }
    $data['media'] = [];
    foreach ($media as $url => $rel) $data['media'][] = ['url' => $url, 'path' => $rel];
    $name = 'nexa-export-full-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    $zip->open($dir . '/' . $name, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('export.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $added = 0;
    foreach (array_unique(array_values($media)) as $rel) {
        $abs = $root . '/' . $rel;
        if (is_file($abs)) { $zip->addFile($abs, 'media/' . $rel); $added++; }
    }
    $zip->close();
    return ['url' => home_url('content/temp_uploads/' . $name), 'count' => count($data['items']), 'media' => $added, 'name' => $name];
}

/**
 * Import from a ZIP produced by nexa_ie_write_export_zip (already extracted to $extractDir).
 * Copies bundled media into this site and rewrites the original URLs to local ones.
 */
function nexa_ie_import_zip(string $extractDir, string $mode = 'skip', bool $dryRun = false): array {
    global $config;
    $root = rtrim((string) ($config['abs'] ?? dirname(__DIR__, 3)), '/');
    $jsonFile = null;
    foreach ([$extractDir . '/export.json', $extractDir . '/nexa-export.json'] as $cand) if (is_file($cand)) { $jsonFile = $cand; break; }
    if ($jsonFile === null) {
        $found = glob($extractDir . '/*/export.json') ?: [];
        if ($found) $jsonFile = $found[0];
    }
    if ($jsonFile === null) return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['No export.json found inside the ZIP.'], 'log' => []];
    $data = json_decode((string) file_get_contents($jsonFile), true);
    $items = (is_array($data) && isset($data['items']) && is_array($data['items'])) ? $data['items'] : (is_array($data) ? $data : null);
    if ($items === null) return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['export.json inside the ZIP has no items.'], 'log' => []];

    // Copy bundled media home + build URL rewrite map (original -> this site).
    $rewrite = [];
    $copied = 0;
    foreach ((array) ($data['media'] ?? []) as $m) {
        $rel = (string) ($m['path'] ?? '');
        if (!preg_match('#^content/uploads/[A-Za-z0-9/._-]+$#', $rel) || str_contains($rel, '..')) continue;
        $srcFile = $extractDir . '/media/' . $rel;
        if (!is_file($srcFile)) {
            // tolerate a wrapping folder
            $alt = glob($extractDir . '/*/media/' . $rel) ?: [];
            if ($alt) $srcFile = $alt[0];
        }
        if (!is_file($srcFile)) continue;
        $dest = $root . '/' . $rel;
        if (!$dryRun) {
            @mkdir(dirname($dest), 0755, true);
            if (@copy($srcFile, $dest)) $copied++;
        } else { $copied++; }
        $rewrite[(string) ($m['url'] ?? '')] = home_url($rel);
        $rewrite[$rel] = home_url($rel);
        $rewrite['/' . $rel] = home_url($rel);
    }
    if ($rewrite) {
        foreach ($items as &$it) {
            foreach (['post_content', 'post_thumb'] as $f) {
                if (!empty($it[$f])) $it[$f] = strtr((string) $it[$f], $rewrite);
            }
        }
        unset($it);
    }
    $report = nexa_ie_import($items, $mode, $dryRun);
    $report['log'][] = ($dryRun ? 'Would copy' : 'Copied') . " {$copied} media file(s) into content/uploads/.";
    return $report;
}
