<?php
if (empty($GLOBALS['adminarea'])) return;
add_menu_item('dashboard', ['name'=>'echo-echo','class'=>(($_GET['plugin_page']??'')==='echo-echo')?'active':'','icon'=>'fa fa-comment-o fa-fw','label'=>' Echo Echo','link'=>'admin.php?plugin_page=echo-echo'], 67);
add_action('nexa_admin','echo_echo_admin_render');
function echo_echo_admin_render(): void { static $d=false; if($d) return; if(($_GET['plugin_page']??'')!=='echo-echo') return; $d=true;
 echo '<h2><i class="fa fa-comment-o"></i> Echo Echo (demo)</h2><p>This classic demo plugin shows how stage files register hooks with <code>addHook()</code>. It is a teaching example - most sites can leave it disabled. See <code>PLUGIN_DEVELOPMENT.md</code> for the modern pattern.</p>'; }
