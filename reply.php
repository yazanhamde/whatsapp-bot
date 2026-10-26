<?php
declare(strict_types=1);
/**
 * POST /api/reply.php
 *
 * حالتان:
 * ١. رد على notification (مع AI Refinement):
 *    Body: { "notification_id": X, "owner_answer": "..." }
 *
 * ٢. رد مباشر على محادثة (بدون refinement):
 *    Body: { "conversation_id": X, "owner_answer": "..." }
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../includes/Agent.php';

requireMethod('POST');
requireAuth('reply');

$input = readJsonBody();

$notificationId = (int)($input['notification_id'] ?? 0);
$conversationId = (int)($input['conversation_id'] ?? 0);
$ownerAnswer    = trim((string)($input['owner_answer'] ?? ''));

// ── Validate ──────────────────────────────────────────────────────────────
if ($notificationId === 0 && $conversationId === 0) {
    respond(422, ['ok' => false, 'message' => 'notification_id أو conversation_id مطلوب']);
}

$len = mb_strlen($ownerAnswer);
if ($len < 2)    respond(422, ['ok' => false, 'message' => 'owner_answer قصير جداً']);
if ($len > 2000) respond(422, ['ok' => false, 'message' => 'owner_answer طويل جداً']);

// ── Business Logic ────────────────────────────────────────────────────────
try {
    $agent = new Agent();

    // ── الحالة ١: رد على notification — مع AI Refinement ─────────────────
    if ($notificationId > 0) {
        $result = $agent->sendOwnerAnswer($notificationId, $ownerAnswer);

        if (!($result['ok'] ?? false)) {
            respond(400, ['ok' => false, 'message' => $result['error'] ?? 'فشل الإرسال']);
        }

        logRequest('reply', 'info', 'Refined reply sent', [
            'notification_id' => $notificationId,
        ]);

        respond(200, [
            'ok'    => true,
            'reply' => $result['reply'], // الرد بعد تحسين الـ AI
        ]);
    }

    // ── الحالة ٢: رد مباشر على محادثة — بدون refinement ─────────────────
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT c.id, c.status, cu.phone
        FROM conversations c
        JOIN customers cu ON cu.id = c.customer_id
        WHERE c.id = ? LIMIT 1
    ");
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conv) respond(404, ['ok' => false, 'message' => 'المحادثة غير موجودة']);

    $db->beginTransaction();

    // احفظ الرسالة
    $db->prepare("
        INSERT INTO messages (conversation_id, sender, msg_type, body)
        VALUES (?, 'owner', 'text', ?)
    ")->execute([$conversationId, $ownerAnswer]);

    // تحديث وقت آخر رسالة فقط (لتبقى المحادثة في الأعلى) بدون إعادة تشغيل البوت
    $db->prepare("
        UPDATE conversations
        SET last_msg_at = NOW()
        WHERE id = ?
    ")->execute([$conversationId]);

    // أغلق أي notification pending
    $db->prepare("
        UPDATE notifications
        SET owner_answer = ?, status = 'resolved', resolved_at = NOW()
        WHERE conversation_id = ? AND status = 'pending'
    ")->execute([$ownerAnswer, $conversationId]);

    $db->commit();

    // ابعت للزبون على واتساب
    $result = $agent->sendDirectMessage($conv['phone'], $ownerAnswer);

    logRequest('reply', 'info', 'Direct reply sent', [
        'conversation_id' => $conversationId,
        'wa_ok'           => $result['ok'] ?? false,
    ]);

    respond(200, ['ok' => true, 'reply' => $ownerAnswer]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    logRequest('reply', 'error', $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'خطأ في السيرفر']);
}