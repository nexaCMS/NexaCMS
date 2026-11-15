<?php
/** Nexa AI Agent - Admin UI. Every enabled plugin hooks the admin hook: menu + screen. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name' => 'nexa-ai-agent', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-ai-agent') ? 'active' : '', 'icon' => 'fa fa-key fa-fw', 'label' => ' AI Agent Users', 'link' => 'admin.php?plugin_page=nexa-ai-agent'], 69);
add_action('nexa_admin', 'nexa_ai_admin_render'); add_action('di_admin', 'nexa_ai_admin_render'); add_action('up_admin', 'nexa_ai_admin_render');

function nexa_ai_admin_render(): void {
    static $done = false; if ($done) return;
    if (($_GET['plugin_page'] ?? '') !== 'nexa-ai-agent') return;
    $done = true;
    nexa_ai_ensure();
    $newKey = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'settings') {
            set_setting('ai_agent_enabled', (string)($_POST['ai_agent_enabled'] ?? '1'));
            set_setting('ai_default_hour', (string)max(1, (int)($_POST['ai_default_hour'] ?? 60)));
            set_setting('ai_default_day', (string)max(1, (int)($_POST['ai_default_day'] ?? 500)));
            set_setting('ai_global_hour', (string)max(1, (int)($_POST['ai_global_hour'] ?? 1000)));
            echo '<div class="alert alert-success">AI Agent limits saved.</div>';
        } elseif ($do === 'create') {
            [$row, $newKey] = nexa_ai_create_key(trim((string)$_POST['name']) ?: 'ai-user', (array)($_POST['perms'] ?? ['read']), (int)($_POST['rate_hour'] ?? 0), (int)($_POST['rate_day'] ?? 0), (int)($_POST['expires_days'] ?? 0));
            echo '<div class="alert alert-success">AI user created.</div>';
        } elseif ($do === 'revoke' || $do === 'activate') {
            query("UPDATE ai_users SET status = ? WHERE id = ?", [$do === 'revoke' ? 'revoked' : 'active', (int)$_POST['id']]);
            echo '<div class="alert alert-success">AI user ' . ($do === 'revoke' ? 'revoked' : 'activated') . '.</div>';
        } elseif ($do === 'delete') {
            query("DELETE FROM ai_users WHERE id = ?", [(int)$_POST['id']]);
            echo '<div class="alert alert-success">AI user deleted.</div>';
        }
    }
    $enabled = (string)get_setting('ai_agent_enabled', '1') === '1';
    echo '<h2><i class="fa fa-key"></i> AI Agent - API Users</h2>';
    echo '<div class="alert alert-' . ($enabled ? 'info' : 'warning') . '">Endpoint: <code>POST ' . esc_html(home_url('?nexa_ai_api=1')) . '</code> with header <code>Authorization: Bearer nxai_...</code>. '
       . '<strong>Master switch:</strong> deactivate the <em>Nexa AI Agent</em> plugin in Plugins and this API disappears entirely. Keys are stored only as SHA-256 hashes.</div>';
    if ($newKey) echo '<div class="alert alert-danger"><strong>Copy this API key now - it is shown only once:</strong><br><code style="font-size:15px">' . esc_html($newKey) . '</code></div>';

    echo '<div class="panel panel-default"><div class="panel-heading">Global Limits (kill-switch &amp; defaults)</div><div class="panel-body"><form method="post" class="form-inline">' . nexa_csrf_field() . '<input type="hidden" name="do" value="settings">'
       . '<label>Agent <select name="ai_agent_enabled" class="form-control"><option value="1"' . ($enabled ? ' selected' : '') . '>Enabled</option><option value="0"' . (!$enabled ? ' selected' : '') . '>Disabled (kill-switch)</option></select></label> '
       . '<label>Default /hour <input type="number" name="ai_default_hour" class="form-control" value="' . esc_attr((string)get_setting('ai_default_hour', '60')) . '" style="width:90px"></label> '
       . '<label>Default /day <input type="number" name="ai_default_day" class="form-control" value="' . esc_attr((string)get_setting('ai_default_day', '500')) . '" style="width:90px"></label> '
       . '<label>Global cap /hour <input type="number" name="ai_global_hour" class="form-control" value="' . esc_attr((string)get_setting('ai_global_hour', '1000')) . '" style="width:100px"></label> '
       . '<button class="btn btn-primary">Save Limits</button></form></div></div>';

    echo '<div class="panel panel-default"><div class="panel-heading">Create AI User</div><div class="panel-body"><form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="create">'
       . '<div class="form-group"><input name="name" required class="form-control" placeholder="Name, e.g. claude-assistant"></div><div class="form-group">';
    foreach (nexa_ai_permission_defs() as $k => $label) echo '<label class="checkbox-inline"><input type="checkbox" name="perms[]" value="' . esc_attr($k) . '"' . ($k === 'read' ? ' checked' : '') . '> ' . esc_html($k) . ' <small class="text-muted">(' . esc_html($label) . ')</small></label> ';
    echo '</div><div class="form-inline"><label>Rate /hour (0 = default) <input type="number" name="rate_hour" class="form-control" value="0" style="width:90px"></label> '
       . '<label>Rate /day (0 = default) <input type="number" name="rate_day" class="form-control" value="0" style="width:90px"></label> '
       . '<label>Expires in days (0 = never) <input type="number" name="expires_days" class="form-control" value="0" style="width:90px"></label> '
       . '<button class="btn btn-success">Create Key</button></div></form></div></div>';

    echo '<h3>AI Users</h3><table class="table table-striped"><thead><tr><th>Name</th><th>Key</th><th>Permissions</th><th>Limits h/d</th><th>Used h/d</th><th>Status</th><th>Last Used</th><th></th></tr></thead><tbody>';
    $hourAgo = date('Y-m-d H:i:s', time() - 3600); $dayAgo = date('Y-m-d H:i:s', time() - 86400);
    foreach (query("SELECT * FROM ai_users ORDER BY id DESC") as $r) {
        $uh = (int)(query("SELECT COUNT(*) AS c FROM ai_audit WHERE ai_user = ? AND created_at > ?", [$r['name'], $hourAgo])[0]['c'] ?? 0);
        $ud = (int)(query("SELECT COUNT(*) AS c FROM ai_audit WHERE ai_user = ? AND created_at > ?", [$r['name'], $dayAgo])[0]['c'] ?? 0);
        echo '<tr><td>' . esc_html($r['name']) . '</td><td><code>' . esc_html($r['key_prefix']) . '...</code></td><td><small>' . esc_html($r['permissions']) . '</small></td>'
           . '<td>' . esc_html($r['rate_hour'] ?: 'def') . ' / ' . esc_html($r['rate_day'] ?: 'def') . '</td><td>' . $uh . ' / ' . $ud . '</td><td>' . esc_html($r['status']) . (!empty($r['expires_at']) ? '<br><small>exp ' . esc_html($r['expires_at']) . '</small>' : '') . '</td><td>' . esc_html($r['last_used'] ?: '-') . '</td>'
           . '<td><form method="post" style="display:inline">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
           . (($r['status'] ?? '') === 'active' ? '<button name="do" value="revoke" class="btn btn-xs btn-warning">Revoke</button>' : '<button name="do" value="activate" class="btn btn-xs btn-success">Activate</button>')
           . ' <button name="do" value="delete" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete this AI user?\')">Delete</button></form></td></tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Audit Log (latest 30)</h3><table class="table table-striped"><thead><tr><th>When</th><th>AI User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead><tbody>';
    foreach (query("SELECT * FROM ai_audit ORDER BY id DESC LIMIT 30") as $r) echo '<tr><td>' . esc_html($r['created_at']) . '</td><td>' . esc_html($r['ai_user']) . '</td><td>' . esc_html($r['action']) . '</td><td>' . esc_html($r['detail']) . '</td><td>' . esc_html($r['ip']) . '</td></tr>';
    echo '</tbody></table>';
    echo '<h4>Example call</h4><pre>curl -X POST "' . esc_html(home_url('?nexa_ai_api=1')) . '" -H "Authorization: Bearer nxai_YOUR_KEY" -H "Content-Type: application/json" -d \'{"action":"create_post","params":{"title":"Hello from AI","content":"Posted by my AI assistant.","status":"draft"}}\'</pre>';
}
