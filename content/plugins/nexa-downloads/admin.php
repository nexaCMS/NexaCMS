<?php
/** Nexa Downloads — admin: items/uploads, requests (final decision), grants, logs, settings. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-downloads', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-downloads') ? 'active' : '',
    'icon' => 'fa fa-download fa-fw', 'label' => ' Downloads', 'link' => 'admin.php?plugin_page=nexa-downloads',
], 53);
add_action('nexa_admin', 'nexa_dl_admin_render');

function nexa_dl_admin_url(string $tab = 'overview'): string { return 'admin.php?plugin_page=nexa-downloads&tab=' . urlencode($tab); }
function nexa_dl_admin_manager(): bool { return nexa_dl_identity()['manager'] ?? false; }

function nexa_dl_admin_private_dir(): string {
    $root = rtrim((string) ($GLOBALS['config']['abs'] ?? dirname(__DIR__, 3)), '/');
    $dir = $root . '/content/uploads/nexa-downloads';
    @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}

function nexa_dl_admin_store_upload(): array {
    if (empty($_FILES['download_file']['tmp_name']) || !is_uploaded_file($_FILES['download_file']['tmp_name'])) return [];
    $err = (int) ($_FILES['download_file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) return ['error' => 'Upload failed (PHP upload error ' . $err . '). Check the server upload limits or use a server path for a large file.'];
    $orig = (string) ($_FILES['download_file']['name'] ?? 'download');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (in_array($ext, ['php','phtml','phar','cgi','pl','asp','aspx','jsp','sh','htaccess'], true)) return ['error' => 'That file type cannot be stored as a download for security reasons. Zip it first.'];
    $maxMb = max(1, (int) nexa_dl_setting('max_upload_mb', '100'));
    if ((int) $_FILES['download_file']['size'] > $maxMb * 1024 * 1024) return ['error' => 'File is larger than the configured ' . $maxMb . ' MB upload limit. Use a server path or external URL for this file.'];
    $dir = nexa_dl_admin_private_dir();
    $stored = 'dl_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '.bin');
    if (!move_uploaded_file($_FILES['download_file']['tmp_name'], $dir . '/' . $stored)) return ['error' => 'Could not save the uploaded file.'];
    return [
        'file_path' => 'content/uploads/nexa-downloads/' . $stored, 'filename' => basename(str_replace('\\', '/', $orig)),
        'file_size' => (string) (int) $_FILES['download_file']['size'], 'mime' => (string) ($_FILES['download_file']['type'] ?? 'application/octet-stream'), 'stored' => $stored,
    ];
}

function nexa_dl_admin_grant_custom(array $item, int $userId, string $email, string $source, int $max, int $days): int {
    $id = update_table('download_entitlements', [
        'item_id' => (string) $item['id'], 'user_id' => (string) $userId, 'email' => strtolower(trim($email)), 'source' => $source,
        'max_downloads' => (string) max(0, $max), 'used_downloads' => '0', 'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : '',
        'status' => 'active', 'created_at' => nexa_dl_now(),
    ], 'insert');
    return (int) $id;
}

function nexa_dl_admin_render(): void {
    static $done = false;
    if ($done || (($_GET['plugin_page'] ?? '') !== 'nexa-downloads')) return;
    $done = true;
    nexa_dl_ensure();
    if (!nexa_dl_admin_manager()) { echo '<div class="alert alert-warning">Only site managers can administer downloads.</div>'; return; }
    $tab = (string) ($_GET['tab'] ?? 'overview');
    if (!in_array($tab, ['overview','items','requests','entitlements','logs','settings'], true)) $tab = 'overview';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'item_save') {
            $id = (int) ($_POST['id'] ?? 0);
            $existing = $id > 0 ? nexa_dl_item($id) : null;
            $row = [
                'title' => nexa_dl_clean((string) ($_POST['title'] ?? 'Untitled download'), 200), 'description' => nexa_dl_clean((string) ($_POST['description'] ?? ''), 3000),
                'category' => nexa_dl_clean((string) ($_POST['category'] ?? ''), 80), 'filename' => nexa_dl_clean((string) ($_POST['filename'] ?? ''), 200),
                'file_path' => (string) ($existing['file_path'] ?? ''), 'file_type' => (string) ($existing['file_type'] ?? 'local'), 'file_size' => (string) ($existing['file_size'] ?? 0), 'mime' => (string) ($existing['mime'] ?? ''),
                'version' => nexa_dl_clean((string) ($_POST['version'] ?? ''), 50), 'shop_product_id' => (string) (int) ($_POST['shop_product_id'] ?? 0),
                'buy_url' => trim((string) ($_POST['buy_url'] ?? '')), 'price_label' => nexa_dl_clean((string) ($_POST['price_label'] ?? ''), 100),
                'access_mode' => in_array((string) ($_POST['access_mode'] ?? 'public'), ['public','email','logged_in','purchase','approval','members_or_customers'], true) ? (string) $_POST['access_mode'] : 'public',
                'require_email' => !empty($_POST['require_email']) ? '1' : '0', 'require_approval' => !empty($_POST['require_approval']) ? '1' : '0',
                'wait_seconds' => (string) max(0, (int) ($_POST['wait_seconds'] ?? nexa_dl_setting('default_wait', '30'))), 'wait_skip_entitled' => !empty($_POST['wait_skip_entitled']) ? '1' : '0',
                'grant_max_downloads' => (string) max(0, (int) ($_POST['grant_max_downloads'] ?? 0)), 'grant_days' => (string) max(0, (int) ($_POST['grant_days'] ?? 0)),
                'status' => in_array((string) ($_POST['status'] ?? 'active'), ['active','draft','disabled'], true) ? (string) $_POST['status'] : 'active',
                'updated_at' => nexa_dl_now(),
            ];
            $source = (string) ($_POST['file_source'] ?? 'upload');
            $uploadError = '';
            if ($source === 'upload') {
                $up = nexa_dl_admin_store_upload();
                if (!empty($up['error'])) $uploadError = $up['error'];
                elseif ($up) {
                    $row['file_path'] = $up['file_path']; $row['file_type'] = 'local'; $row['file_size'] = $up['file_size']; $row['mime'] = $up['mime'];
                    if ($row['filename'] === '') $row['filename'] = $up['filename'];
                    if ($existing && !empty($_POST['delete_old_file']) && ($existing['file_type'] ?? '') === 'local') {
                        $old = nexa_dl_resolve_file($existing); if ($old && str_contains(str_replace('\\','/',$old), '/content/uploads/nexa-downloads/')) @unlink($old);
                    }
                } elseif (!$existing) $uploadError = 'Please upload a file, or choose a server path / external URL.';
            } elseif ($source === 'path') {
                $path = trim((string) ($_POST['file_path'] ?? ''));
                $row['file_path'] = $path; $row['file_type'] = 'local';
                $probe = nexa_dl_resolve_file(['file_type' => 'local', 'file_path' => $path]);
                if ($probe) { $row['file_size'] = (string) filesize($probe); $row['mime'] = function_exists('mime_content_type') ? (mime_content_type($probe) ?: '') : ''; if ($row['filename'] === '') $row['filename'] = basename($probe); }
            } elseif ($source === 'url') {
                $row['file_path'] = trim((string) ($_POST['external_url'] ?? '')); $row['file_type'] = 'external'; $row['file_size'] = '0'; $row['mime'] = '';
                if ($row['filename'] === '') $row['filename'] = basename(parse_url($row['file_path'], PHP_URL_PATH) ?: 'download');
            }
            if ($uploadError !== '') echo '<div class="alert alert-danger">' . esc_html($uploadError) . '</div>';
            elseif ($row['title'] !== '' && $row['file_path'] !== '') {
                if ($id > 0) { $row['id'] = $id; update_table('download_items', $row, 'update'); }
                else { $row['download_count'] = '0'; $row['created_at'] = nexa_dl_now(); $id = (int) update_table('download_items', $row, 'insert'); }
                echo '<div class="alert alert-success">Download saved. Embed it with <code>[download id="' . $id . '"]</code> or show the library with <code>[downloads]</code>.</div>';
                $tab = 'items';
            } else echo '<div class="alert alert-danger">A title and a file/URL are required.</div>';
        } elseif ($do === 'item_delete' && !empty($_POST['id'])) {
            $item = nexa_dl_item((int) $_POST['id']);
            if ($item && !empty($_POST['delete_file']) && ($item['file_type'] ?? '') === 'local') {
                $file = nexa_dl_resolve_file($item); if ($file && str_contains(str_replace('\\','/',$file), '/content/uploads/nexa-downloads/')) @unlink($file);
            }
            query("DELETE FROM download_tokens WHERE item_id = ?", [(string) (int) $_POST['id']]);
            query("DELETE FROM download_entitlements WHERE item_id = ?", [(string) (int) $_POST['id']]);
            query("DELETE FROM download_requests WHERE item_id = ?", [(string) (int) $_POST['id']]);
            delete_records('download_items', (int) $_POST['id']);
            echo '<div class="alert alert-info">Download deleted. Historical log rows remain for audit.</div>'; $tab = 'items';
        } elseif ($do === 'request_decision' && !empty($_POST['request_id'])) {
            $reqRows = query("SELECT * FROM download_requests WHERE id = ?", [(int) $_POST['request_id']]);
            $req = $reqRows[0] ?? null; $item = $req ? nexa_dl_item((int) $req['item_id']) : null;
            if ($req && $item) {
                $decision = (string) ($_POST['decision'] ?? '');
                $note = nexa_dl_clean((string) ($_POST['admin_note'] ?? ''), 500);
                if ($decision === 'approve') {
                    $max = isset($_POST['grant_max']) ? max(0, (int) $_POST['grant_max']) : (int) ($item['grant_max_downloads'] ?? 0);
                    $days = isset($_POST['grant_days']) ? max(0, (int) $_POST['grant_days']) : (int) ($item['grant_days'] ?? 0);
                    $source = trim((string) ($req['purchase_ref'] ?? '')) !== '' ? 'purchase_claim' : 'approval';
                    $grantId = nexa_dl_admin_grant_custom($item, (int) ($req['user_id'] ?? 0), (string) ($req['email'] ?? ''), $source, $max, $days);
                    update_table('download_requests', ['id' => (int) $req['id'], 'status' => 'approved', 'admin_note' => $note, 'decision_by' => (string) ($_SESSION['display-name'] ?? 'Admin'), 'decided_at' => nexa_dl_now(), 'grant_id' => (string) $grantId], 'update');
                    if (function_exists('nexa_mail') && nexa_dl_email((string) $req['email']) !== '') @nexa_mail((string) $req['email'], 'Your download access was approved', 'Good news — your request for "' . $item['title'] . '" was approved. Return to the download page and use this email address to prepare your download.');
                    echo '<div class="alert alert-success">Request approved and access granted. That was the admin final decision for this request.</div>';
                } elseif ($decision === 'deny') {
                    update_table('download_requests', ['id' => (int) $req['id'], 'status' => 'denied', 'admin_note' => $note, 'decision_by' => (string) ($_SESSION['display-name'] ?? 'Admin'), 'decided_at' => nexa_dl_now()], 'update');
                    if (function_exists('nexa_mail') && nexa_dl_email((string) $req['email']) !== '') @nexa_mail((string) $req['email'], 'Your download request was reviewed', 'Your request for "' . $item['title'] . '" was not approved.' . ($note !== '' ? ' Note: ' . $note : ''));
                    echo '<div class="alert alert-info">Request denied.</div>';
                }
            }
            $tab = 'requests';
        } elseif ($do === 'grant_save') {
            $item = nexa_dl_item((int) ($_POST['item_id'] ?? 0));
            $uid = (int) ($_POST['user_id'] ?? 0); $email = nexa_dl_email((string) ($_POST['email'] ?? ''));
            if ($uid > 0) { $u = get_user($uid); if (is_array($u) && $email === '') $email = nexa_dl_email((string) ($u['email'] ?? '')); }
            if ($item && ($uid > 0 || $email !== '')) {
                nexa_dl_admin_grant_custom($item, $uid, $email, 'admin', (int) ($_POST['max_downloads'] ?? 0), (int) ($_POST['grant_days'] ?? 0));
                echo '<div class="alert alert-success">Manual access granted.</div>';
            } else echo '<div class="alert alert-danger">Choose a download and a user or email.</div>';
            $tab = 'entitlements';
        } elseif ($do === 'grant_revoke' && !empty($_POST['grant_id'])) {
            update_table('download_entitlements', ['id' => (int) $_POST['grant_id'], 'status' => 'revoked'], 'update');
            echo '<div class="alert alert-info">Access revoked.</div>'; $tab = 'entitlements';
        } elseif ($do === 'settings') {
            set_setting('downloads_default_wait', (string) max(0, (int) ($_POST['default_wait'] ?? 30)), 'Downloads Default Wait Seconds');
            set_setting('downloads_token_ttl', (string) max(1, (int) ($_POST['token_ttl'] ?? 30)), 'Downloads Token Minutes');
            set_setting('downloads_max_upload_mb', (string) max(1, (int) ($_POST['max_upload_mb'] ?? 100)), 'Downloads Max Upload MB');
            set_setting('downloads_admin_email', nexa_dl_email((string) ($_POST['admin_email'] ?? '')), 'Downloads Admin Email');
            set_setting('downloads_login_url', trim((string) ($_POST['login_url'] ?? '')), 'Downloads Login URL');
            set_setting('downloads_shop_statuses', nexa_dl_clean((string) ($_POST['shop_statuses'] ?? 'paid,completed,shipped'), 200), 'Downloads Accepted Shop Statuses');
            set_setting('downloads_terms', nexa_dl_clean((string) ($_POST['terms'] ?? ''), 1000), 'Downloads Terms Note');
            echo '<div class="alert alert-success">Download settings saved.</div>'; $tab = 'settings';
        }
    }

    $items = nexa_dl_items(); $requests = get_table('download_requests')['data']; $logs = get_table('download_logs')['data'];
    $pending = count(array_filter($requests, static fn($r) => ($r['status'] ?? '') === 'pending'));
    $delivered = count(array_filter($logs, static fn($r) => ($r['result'] ?? '') === 'delivered'));
    echo '<h2><i class="fa fa-download"></i> Nexa Downloads <small>protected files · tracking · timers · purchases · admin approval</small></h2>';
    echo '<div class="row"><div class="col-sm-3"><div class="panel panel-default"><div class="panel-body text-center"><strong style="font-size:24px;">' . count($items) . '</strong><br>Download items</div></div></div>'
        . '<div class="col-sm-3"><div class="panel panel-warning"><div class="panel-body text-center"><strong style="font-size:24px;">' . $pending . '</strong><br>Requests awaiting your decision</div></div></div>'
        . '<div class="col-sm-3"><div class="panel panel-success"><div class="panel-body text-center"><strong style="font-size:24px;">' . $delivered . '</strong><br>Tracked deliveries</div></div></div>'
        . '<div class="col-sm-3"><div class="panel panel-info"><div class="panel-body text-center"><strong style="font-size:24px;">' . count_records('download_entitlements') . '</strong><br>Access grants</div></div></div></div>';
    echo '<ul class="nav nav-tabs" style="margin-bottom:18px;">';
    foreach (['overview' => 'Overview', 'items' => 'Download Items', 'requests' => 'Requests & Decisions' . ($pending ? ' (' . $pending . ')' : ''), 'entitlements' => 'Access Grants', 'logs' => 'Tracking Logs', 'settings' => 'Settings'] as $k => $label) echo '<li' . ($tab === $k ? ' class="active"' : '') . '><a href="' . esc_attr(nexa_dl_admin_url($k)) . '">' . esc_html($label) . '</a></li>';
    echo '</ul>';

    if ($tab === 'overview') nexa_dl_admin_overview($items, $requests, $logs);
    elseif ($tab === 'items') nexa_dl_admin_items($items);
    elseif ($tab === 'requests') nexa_dl_admin_requests($requests);
    elseif ($tab === 'entitlements') nexa_dl_admin_entitlements($items);
    elseif ($tab === 'logs') nexa_dl_admin_logs($logs, $items);
    elseif ($tab === 'settings') nexa_dl_admin_settings();
}

function nexa_dl_admin_overview(array $items, array $requests, array $logs): void {
    echo '<div class="panel panel-default"><div class="panel-heading">How access works</div><div class="panel-body"><ol style="margin-bottom:0;"><li>Create a download and upload the file (stored under a random protected name — never linked directly).</li><li>Choose who may download: everyone, email gate, logged-in members, verified Shop customers, admin approval, or members/customers.</li><li>Set a free-download wait timer if wanted; paying/approved customers can skip it.</li><li>Visitors who are not eligible send a request. <strong>You make the final decision</strong> under Requests & Decisions.</li><li>Every prepare, denial, request, timer and delivery is tracked under Tracking Logs.</li></ol></div></div>';
    $top = $items; usort($top, static fn($a, $b) => (int) ($b['download_count'] ?? 0) <=> (int) ($a['download_count'] ?? 0));
    echo '<div class="row"><div class="col-md-6"><div class="panel panel-default"><div class="panel-heading">Most downloaded</div><div class="panel-body"><table class="table"><tbody>';
    foreach (array_slice($top, 0, 8) as $i) echo '<tr><td>' . esc_html($i['title']) . '</td><td class="text-right"><strong>' . (int) ($i['download_count'] ?? 0) . '</strong></td></tr>';
    echo '</tbody></table></div></div></div><div class="col-md-6"><div class="panel panel-default"><div class="panel-heading">Recent activity</div><div class="panel-body"><table class="table"><tbody>';
    foreach (array_slice(array_reverse($logs), 0, 10) as $l) { $item = nexa_dl_item((int) $l['item_id']); echo '<tr><td><small>' . esc_html($l['created_at']) . '</small></td><td>' . esc_html($item['title'] ?? ('#' . $l['item_id'])) . '</td><td>' . esc_html($l['result']) . '</td><td><small>' . esc_html($l['email']) . '</small></td></tr>'; }
    echo '</tbody></table></div></div></div></div>';
}

function nexa_dl_admin_items(array $items): void {
    $edit = null;
    if (!empty($_GET['edit_item'])) $edit = nexa_dl_item((int) $_GET['edit_item']);
    $products = table_exists('shop_products') ? get_table('shop_products')['data'] : [];
    echo '<div class="panel panel-primary"><div class="panel-heading">' . ($edit ? 'Edit download' : 'Add a download') . '</div><div class="panel-body"><form method="post" enctype="multipart/form-data">' . nexa_csrf_field() . '<input type="hidden" name="do" value="item_save">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
        . '<div class="row"><div class="col-md-7"><div class="form-group"><label>Title *</label><input name="title" class="form-control" required value="' . esc_attr($edit['title'] ?? '') . '"></div>'
        . '<div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="4">' . esc_textarea($edit['description'] ?? '') . '</textarea></div>'
        . '<div class="row"><div class="col-sm-6"><div class="form-group"><label>Category</label><input name="category" class="form-control" value="' . esc_attr($edit['category'] ?? '') . '" placeholder="ebooks, software, forms"></div></div><div class="col-sm-6"><div class="form-group"><label>Version</label><input name="version" class="form-control" value="' . esc_attr($edit['version'] ?? '') . '"></div></div></div>'
        . '<div class="form-group"><label>File source</label><select name="file_source" class="form-control"><option value="upload">Upload a protected file</option><option value="path">Use a file already on the server</option><option value="url">External URL (tracked redirect)</option></select></div>'
        . '<div class="form-group"><label>Upload file</label><input type="file" name="download_file" class="form-control"><p class="help-block">Stored with a random name under <code>content/uploads/nexa-downloads/</code> with direct access denied. Visitors only receive it through an expiring token.</p>' . ($edit ? '<label class="checkbox-inline"><input type="checkbox" name="delete_old_file" value="1"> Delete the old protected file when replaced</label>' : '') . '</div>'
        . '<div class="form-group"><label>Server path</label><input name="file_path" class="form-control" value="' . esc_attr(($edit['file_type'] ?? '') === 'local' ? ($edit['file_path'] ?? '') : '') . '" placeholder="content/uploads/nexa-downloads/file.zip or /full/path/file.zip"></div>'
        . '<div class="form-group"><label>External URL</label><input name="external_url" class="form-control" value="' . esc_attr(($edit['file_type'] ?? '') === 'external' ? ($edit['file_path'] ?? '') : '') . '" placeholder="https://..."></div>'
        . '<div class="form-group"><label>Display filename</label><input name="filename" class="form-control" value="' . esc_attr($edit['filename'] ?? '') . '" placeholder="My-Book.pdf"></div></div>'
        . '<div class="col-md-5"><div class="panel panel-default"><div class="panel-heading">Who may download?</div><div class="panel-body">'
        . '<div class="form-group"><label>Access rule</label><select name="access_mode" class="form-control">'
        . '<option value="public"' . (($edit['access_mode'] ?? '') === 'public' ? ' selected' : '') . '>Everyone (free)</option><option value="email"' . (($edit['access_mode'] ?? '') === 'email' ? ' selected' : '') . '>Anyone with an email address</option><option value="logged_in"' . (($edit['access_mode'] ?? '') === 'logged_in' ? ' selected' : '') . '>Logged-in users only</option><option value="purchase"' . (($edit['access_mode'] ?? '') === 'purchase' ? ' selected' : '') . '>Verified purchasers only</option><option value="approval"' . (($edit['access_mode'] ?? '') === 'approval' ? ' selected' : '') . '>Admin approval only</option><option value="members_or_customers"' . (($edit['access_mode'] ?? '') === 'members_or_customers' ? ' selected' : '') . '>Logged-in users OR verified purchasers</option></select></div>'
        . '<label class="checkbox-inline"><input type="checkbox" name="require_email" value="1" ' . (($edit['require_email'] ?? '0') === '1' ? 'checked' : '') . '> Require an email address</label><br><label class="checkbox-inline"><input type="checkbox" name="require_approval" value="1" ' . (($edit['require_approval'] ?? '0') === '1' ? 'checked' : '') . '> Also require my approval (final decision)</label>'
        . '<div class="form-group" style="margin-top:12px;"><label>Free-download wait timer (seconds)</label><input type="number" name="wait_seconds" class="form-control" min="0" max="3600" value="' . esc_attr((string) ($edit['wait_seconds'] ?? nexa_dl_setting('default_wait', '30'))) . '"><p class="help-block">0 = no wait. The server enforces the timer; visitors cannot skip it by clicking early.</p></div>'
        . '<label class="checkbox-inline"><input type="checkbox" name="wait_skip_entitled" value="1" ' . (($edit['wait_skip_entitled'] ?? '1') === '1' ? 'checked' : '') . '> Let purchasers/approved users skip the timer</label>'
        . '<div class="form-group"><label>Downloads allowed per grant (0 = unlimited)</label><input type="number" name="grant_max_downloads" class="form-control" min="0" value="' . esc_attr((string) ($edit['grant_max_downloads'] ?? 0)) . '"></div>'
        . '<div class="form-group"><label>Grant expires after days (0 = never)</label><input type="number" name="grant_days" class="form-control" min="0" value="' . esc_attr((string) ($edit['grant_days'] ?? 0)) . '"></div>'
        . '<div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active"' . (($edit['status'] ?? '') === 'active' ? ' selected' : '') . '>Active</option><option value="draft"' . (($edit['status'] ?? '') === 'draft' ? ' selected' : '') . '>Draft</option><option value="disabled"' . (($edit['status'] ?? '') === 'disabled' ? ' selected' : '') . '>Disabled</option></select></div>'
        . '</div></div><div class="panel panel-default"><div class="panel-heading">Purchase options</div><div class="panel-body">'
        . '<div class="form-group"><label>Linked Nexa Shop product</label><select name="shop_product_id" class="form-control"><option value="0">— None —</option>';
    foreach ($products as $p) echo '<option value="' . (int) $p['id'] . '"' . ((int) ($edit['shop_product_id'] ?? 0) === (int) $p['id'] ? ' selected' : '') . '>' . esc_html($p['name'] . ' (#' . $p['id'] . ')') . '</option>';
    echo '</select><p class="help-block">A paid/completed/shipped Shop order for this product, using the same email, unlocks the download automatically.</p></div>'
        . '<div class="form-group"><label>Buy URL</label><input name="buy_url" class="form-control" value="' . esc_attr($edit['buy_url'] ?? '') . '" placeholder="Your shop/checkout page"></div>'
        . '<div class="form-group"><label>Price label</label><input name="price_label" class="form-control" value="' . esc_attr($edit['price_label'] ?? '') . '" placeholder="$19 or Free with membership"></div>'
        . '</div></div></div></div><button class="btn btn-primary btn-lg">' . ($edit ? 'Save Download' : 'Add Download') . '</button> ' . ($edit ? '<a class="btn btn-default btn-lg" href="' . esc_attr(nexa_dl_admin_url('items')) . '">Cancel edit</a>' : '') . '</form></div></div>';

    echo '<div class="panel panel-default"><div class="panel-heading">Downloads library</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>Title</th><th>Access</th><th>File</th><th>Timer</th><th>Downloads</th><th>Shortcode</th><th></th></tr></thead><tbody>';
    foreach ($items as $i) {
        echo '<tr><td><strong>' . esc_html($i['title']) . '</strong><br><small>' . esc_html($i['category']) . ' ' . esc_html($i['version'] ? 'v' . $i['version'] : '') . ' · ' . esc_html($i['status']) . '</small></td><td>' . esc_html($i['access_mode']) . (($i['require_email'] ?? '') === '1' ? '<br><small>Email required</small>' : '') . (($i['require_approval'] ?? '') === '1' ? '<br><small>Approval required</small>' : '') . '</td><td><small>' . esc_html($i['filename']) . '<br>' . esc_html(nexa_dl_format_bytes($i['file_size'] ?? 0)) . ' · ' . esc_html($i['file_type']) . '</small></td><td>' . (int) ($i['wait_seconds'] ?? 0) . 's</td><td>' . (int) ($i['download_count'] ?? 0) . '</td><td><code>[download id="' . (int) $i['id'] . '"]</code></td>'
            . '<td class="text-right"><a class="btn btn-xs btn-primary" href="' . esc_attr(nexa_dl_admin_url('items') . '&edit_item=' . (int) $i['id']) . '">Edit</a> <form method="post" style="display:inline" onsubmit="return confirm(\'Delete this download item?\');">' . nexa_csrf_field() . '<input type="hidden" name="do" value="item_delete"><input type="hidden" name="id" value="' . (int) $i['id'] . '"><label style="font-weight:normal;"><input type="checkbox" name="delete_file" value="1"> file</label> <button class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    echo '</tbody></table><p class="text-muted">Library shortcode: <code>[downloads]</code> · Category: <code>[downloads category="ebooks"]</code> · Member grants: <code>[my_downloads]</code></p></div></div>';
}

function nexa_dl_admin_requests(array $requests): void {
    usort($requests, static fn($a, $b) => ((($a['status'] ?? '') === 'pending') <=> (($b['status'] ?? '') === 'pending')) ?: strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    echo '<div class="panel panel-warning"><div class="panel-heading">Requests awaiting your final decision</div><div class="panel-body">';
    $any = false;
    foreach ($requests as $r) {
        $item = nexa_dl_item((int) $r['item_id']); if (!$item) continue;
        if (($r['status'] ?? '') !== 'pending' && ($r['status'] ?? '') !== 'approved' && ($r['status'] ?? '') !== 'denied') continue;
        $any = true;
        echo '<div class="panel panel-default"><div class="panel-body"><div class="row"><div class="col-md-5"><strong>' . esc_html($item['title']) . '</strong><br>' . esc_html($r['name']) . ' &lt;' . esc_html($r['email']) . '&gt;<br><small>Requested ' . esc_html($r['created_at']) . ((!empty($r['user_id']) && (int) $r['user_id'] > 0) ? ' · user #' . (int) $r['user_id'] : ' · guest') . '</small>'
            . (!empty($r['purchase_ref']) ? '<br><span class="label label-info">Purchase ref: ' . esc_html($r['purchase_ref']) . '</span>' : '') . (!empty($r['reason']) ? '<p style="margin-top:8px;">' . nl2br(esc_html($r['reason'])) . '</p>' : '') . '</div>'
            . '<div class="col-md-7">';
        if (($r['status'] ?? '') === 'pending') {
            echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="request_decision"><input type="hidden" name="request_id" value="' . (int) $r['id'] . '">'
                . '<div class="row"><div class="col-sm-4"><label>Downloads allowed</label><input type="number" name="grant_max" class="form-control" min="0" value="' . esc_attr((string) ($item['grant_max_downloads'] ?? 0)) . '"><small>0 = unlimited</small></div><div class="col-sm-4"><label>Access days</label><input type="number" name="grant_days" class="form-control" min="0" value="' . esc_attr((string) ($item['grant_days'] ?? 0)) . '"><small>0 = no expiry</small></div><div class="col-sm-4"><label>Status</label><br><span class="label label-warning">Pending your decision</span></div></div>'
                . '<div class="form-group" style="margin-top:8px;"><input name="admin_note" class="form-control" placeholder="Note to the requester (optional)"></div>'
                . '<button name="decision" value="approve" class="btn btn-success"><i class="fa fa-check"></i> Approve & grant access</button> <button name="decision" value="deny" class="btn btn-danger" onclick="return confirm(\'Deny this request?\');"><i class="fa fa-times"></i> Deny</button></form>';
        } else {
            echo '<span class="label label-' . (($r['status'] ?? '') === 'approved' ? 'success' : 'danger') . '">' . esc_html(ucfirst((string) $r['status'])) . '</span> <small>by ' . esc_html($r['decision_by']) . ' · ' . esc_html($r['decided_at']) . '</small>' . (!empty($r['admin_note']) ? '<p>' . esc_html($r['admin_note']) . '</p>' : '');
        }
        echo '</div></div></div></div>';
    }
    if (!$any) echo '<p class="text-muted" style="margin:0;">No requests yet. When a visitor cannot download automatically, their request will appear here for your decision.</p>';
    echo '</div></div>';
}

function nexa_dl_admin_entitlements(array $items): void {
    $users = get_table('users')['data'];
    echo '<div class="panel panel-default"><div class="panel-heading">Manually grant access</div><div class="panel-body"><form method="post" class="form-inline">' . nexa_csrf_field() . '<input type="hidden" name="do" value="grant_save">'
        . '<select name="item_id" class="form-control" required><option value="">Choose download…</option>';
    foreach ($items as $i) echo '<option value="' . (int) $i['id'] . '">' . esc_html($i['title']) . '</option>';
    echo '</select> <select name="user_id" class="form-control"><option value="0">Guest / email only</option>';
    foreach ($users as $u) echo '<option value="' . (int) $u['id'] . '">' . esc_html(($u['user'] ?? '') . ' — ' . (function_exists('di_user_display_name') ? di_user_display_name($u) : '')) . '</option>';
    echo '</select> <input type="email" name="email" class="form-control" placeholder="or email address"> <label>Max <input type="number" name="max_downloads" class="form-control" value="0" min="0" style="width:75px"></label> <label>Days <input type="number" name="grant_days" class="form-control" value="0" min="0" style="width:75px"></label> <button class="btn btn-success">Grant Access</button></form><p class="help-block">Use this after an offline payment, as a favour, or whenever you want the admin — not an automatic rule — to decide.</p></div></div>';
    echo '<div class="panel panel-default"><div class="panel-heading">Current grants</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>Download</th><th>Who</th><th>Source</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach (array_reverse(get_table('download_entitlements')['data']) as $g) {
        $item = nexa_dl_item((int) $g['item_id']);
        echo '<tr><td>' . esc_html($item['title'] ?? ('#' . $g['item_id'])) . '</td><td>' . (!empty($g['user_id']) ? 'User #' . (int) $g['user_id'] . '<br>' : '') . '<small>' . esc_html($g['email']) . '</small></td><td>' . esc_html($g['source']) . '</td><td>' . (int) $g['used_downloads'] . ' / ' . (((int) $g['max_downloads'] === 0) ? '∞' : (int) $g['max_downloads']) . '</td><td>' . esc_html($g['expires_at'] ?: 'Never') . '</td><td>' . esc_html($g['status']) . '</td>'
            . '<td>' . (($g['status'] ?? '') === 'active' ? '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="grant_revoke"><input type="hidden" name="grant_id" value="' . (int) $g['id'] . '"><button class="btn btn-xs btn-danger">Revoke</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

function nexa_dl_admin_logs(array $logs, array $items): void {
    $titles = []; foreach ($items as $i) $titles[(int) $i['id']] = $i['title'];
    echo '<div class="panel panel-default"><div class="panel-heading">Download tracking (newest first)</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>When</th><th>Download</th><th>Event</th><th>User / email</th><th>IP</th><th>Size</th></tr></thead><tbody>';
    foreach (array_slice(array_reverse($logs), 0, 200) as $l) {
        echo '<tr><td><small>' . esc_html($l['created_at']) . '</small></td><td>' . esc_html($titles[(int) $l['item_id']] ?? ('#' . $l['item_id'])) . '</td><td><span class="label label-' . (($l['result'] ?? '') === 'delivered' ? 'success' : (str_starts_with((string) ($l['result'] ?? ''), 'denied') ? 'danger' : 'info')) . '">' . esc_html($l['result']) . '</span></td><td>' . (!empty($l['user_id']) ? '#' . (int) $l['user_id'] . '<br>' : '') . '<small>' . esc_html($l['email']) . '</small></td><td><small>' . esc_html($l['ip']) . '</small></td><td>' . esc_html(nexa_dl_format_bytes($l['bytes'] ?? 0)) . '</td></tr>';
    }
    echo '</tbody></table><p class="text-muted">Events include token_created, timer_started, delivered, delivered_range (resume/range requests), denied_* reasons, and request_submitted.</p></div></div>';
}

function nexa_dl_admin_settings(): void {
    echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="settings"><div class="panel panel-default"><div class="panel-heading">Download settings</div><div class="panel-body"><div class="row">'
        . '<div class="col-md-6"><div class="form-group"><label>Default free-download timer (seconds)</label><input type="number" name="default_wait" class="form-control" min="0" max="3600" value="' . esc_attr(nexa_dl_setting('default_wait', '30')) . '"></div>'
        . '<div class="form-group"><label>Secure link lifetime (minutes)</label><input type="number" name="token_ttl" class="form-control" min="1" max="10080" value="' . esc_attr(nexa_dl_setting('token_ttl', '30')) . '"></div>'
        . '<div class="form-group"><label>Admin upload limit (MB)</label><input type="number" name="max_upload_mb" class="form-control" min="1" max="2048" value="' . esc_attr(nexa_dl_setting('max_upload_mb', '100')) . '"><p class="help-block">PHP/host upload limits may be lower. Use a server path for very large files.</p></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>Admin notification email</label><input type="email" name="admin_email" class="form-control" value="' . esc_attr(nexa_dl_setting('admin_email', (string) get_setting('anim_email', ''))) . '"></div>'
        . '<div class="form-group"><label>Login page URL (blank = /dashboard/)</label><input name="login_url" class="form-control" value="' . esc_attr(nexa_dl_setting('login_url', '')) . '" placeholder="https://example.com/dashboard/"></div>'
        . '<div class="form-group"><label>Accepted Nexa Shop order statuses</label><input name="shop_statuses" class="form-control" value="' . esc_attr(nexa_dl_setting('shop_statuses', 'paid,completed,shipped')) . '"><p class="help-block">Comma-separated. A matching order + product + checkout email creates a customer grant automatically.</p></div></div></div>'
        . '<div class="form-group"><label>Terms / note shown under download cards</label><textarea name="terms" class="form-control" rows="3">' . esc_textarea(nexa_dl_setting('terms', 'Downloads are for the licensed user only. Download activity is logged.')) . '</textarea></div>'
        . '<button class="btn btn-primary btn-lg">Save Settings</button></div></div></form>';
}
