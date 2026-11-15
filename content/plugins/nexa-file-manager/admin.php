<?php
/** Nexa File Manager — browse/upload/edit/download/delete site files from the admin. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-file-manager', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-file-manager') ? 'active' : '',
    'icon' => 'fa fa-folder-open fa-fw', 'label' => ' File Manager', 'link' => 'admin.php?plugin_page=nexa-file-manager',
], 47);
add_action('nexa_admin', 'nexa_fm_render');

function nexa_fm_root(): string {
    global $config;
    return rtrim((string) ($config['abs'] ?? dirname(__DIR__, 3)), '/');
}
function nexa_fm_can(): bool {
    return in_array((string) ($_SESSION['admin_type'] ?? ''), ['super_admin', 'admin'], true);
}
/** Resolve a user-supplied relative path safely inside the site root. Returns [abs, rel] or null. */
function nexa_fm_resolve(string $rel): ?array {
    $root = nexa_fm_root();
    $rel = str_replace('\\', '/', trim($rel));
    $rel = trim($rel, '/');
    $parts = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $parts[] = $seg;
    }
    $clean = implode('/', $parts);
    $abs = $clean === '' ? $root : $root . '/' . $clean;
    $real = realpath($abs);
    if ($real !== false) {
        $rootReal = realpath($root);
        if ($real !== $rootReal && !str_starts_with($real, rtrim((string) $rootReal, '/') . '/')) return null;
        return [$real, $clean];
    }
    // Non-existent target (mkdir/rename): parent must resolve inside root.
    $parent = realpath(dirname($abs));
    if ($parent === false) return null;
    $rootReal = realpath($root);
    if ($parent !== $rootReal && !str_starts_with($parent, rtrim((string) $rootReal, '/') . '/')) return null;
    return [$abs, $clean];
}
/** Files/folders an admin may look at but never edit or delete through this tool. */
function nexa_fm_protected(string $rel): bool {
    if ($rel === 'config.php') return true;
    if ($rel === 'admin/database' || str_starts_with($rel, 'admin/database/')) return true;
    return false;
}
function nexa_fm_size(int $b): string {
    foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($b < 1024 || $u === 'GB') return round($b, $u === 'B' ? 0 : 1) . ' ' . $u; $b /= 1024; }
    return $b . ' B';
}
function nexa_fm_url(array $extra = []): string {
    return 'admin.php?plugin_page=nexa-file-manager' . ($extra ? '&' . http_build_query($extra) : '');
}
function nexa_fm_redirect(string $dir, string $msg, bool $ok = true): void {
    $_SESSION[$ok ? 'success' : 'info'] = $msg;
    header('Location: ' . nexa_fm_url($dir !== '' ? ['dir' => $dir] : []));
    exit;
}
function nexa_fm_rmdir(string $dir): void {
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        is_dir($p) ? nexa_fm_rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/* ---- Early action handling (before any admin output): downloads + all POSTs ---- */
if (($_GET['plugin_page'] ?? '') === 'nexa-file-manager' && nexa_fm_can()) {
    // Download
    if (isset($_GET['fm_download'])) {
        $r = nexa_fm_resolve((string) $_GET['fm_download']);
        if ($r && is_file($r[0])) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($r[0]) . '"');
            header('Content-Length: ' . filesize($r[0]));
            readfile($r[0]);
            exit;
        }
        nexa_fm_redirect((string) ($_GET['dir'] ?? ''), 'File not found.', false);
    }
    // POST actions
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $dir = (string) ($_POST['dir'] ?? '');
        $dirRes = nexa_fm_resolve($dir);
        $action = (string) ($_POST['fm_action'] ?? '');
        if ($action === 'mkdir' && $dirRes) {
            $name = trim((string) ($_POST['name'] ?? ''));
            $target = nexa_fm_resolve(($dir !== '' ? $dir . '/' : '') . $name);
            if ($name !== '' && $target && !file_exists($target[0]) && @mkdir($target[0], 0755, true)) {
                nexa_fm_redirect($dir, "Folder '{$name}' created.");
            }
            nexa_fm_redirect($dir, 'Could not create that folder (invalid name or already exists).', false);
        } elseif ($action === 'upload' && $dirRes) {
            $ok = 0;
            foreach (($_FILES['fm_files']['name'] ?? []) as $i => $orig) {
                if (($orig ?? '') === '' || ($_FILES['fm_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $target = nexa_fm_resolve(($dir !== '' ? $dir . '/' : '') . basename((string) $orig));
                if ($target && @move_uploaded_file($_FILES['fm_files']['tmp_name'][$i], $target[0])) $ok++;
            }
            nexa_fm_redirect($dir, $ok ? "Uploaded {$ok} file(s)." : 'No files were uploaded.', $ok > 0);
        } elseif ($action === 'delete') {
            $r = nexa_fm_resolve((string) ($_POST['path'] ?? ''));
            if ($r && $r[1] !== '' && !nexa_fm_protected($r[1]) && file_exists($r[0])) {
                is_dir($r[0]) ? nexa_fm_rmdir($r[0]) : @unlink($r[0]);
                nexa_fm_redirect($dir, 'Deleted: ' . $r[1]);
            }
            nexa_fm_redirect($dir, 'Delete failed (missing or protected file).', false);
        } elseif ($action === 'rename') {
            $r = nexa_fm_resolve((string) ($_POST['path'] ?? ''));
            $newName = basename(trim((string) ($_POST['new_name'] ?? '')));
            $parentRel = trim(str_replace('\\', '/', dirname((string) ($_POST['path'] ?? ''))), '.');
            $target = $newName !== '' ? nexa_fm_resolve(($parentRel !== '' && $parentRel !== '.' ? $parentRel . '/' : '') . $newName) : null;
            if ($r && $target && !nexa_fm_protected($r[1]) && file_exists($r[0]) && !file_exists($target[0]) && @rename($r[0], $target[0])) {
                nexa_fm_redirect($dir, 'Renamed to ' . $newName . '.');
            }
            nexa_fm_redirect($dir, 'Rename failed (protected, missing, or name already exists).', false);
        } elseif ($action === 'save_edit') {
            $r = nexa_fm_resolve((string) ($_POST['path'] ?? ''));
            $editable = ['php', 'css', 'js', 'html', 'htm', 'txt', 'md', 'json', 'xml', 'svg', 'ini'];
            $ext = strtolower(pathinfo((string) ($_POST['path'] ?? ''), PATHINFO_EXTENSION));
            if ($r && is_file($r[0]) && !nexa_fm_protected($r[1]) && (in_array($ext, $editable, true) || basename($r[1]) === '.htaccess')) {
                // Keep a one-click undo copy next to the file before overwriting.
                @copy($r[0], $r[0] . '.bak');
                file_put_contents($r[0], (string) ($_POST['content'] ?? ''));
                nexa_fm_redirect($dir, 'Saved ' . $r[1] . ' (previous version kept as ' . basename($r[1]) . '.bak).');
            }
            nexa_fm_redirect($dir, 'That file cannot be edited here.', false);
        }
    }
}

function nexa_fm_render(): void {
    if (($_GET['plugin_page'] ?? '') !== 'nexa-file-manager') return;
    echo '<h2><i class="fa fa-folder-open"></i> Nexa File Manager <small>browse, upload, edit &amp; organize site files</small></h2>';
    if (!nexa_fm_can()) {
        echo '<div class="alert alert-danger">File management is restricted to administrators (your role: ' . esc_html((string) ($_SESSION['admin_type'] ?? 'unknown')) . ').</div>';
        return;
    }
    $dir = (string) ($_GET['dir'] ?? '');
    $res = nexa_fm_resolve($dir);
    if ($res === null || !is_dir($res[0])) { $res = [nexa_fm_root(), '']; $dir = ''; }
    [$absDir, $relDir] = $res;
    $roots = ['' => 'Site Root', 'content/uploads' => 'Uploads', 'content' => 'Content', 'content/themes' => 'Themes', 'content/plugins' => 'Plugins', 'admin' => 'Admin'];

    // Quick roots
    echo '<div style="margin:10px 0 8px;">';
    foreach ($roots as $rv => $rl) {
        $active = ($relDir === $rv || ($rv !== '' && str_starts_with($relDir . '/', $rv . '/')));
        echo '<a class="btn btn-sm ' . ($active ? 'btn-primary' : 'btn-default') . '" href="' . esc_url(nexa_fm_url($rv !== '' ? ['dir' => $rv] : [])) . '">' . esc_html($rl) . '</a> ';
    }
    echo '</div><p>';
    $crumbs = ['<a href="' . esc_url(nexa_fm_url()) . '"><i class="fa fa-home"></i> root</a>'];
    $acc = '';
    foreach (array_filter(explode('/', $relDir)) as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $crumbs[] = '<a href="' . esc_url(nexa_fm_url(['dir' => $acc])) . '">' . esc_html($seg) . '</a>';
    }
    echo implode(' / ', $crumbs) . '</p>';

    // Editor view
    if (isset($_GET['edit'])) {
        $r = nexa_fm_resolve((string) $_GET['edit']);
        $editable = ['php', 'css', 'js', 'html', 'htm', 'txt', 'md', 'json', 'xml', 'svg', 'ini'];
        $ext = $r ? strtolower(pathinfo($r[1], PATHINFO_EXTENSION)) : '';
        if ($r && is_file($r[0]) && !nexa_fm_protected($r[1]) && (in_array($ext, $editable, true) || basename($r[1]) === '.htaccess') && filesize($r[0]) < 1048576) {
            echo '<div class="panel panel-warning"><div class="panel-heading"><i class="fa fa-pencil"></i> Editing <code>' . esc_html($r[1]) . '</code> &mdash; a <code>.bak</code> copy is kept on save. Edit with care: PHP/CSS changes go live immediately.</div><div class="panel-body">'
                . '<form method="post" action="' . esc_url(nexa_fm_url()) . '">' . di_csrf_field()
                . '<input type="hidden" name="fm_action" value="save_edit"><input type="hidden" name="path" value="' . esc_attr($r[1]) . '"><input type="hidden" name="dir" value="' . esc_attr($relDir) . '">'
                . '<textarea name="content" class="form-control" rows="22" style="font-family:monospace;font-size:13px;" spellcheck="false">' . esc_textarea((string) file_get_contents($r[0])) . '</textarea>'
                . '<p style="margin-top:10px;"><button class="btn btn-primary"><i class="fa fa-save"></i> Save File</button> <a class="btn btn-default" href="' . esc_url(nexa_fm_url($relDir !== '' ? ['dir' => $relDir] : [])) . '">Cancel</a></p></form></div></div>';
            return;
        }
        echo '<div class="alert alert-warning">That file cannot be edited here (protected, binary, or over 1 MB).</div>';
    }
    // Rename view
    if (isset($_GET['rename'])) {
        $r = nexa_fm_resolve((string) $_GET['rename']);
        if ($r && $r[1] !== '' && !nexa_fm_protected($r[1])) {
            echo '<div class="panel panel-info"><div class="panel-heading"><i class="fa fa-pencil-square-o"></i> Rename</div><div class="panel-body">'
                . '<form method="post" action="' . esc_url(nexa_fm_url()) . '" class="form-inline">' . di_csrf_field()
                . '<input type="hidden" name="fm_action" value="rename"><input type="hidden" name="path" value="' . esc_attr($r[1]) . '"><input type="hidden" name="dir" value="' . esc_attr($relDir) . '">'
                . '<code>' . esc_html($r[1]) . '</code> &rarr; <input type="text" name="new_name" class="form-control" value="' . esc_attr(basename($r[1])) . '" required> '
                . '<button class="btn btn-primary">Rename</button> <a class="btn btn-default" href="' . esc_url(nexa_fm_url($relDir !== '' ? ['dir' => $relDir] : [])) . '">Cancel</a></form></div></div>';
        }
    }

    // Upload + mkdir
    echo '<div class="row"><div class="col-md-7"><div class="panel panel-default"><div class="panel-body">'
        . '<form method="post" enctype="multipart/form-data" action="' . esc_url(nexa_fm_url()) . '" class="form-inline">' . di_csrf_field()
        . '<input type="hidden" name="fm_action" value="upload"><input type="hidden" name="dir" value="' . esc_attr($relDir) . '">'
        . '<input type="file" name="fm_files[]" multiple class="form-control"> <button class="btn btn-success"><i class="fa fa-upload"></i> Upload Here</button></form></div></div></div>'
        . '<div class="col-md-5"><div class="panel panel-default"><div class="panel-body">'
        . '<form method="post" action="' . esc_url(nexa_fm_url()) . '" class="form-inline">' . di_csrf_field()
        . '<input type="hidden" name="fm_action" value="mkdir"><input type="hidden" name="dir" value="' . esc_attr($relDir) . '">'
        . '<input type="text" name="name" class="form-control" placeholder="new-folder" required> <button class="btn btn-primary"><i class="fa fa-plus"></i> Create Folder</button></form></div></div></div></div>';

    // Listing
    $entries = scandir($absDir) ?: [];
    $dirs = $files = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        is_dir($absDir . '/' . $e) ? $dirs[] = $e : $files[] = $e;
    }
    natcasesort($dirs); natcasesort($files);
    echo '<div class="table-responsive"><table class="table table-hover table-bordered"><thead><tr><th>Name</th><th width="110">Size</th><th width="170">Modified</th><th width="300">Actions</th></tr></thead><tbody>';
    if ($relDir !== '') {
        $parent = trim(dirname($relDir), '.');
        echo '<tr><td><a href="' . esc_url(nexa_fm_url($parent !== '' && $parent !== '.' ? ['dir' => $parent] : [])) . '"><i class="fa fa-level-up"></i> .. (parent)</a></td><td></td><td></td><td></td></tr>';
    }
    $editable = ['php', 'css', 'js', 'html', 'htm', 'txt', 'md', 'json', 'xml', 'svg', 'ini'];
    foreach (array_merge($dirs, $files) as $e) {
        $rel = ($relDir !== '' ? $relDir . '/' : '') . $e;
        $abs = $absDir . '/' . $e;
        $isDir = is_dir($abs);
        $prot = nexa_fm_protected($rel);
        echo '<tr><td>' . ($isDir
            ? '<a href="' . esc_url(nexa_fm_url(['dir' => $rel])) . '"><i class="fa fa-folder" style="color:#e8a33d;"></i> <strong>' . esc_html($e) . '</strong></a>'
            : '<i class="fa fa-file-o"></i> ' . esc_html($e) . ($prot ? ' <span class="label label-danger">protected</span>' : '')) . '</td>';
        echo '<td>' . ($isDir ? count(array_diff(scandir($abs) ?: [], ['.', '..'])) . ' items' : nexa_fm_size((int) filesize($abs))) . '</td>';
        echo '<td>' . esc_html(date('Y-m-d H:i', (int) filemtime($abs))) . '</td><td>';
        if ($isDir) {
            echo '<a class="btn btn-xs btn-primary" href="' . esc_url(nexa_fm_url(['dir' => $rel])) . '">Open</a> ';
        } else {
            echo '<a class="btn btn-xs btn-success" href="' . esc_url(nexa_fm_url(['fm_download' => $rel, 'dir' => $relDir])) . '"><i class="fa fa-download"></i></a> ';
            if (str_starts_with($rel, 'content/')) {
                echo '<a class="btn btn-xs btn-info" target="_blank" href="' . esc_url(home_url($rel)) . '"><i class="fa fa-eye"></i></a> ';
            }
            $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
            if (!$prot && (in_array($ext, $editable, true) || $e === '.htaccess')) {
                echo '<a class="btn btn-xs btn-warning" href="' . esc_url(nexa_fm_url(['edit' => $rel, 'dir' => $relDir])) . '"><i class="fa fa-pencil"></i> Edit</a> ';
            }
        }
        if (!$prot) {
            echo '<a class="btn btn-xs btn-default" href="' . esc_url(nexa_fm_url(['rename' => $rel, 'dir' => $relDir])) . '">Rename</a> ';
            echo '<form method="post" action="' . esc_url(nexa_fm_url()) . '" style="display:inline;" onsubmit="return confirm(\'Delete ' . esc_attr($e) . ($isDir ? ' and everything inside it' : '') . '? This cannot be undone.\');">' . di_csrf_field()
                . '<input type="hidden" name="fm_action" value="delete"><input type="hidden" name="path" value="' . esc_attr($rel) . '"><input type="hidden" name="dir" value="' . esc_attr($relDir) . '">'
                . '<button class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="text-muted"><small><i class="fa fa-shield"></i> Safety: paths cannot escape the site root; <code>config.php</code> and the database stores (<code>admin/database/</code>) are protected from editing/deletion; text edits keep a <code>.bak</code> copy.</small></p>';
}
