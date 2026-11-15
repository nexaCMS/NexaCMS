<?php
/**
 * NexaCMS Live Theme Editor (admin/theme-customizer.php)
 * Edit any installed theme (active or not) with a live preview beside the
 * controls. Changes are kept as an unsaved DRAFT in the session and applied
 * to the admin's ?theme= preview only — visitors keep seeing the saved look
 * until "Save" (or "Save & Activate Theme") is pressed.
 * Appearance > Live Theme Editor.
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$activeTheme = (string) get_setting('template', 'multi');
$msSite = function_exists('nexa_current_site') ? nexa_current_site() : null;
$installed = function_exists('nexa_available_theme_slugs') ? nexa_available_theme_slugs($msSite) : [];

$slug = basename((string) ($_REQUEST['theme'] ?? $activeTheme));
if (!in_array($slug, $installed, true)) $slug = $activeTheme;
$schema = nexa_theme_schema($slug);
$knobs  = function_exists('nexa_theme_knobs') ? nexa_theme_knobs() : [];

/** Validate + collect submitted values (schema fields + universal knobs). */
function nexa_cust_collect(array $schema, array $knobs, string $slug): array {
    $values = [];
    foreach ($schema as $field) {
        $key  = (string) ($field['key'] ?? '');
        if ($key === '') continue;
        $type = (string) ($field['type'] ?? 'text');
        $raw  = $_POST['ts'][$key] ?? null;
        if ($type === 'checkbox') {
            $values[$key] = ($raw === '1') ? '1' : '0';
        } elseif ($type === 'color') {
            $values[$key] = (is_string($raw) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($raw))) ? trim($raw) : (string) ($field['default'] ?? '#000000');
        } elseif ($type === 'select') {
            $opts = array_keys((array) ($field['options'] ?? []));
            $values[$key] = in_array($raw, $opts, true) ? (string) $raw : (string) ($field['default'] ?? ($opts[0] ?? ''));
        } else {
            $values[$key] = trim((string) ($raw ?? ''));
        }
    }
    foreach ($knobs as $key => $knob) {
        $raw = trim((string) ($_POST['knob'][$key] ?? ''));
        $values[$key] = preg_match('/^#[0-9a-fA-F]{6}$/', $raw) ? $raw : ''; // '' = theme default
    }
    return $values;
}

function nexa_cust_purge_cache(): void {
    if (function_exists('nexa_cache_purge')) { nexa_cache_purge(); return; }
    foreach (glob(dirname(__DIR__) . '/content/cache/*.html') ?: [] as $f) @unlink($f);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && di_verify_csrf()) {
    $action = (string) ($_POST['cust_action'] ?? 'save');

    if ($action === 'draft') {
        $_SESSION['nexa_customizer_draft'] = ['slug' => $slug, 'values' => nexa_cust_collect($schema, $knobs, $slug), 'at' => time()];
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'draft' => true]);
        exit;
    }
    if ($action === 'discard') {
        unset($_SESSION['nexa_customizer_draft']);
        $_SESSION['success'] = 'Unsaved preview changes discarded.';
        header('Location: theme-customizer.php?theme=' . urlencode($slug)); exit;
    }
    if ($action === 'reset') {
        foreach ($schema as $field) set_setting(nexa_theme_setting_name($slug, $field), (string) ($field['default'] ?? ''), 'Theme: ' . $slug);
        foreach ($knobs as $key => $knob) set_setting('theme_' . $slug . '_' . $key, '', 'Theme: ' . $slug);
        unset($_SESSION['nexa_customizer_draft']);
        nexa_cust_purge_cache();
        $_SESSION['success'] = "Theme '{$slug}' reset to its default look.";
        header('Location: theme-customizer.php?theme=' . urlencode($slug)); exit;
    }
    // save / save_activate
    $values = nexa_cust_collect($schema, $knobs, $slug);
    foreach ($schema as $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key !== '') set_setting(nexa_theme_setting_name($slug, $field), $values[$key], 'Theme: ' . $slug);
    }
    foreach ($knobs as $key => $knob) set_setting('theme_' . $slug . '_' . $key, $values[$key], 'Theme: ' . $slug);
    unset($_SESSION['nexa_customizer_draft']);
    nexa_cust_purge_cache();
    if ($action === 'save_activate') {
        set_setting('template', $slug, 'Current Theme');
        nexa_cust_purge_cache();
        $_SESSION['success'] = "Saved — and '{$slug}' is now the ACTIVE theme.";
    } else {
        $_SESSION['success'] = "Live Theme Editor changes saved for '{$slug}'." . ($slug === $activeTheme ? '' : ' This theme is not active yet — use Save & Activate Theme to go live.');
    }
    header('Location: theme-customizer.php?theme=' . urlencode($slug)); exit;
}

// Values shown in the form: an existing draft for this theme wins over saved.
$draft = (!empty($_SESSION['nexa_customizer_draft']) && is_array($_SESSION['nexa_customizer_draft'])
    && (string) ($_SESSION['nexa_customizer_draft']['slug'] ?? '') === $slug)
    ? (array) $_SESSION['nexa_customizer_draft']['values'] : null;

$values = [];
foreach ($schema as $field) {
    $key = (string) $field['key'];
    $values[$key] = $draft !== null && array_key_exists($key, $draft)
        ? (string) $draft[$key]
        : (string) get_setting(nexa_theme_setting_name($slug, $field), (string) ($field['default'] ?? ''));
}
foreach ($knobs as $key => $knob) {
    $values[$key] = $draft !== null && array_key_exists($key, $draft)
        ? (string) $draft[$key]
        : (string) get_setting('theme_' . $slug . '_' . $key, '');
}

// Preview page choices: home + published posts/pages.
$pages = [['url' => home_url('?theme=' . urlencode($slug) . '&cust=1'), 'label' => 'Homepage']];
try {
    foreach (get_table('posts')['data'] as $row) {
        if (($row['post_status'] ?? '') !== 'published') continue;
        $type = (string) ($row['post_type'] ?? 'post');
        $pages[] = ['url' => home_url('?p=' . (int) $row['id'] . '&theme=' . urlencode($slug) . '&cust=1'), 'label' => ($type === 'page' ? 'Page: ' : 'Post: ') . (string) ($row['post_title'] ?? ('#' . $row['id']))];
        if (count($pages) >= 40) break;
    }
} catch (Throwable $e) { /* preview list is best-effort */ }

include_once __DIR__ . '/includes/header.php';
?>
<div id="page-wrapper">
  <div class="row"><div class="col-lg-12">
    <h1 class="page-header"><i class="fa fa-magic fa-fw"></i> Live Theme Editor
      <small style="font-size:14px;">edit any theme and watch it change live — nothing goes public until you Save</small></h1>
  </div></div>

  <?php include __DIR__ . '/includes/flash_messages.php'; ?>
  <?php if ($draft !== null): ?>
    <div class="alert alert-warning"><strong>You have unsaved preview changes</strong> for “<?= esc_html($slug) ?>”. They only affect your preview. Save them, or discard them below.</div>
  <?php endif; ?>

  <div class="row">
    <div class="col-lg-4">
      <div class="panel panel-primary">
        <div class="panel-heading"><strong>Editing theme</strong></div>
        <div class="panel-body">
          <form method="get" action="theme-customizer.php" style="margin-bottom:10px;">
            <select name="theme" class="form-control" onchange="this.form.submit()">
              <?php foreach ($installed as $th): ?>
                <option value="<?= esc_attr($th) ?>" <?= $th === $slug ? 'selected' : '' ?>><?= esc_html(ucfirst($th)) ?><?= $th === $activeTheme ? ' (active)' : '' ?><?= $msSite && nexa_site_has_theme_copy($th, $msSite) ? ' — site copy' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <?php if ($slug !== $activeTheme): ?>
            <p class="text-muted" style="margin:0;">You are designing a <strong>non-active</strong> theme. Visitors still see “<?= esc_html($activeTheme) ?>”. Use <em>Save &amp; Activate Theme</em> when the new look is ready.</p>
          <?php else: ?>
            <p class="text-muted" style="margin:0;">This is the <strong>active</strong> theme — but your edits still stay private in the preview until you Save.</p>
          <?php endif; ?>
        </div>
      </div>

      <form id="custForm" method="post" action="theme-customizer.php?theme=<?= urlencode($slug) ?>">
        <?= di_csrf_field() ?>
        <input type="hidden" name="cust_action" id="custAction" value="save">

        <div class="panel panel-default">
          <div class="panel-heading"><strong><i class="fa fa-paint-brush"></i> Colours — every theme</strong> <span class="text-muted">(blank = theme's own colour)</span></div>
          <div class="panel-body">
            <?php foreach ($knobs as $key => $knob): $val = $values[$key] ?? ''; ?>
              <div class="form-group" style="margin-bottom:10px;">
                <label style="font-weight:600;"><?= esc_html($knob['label']) ?></label>
                <div class="input-group" style="max-width:320px;">
                  <input type="color" class="form-control cust-color" data-knob="<?= esc_attr($key) ?>" value="<?= esc_attr(preg_match('/^#[0-9a-fA-F]{6}$/', $val) ? $val : $knob['hint']) ?>" style="height:36px;padding:2px;max-width:70px;">
                  <input type="text" name="knob[<?= esc_attr($key) ?>]" class="form-control cust-hex" data-knob-hex="<?= esc_attr($key) ?>" value="<?= esc_attr($val) ?>" placeholder="Theme default (<?= esc_attr($knob['hint']) ?>)">
                  <span class="input-group-btn"><button type="button" class="btn btn-default cust-clear" data-knob-clear="<?= esc_attr($key) ?>" title="Back to theme default">&times;</button></span>
                </div>
              </div>
            <?php endforeach; ?>
            <p class="help-block" style="margin-bottom:0;">These knobs apply on top of <em>any</em> theme (including third-party ones) — body/heading/link text, header &amp; nav, buttons and footer colours.</p>
          </div>
        </div>

        <div class="panel panel-default">
          <div class="panel-heading"><strong><i class="fa fa-sliders"></i> <?= esc_html(ucfirst($slug)) ?> options</strong></div>
          <div class="panel-body">
            <?php foreach ($schema as $field):
              $key = (string) $field['key']; $type = (string) ($field['type'] ?? 'text'); $val = $values[$key] ?? '';
            ?>
              <div class="form-group">
                <label><?= esc_html($field['label'] ?? $key) ?></label>
                <?php if ($type === 'textarea'): ?>
                  <textarea name="ts[<?= esc_attr($key) ?>]" class="form-control cust-field" data-key="<?= esc_attr($key) ?>" rows="<?= $key === 'custom_css' ? 6 : 3 ?>"><?= esc_html($val) ?></textarea>
                <?php elseif ($type === 'select'): ?>
                  <select name="ts[<?= esc_attr($key) ?>]" class="form-control cust-field" data-key="<?= esc_attr($key) ?>">
                    <?php foreach ((array) ($field['options'] ?? []) as $ov => $ol): ?>
                      <option value="<?= esc_attr((string) $ov) ?>" <?= $val === (string) $ov ? 'selected' : '' ?>><?= esc_html((string) $ol) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($type === 'checkbox'): ?>
                  <div class="checkbox"><label><input type="checkbox" name="ts[<?= esc_attr($key) ?>]" value="1" class="cust-field" data-key="<?= esc_attr($key) ?>" <?= $val === '1' ? 'checked' : '' ?>> Enabled</label></div>
                <?php elseif ($type === 'color'): ?>
                  <div class="input-group" style="max-width:320px;">
                    <input type="color" name="ts[<?= esc_attr($key) ?>]" class="form-control cust-field cust-accent" data-key="<?= esc_attr($key) ?>" value="<?= esc_attr(preg_match('/^#[0-9a-fA-F]{6}$/', $val) ? $val : '#000000') ?>" style="height:36px;padding:2px;max-width:70px;">
                    <span class="input-group-addon"><?= esc_html($val) ?></span>
                  </div>
                <?php else: ?>
                  <input type="text" name="ts[<?= esc_attr($key) ?>]" class="form-control cust-field" data-key="<?= esc_attr($key) ?>" value="<?= esc_attr($val) ?>">
                <?php endif; ?>
                <?php if (!empty($field['desc'])): ?><p class="help-block"><?= esc_html((string) $field['desc']) ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel panel-success">
          <div class="panel-body">
            <button type="submit" class="btn btn-success btn-block" onclick="document.getElementById('custAction').value='save'"><i class="fa fa-save"></i> Save Changes</button>
            <?php if ($slug !== $activeTheme): ?>
              <button type="submit" class="btn btn-primary btn-block" onclick="document.getElementById('custAction').value='save_activate'"><i class="fa fa-check"></i> Save &amp; Activate Theme</button>
            <?php endif; ?>
            <button type="submit" class="btn btn-default btn-block" onclick="document.getElementById('custAction').value='discard'">Discard unsaved preview changes</button>
            <button type="submit" class="btn btn-link btn-block text-danger" onclick="if(!confirm('Reset this theme to its default look?'))return false;document.getElementById('custAction').value='reset'">Reset theme to defaults</button>
            <p class="help-block" style="margin:8px 0 0;"><span id="custStatus">Preview is live — start editing.</span></p>
          </div>
        </div>
      </form>
    </div>

    <div class="col-lg-8">
      <div class="panel panel-default">
        <div class="panel-heading" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <strong><i class="fa fa-eye"></i> Live preview</strong>
          <select id="custPage" class="form-control" style="width:auto;max-width:320px;">
            <?php foreach ($pages as $i => $pg): ?><option value="<?= esc_attr($pg['url']) ?>" <?= $i === 0 ? 'selected' : '' ?>><?= esc_html($pg['label']) ?></option><?php endforeach; ?>
          </select>
          <span class="btn-group">
            <button type="button" class="btn btn-default btn-sm cust-device" data-w="100%">Desktop</button>
            <button type="button" class="btn btn-default btn-sm cust-device" data-w="768px">Tablet</button>
            <button type="button" class="btn btn-default btn-sm cust-device" data-w="390px">Phone</button>
          </span>
          <button type="button" class="btn btn-default btn-sm" id="custReload"><i class="fa fa-refresh"></i> Reload</button>
          <a class="btn btn-default btn-sm" id="custOpen" href="<?= esc_attr($pages[0]['url']) ?>" target="_blank">Open full preview</a>
        </div>
        <div class="panel-body" style="background:#e5e7eb;text-align:center;">
          <div id="custFrameWrap" style="margin:0 auto;width:100%;transition:width .25s;">
            <iframe id="custFrame" src="<?= esc_attr($pages[0]['url']) ?>" style="width:100%;height:78vh;border:1px solid #cbd5e1;border-radius:8px;background:#fff;" title="Theme live preview"></iframe>
          </div>
          <p class="text-muted" style="margin:8px 0 0;">Only you (a logged-in admin) see this draft. You can click around inside the preview — the theme stays applied.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var form=document.getElementById('custForm'), frame=document.getElementById('custFrame'),
      wrap=document.getElementById('custFrameWrap'), status=document.getElementById('custStatus'),
      pageSel=document.getElementById('custPage'), timer=null;

  function hexOk(v){return /^#[0-9a-fA-F]{6}$/.test(v||'');}
  function knobCss(){
    var v={};
    document.querySelectorAll('.cust-hex').forEach(function(inp){
      var val=(inp.value||'').trim(); if(hexOk(val)) v[inp.getAttribute('data-knob-hex')]=val;
    });
    var css=':root{'; Object.keys(v).forEach(function(k){css+='--nexa-'+k.replace(/_/g,'-')+':'+v[k]+';';}); css+='}';
    if(v.k_page_bg)css+='body{background-color:'+v.k_page_bg+'!important}';
    if(v.k_body_text)css+='body{color:'+v.k_body_text+'!important}';
    if(v.k_heading_color)css+='h1,h2,h3,h4,h5,h6,h1 a,h2 a,h3 a{color:'+v.k_heading_color+'!important}';
    if(v.k_link_color)css+='a{color:'+v.k_link_color+'!important}';
    if(v.k_link_hover)css+='a:hover{color:'+v.k_link_hover+'!important}';
    if(v.k_muted_color)css+='.text-muted,.muted,small,.post-meta,.meta{color:'+v.k_muted_color+'!important}';
    if(v.k_header_bg)css+='header,.site-header,.navbar,[class*="-header"]{background-color:'+v.k_header_bg+'!important}';
    if(v.k_header_text)css+='header,.site-header,[class*="-header"]{color:'+v.k_header_text+'!important}';
    if(v.k_nav_link)css+='nav a,.nav a,.menu a,.navbar a,header a{color:'+v.k_nav_link+'!important}';
    if(v.k_button_bg||v.k_button_text)css+='.btn,button,input[type="submit"],.button{'+(v.k_button_bg?'background-color:'+v.k_button_bg+'!important;border-color:'+v.k_button_bg+'!important;':'')+(v.k_button_text?'color:'+v.k_button_text+'!important;':'')+'}';
    if(v.k_footer_bg)css+='footer,.site-footer,[class*="-footer"],.footer{background-color:'+v.k_footer_bg+'!important}';
    if(v.k_footer_text)css+='footer,.site-footer,[class*="-footer"],.footer,footer a,[class*="-footer"] a{color:'+v.k_footer_text+'!important}';
    var cc=document.querySelector('textarea[data-key="custom_css"]'); if(cc&&cc.value)css+=cc.value;
    return css;
  }
  function applyInstant(){
    try{
      var doc=frame.contentDocument; if(!doc||!doc.head)return;
      var st=doc.getElementById('nexa-cust-preview');
      if(!st){st=doc.createElement('style');st.id='nexa-cust-preview';doc.head.appendChild(st);}
      st.textContent=knobCss();
    }catch(e){/* cross-origin safety: preview is same-origin in normal installs */}
  }
  function saveDraft(reload){
    var fd=new FormData(form); fd.set('cust_action','draft');
    status.textContent='Updating preview…';
    fetch(form.action,{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(){ status.textContent='Preview updated (unsaved draft).'; if(reload){frame.src=frame.src;} else applyInstant(); })
      .catch(function(){ status.textContent='Could not update the preview — you can still Save normally.'; });
  }
  function schedule(reload){
    clearTimeout(timer);
    timer=setTimeout(function(){saveDraft(reload);}, reload?700:450);
  }
  document.querySelectorAll('.cust-color').forEach(function(picker){
    var key=picker.getAttribute('data-knob');
    var hex=document.querySelector('.cust-hex[data-knob-hex="'+key+'"]');
    picker.addEventListener('input',function(){ hex.value=picker.value; applyInstant(); schedule(false); });
  });
  document.querySelectorAll('.cust-hex').forEach(function(inp){
    inp.addEventListener('input',function(){ applyInstant(); schedule(false); });
  });
  document.querySelectorAll('.cust-clear').forEach(function(btn){
    btn.addEventListener('click',function(){
      var key=btn.getAttribute('data-knob-clear');
      document.querySelector('.cust-hex[data-knob-hex="'+key+'"]').value='';
      applyInstant(); schedule(false);
    });
  });
  document.querySelectorAll('.cust-field').forEach(function(f){
    f.addEventListener('input',function(){ schedule(true); });
    f.addEventListener('change',function(){ schedule(true); });
  });
  frame.addEventListener('load',applyInstant);
  document.querySelectorAll('.cust-device').forEach(function(b){
    b.addEventListener('click',function(){ wrap.style.width=b.getAttribute('data-w'); setTimeout(applyInstant,60); });
  });
  document.getElementById('custReload').addEventListener('click',function(){ frame.src=pageSel.value; });
  pageSel.addEventListener('change',function(){
    frame.src=pageSel.value;
    document.getElementById('custOpen').href=pageSel.value;
  });
})();
</script>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
