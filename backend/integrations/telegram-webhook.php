<?php
/**
 * Deprecated duplicate integration endpoint.
 *
 * The only supported Telegram endpoint is /api/telegram-webhook.php.
 * This file intentionally contains no provider credentials or runtime logic.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => 'Deprecated integration endpoint. Use /api/telegram-webhook.php.',
]);