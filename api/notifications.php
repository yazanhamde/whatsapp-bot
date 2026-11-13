<?php
declare(strict_types=1);
/**
 * GET /api/notifications.php
 * يُعيد قائمة الأسئلة التي تنتظر رد صاحب المتجر.
 * Headers: Authorization: Bearer <OWNER_API_KEY>
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../includes/Agent.php';

// ✅ يتولى CORS + Method + Auth بسطر واحد لكل منهم
requireMethod('GET');
requireAuth('notifications');

try {
    $agent = new Agent();
    $items = $agent->getPendingNotifications();

    logRequest('notifications', 'info', 'Fetched pending notifications', ['count' => count($items)]);

    respond(200, [
        'ok'    => true,
        'count' => count($items),
        'items' => $items,
    ]);

} catch (Throwable $e) {
    logRequest('notifications', 'error', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    respond(500, ['ok' => false, 'error' => 'Internal server error']);
}