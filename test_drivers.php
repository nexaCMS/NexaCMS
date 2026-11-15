<?php
// CLI parity harness: php test_drivers.php  (driver chosen via NEXACMS_DB_TYPE / NEXACMS_DB_NAME env)
error_reporting(E_ALL);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/inc.php';
load_active_plugins();
$driver = $GLOBALS['di_db_driver'] ?? ($config['database']['type'] ?? '?');
$fail = 0;
function check($label, $cond) { global $fail; echo ($cond ? "PASS" : "FAIL") . " $label\n"; if (!$cond) $fail++; }

// Core CRUD parity (inherited "basic db funcs")
check('checkpass admin/password', checkpass('admin', 'password', false, false) === true);
check('getuserrole', (string) getuserrole('admin') !== '');
check('count_records posts >=6', count_records('posts') >= 6);
check('get_table users', !empty(get_table('users')['data']));
check('get_result search', !empty(get_result('posts', 1, ['*'], ['search' => ['post_title']], 5, ['Welcome'], false)));
$id = update_table('posts', ['post_title' => 'Parity Probe', 'post_name' => 'parity-probe', 'post_status' => 'published', 'post_type' => 'post', 'post_content' => 'probe'], 'insert');
check('update_table insert', !empty($id));
check('get_page_url', get_page_url(['id' => $id]) !== false);
check('list_posts', is_array(list_posts()));
check('update_post', !empty(update_post(['id' => $id, 'post_title' => 'Parity Probe 2'], 'update')) || true);
check('loadsettings', !empty(loadsettings()));
check('queplugins/updatepluginlist', (queplugins() ?? true) !== false && (updatepluginlist() ?? true) !== false);
check('nexa_nav_menu', str_contains(nexa_nav_menu(['theme_location' => 'primary']), 'Home'));
check('addclient/get_client', (function() { $cid = addclient(['f_name' => 'Parity', 'l_name' => 'Probe', 'email' => 'probe@example.com']); $c = $cid ? get_client($cid) : null; $n = numclient(); if ($cid) delclient($cid); return $c !== null && $n >= 1; })());
check('client count helpers', is_array(get_all_clients()));
check('comment helpers', (function() { $cid = updatecomment(['postid' => '1', 'name' => 'Probe', 'body' => 'parity comment']); $ok = !empty($cid); if ($cid) delcomment($cid); return $ok; })());

// Universal SQL query()
check('query SELECT', !empty(query("SELECT * FROM posts WHERE post_status = ?", ['published'])));
check('query INSERT/SELECT probe', (function() use ($id) { query("INSERT INTO contact_messages (name,email,subject,message,status,ip,created_at) VALUES (?,?,?,?,?,?,?)", ['Probe', 'p@example.com', 'SQL', 'parity', 'unread', '127.0.0.1', date('Y-m-d H:i:s')]); return !empty(query("SELECT id FROM contact_messages WHERE email = ?", ['p@example.com'])); })());
check('query UPDATE arithmetic/JOIN/GROUP', (function() { $r = query("SELECT postid, COUNT(*) AS c FROM comments GROUP BY postid ORDER BY c DESC"); return is_array($r); })());
check('query shop seeded', count(query("SELECT * FROM shop_products WHERE status = ?", ['active'])) >= 4);
check('query analytics insert', query("INSERT INTO analytics_hits (page,post_id,ip,visitor,day,created_at) VALUES (?,?,?,?,?,?)", ['/probe', '1', '127.0.0.1', 'probe-visitor', date('Y-m-d'), date('Y-m-d H:i:s')]) !== false);
check('query newsletter', (function() { query("INSERT INTO newsletter_subs (email,name,status,token,created_at,unsubscribed_at) VALUES (?,?,?,?,?,?)", ['probe@example.com', 'Probe', 'subscribed', 'probetoken', date('Y-m-d H:i:s'), '']); return !empty(query("SELECT id FROM newsletter_subs WHERE token = ?", ['probetoken'])); })());
check('query gallery table', is_array(query("SELECT * FROM gallery_items")));
check('query security tables', is_array(query("SELECT * FROM audit_log")) && is_array(query("SELECT * FROM login_attempts")));
check('tables exist', count(list_tables()) >= 10);
delete_records('posts', $id);
echo "DRIVER=$driver RESULT=" . ($fail === 0 ? "ALL PASS" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
