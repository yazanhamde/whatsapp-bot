<?php
declare(strict_types=1);
/**
 * GET /api/owner_alerts.php?conversation_id=ID   → كل إشعارات رمزي لهالزبون
 * GET /api/owner_alerts.php                      → ملخص: عدد الإشعارات لكل زبون
 */
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';

requireMethod('GET');
requireAuth('owner_alerts');

try {
    $db     = getDB();
    $convId = (int)($_GET['conversation_id'] ?? 0);

    if ($convId > 0) {
        // نجيب الزبون من المحادثة، ونعرض إشعاراته من كل محادثاته
        $st = $db->prepare("SELECT customer_id FROM conversations WHERE id=? LIMIT 1");
        $st->execute([$convId]);
        $custId = (int)$st->fetchColumn();

        $st = $db->prepare("
            SELECT id, kind, message, created_at, handled_at
            FROM owner_alerts
            WHERE conversation_id = ? OR (customer_id IS NOT NULL AND customer_id = ?)
            ORDER BY id DESC LIMIT 200
        ");
        $st->execute([$convId, $custId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        respond(200, ['ok' => true, 'count' => count($items), 'items' => $items]);
    }

    $rows = $db->query("
        SELECT COALESCE(customer_phone,'—') AS phone, COUNT(*) AS total, MAX(created_at) AS last_at
        FROM owner_alerts GROUP BY customer_phone ORDER BY last_at DESC LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    respond(200, ['ok' => true, 'items' => $rows]);

} catch (Throwable $e) {
    error_log('[owner_alerts] ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Internal server error']);
}
