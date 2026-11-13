<?php
declare(strict_types=1);
/**
 * GET /api/messages.php?conversation_id=X
 * يُعيد جميع رسائل محادثة معينة (الزبون + البوت) دفعة واحدة (بدون Pagination)
 * Headers: Authorization: Bearer <OWNER_API_KEY>
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';

// ✅ Auth أولاً — قبل أي شيء
requireMethod('GET');
requireAuth('messages');

$convId  = (int)($_GET['conversation_id'] ?? 0);
$afterId = (int)($_GET['after_id']        ?? 0); // ✅ جديد: اجلب الرسائل بعد هاد الـ ID بس

if ($convId === 0) {
    respond(400, ['ok' => false, 'message' => 'conversation_id مطلوب']);
}

$db = getDB();

try {
    // تحقق إن المحادثة موجودة
    $stmt = $db->prepare('
        SELECT c.*, cu.phone
        FROM conversations c
        JOIN customers cu ON cu.id = c.customer_id
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$convId]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        respond(404, ['ok' => false, 'message' => 'المحادثة غير موجودة']);
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id = ?');
    $countStmt->execute([$convId]);
    $total = (int)$countStmt->fetchColumn();

    // ✅ لو after_id موجود: ارجع الرسائل الجديدة بس (للـ polling السريع)
    // لو مش موجود: ارجع آخر 100 رسالة (للتحميل الأولي)
    if ($afterId > 0) {
        $msgStmt = $db->prepare('
            SELECT id, sender, msg_type, body, media_path, transcription, created_at
            FROM messages
            WHERE conversation_id = ? AND id > ?
            ORDER BY id ASC
        ');
        $msgStmt->execute([$convId, $afterId]);
    } else {
        // ✅ إصلاح: جيب آخر 100 رسالة (مش أول 100) عشان _lastMsgId يكون صحيح دايماً
        $msgStmt = $db->prepare('
            SELECT * FROM (
                SELECT id, sender, msg_type, body, media_path, transcription, created_at
                FROM messages
                WHERE conversation_id = ?
                ORDER BY id DESC
                LIMIT 100
            ) sub
            ORDER BY id ASC
        ');
        $msgStmt->execute([$convId]);
    }
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ تم إزالة page, per_page, pages من الرد
    respond(200, [
        'ok'           => true,
        'conversation' => $conversation,
        'messages'     => $messages,
        'total'        => $total
    ]);

} catch (Throwable $e) {
    error_log('[messages API] ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'خطأ في السيرفر']);
}