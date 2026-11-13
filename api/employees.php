<?php
declare(strict_types=1);
/**
 * /api/employees.php
 * GET      → كل الموظفين (دفعة واحدة بدون صفحات) / أو موظف واحد بـ ID
 * POST     → إضافة موظف
 * PUT      → تعديل موظف
 * PATCH    → toggle bot (is_bot_active)
 * DELETE   → حذف موظف
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../config/auth_helpers.php';

requireAuth('employees');

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

$body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])
    ? readJsonBody()
    : [];

try {
    switch ($method) {

        // ── GET (جلب كل الموظفين دفعة واحدة أو موظف واحد) ────────────────
        case 'GET':
            $id = (int)($_GET['id'] ?? 0);

            // أ. إذا طلب موظف واحد محدد بـ ID
            if ($id > 0) {
                $stmt = $db->prepare('
                    SELECT id, name, phone, role, is_bot_active, created_at
                    FROM users WHERE id = ? LIMIT 1
                ');
                $stmt->execute([$id]);
                $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$emp) respond(404, ['ok' => false, 'message' => 'الموظف غير موجود']);
                respond(200, ['ok' => true, 'data' => $emp]);
            }

            // ب. 🔥 جلب كـل الموظفين دفعة واحدة بدون أي Pagination
            $stmt = $db->query('
                SELECT id, name, phone, role, is_bot_active, created_at
                FROM users ORDER BY id DESC
            ');
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // الرد النظيف الشامل والسهل لتاع الداش بورد
            respond(200, [
                'ok' => true,
                'data' => [
                    'items' => $items,
                    'total' => count($items)
                ]
            ]);

        // ── POST (إضافة موظف جديد) ───────────────────────────────────────
        case 'POST':
            $name  = trim($body['name']  ?? '');
            $phone = trim($body['phone'] ?? '');

            if ($name  === '') respond(400, ['ok' => false, 'message' => 'الاسم مطلوب']);
            if ($phone === '') respond(400, ['ok' => false, 'message' => 'رقم الهاتف مطلوب']);

            $db->prepare('
                INSERT INTO users (name, phone, role, is_bot_active)
                VALUES (?, ?, ?, ?)
            ')->execute([
                mb_substr($name, 0, 100),
                mb_substr($phone, 0, 25),
                in_array($body['role'] ?? '', ['admin', 'employee']) ? $body['role'] : 'employee',
                (int)(bool)($body['is_bot_active'] ?? 1),
            ]);

            $newId = (int)$db->lastInsertId();
            $stmt  = $db->prepare('
                SELECT id, name, phone, role, is_bot_active, created_at
                FROM users WHERE id = ?
            ');
            $stmt->execute([$newId]);
            respond(201, ['ok' => true, 'message' => 'تم إضافة الموظف', 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);

        // ── PUT (تعديل موظف) ──────────────────────────────────────────────
        case 'PUT':
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if ($id === 0) respond(400, ['ok' => false, 'message' => 'id مطلوب']);

            $check = $db->prepare('SELECT id FROM users WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) respond(404, ['ok' => false, 'message' => 'الموظف غير موجود']);

            $fields = [];
            $params = [];

            if (isset($body['name']))          { $fields[] = 'name = ?';          $params[] = mb_substr(trim($body['name']), 0, 100); }
            if (isset($body['phone']))         { $fields[] = 'phone = ?';         $params[] = mb_substr(trim($body['phone']), 0, 25); }
            if (isset($body['role']))          { $fields[] = 'role = ?';          $params[] = in_array($body['role'], ['admin','employee']) ? $body['role'] : 'employee'; }
            if (isset($body['is_bot_active'])) { $fields[] = 'is_bot_active = ?'; $params[] = (int)(bool)$body['is_bot_active']; }

            if (empty($fields)) respond(400, ['ok' => false, 'message' => 'ما في حقول للتعديل']);

            $params[] = $id;
            $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

            $stmt = $db->prepare('
                SELECT id, name, phone, role, is_bot_active, created_at
                FROM users WHERE id = ?
            ');
            $stmt->execute([$id]);
            respond(200, ['ok' => true, 'message' => 'تم التعديل', 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);

        // ── PATCH (تفعيل / إيقاف البوت للموظف) ───────────────────────────
        case 'PATCH':
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if ($id === 0) respond(400, ['ok' => false, 'message' => 'id مطلوب']);

            $stmt = $db->prepare('SELECT id, is_bot_active FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$emp) respond(404, ['ok' => false, 'message' => 'الموظف غير موجود']);

            $newStatus = $emp['is_bot_active'] ? 0 : 1;
            $db->prepare('UPDATE users SET is_bot_active = ? WHERE id = ?')->execute([$newStatus, $id]);

            respond(200, ['ok' => true,
                'message' => $newStatus ? 'تم تفعيل البوت' : 'تم إيقاف البوت',
                'data'    => ['id' => $id, 'is_bot_active' => $newStatus],
            ]);

        // ── DELETE (حذف موظف) ─────────────────────────────────────────────
        case 'DELETE':
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if ($id === 0) respond(400, ['ok' => false, 'message' => 'id مطلوب']);

            $targetRole = $db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $targetRole->execute([$id]);
            $target = $targetRole->fetch(PDO::FETCH_ASSOC);
            if (!$target) respond(404, ['ok' => false, 'message' => 'الموظف غير موجود']);

            if ($target['role'] === 'admin') {
                $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    respond(403, ['ok' => false, 'message' => 'لا يمكن حذف آخر admin']);
                }
            }

            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            respond(200, ['ok' => true, 'message' => 'تم حذف الموظف']);

        default:
            respond(405, ['ok' => false, 'message' => 'Method Not Allowed']);
    }

} catch (Throwable $e) {
    respond(500, [
        'ok' => false, 
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}