<?php
/**
 * GET /
 * Health check — يُستخدم من monitoring tools أو Meta للتحقق من أن الـ server شغّال.
 * لا يكشف أي معلومات داخلية.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/db.php';

// ── فحص صحة المكونات ─────────────────────────────────────────────────────

$checks = [];

// ✅ فحص قاعدة البيانات
try {
    getDB()->query('SELECT 1');
    $checks['database'] = 'ok';
} catch (Throwable) {
    $checks['database'] = 'error';
}

// ✅ فحص وجود المتغيرات الأساسية (يدعم متغيرات البيئة أو الثوابت المعرفة في settings.php)
$hasOpenAI = getenv('OPENAI_KEY') || defined('OPENAI_KEY');
$hasWA     = getenv('WA_TOKEN') || defined('WA_TOKEN') || defined('WA_VERIFY_TOKEN');
$hasOwner  = getenv('OWNER_API_KEY') || defined('OWNER_API_KEY');

$checks['config'] = ($hasOpenAI && $hasWA) ? 'ok' : 'missing';

// ── تحديد الحالة العامة ───────────────────────────────────────────────────
$allOk  = !in_array('error', $checks, true) && !in_array('missing', $checks, true);
$status = $allOk ? 'healthy' : 'degraded';

http_response_code($allOk ? 200 : 503);

// جلب اسم المتجر بأمان بدون استدعاء دالة غير معرفة
$storeName = getenv('STORE_NAME') ?: (defined('STORE_NAME') ? STORE_NAME : 'WhatsApp AI Agent');

echo json_encode([
    'ok'        => $allOk,
    'service'   => $storeName,
    'status'    => $status,
    'checks'    => $checks,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);