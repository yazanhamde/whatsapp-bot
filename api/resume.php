<?php
declare(strict_types=1);
/**
 * POST /api/resume.php
 * يعيد تشغيل البوت على محادثة موقوفة
 * Body: { "conversation_id": X }
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../includes/Agent.php';

requireMethod('POST');
requireAuth('resume');

$input          = readJsonBody();
$conversationId = (int)($input['conversation_id'] ?? 0);

if ($conversationId <= 0) {
    respond(422, ['ok' => false, 'message' => 'conversation_id must be a positive integer']);
}

try {
    $agent = new Agent();
    $conv  = $agent->getConversation($conversationId);

    if (!$conv) {
        respond(404, ['ok' => false, 'message' => 'Conversation not found']);
    }

    // لو البوت شغّال مسبقاً — رد فوري
    if (($conv['status'] ?? '') === 'bot' && empty($conv['bot_paused'])) {
        respond(200, ['ok' => true, 'message' => 'Conversation was already active']);
    }

    $ok = $agent->resumeConversation($conversationId);

    logRequest('resume', 'info', 'Conversation resumed', [
        'conversation_id' => $conversationId,
        'ok'              => $ok,
    ]);

    respond($ok ? 200 : 500, [
        'ok'      => $ok,
        'message' => $ok ? 'Conversation resumed successfully' : 'Failed to resume conversation',
    ]);

} catch (Throwable $e) {
    logRequest('resume', 'error', $e->getMessage(), ['conversation_id' => $conversationId]);
    respond(500, ['ok' => false, 'message' => 'Internal server error']);
}