<?php
declare(strict_types=1);

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Dashboard-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * /api/rag.php — دماغ البوت: الذاكرة الذكية + الأسماء العامية + الأعطال
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../includes/RAG.php';

// حماية
define('RAG_DASHBOARD_KEY', 'GoldenSketch_Emergency_2026!#');
$headers     = getallheaders();
$receivedKey = $headers['X-Dashboard-Key'] ?? $headers['x-dashboard-key'] ?? '';
if ($receivedKey !== RAG_DASHBOARD_KEY) {
    requireAuth('rag');
}

$db     = getDB();
$rag    = new RAG($db);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = trim($_GET['action'] ?? '');
$body   = in_array($method, ['POST','PUT','DELETE']) ? readJsonBody() : [];

try {

    // ═══ GET ═══════════════════════════════════════════════════════════════
    if ($method === 'GET') {

        // ── إحصائيات شاملة
        if ($action === 'stats' || $action === '') {
            $base = $rag->stats();

            $aliases = (int)$db->query("SELECT COUNT(*) FROM product_aliases")->fetchColumn();
            $faults  = (int)$db->query("SELECT COUNT(*) FROM fault_knowledge")->fetchColumn();

            $topMem = $db->query(
                "SELECT question, answer, use_count, score, source, updated_at
                 FROM rag_memories ORDER BY use_count DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);

            $recentMem = $db->query(
                "SELECT question, answer, source, score, created_at
                 FROM rag_memories ORDER BY created_at DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);

            $faultByDevice = $db->query(
                "SELECT device, COUNT(*) as cnt FROM fault_knowledge GROUP BY device ORDER BY cnt DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            respond(200, ['ok' => true, 'data' => [
                'memories'        => $base,
                'aliases_count'   => $aliases,
                'faults_count'    => $faults,
                'top_memories'    => $topMem,
                'recent_memories' => $recentMem,
                'fault_by_device' => $faultByDevice,
            ]]);
        }

        // ── قائمة الذكريات
        if ($action === 'list') {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 10;
            $source  = $_GET['source'] ?? '';
            $q       = trim($_GET['q'] ?? '');

            $where  = [];
            $params = [];

            if ($source && in_array($source, ['approved','auto'])) {
                $where[]  = 'source = ?';
                $params[] = $source;
            }
            if ($q !== '') {
                $where[]  = '(question LIKE ? OR answer LIKE ? OR tags LIKE ?)';
                $params   = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
            }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $countStmt = $db->prepare("SELECT COUNT(*) FROM rag_memories $whereSQL");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $stmt   = $db->prepare(
                "SELECT id, question, answer, source, score, use_count, tags, created_at
                 FROM rag_memories $whereSQL
                 ORDER BY score DESC, use_count DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            respond(200, ['ok' => true, 'data' => [
                'items' => $items,
                'total' => $total,
                'pages' => (int)ceil($total / max(1, $perPage)),
                'page'  => $page,
            ]]);
        }

        // ── بحث تجريبي
        if ($action === 'search') {
            $q = trim($_GET['q'] ?? '');
            if ($q === '') respond(400, ['ok' => false, 'error' => 'q مطلوب']);
            $results = $rag->search($q);
            $alias   = $rag->resolveAlias($q);
            respond(200, ['ok' => true, 'results' => $results, 'resolved_alias' => $alias]);
        }

        // ── الأسماء العامية
        if ($action === 'aliases') {
            $items = $db->query(
                "SELECT id, alias, official_name, device, notes
                 FROM product_aliases ORDER BY device, alias"
            )->fetchAll(PDO::FETCH_ASSOC);
            respond(200, ['ok' => true, 'data' => $items]);
        }

        // ── الأعطال
        if ($action === 'faults') {
            $device = trim($_GET['device'] ?? '');
            if ($device !== '') {
                $stmt = $db->prepare("SELECT * FROM fault_knowledge WHERE device = ? ORDER BY priority, id");
                $stmt->execute([$device]);
            } else {
                $stmt = $db->query("SELECT * FROM fault_knowledge ORDER BY device, priority");
            }
            respond(200, ['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        respond(400, ['ok' => false, 'error' => 'action غير معروف']);
    }

    // ═══ POST ══════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $action = $body['action'] ?? $action;

        // أضف ذكرى مؤكدة
        if ($action === 'approve') {
            $q    = trim($body['question'] ?? '');
            $a    = trim($body['answer']   ?? '');
            $tags = trim($body['tags']     ?? '') ?: null;
            if ($q === '') respond(400, ['ok' => false, 'error' => 'question مطلوب']);
            if ($a === '') respond(400, ['ok' => false, 'error' => 'answer مطلوب']);
            $ok = $rag->saveApproved($q, $a, $tags);
            respond(200, ['ok' => $ok, 'message' => $ok ? 'تم الحفظ ✅' : 'فشل الحفظ']);
        }

        // أضف اسم عامي
        if ($action === 'add_alias') {
            $alias    = trim($body['alias']         ?? '');
            $official = trim($body['official_name'] ?? '');
            $device   = trim($body['device']        ?? '') ?: null;
            if ($alias === '')    respond(400, ['ok' => false, 'error' => 'alias مطلوب']);
            if ($official === '') respond(400, ['ok' => false, 'error' => 'official_name مطلوب']);
            $db->prepare(
                "INSERT INTO product_aliases (product_id, alias, official_name, device)
                 VALUES (NULL, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE official_name=?, device=?"
            )->execute([$alias, $official, $device, $official, $device]);
            respond(200, ['ok' => true, 'message' => 'تمت الإضافة ✅']);
        }

        // أضف عطل
        if ($action === 'add_fault') {
            $device  = trim($body['device']      ?? '');
            $symptom = trim($body['symptom']     ?? '');
            $cause   = trim($body['cause']       ?? '');
            $part    = trim($body['part_needed'] ?? '') ?: null;
            if ($device === '')  respond(400, ['ok' => false, 'error' => 'device مطلوب']);
            if ($symptom === '') respond(400, ['ok' => false, 'error' => 'symptom مطلوب']);
            if ($cause === '')   respond(400, ['ok' => false, 'error' => 'cause مطلوب']);
            $db->prepare(
                "INSERT INTO fault_knowledge (device, symptom, cause, part_needed, priority)
                 VALUES (?, ?, ?, ?, 1)"
            )->execute([$device, $symptom, $cause, $part]);
            respond(200, ['ok' => true, 'message' => 'تمت الإضافة ✅']);
        }

        // خفّض تقييم
        if ($action === 'penalize') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) respond(400, ['ok' => false, 'error' => 'id مطلوب']);
            $rag->penalize($id);
            respond(200, ['ok' => true, 'message' => 'تم تخفيض التقييم']);
        }

        // تنظيف
        if ($action === 'cleanup') {
            $deleted = $rag->cleanup();
            respond(200, ['ok' => true, 'deleted' => $deleted, 'message' => "تم حذف $deleted ذكرى ضعيفة"]);
        }

        respond(400, ['ok' => false, 'error' => 'action غير معروف']);
    }

    // ═══ DELETE ════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        $action = $body['action'] ?? $action;

        if ($action === 'delete_memory') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) respond(400, ['ok' => false, 'error' => 'id مطلوب']);
            $db->prepare("DELETE FROM rag_memories WHERE id = ?")->execute([$id]);
            respond(200, ['ok' => true, 'message' => 'تم الحذف']);
        }

        if ($action === 'delete_alias') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) respond(400, ['ok' => false, 'error' => 'id مطلوب']);
            $db->prepare("DELETE FROM product_aliases WHERE id = ?")->execute([$id]);
            respond(200, ['ok' => true]);
        }

        if ($action === 'delete_fault') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) respond(400, ['ok' => false, 'error' => 'id مطلوب']);
            $db->prepare("DELETE FROM fault_knowledge WHERE id = ?")->execute([$id]);
            respond(200, ['ok' => true]);
        }

        respond(400, ['ok' => false, 'error' => 'action غير معروف']);
    }

    respond(405, ['ok' => false, 'error' => 'Method Not Allowed']);

} catch (Throwable $e) {
    error_log('[RAG API] ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
