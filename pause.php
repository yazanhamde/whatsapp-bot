<?php
declare(strict_types=1);
/**
 * POST /api/pause.php
 * يوقف البوت على محادثة معينة
 * Headers: Authorization: Bearer <OWNER_API_KEY>
 * Body:    { "conversation_id": 12 }
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../includes/Agent.php';

requireMethod('POST');
requireAuth('pause');

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

    // لو البوت موقوف مسبقاً — رد فوري
    if (!empty($conv['bot_paused'])) {
        respond(200, ['ok' => true, 'message' => 'Bot was already paused']);
    }

    $ok = $agent->pauseBot($conversationId);

    logRequest('pause', 'info', 'Bot paused', [
        'conversation_id' => $conversationId,
        'ok'              => $ok,
    ]);

    respond($ok ? 200 : 500, [
        'ok'      => $ok,
        'message' => $ok ? 'Bot paused successfully' : 'Failed to pause bot',
    ]);

} catch (Throwable $e) {
    logRequest('pause', 'error', $e->getMessage(), ['conversation_id' => $conversationId]);
    respond(500, ['ok' => false, 'message' => 'Internal server error']);
}