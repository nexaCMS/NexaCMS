<?php
/**
 * Nexa Repository Hub — turns this site into a plugin/theme repository.
 *
 * Endpoints (frontend, while this plugin is active):
 *   GET /?nexa_repo=1                                  -> repo index JSON
 *   GET /?nexa_repo=download&type=plugin&slug=<slug>   -> package .zip
 *   GET /?nexa_repo=download&type=theme&slug=<slug>    -> package .zip
 *
 * Only packages the admin explicitly publishes (Repository Hub screen)
 * are served, and only from this install's SHARED folders
 * (content/plugins + content/themes). Other NexaCMS sites consume the
 * hub via Plugins -> Repositories ("NexaCMS hub site" + this site's URL).
 */

function nexa_repohub_published(): array {
    $list = json_decode((string) get_setting('repohub_published', '[]'), true);
    return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
}

function nexa_repohub_all_packages(): array {
    $out = [];
    $pdir = nexa_plugins_dir();
    if (is_dir($pdir)) {
        foreach (scandir($pdir) ?: [] as $e) {
            if ($e === '.' || $e === '..' || !is_dir($pdir . '/' . $e) || !is_file($pdir . '/' . $e . '/plugin.json')) continue;
            $m = di_read_plugin_manifest($pdir . '/' . $e, $e);
            $out[] = ['type' => 'plugin', 'slug' => $e, 'name' => (string) $m['displayed-name'], 'version' => (string) $m['version'], 'author' => (string) $m['author'], 'description' => (string) $m['description'], 'dir' => $pdir . '/' . $e];
        }
    }
    $tdir = nexa_themes_dir();
    if (is_dir($tdir)) {
        foreach (scandir($tdir) ?: [] as $e) {
            if ($e === '.' || $e === '..' || !is_dir($tdir . '/' . $e) || !is_file($tdir . '/' . $e . '/index.php') || !is_file($tdir . '/' . $e . '/style.css')) continue;
            $m = nexa_read_theme_manifest($tdir . '/' . $e, $e);
            $out[] = ['type' => 'theme', 'slug' => $e, 'name' => (string) $m['name'], 'version' => (string) $m['version'], 'author' => (string) $m['author'], 'description' => (string) $m['description'], 'dir' => $tdir . '/' . $e];
        }
    }
    usort($out, static fn($a, $b) => [$a['type'], $a['slug']] <=> [$b['type'], $b['slug']]);
    return $out;
}

function nexa_repohub_zip_dir(string $srcDir, string $slug): ?string {
    $tmpBase = is_dir(dirname(__DIR__, 3) . '/content/temp_uploads') ? dirname(__DIR__, 3) . '/content/temp_uploads' : sys_get_temp_dir();
    $zipPath = $tmpBase . '/repohub_' . $slug . '_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($srcDir) + 1));
        $zip->addFile($file->getPathname(), $slug . '/' . $rel);
    }
    $zip->close();
    return is_file($zipPath) ? $zipPath : null;
}

function nexa_repohub_serve(): void {
    if (empty($_GET['nexa_repo'])) return;
    $published = nexa_repohub_published();
    $mode = (string) $_GET['nexa_repo'];

    if ($mode === 'download') {
        $type = (string) ($_GET['type'] ?? '');
        $slug = basename((string) ($_GET['slug'] ?? ''));
        if (!in_array($type, ['plugin', 'theme'], true) || $slug === '' || !in_array($type . ':' . $slug, $published, true)) {
            http_response_code(404);
            echo 'Package not published by this repository.';
            exit;
        }
        $dir = $type === 'plugin' ? nexa_plugins_dir() . '/' . $slug : nexa_themes_dir() . '/' . $slug;
        if (!is_dir($dir)) { http_response_code(404); echo 'Package not found.'; exit; }
        $zipPath = nexa_repohub_zip_dir($dir, $slug);
        if ($zipPath === null) { http_response_code(500); echo 'Could not build the package zip.'; exit; }
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    $packages = [];
    foreach (nexa_repohub_all_packages() as $p) {
        if (!in_array($p['type'] . ':' . $p['slug'], $published, true)) continue;
        $packages[] = [
            'type' => $p['type'], 'slug' => $p['slug'], 'name' => $p['name'],
            'version' => $p['version'], 'author' => $p['author'], 'description' => $p['description'],
            'download_url' => home_url('?nexa_repo=download&type=' . $p['type'] . '&slug=' . urlencode($p['slug'])),
        ];
    }
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'name' => (string) get_setting('repohub_name', '') !== '' ? (string) get_setting('repohub_name', '') : (get_bloginfo('name') . ' Repository'),
        'hub' => 'nexacms-repohub/1.0',
        'generated_at' => date('c'),
        'packages' => $packages,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
add_action('up_pre', 'nexa_repohub_serve', [], 1);
