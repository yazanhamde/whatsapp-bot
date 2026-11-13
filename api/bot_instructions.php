<?php
declare(strict_types=1);
/**
 * /api/bot_instructions.php
 * GET  → يرجع قواعد وشخصية محمد الكاملة (المصدر الفعلي لبرومبته — راجع
 *         MohammadBotTrait::handleMohammad، اللي بيقرا هاد الحقل حرفياً كقواعد
 *         التشغيل الأساسية، مش مجرد إضافات فوق برومبت مثبّت بالكود).
 * POST → يحدّث القواعد (أدمن فقط)
 *
 * القيمة متخزنة بجدول settings تحت المفتاح custom_bot_instructions.
 * إذا كانت فاضية (أول استخدام)، منرجع القواعد الافتراضية الأصلية
 * (MohammadBotTrait::defaultMohammadRules) عشان الأدمن يشوف ويعدّل على
 * نفس النص اللي البوت فعلياً شغال فيه، مش صفحة فاضية.
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';
require_once __DIR__ . '/../config/auth_helpers.php';
require_once __DIR__ . '/../includes/Agent.php'; // بس عشان Agent::defaultMohammadRules()

$user = requireAuth('bot_instructions');

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

const INSTRUCTIONS_KEY = 'custom_bot_instructions';
// ✅ رفعنا الحد لأن هاد الحقل هلق فيه قواعد محمد الكاملة (شخصية + 16 قاعدة
// + Knowledge Base) مش بس ملاحظات إضافية قصيرة — النص الافتراضي وحده ~10,000
// حرف، فلازم في هامش كافي للأدمن يضيف/يعدّل فوقه.
const MAX_LEN = 100000;

try {
    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([INSTRUCTIONS_KEY]);
        $value = $stmt->fetchColumn();

        $priceText = Agent::VISIT_PRICE_MIN . ' إلى ' . Agent::VISIT_PRICE_MAX . ' دينار';
        $isDefault = ($value === false || trim((string)$value) === '');
        $instructions = $isDefault
            ? Agent::defaultMohammadRules($priceText)
            : (string)$value;

        respond(200, [
            'ok'   => true,
            'data' => [
                'instructions' => $instructions,
                'is_default'   => $isDefault, // بتفيد الواجهة تعرض تنبيه "هاي القيمة الافتراضية، لسا ما حفظتها"
            ],
        ]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        requireAdmin($user); // بس الأدمن يعدل على برومبت البوت

        $body = readJsonBody();
        $text = trim((string)($body['instructions'] ?? ''));

        if (mb_strlen($text) > MAX_LEN) {
            respond(400, ['ok' => false, 'error' => "التعليمات طويلة كتير (الحد الأقصى $MAX_LEN حرف)"]);
        }

        // upsert
        $exists = $db->prepare("SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1");
        $exists->execute([INSTRUCTIONS_KEY]);

        if ($exists->fetchColumn()) {
            $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")
               ->execute([$text, INSTRUCTIONS_KEY]);
        } else {
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")
               ->execute([INSTRUCTIONS_KEY, $text]);
        }

        respond(200, [
            'ok'      => true,
            'data'    => ['instructions' => $text],
            'message' => 'تم حفظ التعليمات، البوت رح يستخدمها من هلق',
        ]);
    }

    respond(405, ['ok' => false, 'error' => 'Method Not Allowed']);

} catch (Throwable $e) {
    error_log('[bot_instructions] ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'صار خطأ بالسيرفر']);
}
