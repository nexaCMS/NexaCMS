<?php
/**
 * NexaCMS Admin Dashboard (admin/index.php)
 */
require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/includes/auth_validate.php';

$allPosts       = get_table('posts')['data'];
$numPosts       = count(array_filter($allPosts, static fn($p) => ($p['post_type'] ?? 'post') === 'post' && ($p['post_status'] ?? '') !== 'trash'));
$numPages       = count(array_filter($allPosts, static fn($p) => ($p['post_type'] ?? '') === 'page' && ($p['post_status'] ?? '') !== 'trash'));
$numUsers       = count_records('users');
$numCustomers   = table_exists('customers') ? count_records('customers') : 0;
$allPluginRows  = get_table('plugins')['data'];
$numActivePlugs = count(array_filter($allPluginRows, static fn($p) => ($p['pre'] ?? '0') == '1' || ($p['head'] ?? '0') == '1' || ($p['body'] ?? '0') == '1' || ($p['post'] ?? '0') == '1'));
$recentItems    = array_slice(array_reverse($allPosts), 0, 6);

include_once __DIR__ . '/includes/header.php';
include __DIR__ . '/forms/dashboard.php';
include_once __DIR__ . '/includes/footer.php';
