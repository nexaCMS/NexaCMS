<?php
function nexa_snippets_ensure(): void { if(!table_exists('snippets')) create_table('snippets',['name','location','code','active','created_at']); }
nexa_snippets_ensure();
function nexa_snippets_out(string $loc): void { nexa_snippets_ensure(); foreach(query("SELECT code FROM snippets WHERE location=? AND active=? ORDER BY id ASC",[$loc,'1']) as $r) echo $r['code']."\n"; }
add_action('nexa_head', fn()=>nexa_snippets_out('head')); add_action('nexa_post', fn()=>nexa_snippets_out('footer'));
