<?php
/** Nexa Live Chat — admin console, queue, FAQs, agents, settings and history. */
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', [
    'name' => 'nexa-live-chat', 'class' => (($_GET['plugin_page'] ?? '') === 'nexa-live-chat') ? 'active' : '',
    'icon' => 'fa fa-comments fa-fw', 'label' => ' Live Chat', 'link' => 'admin.php?plugin_page=nexa-live-chat',
], 52);
add_action('nexa_admin', 'nexa_livechat_admin_render');
add_action('nexa_footer', 'nexa_livechat_admin_alert_bubble');

function nexa_livechat_admin_tab_url(string $tab): string {
    return 'admin.php?plugin_page=nexa-live-chat&tab=' . urlencode($tab);
}

function nexa_livechat_admin_alert_bubble(): void {
    static $done = false;
    if ($done || empty($GLOBALS['adminarea']) || !is_user_logged_in()) return;
    if (nexa_livechat_setting('admin_alerts', '1') !== '1' || !nexa_livechat_enabled()) return;
    if (($_GET['plugin_page'] ?? '') === 'nexa-live-chat' && (($_GET['tab'] ?? 'console') === 'console')) return; // the console itself already live-updates
    $manager = nexa_livechat_is_manager();
    $agent = nexa_livechat_current_agent($manager);
    if (!$agent) return;
    $done = true;
    $endpoint = home_url('content/plugins/nexa-live-chat/api.php?nexa_livechat=agent');
    $console = admin_url('admin.php?plugin_page=nexa-live-chat&tab=console');
    $color = nexa_livechat_setting('color', '#6d28d9');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6d28d9';
    echo '<style>'
        . '#nlcAdminBubble{--nlca:' . $color . ';position:fixed;right:20px;bottom:20px;z-index:99999;font-family:Arial,Helvetica,sans-serif;color:#172033}'
        . '#nlcAdminBtn{width:58px;height:58px;border:0;border-radius:50%;background:var(--nlca);color:#fff;box-shadow:0 12px 30px rgba(0,0,0,.30);cursor:pointer;display:flex;align-items:center;justify-content:center;position:relative}'
        . '#nlcAdminBtn svg{width:28px;height:28px;fill:currentColor}#nlcAdminBadge{position:absolute;top:-5px;right:-4px;min-width:23px;height:23px;padding:2px 6px;border-radius:999px;background:#dc2626;color:#fff;font-size:12px;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid #fff}'
        . '#nlcAdminBubble.alerting #nlcAdminBtn{animation:nlcaPulse 1.15s ease-in-out infinite}@keyframes nlcaPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.09)}}'
        . '#nlcAdminPanel{position:absolute;right:0;bottom:72px;width:min(370px,calc(100vw - 32px));background:#fff;border:1px solid rgba(15,23,42,.14);border-radius:16px;box-shadow:0 24px 65px rgba(15,23,42,.34);overflow:hidden;display:none}#nlcAdminPanel.open{display:block}'
        . '#nlcAdminPanel .head{background:var(--nlca);color:#fff;padding:13px 15px}#nlcAdminPanel .head strong{font-size:15px}#nlcAdminPanel .head div{font-size:12px;opacity:.9;margin-top:2px}'
        . '#nlcAdminItems{max-height:330px;overflow:auto;padding:9px}.nlcAdminItem{border:1px solid #e5e7eb;border-radius:11px;padding:10px;margin-bottom:8px;cursor:pointer;background:#fff}.nlcAdminItem:hover{border-color:var(--nlca);background:#f5f3ff}.nlcAdminItem strong{font-size:13px}.nlcAdminItem .why{float:right;background:#fee2e2;color:#991b1b;border-radius:999px;font-size:10px;font-weight:800;padding:3px 7px}.nlcAdminItem p{margin:5px 0 0;color:#475569;font-size:12px;line-height:1.4}.nlcAdminItem small{color:#94a3b8}'
        . '#nlcAdminPanel .tools{display:flex;gap:7px;flex-wrap:wrap;padding:10px;border-top:1px solid #eef2f7;background:#f8fafc}.nlcAdminTool{border:1px solid #cbd5e1;background:#fff;border-radius:999px;padding:6px 10px;cursor:pointer;font-size:12px;font-weight:700}.nlcAdminTool.on{background:var(--nlca);color:#fff;border-color:var(--nlca)}#nlcAdminOpen{background:var(--nlca);color:#fff;border-color:var(--nlca)}'
        . '@media(max-width:640px){#nlcAdminBubble{right:14px;bottom:14px}}'
        . '</style>'
        . '<div id="nlcAdminBubble" aria-live="polite"><section id="nlcAdminPanel" aria-label="Live chat alerts"><div class="head"><strong>Live Chat alerts</strong><div id="nlcAdminMe">Checking for visitors…</div></div><div id="nlcAdminItems"><p style="color:#64748b;font-size:13px;margin:6px;">Checking…</p></div><div class="tools"><button type="button" class="nlcAdminTool" id="nlcAdminOpen">Open Agent Console</button><button type="button" class="nlcAdminTool" id="nlcAdminSound">🔔 Sound: off</button><button type="button" class="nlcAdminTool" id="nlcAdminNotify">Desktop alerts</button></div></section>'
        . '<button type="button" id="nlcAdminBtn" aria-label="Open live chat alerts" title="Live Chat alerts"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.14 2 11.25c0 2.92 1.48 5.53 3.8 7.24-.14 1.1-.62 2.6-1.55 3.72 0 0 2.9-.1 5.02-1.34.87.22 1.79.38 2.73.38 5.52 0 10-4.14 10-9.25S17.52 2 12 2Z"/></svg><span id="nlcAdminBadge">0</span></button></div>'
        . '<script>(function(){'
        . 'var endpoint=' . json_encode($endpoint) . ',csrf=' . json_encode(nexa_csrf_token()) . ',consoleUrl=' . json_encode($console) . ';'
        . 'var root=document.getElementById("nlcAdminBubble");if(!root)return;var btn=document.getElementById("nlcAdminBtn"),panel=document.getElementById("nlcAdminPanel"),badge=document.getElementById("nlcAdminBadge"),itemsBox=document.getElementById("nlcAdminItems"),meBox=document.getElementById("nlcAdminMe"),soundBtn=document.getElementById("nlcAdminSound"),notifyBtn=document.getElementById("nlcAdminNotify");'
        . 'var seen={},firstLoad=true,audioCtx=null,originalTitle=document.title,titleTimer=null;'
        . 'function pref(k){try{return localStorage.getItem(k)==="1"}catch(e){return false}}function setPref(k,v){try{localStorage.setItem(k,v?"1":"0")}catch(e){}}'
        . 'function refreshToggles(){soundBtn.textContent=pref("nexaLCAdminSound")?"🔔 Sound: on":"🔕 Sound: off";soundBtn.classList.toggle("on",pref("nexaLCAdminSound"));var np=("Notification" in window)?Notification.permission:"unsupported";notifyBtn.textContent=np==="granted"?"Desktop alerts: on":(np==="denied"?"Desktop alerts blocked":"Desktop alerts");notifyBtn.classList.toggle("on",np==="granted")}'
        . 'function beep(){if(!pref("nexaLCAdminSound"))return;try{audioCtx=audioCtx||new (window.AudioContext||window.webkitAudioContext)();if(audioCtx.state==="suspended")audioCtx.resume();var t=audioCtx.currentTime;[880,659].forEach(function(f,i){var o=audioCtx.createOscillator(),g=audioCtx.createGain();o.frequency.value=f;o.connect(g);g.connect(audioCtx.destination);g.gain.setValueAtTime(.0001,t+i*.16);g.gain.exponentialRampToValueAtTime(.18,t+i*.16+.02);g.gain.exponentialRampToValueAtTime(.0001,t+i*.16+.14);o.start(t+i*.16);o.stop(t+i*.16+.15)})}catch(e){}}'
        . 'function desktop(item){if(!pref("nexaLCAdminNotify")||!("Notification" in window)||Notification.permission!=="granted"||!item)return;try{var n=new Notification("Live chat: "+(item.visitor_name||"Guest"),{body:(item.last_message||"A visitor needs attention"),tag:"nexa-livechat-"+item.id});n.onclick=function(){window.focus();openChat(item.id)}}catch(e){}}'
        . 'function openChat(id){if(id){try{sessionStorage.setItem("nlcA-selected",String(id))}catch(e){}}window.location.href=consoleUrl}'
        . 'function post(){var b=new URLSearchParams();b.append("action","alerts");b.append("nexa_csrf_token",csrf);return fetch(endpoint,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:b.toString(),credentials:"same-origin"}).then(function(r){return r.text().then(function(txt){try{return JSON.parse(txt)}catch(e){throw new Error("bad response")}})})}'
        . 'function render(j){var items=j.items||[],attention=items.length;badge.textContent=attention;badge.style.display=attention?"flex":"none";root.classList.toggle("alerting",attention>0);meBox.textContent="You: "+j.me.name+" · "+j.me.status+" · "+j.me.load+"/"+j.me.capacity+" chats"+(j.waiting?" · "+j.waiting+" waiting":"");'
        . 'var changed=null;items.forEach(function(it){var key=it.id+"|"+it.status+"|"+(it.last_message_at||"")+"|"+(it.last_message||"");if(!firstLoad&&seen[it.id]!==key)changed=it;seen[it.id]=key});firstLoad=false;'
        . 'itemsBox.innerHTML="";if(!items.length){var p=document.createElement("p");p.style.cssText="color:#64748b;font-size:13px;margin:6px;";p.textContent="No one is waiting and there are no unread visitor messages.";itemsBox.appendChild(p)}'
        . 'items.forEach(function(it){var d=document.createElement("div");d.className="nlcAdminItem";var head=document.createElement("div");var nm=document.createElement("strong");nm.textContent=(it.visitor_name||"Guest")+" · #"+it.id;var why=document.createElement("span");why.className="why";why.textContent=it.status==="queued"?("Waiting"+(it.queue_position?" #"+it.queue_position:"")):"New message";head.appendChild(nm);head.appendChild(why);d.appendChild(head);var msg=document.createElement("p");msg.textContent=it.last_message||"Visitor needs attention.";d.appendChild(msg);var meta=document.createElement("small");meta.textContent=(it.last_message_at||"")+(it.agent_name?" · with "+it.agent_name:"");d.appendChild(meta);d.addEventListener("click",function(){openChat(it.id)});itemsBox.appendChild(d)});'
        . 'if(changed){beep();desktop(changed)}'
        . 'if(attention>0&&document.hidden){if(!titleTimer){var showAlert=true;titleTimer=setInterval(function(){document.title=showAlert?("("+attention+") Live chat needs you!"):originalTitle;showAlert=!showAlert},1000)}}else{if(titleTimer){clearInterval(titleTimer);titleTimer=null}document.title=originalTitle}}'
        . 'function poll(){post().then(render).catch(function(){meBox.textContent="Could not check for chats right now."})}'
        . 'btn.addEventListener("click",function(){panel.classList.toggle("open");if(panel.classList.contains("open"))poll()});document.getElementById("nlcAdminOpen").addEventListener("click",function(){openChat(0)});'
        . 'soundBtn.addEventListener("click",function(){var on=!pref("nexaLCAdminSound");setPref("nexaLCAdminSound",on);if(on)beep();refreshToggles()});'
        . 'notifyBtn.addEventListener("click",function(){if(!("Notification" in window)){alert("Desktop notifications are not supported in this browser.");return}Notification.requestPermission().then(function(p){setPref("nexaLCAdminNotify",p==="granted");refreshToggles();if(p==="granted")desktop({id:"test",visitor_name:"Live Chat",last_message:"Desktop alerts are enabled."})})});'
        . 'document.addEventListener("visibilitychange",function(){if(!document.hidden)poll()});refreshToggles();poll();setInterval(poll,10000);'
        . '})();</script>' . "\n";
}

function nexa_livechat_admin_faq_key($key): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string) $key)) ?? '';
}

function nexa_livechat_admin_faq_row($row, int $line = 0): array {
    if (!is_array($row)) return [];
    if (array_is_list($row)) {
        // New format: question,answer,keywords,followups,enabled,sort — detected when col 4 is not boolean-like.
        $isBool = static fn($v) => $v === null || $v === '' || is_bool($v) || in_array(strtolower(trim((string) $v)), ['1','0','true','false','yes','no','on','off','enabled','active'], true);
        if (count($row) >= 6 && !$isBool($row[3] ?? null) && $isBool($row[4] ?? null)) {
            return ['question' => (string) ($row[0] ?? ''), 'answer' => (string) ($row[1] ?? ''), 'keywords' => (string) ($row[2] ?? ''), 'followups' => (string) ($row[3] ?? ''), 'enabled' => $row[4] ?? null, 'sort' => $row[5] ?? null, '_line' => $line];
        }
        return ['question' => (string) ($row[0] ?? ''), 'answer' => (string) ($row[1] ?? ''), 'keywords' => (string) ($row[2] ?? ''), 'followups' => '', 'enabled' => $row[3] ?? null, 'sort' => $row[4] ?? null, '_line' => $line];
    }
    $n = [];
    foreach ($row as $k => $v) $n[nexa_livechat_admin_faq_key($k)] = $v;
    $pick = static function (array $keys) use ($n) {
        foreach ($keys as $k) if (array_key_exists($k, $n)) return $n[$k];
        return null;
    };
    return [
        'question' => (string) ($pick(['question', 'q', 'faqquestion', 'prompt']) ?? ''), 'answer' => (string) ($pick(['answer', 'a', 'response', 'content']) ?? ''),
        'keywords' => (string) ($pick(['keywords', 'keyword', 'tags', 'tag', 'phrases']) ?? ''), 'followups' => (string) ($pick(['followups', 'followup', 'followupquestions', 'related', 'relatedquestions', 'suggestions']) ?? ''), 'enabled' => $pick(['enabled', 'enable', 'active', 'published']),
        'sort' => $pick(['sort', 'order', 'position']), '_line' => $line,
    ];
}

function nexa_livechat_admin_faq_parse(string $raw, string $format): array {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $format = strtolower(trim($format));
    if ($format === 'auto') {
        $head = ltrim($raw);
        if (str_starts_with($head, '[') || str_starts_with($head, '{')) $format = 'json';
        elseif (preg_match('/(^|\R)\s*(#{2,6}\s+|Q\s*:|Question\s*:)/i', $raw)) $format = 'text';
        else $format = 'csv';
    }
    $rows = []; $errors = [];
    if ($format === 'json') {
        $data = json_decode($raw, true);
        if (!is_array($data)) return ['rows' => [], 'errors' => ['That JSON could not be read. Check for trailing commas or missing quotes.']];
        if (!array_is_list($data)) {
            foreach (['faqs', 'data', 'items', 'questions', 'results'] as $k) if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
        }
        foreach ((array) $data as $i => $row) $rows[] = nexa_livechat_admin_faq_row($row, (int) $i + 1);
        return ['rows' => $rows, 'errors' => $errors];
    }
    if ($format === 'text' || $format === 'txt') {
        $current = null; $field = 'answer';
        $finish = static function () use (&$current, &$rows): void {
            if ($current !== null && trim((string) ($current['question'] ?? '')) !== '') $rows[] = $current;
            $current = null;
        };
        foreach (preg_split('/\R/', $raw) ?: [] as $i => $line) {
            if (preg_match('/^\s*#{2,6}\s+(.+?)\s*$/', $line, $m)) {
                $finish(); $current = ['question' => trim($m[1]), 'answer' => '', 'keywords' => '', 'followups' => '', 'enabled' => null, 'sort' => null, '_line' => $i + 1]; $field = 'answer'; continue;
            }
            if (preg_match('/^\s*(?:Q|Question)\s*:\s*(.*)$/i', $line, $m)) {
                $finish(); $current = ['question' => trim($m[1]), 'answer' => '', 'keywords' => '', 'followups' => '', 'enabled' => null, 'sort' => null, '_line' => $i + 1]; $field = 'question'; continue;
            }
            if ($current === null) continue;
            if (preg_match('/^\s*(?:A|Answer)\s*:\s*(.*)$/i', $line, $m)) { $field = 'answer'; $current['answer'] .= ($current['answer'] !== '' ? "\n" : '') . $m[1]; continue; }
            if (preg_match('/^\s*(?:Keywords?|Tags?)\s*:\s*(.*)$/i', $line, $m)) { $field = 'keywords'; $current['keywords'] = trim($m[1]); continue; }
            if (preg_match('/^\s*(?:Follow-?ups?|Related)\s*:\s*(.*)$/i', $line, $m)) { $field = 'followups'; $current['followups'] = trim($m[1]); continue; }
            if (preg_match('/^\s*Enabled\s*:\s*(.*)$/i', $line, $m)) { $current['enabled'] = trim($m[1]); continue; }
            if (preg_match('/^\s*(?:Sort|Order)\s*:\s*(-?\d+)\s*$/i', $line, $m)) { $current['sort'] = $m[1]; continue; }
            if ($field === 'question' && trim($line) !== '') $current['question'] .= ' ' . trim($line);
            elseif ($field === 'keywords') { if (trim($line) !== '') $current['keywords'] .= ($current['keywords'] !== '' ? ', ' : '') . trim($line); }
            elseif ($field === 'followups') { if (trim($line) !== '') $current['followups'] .= ($current['followups'] !== '' ? "\n" : '') . trim($line); }
            else $current['answer'] .= ($current['answer'] !== '' ? "\n" : '') . $line;
        }
        $finish();
        foreach ($rows as &$r) { $r['answer'] = trim((string) $r['answer']); $r['keywords'] = trim((string) $r['keywords']); }
        unset($r);
        return ['rows' => $rows, 'errors' => $errors];
    }

    // CSV / TSV. The delimiter is detected from the first non-empty line.
    $first = '';
    foreach (preg_split('/\R/', $raw) ?: [] as $line) if (trim($line) !== '') { $first = $line; break; }
    $delimiter = (($format === 'tsv' || substr_count($first, "\t") > substr_count($first, ',')) ? "\t" : ((substr_count($first, ';') > substr_count($first, ',') && !str_contains($first, ',')) ? ';' : ','));
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) return ['rows' => [], 'errors' => ['Could not read the import data.']];
    fwrite($fh, $raw); rewind($fh);
    $records = [];
    while (($rec = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        if ($rec === [null] || trim(implode('', array_map(static fn($v) => (string) $v, $rec))) === '') continue;
        $records[] = $rec;
    }
    fclose($fh);
    if (!$records) return ['rows' => [], 'errors' => ['No rows were found in that file.']];
    $header = null; $start = 0;
    $normHead = array_map(static fn($v) => nexa_livechat_admin_faq_key($v), $records[0]);
    if (in_array('question', $normHead, true) && (in_array('answer', $normHead, true) || in_array('a', $normHead, true))) { $header = $records[0]; $start = 1; }
    foreach ($records as $idx => $rec) {
        if ($idx < $start) continue;
        if ($header !== null) {
            $assoc = [];
            foreach ($header as $c => $name) $assoc[(string) $name] = $rec[$c] ?? '';
            $rows[] = nexa_livechat_admin_faq_row($assoc, $idx + 1);
        } else $rows[] = nexa_livechat_admin_faq_row($rec, $idx + 1);
    }
    return ['rows' => $rows, 'errors' => $errors];
}

function nexa_livechat_admin_faq_bool($value, bool $default): bool {
    if ($value === null || $value === '') return $default;
    if (is_bool($value)) return $value;
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on', 'enabled', 'active'], true);
}

function nexa_livechat_admin_render(): void {
    static $done = false;
    if ($done || (($_GET['plugin_page'] ?? '') !== 'nexa-live-chat')) return;
    $done = true;
    nexa_livechat_ensure();
    $manager = nexa_livechat_is_manager();
    $agent = nexa_livechat_current_agent($manager);
    $tab = (string) ($_GET['tab'] ?? 'console');
    if (!in_array($tab, ['console', 'queue', 'faqs', 'agents', 'settings', 'history'], true)) $tab = 'console';

    /* ---------------- POST actions (CSRF-checked) ---------------- */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && nexa_verify_csrf()) {
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'settings' && $manager) {
            $mode = (string) ($_POST['initial_mode'] ?? 'faq');
            set_setting('livechat_enabled', !empty($_POST['enabled']) ? '1' : '0', 'Live Chat Enabled');
            set_setting('livechat_brand_name', nexa_livechat_clean_text((string) ($_POST['brand_name'] ?? ''), 80) ?: 'Support', 'Live Chat Brand');
            set_setting('livechat_bot_name', nexa_livechat_clean_text((string) ($_POST['bot_name'] ?? ''), 80) ?: 'Nexa Assistant', 'Live Chat Bot Name');
            $color = (string) ($_POST['color'] ?? '#6d28d9'); if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6d28d9';
            set_setting('livechat_color', $color, 'Live Chat Color');
            set_setting('livechat_initial_mode', in_array($mode, ['faq', 'ai', 'hybrid'], true) ? $mode : 'faq', 'Live Chat First Contact Mode');
            set_setting('livechat_faq_threshold', (string) max(1, min(30, (int) ($_POST['faq_threshold'] ?? 4))), 'Live Chat FAQ Match Threshold');
            set_setting('livechat_default_capacity', (string) max(1, min(50, (int) ($_POST['default_capacity'] ?? 3))), 'Live Chat Default Chats Per Agent');
            set_setting('livechat_max_capacity', (string) max(1, min(100, (int) ($_POST['max_capacity'] ?? 10))), 'Live Chat Maximum Chats Per Agent');
            set_setting('livechat_require_email', !empty($_POST['require_email']) ? '1' : '0', 'Live Chat Requires Email');
            set_setting('livechat_admin_alerts', !empty($_POST['admin_alerts']) ? '1' : '0', 'Live Chat Admin Alert Bubble');
            set_setting('livechat_suggestions_visitor', !empty($_POST['suggestions_visitor']) ? '1' : '0', 'Live Chat Visitor Smart Suggestions');
            set_setting('livechat_suggestions_agent', !empty($_POST['suggestions_agent']) ? '1' : '0', 'Live Chat Agent Suggested Replies');
            set_setting('livechat_suggestions_ai', !empty($_POST['suggestions_ai']) ? '1' : '0', 'Live Chat AI Follow-up Suggestions');
            set_setting('livechat_queue_offline', !empty($_POST['queue_offline']) ? '1' : '0', 'Live Chat Queue When Offline');
            set_setting('livechat_welcome_message', nexa_livechat_clean_text((string) ($_POST['welcome_message'] ?? ''), 1000), 'Live Chat Welcome');
            set_setting('livechat_queue_message', nexa_livechat_clean_text((string) ($_POST['queue_message'] ?? ''), 1000), 'Live Chat Queue Message');
            set_setting('livechat_offline_message', nexa_livechat_clean_text((string) ($_POST['offline_message'] ?? ''), 1000), 'Live Chat Offline Message');
            set_setting('livechat_ai_endpoint', trim((string) ($_POST['ai_endpoint'] ?? 'https://api.openai.com/v1/chat/completions')), 'Live Chat AI Endpoint');
            set_setting('livechat_ai_model', trim((string) ($_POST['ai_model'] ?? 'gpt-4o-mini')), 'Live Chat AI Model');
            if (!empty($_POST['clear_ai_key'])) set_setting('livechat_ai_key', '', 'Live Chat AI Key');
            elseif (trim((string) ($_POST['ai_key'] ?? '')) !== '') set_setting('livechat_ai_key', trim((string) $_POST['ai_key']), 'Live Chat AI Key');
            set_setting('livechat_ai_system_prompt', nexa_livechat_clean_text((string) ($_POST['ai_system_prompt'] ?? ''), 3000), 'Live Chat AI Prompt');
            echo '<div class="alert alert-success">Live Chat settings saved.</div>';
        } elseif ($do === 'faq_save' && $manager) {
            $row = [
                'question' => nexa_livechat_clean_text((string) ($_POST['question'] ?? ''), 300), 'answer' => nexa_livechat_clean_text((string) ($_POST['answer'] ?? ''), 4000),
                'keywords' => nexa_livechat_clean_text((string) ($_POST['keywords'] ?? ''), 500), 'followups' => nexa_livechat_clean_text((string) ($_POST['followups'] ?? ''), 1000), 'enabled' => !empty($_POST['enabled']) ? '1' : '0',
                'sort' => (string) (int) ($_POST['sort'] ?? 0), 'created_at' => nexa_livechat_now(),
            ];
            if ($row['question'] !== '' && $row['answer'] !== '') {
                if (!empty($_POST['id'])) { $row['id'] = (int) $_POST['id']; unset($row['created_at']); update_table('livechat_faqs', $row, 'update'); }
                else update_table('livechat_faqs', $row, 'insert');
                echo '<div class="alert alert-success">FAQ saved. The bot uses it immediately.</div>';
            } else echo '<div class="alert alert-danger">FAQ question and answer are required.</div>';
        } elseif ($do === 'faq_delete' && $manager && !empty($_POST['id'])) {
            delete_records('livechat_faqs', (int) $_POST['id']);
            echo '<div class="alert alert-info">FAQ deleted.</div>';
        } elseif ($do === 'faq_import' && $manager) {
            $raw = '';
            $uploadError = '';
            if (!empty($_FILES['faq_file']['tmp_name']) && is_uploaded_file($_FILES['faq_file']['tmp_name'])) {
                if (((int) ($_FILES['faq_file']['size'] ?? 0)) > 10 * 1024 * 1024) $uploadError = 'That import file is larger than 10 MB. Split it into smaller files and import them one at a time.';
                else $raw = (string) file_get_contents($_FILES['faq_file']['tmp_name']);
            } elseif (!empty($_FILES['faq_file']['error']) && (int) $_FILES['faq_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadError = 'The FAQ file could not be uploaded (PHP upload error ' . (int) $_FILES['faq_file']['error'] . '). You can paste the contents instead.';
            } else {
                $raw = (string) ($_POST['faq_import_text'] ?? '');
            }
            if ($uploadError !== '') {
                echo '<div class="alert alert-danger">' . esc_html($uploadError) . '</div>';
            } elseif (trim($raw) === '') {
                echo '<div class="alert alert-danger">Choose a CSV, TSV, JSON or text file, or paste Q&amp;A content to import.</div>';
            } else {
                $parsed = nexa_livechat_admin_faq_parse($raw, (string) ($_POST['faq_import_format'] ?? 'auto'));
                $strategy = in_array((string) ($_POST['faq_duplicate_strategy'] ?? 'update'), ['update', 'skip', 'duplicate'], true) ? (string) $_POST['faq_duplicate_strategy'] : 'update';
                $defaultEnabled = !empty($_POST['faq_import_enabled']);
                $existingRows = get_table('livechat_faqs')['data'];
                $existing = [];
                $maxSort = 0;
                foreach ($existingRows as $f) {
                    $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($f['question'] ?? '')) ?? ''));
                    if ($norm !== '') $existing[$norm] = $f;
                    $maxSort = max($maxSort, (int) ($f['sort'] ?? 0));
                }
                $insertRows = []; $insertByNorm = []; $updateRows = []; $usedNorms = [];
                $inserted = $updated = $skipped = $invalid = 0; $problems = $parsed['errors'];
                if (count($parsed['rows']) > 5000) $problems[] = 'Only the first 5,000 Q&A rows were imported. Split larger libraries into multiple imports.';
                foreach (array_slice($parsed['rows'], 0, 5000) as $row) {
                    $q = nexa_livechat_clean_text((string) ($row['question'] ?? ''), 300);
                    $a = nexa_livechat_clean_text((string) ($row['answer'] ?? ''), 4000);
                    $k = nexa_livechat_clean_text((string) ($row['keywords'] ?? ''), 500);
                    $fu = nexa_livechat_clean_text((string) ($row['followups'] ?? ''), 1000);
                    if ($q === '' || $a === '') { $invalid++; if (count($problems) < 6) $problems[] = 'Row ' . (int) ($row['_line'] ?? 0) . ' was skipped because the question or answer was empty.'; continue; }
                    $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', $q) ?? $q));
                    $sort = isset($row['sort']) && $row['sort'] !== null && $row['sort'] !== '' && is_numeric($row['sort']) ? (int) $row['sort'] : ++$maxSort;
                    $clean = ['question' => $q, 'answer' => $a, 'keywords' => $k, 'followups' => $fu, 'enabled' => nexa_livechat_admin_faq_bool($row['enabled'] ?? null, $defaultEnabled) ? '1' : '0', 'sort' => (string) $sort, 'created_at' => nexa_livechat_now()];
                    if (isset($existing[$norm]) && $strategy !== 'duplicate') {
                        if ($strategy === 'skip' || isset($usedNorms[$norm])) { $skipped++; continue; }
                        $upd = $clean; unset($upd['created_at']); $upd['id'] = (int) $existing[$norm]['id'];
                        if (!isset($row['sort']) || $row['sort'] === null || $row['sort'] === '') $upd['sort'] = (string) ($existing[$norm]['sort'] ?? $sort);
                        $updateRows[(int) $existing[$norm]['id']] = $upd; $usedNorms[$norm] = true; $updated++; continue;
                    }
                    if ($strategy === 'update' && isset($insertByNorm[$norm])) { $insertRows[$insertByNorm[$norm]] = $clean; $skipped++; continue; }
                    if ($strategy === 'skip' && isset($usedNorms[$norm])) { $skipped++; continue; }
                    $insertByNorm[$norm] = count($insertRows); $insertRows[] = $clean; $usedNorms[$norm] = true; $inserted++;
                }
                if ($insertRows && function_exists('multi_update_table')) multi_update_table('livechat_faqs', $insertRows, 'insert'); elseif ($insertRows) foreach ($insertRows as $r) update_table('livechat_faqs', $r, 'insert');
                if ($updateRows && function_exists('multi_update_table')) multi_update_table('livechat_faqs', array_values($updateRows), 'update'); elseif ($updateRows) foreach ($updateRows as $r) update_table('livechat_faqs', $r, 'update');
                echo '<div class="alert alert-success"><strong>FAQ import complete:</strong> ' . $inserted . ' added, ' . $updated . ' updated, ' . $skipped . ' duplicate(s) skipped/merged, ' . $invalid . ' invalid row(s) skipped. The bot can use the imported answers immediately.</div>';
                if ($problems) echo '<div class="alert alert-warning">' . implode('<br>', array_map('esc_html', array_slice($problems, 0, 6))) . '</div>';
            }
            $tab = 'faqs';
        } elseif ($do === 'agent_save' && $manager) {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $user = $uid > 0 ? get_user($uid) : false;
            $agentId = (int) ($_POST['agent_id'] ?? 0);
            if ($user || $agentId > 0) {
                $max = max(1, (int) nexa_livechat_setting('max_capacity', '10'));
                $row = [
                    'capacity' => (string) max(1, min($max, (int) ($_POST['capacity'] ?? nexa_livechat_setting('default_capacity', '3')))),
                    'status' => in_array((string) ($_POST['status'] ?? 'online'), ['online', 'away', 'offline'], true) ? (string) $_POST['status'] : 'online',
                    'enabled' => !empty($_POST['enabled']) ? '1' : '0',
                ];
                if ($user) {
                    $row['user_id'] = (string) $uid; $row['username'] = (string) ($user['user'] ?? '');
                    $row['display_name'] = nexa_livechat_clean_text((string) ($_POST['display_name'] ?? ''), 100) ?: (function_exists('di_user_display_name') ? di_user_display_name($user) : (string) ($user['user'] ?? 'Agent'));
                } elseif (!empty($_POST['display_name'])) $row['display_name'] = nexa_livechat_clean_text((string) $_POST['display_name'], 100);
                if ($agentId > 0) { $row['id'] = $agentId; update_table('livechat_agents', $row, 'update'); }
                else { $row['last_seen'] = ''; $row['created_at'] = nexa_livechat_now(); update_table('livechat_agents', $row, 'insert'); }
                echo '<div class="alert alert-success">Agent saved.</div>';
                $agent = nexa_livechat_current_agent(true);
            }
        } elseif ($do === 'agent_delete' && $manager && !empty($_POST['agent_id'])) {
            $aid = (int) $_POST['agent_id'];
            foreach (query("SELECT id FROM livechat_chats WHERE assigned_agent = ? AND status = ?", [(string) $aid, 'active']) as $c) {
                nexa_livechat_update_chat((int) $c['id'], ['status' => 'queued', 'assigned_agent' => '', 'released_agent_id' => (string) $aid]);
            }
            delete_records('livechat_agents', $aid);
            echo '<div class="alert alert-info">Agent removed; their active chats returned to the queue.</div>';
        } elseif (($do === 'chat_close' || $do === 'chat_delete') && !empty($_POST['chat_id'])) {
            $cid = (int) $_POST['chat_id'];
            $chat = nexa_livechat_get_chat($cid);
            if ($chat && ($manager || (int) ($chat['assigned_agent'] ?? 0) === (int) ($agent['id'] ?? 0))) {
                if ($do === 'chat_close') {
                    nexa_livechat_update_chat($cid, ['status' => 'closed', 'closed_at' => nexa_livechat_now()]);
                    nexa_livechat_add_message($cid, 'system', '', 'System', 'The agent ended this chat.');
                    nexa_livechat_dispatch_queue();
                    echo '<div class="alert alert-success">Chat closed.</div>';
                } elseif ($manager) {
                    query("DELETE FROM livechat_messages WHERE chat_id = ?", [(string) $cid]);
                    delete_records('livechat_chats', $cid);
                    echo '<div class="alert alert-info">Chat and transcript deleted.</div>';
                }
            }
        }
    }

    $chats = get_table('livechat_chats')['data'];
    $queuedCount = count(array_filter($chats, static fn($c) => ($c['status'] ?? '') === 'queued'));
    $activeCount = count(array_filter($chats, static fn($c) => ($c['status'] ?? '') === 'active'));
    $botCount = count(array_filter($chats, static fn($c) => ($c['status'] ?? '') === 'bot'));

    echo '<h2><i class="fa fa-comments"></i> Nexa Live Chat <small>FAQ/AI first contact · human queue · per-agent capacity</small></h2>';
    echo '<div class="row">'
        . '<div class="col-sm-3"><div class="panel panel-warning"><div class="panel-body text-center"><strong style="font-size:24px;">' . $queuedCount . '</strong><br>Waiting in queue</div></div></div>'
        . '<div class="col-sm-3"><div class="panel panel-success"><div class="panel-body text-center"><strong style="font-size:24px;">' . $activeCount . '</strong><br>With an agent</div></div></div>'
        . '<div class="col-sm-3"><div class="panel panel-info"><div class="panel-body text-center"><strong style="font-size:24px;">' . $botCount . '</strong><br>With FAQ/AI bot</div></div></div>'
        . '<div class="col-sm-3"><div class="panel panel-default"><div class="panel-body text-center"><strong style="font-size:24px;">' . count(nexa_livechat_agents()) . '</strong><br>Enabled agents</div></div></div></div>';
    echo '<ul class="nav nav-tabs" style="margin-bottom:18px;">';
    foreach (['console' => 'Agent Console', 'queue' => 'Queue & Capacity', 'faqs' => 'FAQs', 'agents' => 'Agents', 'settings' => 'Settings', 'history' => 'History'] as $k => $label) {
        echo '<li' . ($tab === $k ? ' class="active"' : '') . '><a href="' . esc_attr(nexa_livechat_admin_tab_url($k)) . '">' . esc_html($label) . '</a></li>';
    }
    echo '</ul>';

    if ($tab === 'console') nexa_livechat_admin_console($agent, $manager);
    elseif ($tab === 'queue') nexa_livechat_admin_queue($manager);
    elseif ($tab === 'faqs') nexa_livechat_admin_faqs($manager);
    elseif ($tab === 'agents') nexa_livechat_admin_agents($manager);
    elseif ($tab === 'settings') nexa_livechat_admin_settings($manager);
    elseif ($tab === 'history') nexa_livechat_admin_history($manager, $agent);
}

function nexa_livechat_admin_console(?array $agent, bool $manager): void {
    echo '<style>'
        . '.nlcA-wrap{display:grid;grid-template-columns:330px 1fr;gap:16px}.nlcA-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px}.nlcA-list{max-height:520px;overflow:auto}.nlcA-chat-item{border:1px solid #e5e7eb;border-radius:8px;padding:9px;margin-bottom:8px;cursor:pointer}.nlcA-chat-item:hover,.nlcA-chat-item.sel{border-color:#6d28d9;background:#f5f3ff}.nlcA-msgs{height:430px;overflow:auto;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px}.nlcA-msg{max-width:82%;padding:8px 10px;border-radius:12px;margin-bottom:8px;white-space:pre-wrap}.nlcA-msg small{display:block;opacity:.65}.nlcA-msg.visitor{background:#ede9fe;margin-right:auto}.nlcA-msg.agent{background:#6d28d9;color:#fff;margin-left:auto}.nlcA-msg.bot{background:#fff;border:1px solid #ddd;margin-right:auto}.nlcA-msg.system{background:#e0e7ff;text-align:center;max-width:95%;margin-left:auto;margin-right:auto;border-radius:999px;font-size:12px}.nlcA-sug{border:1px solid #c4b5fd;background:#f5f3ff;color:#5b21b6;border-radius:999px;padding:5px 10px;cursor:pointer;font-size:12px}.nlcA-sug:hover{background:#ede9fe}@media(max-width:900px){.nlcA-wrap{grid-template-columns:1fr}}'
        . '</style>';
    if ($agent === null) {
        echo '<div class="alert alert-warning"><strong>You are not a live-chat agent yet.</strong> An admin can add your user account under <a href="' . esc_attr(nexa_livechat_admin_tab_url('agents')) . '">Agents</a>. Site admins receive an agent profile automatically.</div>';
        return;
    }
    $endpoint = home_url('content/plugins/nexa-live-chat/api.php?nexa_livechat=agent');
    echo '<div class="nlcA-wrap"><div>'
        . '<div class="nlcA-card"><h4 style="margin-top:0;">You: ' . esc_html($agent['display_name']) . '</h4>'
        . '<p><span class="label label-info" id="nlcA-load">…</span> <span class="text-muted">active chats / your capacity</span></p>'
        . '<div class="form-group"><label>Status</label><select id="nlcA-status" class="form-control"><option value="online">Online — accept chats</option><option value="away">Away — no new chats</option><option value="offline">Offline</option></select></div>'
        . '<div class="form-group"><label>How many inquiries can you handle at once?</label><input type="number" id="nlcA-capacity" class="form-control" min="1" max="' . esc_attr(nexa_livechat_setting('max_capacity', '10')) . '" value="' . esc_attr((string) $agent['capacity']) . '"><p class="help-block">When you reach this number, new chats stay in the queue or go to another available agent.</p></div>'
        . '<button class="btn btn-primary btn-block" id="nlcA-profile">Save my availability</button></div>'
        . '<div class="nlcA-card" style="margin-top:14px;"><h4>Waiting queue <span class="badge" id="nlcA-qcount">0</span></h4><div class="nlcA-list" id="nlcA-queue"></div></div>'
        . '<div class="nlcA-card" style="margin-top:14px;"><h4>My active chats</h4><div class="nlcA-list" id="nlcA-mine"></div></div>'
        . '<div class="nlcA-card" style="margin-top:14px;"><h4>With FAQ/AI bot <span class="badge" id="nlcA-bcount">0</span></h4><p class="help-block" style="margin-top:0;">Open one to watch it, or just reply — replying takes the chat over from the bot.</p><div class="nlcA-list" id="nlcA-bot"></div></div>'
        . ($manager ? '<div class="nlcA-card" style="margin-top:14px;"><h4>All active (manager)</h4><div class="nlcA-list" id="nlcA-all"></div></div>' : '')
        . '</div><div class="nlcA-card"><div id="nlcA-detail"><p class="text-muted">Select a queued or active chat to view the transcript and reply.</p></div>'
        . '<div class="btn-group" style="margin:10px 0;"><button class="btn btn-success" id="nlcA-claim">Claim selected</button><button class="btn btn-warning" id="nlcA-release">Release to queue</button><button class="btn btn-danger" id="nlcA-close">End chat</button></div>'
        . ($manager ? '<div class="form-inline" style="margin-bottom:10px;"><select id="nlcA-assign-agent" class="form-control"></select> <button class="btn btn-default" id="nlcA-assign">Assign selected</button></div>' : '')
        . '<div class="nlcA-msgs" id="nlcA-msgs"><p class="text-muted">No chat selected.</p></div>'
        . '<div id="nlcA-suggest-box" style="margin-top:10px;display:none;"><strong style="font-size:13px;">Suggested replies</strong> <span class="text-muted" style="font-size:12px;">— click one to insert it into your reply box, edit if you like, then Send.</span><div id="nlcA-suggest" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;"></div></div>'
        . '<form id="nlcA-reply" style="margin-top:10px;"><div class="input-group"><textarea id="nlcA-text" class="form-control" rows="2" placeholder="Reply as ' . esc_attr($agent['display_name']) . '… (Enter sends, Shift+Enter for a new line)"></textarea><span class="input-group-btn"><button type="submit" id="nlcA-send" class="btn btn-primary" style="height:54px;">Send reply</button></span></div><p id="nlcA-send-status" class="help-block" style="margin:6px 0 0;"></p></form>'
        . '<p class="help-block">The console refreshes every 4 seconds. Visitors keep the same bubble and transcript as they move around the site.</p></div></div>';

    echo '<script>(function(){'
        . 'var endpoint=' . json_encode($endpoint) . ',csrf=' . json_encode(nexa_csrf_token()) . ',selected=parseInt(sessionStorage.getItem("nlcA-selected")||"0",10)||0;'
        . 'function el(id){return document.getElementById(id)} function txt(parent,s){parent.textContent=s==null?"":String(s)}'
        . 'function post(action,data){data=data||{};data.action=action;data.nexa_csrf_token=csrf;var b=new URLSearchParams();Object.keys(data).forEach(function(k){b.append(k,data[k]==null?"":data[k])});return fetch(endpoint,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:b.toString(),credentials:"same-origin"}).then(function(r){return r.text().then(function(txt){var j;try{j=JSON.parse(txt)}catch(e){throw new Error("Live chat server returned an invalid response (HTTP "+r.status+"). Please reload the console and try again.")}if(!r.ok||!j.ok)throw new Error(j.error||"Request failed");return j})})}'
        . 'function item(c,kind){var d=document.createElement("div");d.className="nlcA-chat-item"+(c.id===selected?" sel":"");var title=document.createElement("strong");txt(title,(c.visitor_name||"Guest")+" · #"+c.id);d.appendChild(title);var meta=document.createElement("div");meta.className="text-muted";txt(meta,kind+" · "+(c.status||"")+(c.queue_position?" · queue #"+c.queue_position:"")+(c.agent_unread?" · unread":""));d.appendChild(meta);var page=document.createElement("small");txt(page,c.last_page||c.source_page||"");d.appendChild(page);d.addEventListener("click",function(){selected=c.id;sessionStorage.setItem("nlcA-selected",String(selected));load()});return d}'
        . 'function fill(id,list,kind){var box=el(id);box.innerHTML="";if(!list||!list.length){box.innerHTML="<p class=\\"text-muted\\">None.</p>";return}list.forEach(function(c){box.appendChild(item(c,kind))})}'
        . 'function render(j){el("nlcA-load").textContent=j.me.load+" / "+j.me.capacity;el("nlcA-status").value=j.me.status;if(document.activeElement!==el("nlcA-capacity"))el("nlcA-capacity").value=j.me.capacity;el("nlcA-qcount").textContent=(j.queue||[]).length;fill("nlcA-queue",j.queue,"Waiting");fill("nlcA-mine",j.mine,"Mine");fill("nlcA-bot",j.bot||[],"With bot");el("nlcA-bcount").textContent=(j.bot||[]).length;if(el("nlcA-all"))fill("nlcA-all",j.active,"Active");var sel=el("nlcA-assign-agent");if(sel){var cur=sel.value;sel.innerHTML="";(j.agents||[]).forEach(function(a){var o=document.createElement("option");o.value=a.id;o.textContent=a.name+" ("+a.load+"/"+a.capacity+", "+a.status+")";sel.appendChild(o)});if(cur)sel.value=cur}'
        . 'var box=el("nlcA-msgs"),detail=el("nlcA-detail");if(j.selected&&j.selected.chat){selected=j.selected.chat.id;var c=j.selected.chat;detail.innerHTML="";var h=document.createElement("h4");txt(h,(c.visitor_name||"Guest")+" · Chat #"+c.id+" · "+c.status);detail.appendChild(h);var p=document.createElement("p");p.className="text-muted";txt(p,(c.visitor_email||"No email")+" · Started "+(c.created_at||"")+" · Current page: "+(c.last_page||c.source_page||""));detail.appendChild(p);var near=box.scrollHeight-box.scrollTop-box.clientHeight<100;box.innerHTML="";(j.selected.messages||[]).forEach(function(m){var d=document.createElement("div");d.className="nlcA-msg "+m.type;d.textContent=m.body;var s=document.createElement("small");txt(s,(m.sender||"")+" · "+(m.at||""));d.appendChild(s);box.appendChild(d)});renderSuggest(j.selected.agent_suggestions||[]);if(near)box.scrollTop=box.scrollHeight}else{renderSuggest([]);detail.innerHTML="<p class=\\"text-muted\\">Select a queued or active chat to view the transcript and reply.</p>";box.innerHTML="<p class=\\"text-muted\\">No chat selected.</p>"}}'
        . 'function load(){post("state",{chat_id:selected,mark_read:selected?1:0}).then(render).catch(function(e){el("nlcA-detail").innerHTML="<div class=\\"alert alert-danger\\">"+e.message+"</div>"})}'
        . 'el("nlcA-profile").addEventListener("click",function(){post("profile",{status:el("nlcA-status").value,capacity:el("nlcA-capacity").value}).then(render).catch(function(e){alert(e.message)})});'
        . 'el("nlcA-claim").addEventListener("click",function(){if(!selected)return alert("Select a chat first.");post("claim",{chat_id:selected}).then(render).catch(function(e){alert(e.message)})});'
        . 'el("nlcA-release").addEventListener("click",function(){if(!selected)return;post("release",{chat_id:selected}).then(render).catch(function(e){alert(e.message)})});'
        . 'el("nlcA-close").addEventListener("click",function(){if(!selected||!confirm("End this chat?"))return;post("close",{chat_id:selected}).then(function(j){selected=0;sessionStorage.removeItem("nlcA-selected");render(j)}).catch(function(e){alert(e.message)})});'
        . 'var as=el("nlcA-assign");if(as)as.addEventListener("click",function(){if(!selected)return;post("assign",{chat_id:selected,agent_id:el("nlcA-assign-agent").value}).then(render).catch(function(e){alert(e.message)})});'
        . 'function renderSuggest(list){var box=el("nlcA-suggest-box"),wrap=el("nlcA-suggest");if(!box||!wrap)return;wrap.innerHTML="";if(!list||!list.length){box.style.display="none";return}box.style.display="block";list.forEach(function(s){var b=document.createElement("button");b.type="button";b.className="nlcA-sug";b.textContent=s.label;b.title=s.text;b.addEventListener("click",function(){var tx=el("nlcA-text");var cur=tx.value.replace(/\s+$/,"");tx.value=cur?(cur+"\n"+s.text):s.text;tx.focus();sendStatus("Suggestion inserted — edit it if you like, then Send reply.")});wrap.appendChild(b)})}'
        . 'function sendStatus(msg,ok){var s=el("nlcA-send-status");s.textContent=msg;s.style.color=ok===true?"#15803d":(ok===false?"#b91c1c":"#64748b")}'
        . 'el("nlcA-reply").addEventListener("submit",function(e){e.preventDefault();var textEl=el("nlcA-text"),msg=textEl.value.trim();if(!selected){sendStatus("Select a chat on the left first, then write your reply.",false);return}if(!msg){sendStatus("Type a reply first.",false);textEl.focus();return}var btn=el("nlcA-send");btn.disabled=true;btn.textContent="Sending…";sendStatus("Sending…");post("send",{chat_id:selected,message:msg}).then(function(j){textEl.value="";render(j);var box=el("nlcA-msgs");box.scrollTop=box.scrollHeight;sendStatus("Sent ✓ The visitor can see your reply now.",true);textEl.focus()}).catch(function(err){sendStatus(err.message,false)}).finally(function(){btn.disabled=false;btn.textContent="Send reply"})});'
        . 'el("nlcA-text").addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();el("nlcA-reply").requestSubmit()}});'
        . 'load();setInterval(load,4000);'
        . '})();</script>';
}

function nexa_livechat_admin_queue(bool $manager): void {
    nexa_livechat_dispatch_queue();
    echo '<div class="panel panel-default"><div class="panel-heading">Agent capacity right now</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>Agent</th><th>Status</th><th>Active inquiries</th><th>Can take</th><th>Availability</th></tr></thead><tbody>';
    foreach (nexa_livechat_agents() as $a) {
        echo '<tr><td><strong>' . esc_html($a['display_name']) . '</strong><br><small>' . esc_html($a['username']) . '</small></td><td>' . esc_html($a['status']) . '</td><td>' . (int) $a['load'] . '</td><td>' . (int) $a['capacity'] . '</td><td>' . (!empty($a['available']) ? '<span class="label label-success">Available</span>' : '<span class="label label-default">Full / not online</span>') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    echo '<div class="panel panel-default"><div class="panel-heading">Waiting & active inquiries</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>#</th><th>Visitor</th><th>Status</th><th>Queue #</th><th>Agent</th><th>Current page</th><th>Updated</th></tr></thead><tbody>';
    foreach (query("SELECT * FROM livechat_chats WHERE status != ? ORDER BY FIELD(status,'queued','active','bot'), updated_at DESC", ['closed']) as $c) {
        // FIELD() is SQL-only phrasing; on flat drivers the query shim may keep insertion order. The important fields still render.
        $agentName = !empty($c['assigned_agent']) ? (nexa_livechat_agent_by_id((int) $c['assigned_agent'])['display_name'] ?? '') : '';
        echo '<tr><td>' . (int) $c['id'] . '</td><td><strong>' . esc_html($c['visitor_name']) . '</strong><br><small>' . esc_html($c['visitor_email']) . '</small></td><td>' . esc_html($c['status']) . '</td><td>' . ((($c['status'] ?? '') === 'queued') ? nexa_livechat_queue_position((int) $c['id']) : '—') . '</td><td>' . esc_html($agentName) . '</td><td><small>' . esc_html($c['last_page'] ?: $c['source_page']) . '</small></td><td>' . esc_html($c['updated_at']) . '</td></tr>';
    }
    echo '</tbody></table><p class="text-muted">Capacity is enforced when an agent claims a chat and when the queue auto-dispatches. Lowering capacity never drops an existing conversation; it only stops new assignments until the agent is below the limit.</p></div></div>';
}

function nexa_livechat_admin_faqs(bool $manager): void {
    if (!$manager) { echo '<div class="alert alert-warning">Only site managers can edit FAQs.</div>'; return; }
    $edit = null;
    if (!empty($_GET['edit_faq'])) foreach (get_table('livechat_faqs')['data'] as $f) if ((int) $f['id'] === (int) $_GET['edit_faq']) $edit = $f;
    $faqCount = count(get_table('livechat_faqs')['data']);
    echo '<div class="panel panel-info"><div class="panel-heading"><strong>Import many Q&amp;A at once</strong> <span class="badge">' . $faqCount . ' currently saved</span></div><div class="panel-body">'
        . '<form method="post" enctype="multipart/form-data">' . nexa_csrf_field() . '<input type="hidden" name="do" value="faq_import">'
        . '<div class="row"><div class="col-md-4"><div class="form-group"><label>Upload a file (max 10 MB)</label><input type="file" name="faq_file" class="form-control" accept=".csv,.tsv,.txt,.json,text/csv,application/json,text/plain"><p class="help-block">Best for hundreds or thousands of Q&amp;A. CSV columns: <code>question,answer,keywords,enabled,sort</code> — or add a <code>followups</code> column (new 6-column order: question,answer,keywords,followups,enabled,sort) to control the visitor follow-up chips per answer.</p></div></div>'
        . '<div class="col-md-3"><div class="form-group"><label>Format</label><select name="faq_import_format" class="form-control"><option value="auto">Auto-detect</option><option value="csv">CSV</option><option value="tsv">TSV / spreadsheet tabs</option><option value="json">JSON</option><option value="text">Plain text Q:/A:</option></select></div>'
        . '<div class="form-group"><label>If the question already exists</label><select name="faq_duplicate_strategy" class="form-control"><option value="update">Update the existing answer</option><option value="skip">Skip duplicates</option><option value="duplicate">Import as duplicates</option></select></div>'
        . '<label class="checkbox-inline"><input type="checkbox" name="faq_import_enabled" value="1" checked> Enable imported Q&amp;A when the file has no enabled column</label></div>'
        . '<div class="col-md-5"><div class="form-group"><label>…or paste Q&amp;A here</label><textarea name="faq_import_text" class="form-control" rows="7" placeholder="Q: How long does delivery take?&#10;A: Delivery usually takes 2-4 business days.&#10;Keywords: delivery, shipping, arrive"></textarea></div></div></div>'
        . '<button class="btn btn-success"><i class="fa fa-upload"></i> Import Q&amp;A</button> <span class="text-muted">Up to 5,000 rows per import. Rows with an empty question or answer are skipped and reported.</span></form>'
        . '<details style="margin-top:12px;"><summary><strong>See the accepted formats</strong></summary><div class="row" style="margin-top:10px;"><div class="col-md-4"><strong>CSV</strong><pre>question,answer,keywords,enabled,sort&#10;"How long does delivery take?","Delivery takes 2-4 business days.","delivery,shipping",1,10</pre></div>'
        . '<div class="col-md-4"><strong>JSON</strong><pre>[{&#10;  "question": "How long does delivery take?",&#10;  "answer": "Delivery takes 2-4 business days.",&#10;  "keywords": "delivery, shipping",&#10;  "enabled": 1&#10;}]</pre></div>'
        . '<div class="col-md-4"><strong>Plain text</strong><pre>Q: How long does delivery take?&#10;A: Delivery takes 2-4 business days.&#10;Keywords: delivery, shipping&#10;&#10;Q: How do I contact you?&#10;A: Email support@example.com.</pre></div></div><p class="help-block" style="margin-bottom:0;">Tip: in Excel or Google Sheets, put Question, Answer and Keywords in the first three columns, then download/export as CSV and upload that file.</p></details>'
        . '</div></div>';
    echo '<div class="row"><div class="col-md-5"><div class="panel panel-default"><div class="panel-heading">' . ($edit ? 'Edit FAQ' : 'Add FAQ') . '</div><div class="panel-body">'
        . '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="faq_save">' . ($edit ? '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">' : '')
        . '<div class="form-group"><label>Question visitors ask</label><input name="question" class="form-control" required value="' . esc_attr($edit['question'] ?? '') . '" placeholder="How long does delivery take?"></div>'
        . '<div class="form-group"><label>Answer the bot should give</label><textarea name="answer" class="form-control" rows="5" required>' . esc_textarea($edit['answer'] ?? '') . '</textarea></div>'
        . '<div class="form-group"><label>Follow-up questions to suggest after this answer (one per line)</label><textarea name="followups" class="form-control" rows="3" placeholder="Do you take walk-ins?&#10;Are you open on holidays?">' . esc_textarea($edit['followups'] ?? '') . '</textarea><p class="help-block">Shown to visitors as tappable chips after the bot gives this answer. Leave blank and the system auto-suggests related FAQs instead. You can also add a <code>followups</code> column when importing.</p></div>'
        . '<div class="form-group"><label>Keywords / phrases (comma-separated)</label><input name="keywords" class="form-control" value="' . esc_attr($edit['keywords'] ?? '') . '" placeholder="delivery,shipping,arrive,tracking"><p class="help-block">The systematic bot scores these phrases plus the question. High-confidence matches answer instantly; low-confidence questions go to humans.</p></div>'
        . '<div class="form-inline"><label>Sort <input type="number" name="sort" class="form-control" value="' . esc_attr((string) ($edit['sort'] ?? 0)) . '" style="width:80px"></label> <label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" ' . (($edit['enabled'] ?? '1') === '1' ? 'checked' : '') . '> Enabled</label></div>'
        . '<p style="margin-top:12px;"><button class="btn btn-primary">Save FAQ</button> ' . ($edit ? '<a class="btn btn-default" href="' . esc_attr(nexa_livechat_admin_tab_url('faqs')) . '">Cancel edit</a>' : '') . '</p></form></div></div></div>';
    echo '<div class="col-md-7"><div class="panel panel-default"><div class="panel-heading">FAQ knowledge base</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>Question</th><th>Keywords</th><th>On</th><th></th></tr></thead><tbody>';
    foreach (get_table('livechat_faqs')['data'] as $f) {
        echo '<tr><td><strong>' . esc_html($f['question']) . '</strong><br><small>' . esc_html(mb_substr((string) $f['answer'], 0, 130)) . '…</small></td><td><small>' . esc_html($f['keywords']) . '</small></td><td>' . (($f['enabled'] ?? '') === '1' ? 'Yes' : 'No') . '</td>'
            . '<td class="text-right"><a class="btn btn-xs btn-primary" href="' . esc_attr(nexa_livechat_admin_tab_url('faqs') . '&edit_faq=' . (int) $f['id']) . '">Edit</a> <form method="post" style="display:inline" onsubmit="return confirm(\'Delete this FAQ?\');">' . nexa_csrf_field() . '<input type="hidden" name="do" value="faq_delete"><input type="hidden" name="id" value="' . (int) $f['id'] . '"><button class="btn btn-xs btn-danger">Delete</button></form></td></tr>';
    }
    echo '</tbody></table></div></div></div></div>';
}

function nexa_livechat_admin_agents(bool $manager): void {
    if (!$manager) { echo '<div class="alert alert-warning">Only site managers can add or edit live-chat agents.</div>'; return; }
    $users = get_table('users')['data'];
    echo '<div class="panel panel-default"><div class="panel-heading">Add an agent from an existing user</div><div class="panel-body"><form method="post" class="form-inline">' . nexa_csrf_field() . '<input type="hidden" name="do" value="agent_save">'
        . '<select name="user_id" class="form-control" required><option value="">Choose user…</option>';
    foreach ($users as $u) echo '<option value="' . (int) $u['id'] . '">' . esc_html(($u['user'] ?? '') . ' — ' . (function_exists('di_user_display_name') ? di_user_display_name($u) : '') . ' (' . ($u['role'] ?? '') . ')') . '</option>';
    echo '</select> <input name="display_name" class="form-control" placeholder="Public display name (optional)"> <label>Capacity <input type="number" name="capacity" class="form-control" min="1" max="' . esc_attr(nexa_livechat_setting('max_capacity', '10')) . '" value="' . esc_attr(nexa_livechat_setting('default_capacity', '3')) . '" style="width:80px"></label> '
        . '<select name="status" class="form-control"><option value="online">Online</option><option value="away">Away</option><option value="offline">Offline</option></select> <label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" checked> Enabled</label> <button class="btn btn-success">Add Agent</button></form>'
        . '<p class="help-block">Capacity means simultaneous active inquiries — not total chats per day. The queue automatically sends the next visitor to the least-loaded online agent who still has room.</p></div></div>';

    echo '<div class="panel panel-default"><div class="panel-heading">Agents</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>Agent</th><th>Status</th><th>Load / capacity</th><th>Enabled</th><th>Save</th><th></th></tr></thead><tbody>';
    foreach (get_table('livechat_agents')['data'] as $a) {
        $load = nexa_livechat_agent_load((int) $a['id']);
        echo '<tr><form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="agent_save"><input type="hidden" name="agent_id" value="' . (int) $a['id'] . '">'
            . '<td><input name="display_name" class="form-control" value="' . esc_attr($a['display_name']) . '"><small>' . esc_html($a['username']) . ' · user #' . esc_html($a['user_id']) . '</small></td>'
            . '<td><select name="status" class="form-control"><option value="online"' . (($a['status'] ?? '') === 'online' ? ' selected' : '') . '>Online</option><option value="away"' . (($a['status'] ?? '') === 'away' ? ' selected' : '') . '>Away</option><option value="offline"' . (($a['status'] ?? '') === 'offline' ? ' selected' : '') . '>Offline</option></select></td>'
            . '<td><span class="label label-info">' . $load . ' active</span> <input type="number" name="capacity" class="form-control" min="1" max="' . esc_attr(nexa_livechat_setting('max_capacity', '10')) . '" value="' . esc_attr((string) ($a['capacity'] ?? 3)) . '" style="width:80px;display:inline-block;"> chats at once</td>'
            . '<td><input type="checkbox" name="enabled" value="1" ' . (($a['enabled'] ?? '') === '1' ? 'checked' : '') . '></td><td><button class="btn btn-primary btn-sm">Save</button></td></form>'
            . '<td><form method="post" onsubmit="return confirm(\'Remove this agent? Active chats return to queue.\');">' . nexa_csrf_field() . '<input type="hidden" name="do" value="agent_delete"><input type="hidden" name="agent_id" value="' . (int) $a['id'] . '"><button class="btn btn-danger btn-sm">Remove</button></form></td></tr>';
    }
    echo '</tbody></table></div></div>';
}

function nexa_livechat_admin_settings(bool $manager): void {
    if (!$manager) { echo '<div class="alert alert-warning">Only site managers can change Live Chat settings.</div>'; return; }
    $mode = nexa_livechat_setting('initial_mode', 'faq');
    $aiKeySet = trim(nexa_livechat_setting('ai_key', '')) !== '';
    echo '<form method="post">' . nexa_csrf_field() . '<input type="hidden" name="do" value="settings">'
        . '<div class="panel panel-default"><div class="panel-heading">General & capacity</div><div class="panel-body"><div class="row">'
        . '<div class="col-md-6"><label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" ' . (nexa_livechat_enabled() ? 'checked' : '') . '> Enable live chat site-wide</label>'
        . '<div class="form-group"><label>Chat brand / team name</label><input name="brand_name" class="form-control" value="' . esc_attr(nexa_livechat_setting('brand_name', (string) get_setting('blogname', 'Support'))) . '"></div>'
        . '<div class="form-group"><label>Bot display name</label><input name="bot_name" class="form-control" value="' . esc_attr(nexa_livechat_setting('bot_name', 'Nexa Assistant')) . '"></div>'
        . '<div class="form-group"><label>Bubble colour</label><input type="color" name="color" class="form-control" value="' . esc_attr(nexa_livechat_setting('color', '#6d28d9')) . '" style="max-width:110px;"></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>New-agent default capacity</label><input type="number" name="default_capacity" class="form-control" min="1" max="50" value="' . esc_attr(nexa_livechat_setting('default_capacity', '3')) . '"><p class="help-block">How many simultaneous inquiries a newly added agent can handle.</p></div>'
        . '<div class="form-group"><label>Maximum capacity any agent may set</label><input type="number" name="max_capacity" class="form-control" min="1" max="100" value="' . esc_attr(nexa_livechat_setting('max_capacity', '10')) . '"></div>'
        . '<label class="checkbox-inline"><input type="checkbox" name="queue_offline" value="1" ' . (nexa_livechat_setting('queue_offline', '1') === '1' ? 'checked' : '') . '> Keep visitors in the queue when every agent is offline/full</label><br>'
        . '<label class="checkbox-inline"><input type="checkbox" name="require_email" value="1" ' . (nexa_livechat_setting('require_email', '0') === '1' ? 'checked' : '') . '> Ask for an email for follow-up (shown as optional in this version)</label><br>'
        . '<label class="checkbox-inline"><input type="checkbox" name="admin_alerts" value="1" ' . (nexa_livechat_setting('admin_alerts', '1') === '1' ? 'checked' : '') . '> Floating alert bubble on every admin page (badge, sound/desktop alert options, click to open the chat)</label><br>'
        . '<label class="checkbox-inline"><input type="checkbox" name="suggestions_visitor" value="1" ' . (nexa_livechat_setting('suggestions_visitor', '1') === '1' ? 'checked' : '') . '> Smart suggestions for visitors (starter questions + follow-ups after each answer)</label><br>'
        . '<label class="checkbox-inline"><input type="checkbox" name="suggestions_agent" value="1" ' . (nexa_livechat_setting('suggestions_agent', '1') === '1' ? 'checked' : '') . '> Suggested replies for agents (click to insert into the reply box — never auto-sent)</label><br>'
        . '<label class="checkbox-inline"><input type="checkbox" name="suggestions_ai" value="1" ' . (nexa_livechat_setting('suggestions_ai', '1') === '1' ? 'checked' : '') . '> Let the AI propose follow-ups too when AI mode answers (merged after your hand-picked ones)</label></div></div></div></div>'

        . '<div class="panel panel-primary"><div class="panel-heading">First contact: FAQ bot, AI, or hybrid</div><div class="panel-body"><div class="form-group"><label>Initial-contact engine</label><select name="initial_mode" class="form-control">'
        . '<option value="faq"' . ($mode === 'faq' ? ' selected' : '') . '>Systematic FAQ bot (no external AI; recommended default)</option>'
        . '<option value="hybrid"' . ($mode === 'hybrid' ? ' selected' : '') . '>Hybrid — FAQ/site search first, then AI, then human</option>'
        . '<option value="ai"' . ($mode === 'ai' ? ' selected' : '') . '>AI first — then FAQ fallback, then human</option></select>'
        . '<p class="help-block">In every mode, “human / agent / real person” escalates immediately, and a low-confidence answer routes to the queue instead of guessing.</p></div>'
        . '<div class="form-group"><label>FAQ confidence threshold</label><input type="number" name="faq_threshold" class="form-control" min="1" max="30" value="' . esc_attr(nexa_livechat_setting('faq_threshold', '4')) . '"><p class="help-block">Lower = bot answers more often. Higher = more questions go to humans.</p></div>'
        . '<div class="row"><div class="col-md-6"><div class="form-group"><label>AI endpoint (OpenAI-compatible)</label><input name="ai_endpoint" class="form-control" value="' . esc_attr(nexa_livechat_setting('ai_endpoint', 'https://api.openai.com/v1/chat/completions')) . '"></div></div>'
        . '<div class="col-md-6"><div class="form-group"><label>AI model</label><input name="ai_model" class="form-control" value="' . esc_attr(nexa_livechat_setting('ai_model', 'gpt-4o-mini')) . '"></div></div></div>'
        . '<div class="form-group"><label>AI API key ' . ($aiKeySet ? '<span class="label label-success">Saved</span>' : '<span class="label label-default">Not set</span>') . '</label><input type="password" name="ai_key" class="form-control" autocomplete="new-password" placeholder="' . ($aiKeySet ? 'Leave blank to keep the saved key' : 'Paste an API key if using AI mode') . '"><label class="checkbox-inline"><input type="checkbox" name="clear_ai_key" value="1"> Clear saved key</label><p class="help-block">The key is used only by the server and never printed to visitors. A local OpenAI-compatible endpoint may not need a key. If AI is unavailable, FAQ mode still works.</p></div>'
        . '<div class="form-group"><label>AI system prompt</label><textarea name="ai_system_prompt" class="form-control" rows="4">' . esc_textarea(nexa_livechat_setting('ai_system_prompt', 'You are the friendly first-contact assistant for this website. Answer briefly and helpfully. Use the FAQ context when relevant. If you are unsure, if the visitor asks for a person, or if the issue needs account-specific/private help, escalate to a human.')) . '</textarea></div></div></div>'

        . '<div class="panel panel-default"><div class="panel-heading">Messages</div><div class="panel-body">'
        . '<div class="form-group"><label>Welcome message</label><textarea name="welcome_message" class="form-control" rows="3">' . esc_textarea(nexa_livechat_setting('welcome_message', 'Hi there! I can answer common questions or connect you with a human agent. How can I help?')) . '</textarea></div>'
        . '<div class="form-group"><label>Queue message</label><textarea name="queue_message" class="form-control" rows="3">' . esc_textarea(nexa_livechat_setting('queue_message', 'I’m connecting you with a human agent. Please keep this chat open.')) . '</textarea></div>'
        . '<div class="form-group"><label>Offline / follow-up message</label><textarea name="offline_message" class="form-control" rows="3">' . esc_textarea(nexa_livechat_setting('offline_message', 'No agent is free right now. Leave your question and email address and we will follow up.')) . '</textarea></div>'
        . '<button class="btn btn-primary btn-lg">Save Live Chat Settings</button></div></div></form>';
}

function nexa_livechat_admin_history(bool $manager, ?array $agent): void {
    $viewId = (int) ($_GET['view_chat'] ?? 0);
    if ($viewId > 0) {
        $chat = nexa_livechat_get_chat($viewId);
        if ($chat && ($manager || (int) ($chat['assigned_agent'] ?? 0) === (int) ($agent['id'] ?? 0) || ($chat['status'] ?? '') === 'closed')) {
            echo '<p><a class="btn btn-default" href="' . esc_attr(nexa_livechat_admin_tab_url('history')) . '">← Back to history</a></p><div class="panel panel-default"><div class="panel-heading">Chat #' . (int) $chat['id'] . ' — ' . esc_html($chat['visitor_name']) . ' (' . esc_html($chat['status']) . ')</div><div class="panel-body">';
            foreach (nexa_livechat_messages($viewId, 200) as $m) {
                echo '<p><strong>' . esc_html($m['sender_name'] ?: $m['sender_type']) . '</strong> <small class="text-muted">' . esc_html($m['created_at']) . '</small><br>' . nl2br(esc_html($m['body'])) . '</p>';
            }
            echo '</div></div>';
            return;
        }
    }
    echo '<div class="panel panel-default"><div class="panel-heading">Chat history</div><div class="panel-body"><table class="table table-striped"><thead><tr><th>#</th><th>Visitor</th><th>Status</th><th>Agent</th><th>Started</th><th>Last update</th><th></th></tr></thead><tbody>';
    foreach (array_reverse(get_table('livechat_chats')['data']) as $c) {
        if (!$manager && (int) ($c['assigned_agent'] ?? 0) !== (int) ($agent['id'] ?? 0) && ($c['status'] ?? '') !== 'closed') continue;
        $agentName = !empty($c['assigned_agent']) ? (nexa_livechat_agent_by_id((int) $c['assigned_agent'])['display_name'] ?? '') : '';
        echo '<tr><td>' . (int) $c['id'] . '</td><td><strong>' . esc_html($c['visitor_name']) . '</strong><br><small>' . esc_html($c['visitor_email']) . '</small></td><td>' . esc_html($c['status']) . '</td><td>' . esc_html($agentName) . '</td><td>' . esc_html($c['created_at']) . '</td><td>' . esc_html($c['updated_at']) . '</td>'
            . '<td class="text-right"><a class="btn btn-xs btn-primary" href="' . esc_attr(nexa_livechat_admin_tab_url('history') . '&view_chat=' . (int) $c['id']) . '">Transcript</a> '
            . (($c['status'] ?? '') !== 'closed' ? '<form method="post" style="display:inline">' . nexa_csrf_field() . '<input type="hidden" name="do" value="chat_close"><input type="hidden" name="chat_id" value="' . (int) $c['id'] . '"><button class="btn btn-xs btn-warning">Close</button></form> ' : '')
            . ($manager ? '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this chat and transcript?\');">' . nexa_csrf_field() . '<input type="hidden" name="do" value="chat_delete"><input type="hidden" name="chat_id" value="' . (int) $c['id'] . '"><button class="btn btn-xs btn-danger">Delete</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}
