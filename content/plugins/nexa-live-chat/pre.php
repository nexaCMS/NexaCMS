<?php
// Nexa Live Chat — pre stage: JSON API must answer before any theme output.
add_action('pre', static function (): void {
    try {
        nexa_livechat_handle_api();
    } catch (Throwable $e) {
        error_log('Nexa Live Chat API error: ' . $e->getMessage());
        nexa_livechat_json(['ok' => false, 'error' => 'Live chat server error. Please try again in a moment.'], 500);
    }
});
