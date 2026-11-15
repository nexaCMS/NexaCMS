<?php
/** Nexa Cache — anonymous GET full-page cache (content/cache/). */
function nexa_cache_dir(): string { $d=rtrim((string)($GLOBALS['config']['abs']??dirname(__DIR__,3)),'/').'/content/cache'; if(!is_dir($d)) @mkdir($d,0755,true); return $d; }
function nexa_cache_key(): string { $site = function_exists('nexa_current_site') ? nexa_current_site() : null; $siteKey = $site ? ('site' . (int) $site['id'] . ':' . (string) ($GLOBALS['nexa_site_original_uri'] ?? '')) : 'main'; return md5($siteKey . '|' . (string)($_SERVER['REQUEST_URI']??'/').'|'.(string)($_SERVER['HTTP_HOST']??'')); }
function nexa_cache_serve(): void {
    if (!empty($GLOBALS['adminarea']) || (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') || !empty($_SESSION['user_logged_in'.($GLOBALS['config']['auth_key']??'')]) || isset($_GET['nocache'])) return;
    if ((string)get_setting('cache_enabled','1')!=='1') return;
    // Offline/maintenance mode (Nexa Error Pages) must win over the cache:
    // never serve a cached page while the site is offline for guests.
    if ((string)get_setting('errp_offline_enabled','0')==='1' && empty($_SESSION['user_id'])) return;
    $f=nexa_cache_dir().'/page_'.nexa_cache_key().'.html'; $ttl=max(10,(int)get_setting('cache_ttl','300'));
    if (is_file($f) && (time()-filemtime($f))<$ttl) { header('X-Nexa-Cache: HIT'); readfile($f); exit; }
    $GLOBALS['nexa_cache_buffer']=true; ob_start();
    // Never cache pages containing CSRF-protected forms (tokens are per-session).
    register_shutdown_function(function() use ($f){ if(empty($GLOBALS['nexa_cache_buffer'])) return; $html=ob_get_contents(); if($html!==false && strlen($html)>200 && http_response_code()===200 && !str_contains($html,'nexa_csrf_token')) @file_put_contents($f,$html); });
}
function nexa_cache_purge(): int { $n=0; foreach(glob(nexa_cache_dir().'/*.html')?:[] as $f){ if(@unlink($f)) $n++; } return $n; }
add_action('post_saved','nexa_cache_purge');
