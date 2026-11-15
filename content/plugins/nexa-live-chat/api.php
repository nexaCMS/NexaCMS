<?php
/**
 * Nexa Live Chat — direct JSON API endpoint.
 *
 * This endpoint intentionally bypasses theme/permalink rendering. Some hosts
 * rewrite or cache the site root in ways that can turn /?nexa_livechat=...
 * into an HTML page; calling this PHP file directly keeps chat responses JSON.
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$nexaLiveChatRoot = dirname(__DIR__, 3);
require_once $nexaLiveChatRoot . '/inc.php';
require_once __DIR__ . '/functions.php';

try {
    nexa_livechat_handle_api();
    nexa_livechat_json(['ok' => false, 'error' => 'Unknown live-chat endpoint.'], 404);
} catch (Throwable $e) {
    error_log('Nexa Live Chat API error: ' . $e->getMessage());
    nexa_livechat_json(['ok' => false, 'error' => 'Live chat server error. Please try again in a moment.'], 500);
}
