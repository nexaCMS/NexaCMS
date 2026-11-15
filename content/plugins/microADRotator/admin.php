<?php
/** Nexa Ad Server - Admin UI (hooks the admin hook, as every enabled plugin must). */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name' => 'microADRotator', 'class' => (($_GET['plugin_page'] ?? '') === 'microADRotator') ? 'active' : '', 'icon' => 'fa fa-bullhorn fa-fw', 'label' => ' Ad Server', 'link' => 'admin.php?plugin_page=microADRotator'], 68);
add_action('nexa_admin', 'nexa_ads_admin_render'); add_action('di_admin', 'nexa_ads_admin_render'); add_action('up_admin', 'nexa_ads_admin_render');

function nexa_ads_admin_render(): void {
    static $done = false; if ($done) return;
    if (($_GET['plugin_page'] ?? '') !== 'microADRotator') return;
    $done = true;
    nexa_ads_ensure();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save') {
            query("INSERT INTO ads (name,atype,content,image_url,link_url,zone,weight,status,starts_at,ends_at,impressions,clicks,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [trim((string)$_POST['name']) ?: 'Untitled Ad', (string)$_POST['atype'], (string)$_POST['content'], trim((string)$_POST['image_url']), trim((string)$_POST['link_url']), trim((string)$_POST['zone']) ?: 'default', max(1,(int)$_POST['weight']), (string)$_POST['status'], trim((string)$_POST['starts_at']), trim((string)$_POST['ends_at']), 0, 0, date('Y-m-d H:i:s')]);
            echo '<div class="alert alert-success">Ad created.</div>';
        } elseif ($do === 'toggle') { query("UPDATE ads SET status = ? WHERE id = ?", [(string)$_POST['status'], (int)$_POST['id']]); echo '<div class="alert alert-success">Ad updated.</div>'; }
        elseif ($do === 'del') { query("DELETE FROM ads WHERE id = ?", [(int)$_POST['id']]); echo '<div class="alert alert-success">Ad deleted.</div>'; }
        elseif ($do === 'reset') { query("UPDATE ads SET impressions = 0, clicks = 0 WHERE id = ?", [(int)$_POST['id']]); echo '<div class="alert alert-success">Stats reset.</div>'; }
    }
    $tot = query("SELECT COUNT(*) AS n, COALESCE(SUM(impressions),0) AS imp, COALESCE(SUM(clicks),0) AS clk FROM ads");
    $imp = (int)($tot[0]['imp'] ?? 0); $clk = (int)($tot[0]['clk'] ?? 0);
    echo '<h2><i class="fa fa-bullhorn"></i> Nexa Ad Server</h2>';
    echo '<div class="row">'; foreach ([['Ads',(int)($tot[0]['n']??0)],['Impressions',$imp],['Clicks',$clk],['CTR',($imp?round($clk*100/max(1,$imp),2):0).'%']] as [$l,$v]) echo '<div class="col-md-3"><div class="panel panel-primary"><div class="panel-heading">'.$l.'</div><div class="panel-body"><h2 style="margin:0">'.$v.'</h2></div></div></div>'; echo '</div>';
    echo '<div class="panel panel-default"><div class="panel-heading">Create Ad</div><div class="panel-body"><form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="save">'
      . '<div class="row"><div class="col-md-4"><input name="name" required class="form-control" placeholder="Ad name"></div>'
      . '<div class="col-md-2"><select name="atype" class="form-control"><option value="text">Text</option><option value="image">Image</option><option value="html">HTML</option></select></div>'
      . '<div class="col-md-2"><input name="zone" class="form-control" value="default" title="Zone"></div>'
      . '<div class="col-md-2"><input name="weight" type="number" class="form-control" value="1" min="1" title="Weight (higher = shown more)"></div>'
      . '<div class="col-md-2"><select name="status" class="form-control"><option value="active">Active</option><option value="paused">Paused</option></select></div></div>'
      . '<div class="row" style="margin-top:8px"><div class="col-md-6"><textarea name="content" rows="3" class="form-control" placeholder="Text copy, or raw HTML for HTML ads"></textarea></div>'
      . '<div class="col-md-3"><input name="image_url" class="form-control" placeholder="Image URL (image ads)"><input name="link_url" class="form-control" placeholder="Click-through URL" style="margin-top:8px"></div>'
      . '<div class="col-md-3"><label>Starts <input name="starts_at" type="date" class="form-control"></label> <label>Ends <input name="ends_at" type="date" class="form-control"></label></div></div>'
      . '<button class="btn btn-primary" style="margin-top:8px">Create Ad</button></form></div></div>';
    echo '<table class="table table-striped"><thead><tr><th>Ad</th><th>Type / Zone</th><th>Weight</th><th>Schedule</th><th>Impr.</th><th>Clicks</th><th>CTR</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach (query("SELECT * FROM ads ORDER BY zone ASC, id DESC") as $r) {
        $ctr = (int)$r['impressions'] ? round((int)$r['clicks'] * 100 / max(1,(int)$r['impressions']), 2) : 0;
        echo '<tr><td><strong>' . esc_html($r['name']) . '</strong><br><small>' . esc_html(mb_substr((string)($r['content'] ?: $r['image_url']), 0, 60)) . '</small></td>'
          . '<td>' . esc_html($r['atype']) . ' / ' . esc_html($r['zone']) . '</td><td>' . (int)$r['weight'] . '</td>'
          . '<td><small>' . esc_html($r['starts_at'] ?: '...') . ' to ' . esc_html($r['ends_at'] ?: '...') . '</small></td>'
          . '<td>' . (int)$r['impressions'] . '</td><td>' . (int)$r['clicks'] . '</td><td>' . $ctr . '%</td><td>' . esc_html($r['status']) . '</td>'
          . '<td><form method="post" style="display:inline">' . nexa_csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="status" value="' . ($r['status'] === 'active' ? 'paused' : 'active') . '"><button name="do" value="toggle" class="btn btn-xs btn-default">' . ($r['status'] === 'active' ? 'Pause' : 'Activate') . '</button> <button name="do" value="reset" class="btn btn-xs btn-default">Reset Stats</button> <button name="do" value="del" class="btn btn-xs btn-danger" onclick="return confirm(\'Delete this ad?\')">Delete</button></form></td></tr>';
    }
    echo '</tbody></table><p>Show ads with <code>[ad zone="default" count="1"]</code> (create zones freely, e.g. <code>header</code>, <code>sidebar</code>). Legacy <code>[microAd no="3"]</code> still works. Rotation is weighted-random; clicks are tracked through <code>?nexa_ad_click=ID</code>.</p>';
}
