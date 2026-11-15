<?php
function nexa_forms_ensure(): void { if(!table_exists('nexa_forms')) create_table('nexa_forms',['name','fields','recipient','success_msg','created_at']); if(!table_exists('nexa_form_subs')) create_table('nexa_form_subs',['form_id','data','ip','created_at']); }
nexa_forms_ensure();
add_shortcode('nexa_form', function($a=[]): string { nexa_forms_ensure(); $id=(int)($a['id']??0); $f=query("SELECT * FROM nexa_forms WHERE id=?",[$id]); if(empty($f)) return '<p>Form not found. Create one in Admin &rarr; Form Builder.</p>'; $f=$f[0]; $msg='';
    if(($_SERVER['REQUEST_METHOD']??'')==='POST' && (int)($_POST['nexa_form_id']??0)===$id){ if(!nexa_verify_csrf($_POST['nexa_csrf_token']??null)) $msg='<div class="alert alert-danger">Security check failed.</div>'; elseif(!empty($_POST['website'])) $msg='<div class="alert alert-success">'.esc_html($f['success_msg']?:'Thank you!').'</div>'; else { $data=[]; foreach(array_filter(array_map('trim',explode(',',(string)$f['fields']))) as $fld) $data[$fld]=(string)($_POST['f_'.preg_replace('/[^a-z0-9_]/i','_',strtolower($fld))]??''); query("INSERT INTO nexa_form_subs (form_id,data,ip,created_at) VALUES (?,?,?,?)",[$id,maybe_serialize($data),$_SERVER['REMOTE_ADDR']??'',date('Y-m-d H:i:s')]); $msg='<div class="alert alert-success">'.esc_html($f['success_msg']?:'Thank you! Your submission was received.').'</div>'; } }
    $h=$msg.'<form method="post">'.nexa_csrf_field().'<input type="hidden" name="nexa_form_id" value="'.$id.'">';
    foreach(array_filter(array_map('trim',explode(',',(string)$f['fields']))) as $fld){ $key=preg_replace('/[^a-z0-9_]/i','_',strtolower($fld)); $type=stripos($fld,'email')!==false?'email':(stripos($fld,'message')!==false||stripos($fld,'comment')!==false?'textarea':'text'); $h.='<p><label>'.esc_html($fld).'<br>'.($type==='textarea'?'<textarea name="f_'.$key.'" class="form-control" rows="4"></textarea>':'<input type="'.$type.'" name="f_'.$key.'" class="form-control">').'</label></p>'; }
    return $h.'<p style="display:none"><input name="website" tabindex="-1"></p><button class="btn btn-primary">Submit</button></form>'; });

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    $nexaFormOptions = [];
    if (!empty($GLOBALS['adminarea'])) {
        try {
            if (table_exists('nexa_forms')) {
                foreach (query("SELECT id, name FROM nexa_forms ORDER BY id ASC") as $row) {
                    $nexaFormOptions[] = ['value' => (string) $row['id'], 'label' => (string) ($row['name'] ?? ('Form #' . $row['id'])) . ' (#' . $row['id'] . ')'];
                }
            }
        } catch (Throwable $e) { /* no forms yet */ }
    }
    register_editor_shortcode([
        'plugin' => 'Nexa Forms', 'plugin_slug' => 'nexa-forms', 'label' => 'Built form', 'tag' => 'nexa_form',
        'description' => $nexaFormOptions ? 'Embed a form built in Admin -> Form Builder.' : 'Embed a form built in Admin -> Form Builder. Create a form first, then return here and its name will appear in this list.',
        'fields' => [[
            'name' => 'id', 'label' => 'Form', 'type' => ($nexaFormOptions ? 'select' : 'number'), 'required' => true,
            'options' => $nexaFormOptions, 'default' => $nexaFormOptions ? (string) $nexaFormOptions[0]['value'] : '', 'min' => '1', 'placeholder' => '1',
        ]],
        'template' => '[nexa_form id="{id}"]',
    ]);
    unset($nexaFormOptions);
}
