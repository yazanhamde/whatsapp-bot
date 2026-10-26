<?php
declare(strict_types=1);
/**
 * GET /api/conversations.php
 * يُعيد قائمة المحادثات مع آخر رسالة لكل محادثة (بدون Pagination)
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';

requireMethod('GET');
requireAuth('conversations');

$db = getDB();

try {
    // ✅ 1. تم إزالة متغيرات الـ Pagination ($page, $perPage, $offset)

    // فلترة اختيارية حسب المنصة: whatsapp | instagram | messenger
    $allowedChannels = ['whatsapp', 'instagram', 'messenger'];
    $channel = $_GET['channel'] ?? '';
    $channelWhere = '';
    $params = [];
    if (in_array($channel, $allowedChannels, true)) {
        $channelWhere = ' WHERE c.channel = ?';
        $params[] = $channel;
    }

    // ✅ 2. تم إزالة LIMIT ? OFFSET ? من الاستعلام
    $stmt = $db->prepare("
        SELECT
            c.id,
            c.channel,
            c.status,
            c.bot_paused,
            c.last_msg_at,
            c.created_at,
            cu.phone,
            cu.name AS customer_name,
            (
                SELECT m.body
                FROM messages m
                WHERE m.conversation_id = c.id
                ORDER BY m.id DESC LIMIT 1
            ) AS last_message,
            (
                SELECT m.sender
                FROM messages m
                WHERE m.conversation_id = c.id
                ORDER BY m.id DESC LIMIT 1
            ) AS last_sender
        FROM conversations c
        JOIN customers cu ON cu.id = c.customer_id
        $channelWhere
        ORDER BY c.last_msg_at DESC
    ");

    // ✅ 3. تنفيذ الاستعلام
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($channelWhere) {
        $countStmt = $db->prepare('SELECT COUNT(*) FROM conversations c WHERE c.channel = ?');
        $countStmt->execute([$channel]);
    } else {
        $countStmt = $db->query('SELECT COUNT(*) FROM conversations');
    }
    $total = (int)$countStmt->fetchColumn();

    // ✅ 4. تنظيف الرد (Response) من بيانات الصفحات (page, per_page, pages)
    respond(200, [
        'ok'   => true,
        'data' => [
            'items' => $items,
            'total' => $total,
        ],
    ]);

} catch (Throwable $e) {
    error_log('[conversations] ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'خطأ في السيرفر']);
}