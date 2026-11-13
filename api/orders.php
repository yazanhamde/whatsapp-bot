<?php
declare(strict_types=1);
/**
 * /api/orders.php — قسم الطلبات بالداشبورد
 * GET   → قائمة طلبات الصيانة + ملخص الحالات
 *         ?status=all|pending|in_progress|completed|cancelled  &q=بحث (اسم/رقم/رقم طلب/جهاز)  &limit=
 * PATCH → تغيير حالة طلب: { "id": 12, "status": "pending|in_progress|completed|cancelled" }
 *
 * ملاحظة: حالة "assigned" القديمة بتنعرض وبتنحسب مع "قيد التنفيذ".
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';

$user   = requireAuth('orders');
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

const ORDER_UI_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

try {
    if ($method === 'GET') {
        $status = (string)($_GET['status'] ?? 'all');
        $q      = trim((string)($_GET['q'] ?? ''));
        $limit  = min(500, max(1, (int)($_GET['limit'] ?? 300)));

        // ── الملخص (كل الطلبات بغض النظر عن الفلتر) ─────────────────────
        $sum = $db->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status='pending'),0)                        AS pending,
                    COALESCE(SUM(status IN ('assigned','in_progress')),0)    AS in_progress,
                    COALESCE(SUM(status='completed'),0)                      AS completed,
                    COALESCE(SUM(status='cancelled'),0)                      AS cancelled
             FROM service_requests"
        )->fetch(PDO::FETCH_ASSOC);

        // ── القائمة ─────────────────────────────────────────────────────
        $where = []; $params = [];
        if ($status === 'in_progress') {
            $where[] = "sr.status IN ('assigned','in_progress')";
        } elseif (in_array($status, ORDER_UI_STATUSES, true)) {
            $where[] = "sr.status = ?"; $params[] = $status;
        }
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $like   = '%' . $q . '%';
            $cond   = "(sr.order_number LIKE ? OR sr.customer_name LIKE ? OR sr.product_name LIKE ? OR sr.customer_location LIKE ?";
            $params = array_merge($params, [$like, $like, $like, $like]);
            if (strlen($digits) >= 4) {
                $cond .= " OR sr.customer_phone LIKE ?";
                $params[] = '%' . substr($digits, -9) . '%';
            }
            $where[] = $cond . ")";
        }

        $sql = "SELECT sr.id, sr.order_number, sr.conversation_id, sr.customer_name, sr.customer_phone,
                       sr.customer_location, sr.product_name, sr.issue_description, sr.status,
                       sr.technician_name, sr.notes, sr.created_at, sr.updated_at
                FROM service_requests sr"
             . ($where ? " WHERE " . implode(' AND ', $where) : '')
             . " ORDER BY sr.id DESC LIMIT {$limit}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['ui_status'] = $r['status'] === 'assigned' ? 'in_progress' : $r['status'];
        }
        unset($r);

        respond(200, ['ok' => true, 'summary' => array_map('intval', $sum), 'rows' => $rows]);
    }

    if ($method === 'PATCH' || $method === 'PUT' || $method === 'POST') {
        $body   = readJsonBody();
        $id     = (int)($body['id'] ?? 0);
        $status = (string)($body['status'] ?? '');

        if ($id <= 0 || !in_array($status, ORDER_UI_STATUSES, true)) {
            respond(422, ['ok' => false, 'error' => 'بيانات غير صحيحة (id أو status)']);
        }

        $exists = $db->prepare("SELECT id, status FROM service_requests WHERE id = ? LIMIT 1");
        $exists->execute([$id]);
        $cur = $exists->fetch(PDO::FETCH_ASSOC);
        if (!$cur) respond(404, ['ok' => false, 'error' => 'الطلب غير موجود']);

        $db->prepare(
            "UPDATE service_requests
             SET status = ?,
                 completed_date = CASE WHEN ? = 'completed' THEN COALESCE(completed_date, NOW()) ELSE completed_date END
             WHERE id = ?"
        )->execute([$status, $status, $id]);

        logRequest('orders', 'info', 'status changed', [
            'order_id' => $id, 'from' => $cur['status'], 'to' => $status, 'by' => $user['name'] ?? '',
        ]);

        respond(200, ['ok' => true, 'id' => $id, 'status' => $status]);
    }

    respond(405, ['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    error_log('[orders] ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'query_failed']);
}
