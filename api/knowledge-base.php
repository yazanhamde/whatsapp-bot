<?php
declare(strict_types=1);

// 1. هيدرز الـ CORS الكاملة عشان فرسل والـ Preflight Requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Dashboard-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * /api/knowledge-base.php
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../includes/KnowledgeBase.php';

// 2. فحص الحماية الذكي (يقبل المفتاح الثابت أو التوكن الديناميكي القديم)
define('DASHBOARD_SECRET_KEY', 'GoldenSketch_Emergency_2026!#');

$headers = getallheaders();
$receivedKey = $headers['X-Dashboard-Key'] ?? $headers['x-dashboard-key'] ?? '';

// إذا لم يتم إرسال المفتاح السري الثابت، نخليه يرجع للنظام القديم التلقائي
if ($receivedKey !== DASHBOARD_SECRET_KEY) {
    requireAuth('knowledge-base');
}

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = trim($_GET['action'] ?? '');
$body   = in_array($method, ['POST','PUT','DELETE']) ? readJsonBody() : [];

try {
    switch ($method) {

        // ── GET ───────────────────────────────────────────────────────────
        case 'GET':
            $kb   = new KnowledgeBase();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $data = $kb->getAll($page, 50);
            respond(200, ['ok' => true, 'data' => $data]);

        // ── POST ──────────────────────────────────────────────────────────
        case 'POST':
            $question = trim($body['question'] ?? '');
            $answer   = trim($body['answer']   ?? '');

            if ($question === '') respond(400, ['ok' => false, 'message' => 'السؤال مطلوب']);
            if ($answer   === '') respond(400, ['ok' => false, 'message' => 'الجواب مطلوب']);

            $kb = new KnowledgeBase();
            $id = $kb->save($question, $answer, 'manual');

            respond(201, ['ok' => true, 'message' => 'تم الإضافة', 'data' => ['id' => $id]]);

        // ── PUT ───────────────────────────────────────────────────────────
        case 'PUT':
            $id       = (int)($body['id']       ?? 0);
            $question = trim($body['question']  ?? '');
            $answer   = trim($body['answer']    ?? '');

            if ($id === 0)         respond(400, ['ok' => false, 'message' => 'id مطلوب']);
            if ($question === '') respond(400, ['ok' => false, 'message' => 'السؤال مطلوب']);
            if ($answer   === '') respond(400, ['ok' => false, 'message' => 'الجواب مطلوب']);

            $kb = new KnowledgeBase();
            $kb->update($id, $question, $answer);

            respond(200, ['ok' => true, 'message' => 'تم التعديل']);

        // ── DELETE ────────────────────────────────────────────────────────
        case 'DELETE':

            // ✅ التعديل هنا فقط (المسح الكامل صار يحذف نهائياً وحقيقياً من الـ DB)
            if ($action === 'clear') {
                $count = (int)$db->query('SELECT COUNT(*) FROM knowledge_base')->fetchColumn();
                
                // الحذف الحقيقي من الجذور
                $db->exec('DELETE FROM knowledge_base');

                logRequest('knowledge-base', 'warn', 'Knowledge base cleared permanently', ['count' => $count]);

                respond(200, ['ok' => true, 'message' => "تم مسح $count إدخال نهائياً من قاعدة المعرفة"]);
            }

            // حذف إدخال واحد (رجعناه مؤقت زي ما كان بطلبك عشان يضل مسيف)
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if ($id === 0) respond(400, ['ok' => false, 'message' => 'id مطلوب']);

            $kb = new KnowledgeBase();
            $kb->delete($id);

            respond(200, ['ok' => true, 'message' => 'تم الحذف']);

        default:
            respond(405, ['ok' => false, 'message' => 'Method Not Allowed']);
    }

} catch (Throwable $e) {
    error_log('[knowledge-base API] ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'خطأ في السيرفر']);
}