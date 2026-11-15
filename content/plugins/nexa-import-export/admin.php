<?php
/** Nexa Import / Export — Admin UI (Plugins sidebar + Tools-style plugin screen). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-import-export', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-import-export') ? 'active' : '',
    'icon' => 'fa fa-exchange fa-fw', 'label' => ' Import / Export', 'link' => 'admin.php?plugin_page=nexa-import-export',
], 46);
add_action('nexa_admin', 'nexa_ie_admin_render');

function nexa_ie_admin_render(): void {
    if (($_GET['plugin_page'] ?? '') !== 'nexa-import-export') return;
    global $config;
    $root = rtrim((string) ($config['abs'] ?? dirname(__DIR__, 3)), '/');
    echo '<h2><i class="fa fa-exchange"></i> Nexa Import / Export <small>posts &amp; pages as portable JSON</small></h2>';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        // ---- EXPORT ----
        if (isset($_POST['nexa_ie_export'])) {
            $types = [];
            if (!empty($_POST['type_post'])) $types[] = 'post';
            if (!empty($_POST['type_page'])) $types[] = 'page';
            if (!$types) $types = ['post', 'page'];
            $status = in_array(($_POST['status'] ?? 'all'), ['all', 'published', 'draft'], true) ? (string) $_POST['status'] : 'all';
            if (($_POST['format'] ?? 'json') === 'zip') {
                $res = nexa_ie_write_export_zip($types, $status);
                echo '<div class="alert alert-success"><strong>Full export ready:</strong> ' . (int) $res['count'] . ' item(s) + ' . (int) $res['media'] . ' media file(s) &rarr; <a class="btn btn-xs btn-success" href="' . esc_url($res['url']) . '" download><i class="fa fa-download"></i> Download ' . esc_html($res['name']) . '</a><br><small>ZIP contains <code>export.json</code> plus every referenced image/audio/video/upload under <code>media/</code>. Import it on another NexaCMS site and media URLs are rewritten automatically.</small></div>';
            } else {
                $res = nexa_ie_write_export($types, $status);
                echo '<div class="alert alert-success"><strong>Export ready:</strong> ' . (int) $res['count'] . ' item(s) &rarr; <a class="btn btn-xs btn-success" href="' . esc_url($res['url']) . '" download><i class="fa fa-download"></i> Download ' . esc_html($res['name']) . '</a><br><small>Saved on the server as <code>content/temp_uploads/' . esc_html($res['name']) . '</code>. JSON only — media URLs are kept as-is (use Full ZIP to bundle the files).</small></div>';
            }
        }
        // ---- IMPORT ----
        if (isset($_POST['nexa_ie_import'])) {
            $mode = in_array(($_POST['mode'] ?? 'skip'), ['skip', 'overwrite', 'duplicate'], true) ? (string) $_POST['mode'] : 'skip';
            $dry = !empty($_POST['dry_run']);
            $file = $_FILES['import_file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                echo '<div class="alert alert-danger">Please choose a NexaCMS export file (.json or full .zip) to import.</div>';
            } elseif (str_ends_with(strtolower((string) $file['name']), '.zip')) {
                $tmpDir = $root . '/content/temp_uploads/ie-' . date('Ymd-His');
                @mkdir($tmpDir, 0755, true);
                $zip = new ZipArchive();
                if ($zip->open($file['tmp_name']) !== true) {
                    echo '<div class="alert alert-danger">Could not open that ZIP file.</div>';
                } else {
                    $zip->extractTo($tmpDir);
                    $zip->close();
                    $rep = nexa_ie_import_zip($tmpDir, $mode, $dry);
                    echo '<div class="alert ' . ($rep['errors'] ? 'alert-warning' : 'alert-success') . '"><strong>' . ($dry ? 'Dry run' : 'Full import') . ' complete:</strong> ' . (int) $rep['created'] . ' created, ' . (int) $rep['updated'] . ' updated, ' . (int) $rep['skipped'] . ' skipped' . ($rep['errors'] ? ', ' . count($rep['errors']) . ' error(s)' : '') . '.</div>';
                    echo '<div style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:12px;"><ul style="margin:0;">';
                    foreach ($rep['log'] as $line) echo '<li>' . esc_html($line) . '</li>';
                    foreach ($rep['errors'] as $line) echo '<li class="text-danger">' . esc_html($line) . '</li>';
                    echo '</ul></div>';
                }
            } else {
                $json = file_get_contents($file['tmp_name']);
                $data = json_decode((string) $json, true);
                $items = null;
                if (is_array($data) && isset($data['items']) && is_array($data['items'])) $items = $data['items'];
                elseif (is_array($data)) $items = $data; // tolerate a bare array of posts
                if ($items === null) {
                    echo '<div class="alert alert-danger">That file is not a NexaCMS export (no items found).</div>';
                } else {
                    $rep = nexa_ie_import($items, $mode, $dry);
                    echo '<div class="alert ' . ($rep['errors'] ? 'alert-warning' : 'alert-success') . '"><strong>' . ($dry ? 'Dry run' : 'Import') . ' complete:</strong> ' . (int) $rep['created'] . ' created, ' . (int) $rep['updated'] . ' updated, ' . (int) $rep['skipped'] . ' skipped' . ($rep['errors'] ? ', ' . count($rep['errors']) . ' error(s)' : '') . '.</div>';
                    echo '<div style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:12px;"><ul style="margin:0;">';
                    foreach ($rep['log'] as $line) echo '<li>' . esc_html($line) . '</li>';
                    foreach ($rep['errors'] as $line) echo '<li class="text-danger">' . esc_html($line) . '</li>';
                    echo '</ul></div>';
                    if (!$dry) echo '<p style="margin-top:8px;"><a class="btn btn-default btn-sm" href="../" target="_blank">View site</a> <a class="btn btn-default btn-sm" href="posts.php">Manage posts</a></p>';
                }
            }
        }
    }

    $counts = ['post' => 0, 'page' => 0];
    foreach ((get_table('posts')['data'] ?? []) as $r) { $ty = (string) ($r['post_type'] ?? 'post'); if (isset($counts[$ty])) $counts[$ty]++; }
    ?>
    <div class="row">
      <div class="col-md-6">
        <div class="panel panel-primary">
          <div class="panel-heading"><strong><i class="fa fa-download"></i> Export</strong></div>
          <div class="panel-body">
            <p>Currently: <strong><?= (int) $counts['post'] ?></strong> posts, <strong><?= (int) $counts['page'] ?></strong> pages.</p>
            <form method="post">
              <?= nexa_csrf_field() ?>
              <div class="checkbox"><label><input type="checkbox" name="type_post" value="1" checked> Posts</label></div>
              <div class="checkbox"><label><input type="checkbox" name="type_page" value="1" checked> Pages</label></div>
              <div class="form-group"><label>Status</label>
                <select name="status" class="form-control"><option value="all">All</option><option value="published">Published only</option><option value="draft">Drafts only</option></select></div>
              <div class="form-group"><label>Format</label>
                <select name="format" class="form-control"><option value="json">JSON only (small, no media files)</option><option value="zip">Full ZIP — pages/posts + all media (images, audio, video, uploads)</option></select></div>
              <button name="nexa_ie_export" value="1" class="btn btn-primary"><i class="fa fa-download"></i> Export</button>
            </form>
            <?php
            $exports = glob($root . '/content/temp_uploads/nexa-export-*.json') ?: [];
            rsort($exports);
            if ($exports): ?>
              <hr><strong>Previous exports on server:</strong>
              <ul><?php foreach (array_slice($exports, 0, 5) as $f): ?><li><a href="<?= esc_url(home_url('content/temp_uploads/' . basename($f))) ?>" download><?= esc_html(basename($f)) ?></a> <small>(<?= esc_html(date('Y-m-d H:i', filemtime($f))) ?>)</small></li><?php endforeach; ?></ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="panel panel-default">
          <div class="panel-heading"><strong><i class="fa fa-upload"></i> Import</strong></div>
          <div class="panel-body">
            <form method="post" enctype="multipart/form-data">
              <?= nexa_csrf_field() ?>
              <div class="form-group"><label>Export file (.json or full .zip)</label><input type="file" name="import_file" accept=".json,.zip,application/json,application/zip" class="form-control" required></div>
              <div class="form-group"><label>When a post/page with the same slug already exists</label>
                <select name="mode" class="form-control">
                  <option value="skip">Skip it (keep mine)</option>
                  <option value="overwrite">Overwrite it with the imported one</option>
                  <option value="duplicate">Import as a copy</option>
                </select></div>
              <div class="checkbox"><label><input type="checkbox" name="dry_run" value="1"> Dry run — show what would happen, change nothing</label></div>
              <button name="nexa_ie_import" value="1" class="btn btn-success"><i class="fa fa-upload"></i> Import</button>
            </form>
            <p class="help-block" style="margin-top:10px;">JSON exports contain titles, content (HTML), excerpts, slugs, status, dates, thumbnails and SEO fields (media URLs kept as-is). Full ZIP exports additionally bundle every referenced image, audio, video and other uploaded file — on import the files are copied into <code>content/uploads/</code> and URLs rewritten to this site.</p>
          </div>
        </div>
      </div>
    </div>
    <?php
}
