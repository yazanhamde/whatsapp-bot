<?php
/**
 * api/unresolved_images_api.php
 * الصور غير المعرّفة — GET / POST / DELETE
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../includes/ProductSearch.php';

requireAuth('unresolved_images');

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

    // ── GET: جلب قائمة الصور ──────────────────────────────────────────────
    if ($method === 'GET') {

        $status   = $_GET['status']   ?? 'all';
        $category = $_GET['category'] ?? 'part'; // part | device | ads | bank | all
        $page     = max(1, (int)($_GET['page']     ?? 1));
        $perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $offset   = ($page - 1) * $perPage;

        $allowed = ['all', 'pending', 'learned', 'ignored'];
        if (!in_array($status, $allowed, true)) {
            respond(400, ['ok' => false, 'message' => 'status غير صالح']);
        }
        $allowedCategories = ['all', 'part', 'device', 'ads', 'bank'];
        if (!in_array($category, $allowedCategories, true)) {
            respond(400, ['ok' => false, 'message' => 'category غير صالح']);
        }

        $conditions = [];
        $params     = [];
        if ($status !== 'all') {
            $conditions[] = 'ui.status = ?';
            $params[]     = $status;
        }
        if ($category !== 'all') {
            $conditions[] = 'ui.category = ?';
            $params[]     = $category;
        }
        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $stmt = $db->prepare("
            SELECT
                ui.id,
                ui.image_path,
                ui.image_url,
                ui.part_name,
                ui.device_type,
                ui.brand,
                ui.code,
                ui.visual_desc,
                ui.status,
                ui.category,
                ui.created_at,
                ui.resolved_at,
                p.id        AS matched_product_id,
                p.name      AS matched_product_name,
                p.code      AS matched_product_code,
                p.image_url AS matched_product_image,
                cu.phone    AS customer_phone
            FROM unresolved_images ui
            LEFT JOIN products      p  ON p.id  = ui.matched_product_id
            LEFT JOIN conversations c  ON c.id  = ui.conversation_id
            LEFT JOIN customers     cu ON cu.id = c.customer_id
            {$where}
            ORDER BY ui.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([...$params, $perPage, $offset]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ image_url محفوظ مباشرة بالـ DB — ما نحتاج تحويل مسار
        // (الصور تنسخ لمجلد ثابت عند التسجيل عبر logUnresolvedImage)

        // إحصائيات الحالات (ضمن نفس الفئة المختارة)
        if ($category === 'all') {
            $counts = $db->query("SELECT status, COUNT(*) AS cnt FROM unresolved_images GROUP BY status")
                          ->fetchAll(PDO::FETCH_KEY_PAIR);
        } else {
            $countStmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM unresolved_images WHERE category = ? GROUP BY status");
            $countStmt->execute([$category]);
            $counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        $total = $status === 'all'
            ? array_sum($counts)
            : (int)($counts[$status] ?? 0);

        respond(200, ['ok' => true, 'data' => [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil(max(1, $total) / $perPage),
            'counts'   => [
                'pending' => (int)($counts['pending'] ?? 0),
                'learned' => (int)($counts['learned'] ?? 0),
                'ignored' => (int)($counts['ignored'] ?? 0),
            ],
        ]]);
    }

    // ── POST: ربط صورة بمنتج (تدريب يدوي) ───────────────────────────────
    if ($method === 'POST') {

        $body      = readJsonBody();
        $imageId   = (int)($body['image_id']   ?? 0);
        $productId = (int)($body['product_id'] ?? 0);

        if (!$imageId || !$productId) {
            respond(400, ['ok' => false, 'message' => 'image_id و product_id مطلوبان']);
        }

        $ps = new ProductSearch();
        $ok = $ps->learnFromCorrection($imageId, $productId);

        if (!$ok) {
            respond(404, ['ok' => false, 'message' => 'الصورة أو المنتج غير موجود']);
        }

        $stmt = $db->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $productName = $stmt->fetchColumn() ?: '';

        respond(200, ['ok' => true, 'message' => "✅ تم التدريب — البوت تعلّم ربط الصورة بـ \"{$productName}\""]);
    }

    // ── DELETE: تجاهل صورة ───────────────────────────────────────────────
    if ($method === 'DELETE') {

        $imageId = (int)($_GET['id'] ?? 0);
        if (!$imageId) {
            respond(400, ['ok' => false, 'message' => 'id مطلوب']);
        }

        $stmt = $db->prepare("UPDATE unresolved_images SET status = 'ignored' WHERE id = ?");
        $stmt->execute([$imageId]);

        respond(200, ['ok' => true, 'message' => 'تم تجاهل الصورة']);
    }

    respond(405, ['ok' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    error_log('[unresolved_images_api] ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'خطأ في السيرفر: ' . $e->getMessage()]);
}
