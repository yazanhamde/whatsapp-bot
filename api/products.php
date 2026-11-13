<?php
declare(strict_types=1);
/**
 * /api/products.php
 * GET    → عرض المنتجات / منتج واحد (للوحة التحكم)
 * POST, PUT, DELETE → معطلة (لأن المنتجات تأتي من المزامنة مع الـ POS)
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';

requireAuth('products');

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    if ($method === 'GET') {
        // ── GET: قراءة وبحث المنتجات ──────────────────────────────────────
        $id     = (int)($_GET['id']     ?? 0);
        $search = trim($_GET['search']  ?? '');

        // جلب منتج واحد
        if ($id > 0) {
            $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) respond(404, ['ok' => false, 'message' => 'المنتج غير موجود']);
            respond(200, ['ok' => true, 'data' => $product]);
        }

        // جلب قائمة المنتجات مع الترقيم (Pagination)
        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        if ($search !== '') {
            $like  = '%' . $search . '%';
            // البحث عن طريق الاسم أو كود القطعة فقط
            $stmt  = $db->prepare('SELECT * FROM products WHERE name LIKE ? OR code LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?');
            $stmt->execute([$like, $like, $perPage, $offset]);
            
            $stmtC = $db->prepare('SELECT COUNT(*) FROM products WHERE name LIKE ? OR code LIKE ?');
            $stmtC->execute([$like, $like]);
            $total = (int)$stmtC->fetchColumn();
        } else {
            $stmt  = $db->prepare('SELECT * FROM products ORDER BY id DESC LIMIT ? OFFSET ?');
            $stmt->execute([$perPage, $offset]);
            $total = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
        }

        // ── عدد المنتجات الجديد/المستعمل على مستوى كل المتجر (مش بس الصفحة الحالية) ──
        // condition_status بيتخزن كـ "جديد ✨" أو "مستعمل ♻️"، فبنستخدم LIKE على الكلمة العربية فقط
        $newCount  = (int)$db->query("SELECT COUNT(*) FROM products WHERE condition_status LIKE '%جديد%'")->fetchColumn();
        $usedCount = (int)$db->query("SELECT COUNT(*) FROM products WHERE condition_status LIKE '%مستعمل%'")->fetchColumn();

        respond(200, ['ok' => true, 'data' => [
            'items'       => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'pages'       => (int)ceil($total / $perPage),
            'new_count'   => $newCount,
            'used_count'  => $usedCount,
        ]]);

    } else {
        // ── إيقاف عمليات التعديل اليدوية ──────────────────────────────────
        // POST, PUT, DELETE
        respond(403, [
            'ok' => false, 
            'message' => 'عذراً، لا يمكن إضافة أو تعديل المنتجات يدوياً من هنا. يتم تحديث المنتجات تلقائياً عبر المزامنة مع نظام الـ POS.'
        ]);
    }

} catch (Throwable $e) {
    error_log('[products API] ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'خطأ في السيرفر']);
}