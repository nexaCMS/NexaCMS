<?php
/**
 * NexaCMS Release Builder (CLI)
 *
 *   php build_release.php
 *       Full release: dist/nexacms-{version}.zip (new installs),
 *       dist/updat.zip (FULL update), dist/manifest-{version}.json
 *       (sha256 of every shipped file - keep it! It is the baseline
 *        the next partial update is diffed against.)
 *
 *   php build_release.php partial dist/manifest-1.1.0.json
 *       Partial update: dist/updat.zip containing ONLY files that were
 *       added/changed since that baseline manifest, plus an update.json
 *       {"version","partial":true,"from","delete":[...]} listing files
 *       that were removed (the updater backs them up, then deletes them).
 *       Full and partial packages apply identically in Admin > System Update.
 *
 *   php build_release.php minimal
 *       Minimal fresh-install: dist/nexacms-{version}-minimal.zip with the
 *       core, ONE theme (multi) and only the basic plugins (anti-spam,
 *       cache, security, SEO, SMTP, repository hub) — a lean starting
 *       point; everything else can be added via Repositories/uploads.
 *
 * Packaging rules: config.php NEVER ships (config_.php example does);
 * no database stores, uploads, backups, caches or test files ship.
 * An update overwrites CODE only - config.php, admin/database/,
 * content/uploads/ and content/backup/ on the target are preserved,
 * and the updater backs up every file it replaces or deletes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

$root = __DIR__;
require $root . '/includes/version.php';
$version = defined('NEXACMS_VERSION') ? NEXACMS_VERSION : '1.0.0';
$dist = $root . '/dist';
@mkdir($dist, 0755, true);
$notes = 'NexaCMS ' . $version . ': New bundled plugin Nexa Health - Site Health & Resource Monitor (Admin -> Site Health): 14 live read-only checks, resource usage panels, and an hourly snapshot history with trend sparklines.';

$excluded = static function (string $rel): bool {
    $rel = str_replace('\\', '/', $rel);
    if ($rel === 'config.php') return true;
    if ($rel === 'updat.zip' || $rel === 'build_release.php' || $rel === 'test_drivers.php') return true;
    // Dev-only files never ship in public packages (router.php is the
    // PHP built-in-server router for local development; tools/ held
    // one-off scripts — the runtime subsite seeder now lives in
    // includes/multisite-seed.php and ships; .github/ is repo meta).
    if ($rel === 'router.php' || $rel === 'SHA256SUMS') return true;
    foreach (['dist/', 'plugin-zips/', 'admin/database/', 'content/uploads/', 'content/backup/', 'content/cache/', 'content/temp_uploads/', '.git/', '.github/', 'tools/'] as $prefix) {
        if (str_starts_with($rel, $prefix)) return true;
    }
    return false;
};

$collect = static function () use ($root, $excluded): array {
    $files = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($excluded($rel)) continue;
        $files[$rel] = $file->getPathname();
    }
    ksort($files);
    return $files;
};

$manifestOf = static function (array $files): array {
    $map = [];
    foreach ($files as $rel => $abs) $map[$rel] = hash_file('sha256', $abs);
    return $map;
};

$writeZip = static function (string $zipPath, array $files, ?array $updateJson) use ($root): int {
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) { fwrite(STDERR, "Cannot create $zipPath\n"); exit(1); }
    foreach ($files as $rel => $abs) $zip->addFile($abs, $rel);
    foreach (['admin/database/db/', 'content/uploads/', 'content/backup/', 'content/cache/'] as $dir) $zip->addEmptyDir($dir);
    if ($updateJson !== null) $zip->addFromString('update.json', json_encode($updateJson, JSON_PRETTY_PRINT));
    $zip->close();
    return count($files);
};

// After any build, (re)write dist/SHA256SUMS over the release artifacts
// present, so downloads can be verified (the updater also reads a
// SHA256SUMS file hosted next to a remote updat.zip). Release assets
// should be published as: nexacms-{v}.zip, nexacms-{v}-minimal.zip,
// updat.zip, update.json, SHA256SUMS.
$writeSums = static function () use ($dist, $version): void {
    $lines = [];
    foreach (['nexacms-' . $version . '.zip', 'nexacms-' . $version . '-minimal.zip', 'updat.zip'] as $n) {
        if (is_file($dist . '/' . $n)) $lines[] = hash_file('sha256', $dist . '/' . $n) . '  ' . $n;
    }
    if ($lines) file_put_contents($dist . '/SHA256SUMS', implode("\n", $lines) . "\n");
};

$mode = $argv[1] ?? 'full';

if ($mode === 'partial') {
    $baselineFile = $argv[2] ?? '';
    if ($baselineFile === '' || !is_file($baselineFile)) {
        fwrite(STDERR, "Usage: php build_release.php partial <baseline-manifest.json>\nBuild a full release first - it writes dist/manifest-{version}.json.\n");
        exit(1);
    }
    $baseline = json_decode((string) file_get_contents($baselineFile), true);
    $baseFiles = $baseline['files'] ?? [];
    $fromVersion = (string) ($baseline['version'] ?? 'unknown');
    $current = $collect();
    $currentHashes = $manifestOf($current);
    $changed = [];
    foreach ($currentHashes as $rel => $hash) {
        if (($baseFiles[$rel] ?? '') !== $hash) $changed[$rel] = $current[$rel];
    }
    $deleted = array_values(array_diff(array_keys($baseFiles), array_keys($currentHashes)));
    $updateMeta = ['version' => $version, 'notes' => $notes, 'partial' => true, 'from' => $fromVersion, 'delete' => $deleted];
    $n = $writeZip($dist . '/updat.zip', $changed, $updateMeta);
    file_put_contents($dist . '/update.json', json_encode($updateMeta, JSON_PRETTY_PRINT));
    $writeSums();
    file_put_contents($dist . '/manifest-' . $version . '.json', json_encode(['version' => $version, 'files' => $currentHashes], JSON_PRETTY_PRINT));
    echo "Partial updat.zip: {$n} changed/added file(s), " . count($deleted) . " deletion(s), {$fromVersion} -> {$version} (" . round(filesize($dist . '/updat.zip') / 1024, 1) . " KB)\n";
    echo "New baseline written: dist/manifest-{$version}.json - keep it for the next partial.\n";
    exit(0);
}

if ($mode === 'minimal') {
    $keepPlugins = ['nexa-antispam', 'nexa-cache', 'nexa-repo-hub', 'nexa-security', 'nexa-seo', 'nexa-smtp'];
    $keepThemes = ['multi'];
    $files = array_filter($collect(), static function (string $rel) use ($keepPlugins, $keepThemes): bool {
        if (str_starts_with($rel, 'content/plugins/')) {
            $slug = explode('/', $rel)[2] ?? '';
            return in_array($slug, $keepPlugins, true);
        }
        if (str_starts_with($rel, 'content/themes/')) {
            $slug = explode('/', $rel)[2] ?? '';
            return in_array($slug, $keepThemes, true);
        }
        return true;
    }, ARRAY_FILTER_USE_KEY);
    $n = $writeZip($dist . '/nexacms-' . $version . '-minimal.zip', $files, null);
    $writeSums();
    echo "Built dist/nexacms-{$version}-minimal.zip ({$n} files, " . round(filesize($dist . '/nexacms-' . $version . '-minimal.zip') / 1024 / 1024, 2) . " MB)\n";
    echo 'Minimal profile: core + themes [' . implode(', ', $keepThemes) . '] + plugins [' . implode(', ', $keepPlugins) . "]\n";
    exit(0);
}

$files = $collect();
$n1 = $writeZip($dist . '/nexacms-' . $version . '.zip', $files, null);
$fullMeta = ['version' => $version, 'notes' => $notes];
$n2 = $writeZip($dist . '/updat.zip', $files, $fullMeta);
file_put_contents($dist . '/update.json', json_encode($fullMeta, JSON_PRETTY_PRINT));
$writeSums();
file_put_contents($dist . '/manifest-' . $version . '.json', json_encode(['version' => $version, 'files' => $manifestOf($files)], JSON_PRETTY_PRINT));
echo "Built dist/nexacms-{$version}.zip ({$n1} files, " . round(filesize($dist . '/nexacms-' . $version . '.zip') / 1024 / 1024, 2) . " MB)\n";
echo "Built dist/updat.zip (FULL, {$n2} files, " . round(filesize($dist . '/updat.zip') / 1024 / 1024, 2) . " MB)\n";
echo "Baseline manifest: dist/manifest-{$version}.json (use it for the next `partial` build)\n";
echo "Rules: no config.php, config_.php included, no database/uploads/backup data.\n";
