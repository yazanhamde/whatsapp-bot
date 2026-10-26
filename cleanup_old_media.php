<?php
/**
 * cleanup_old_media.php
 * ──────────────────────────────────────────────────────────────────────────
 * صيانة دورية للبوت (لا علاقة لها بالداشبورد أو الـ API):
 * يحذف ملفات الصور والصوت الأقدم من CLEANUP_MEDIA_DAYS يوماً من السيرفر،
 * ويحذف سجل الرسالة المرتبط بها من جدول messages نهائياً (حذف متزامن
 * للملف والسجل معاً، بدون ترك أي طرف بدون الآخر).
 *
 * طريقة التشغيل (مرة واحدة يومياً عبر cron بلوحة تحكم الاستضافة):
 *   php /path/to/whatsapp-bot/cleanup_old_media.php
 *
 * أو عبر HTTP (لو الاستضافة بتدعم Cron Jobs بس بصيغة URL فقط):
 *   https://yourdomain.com/whatsapp-bot/cleanup_old_media.php?token=CLEANUP_SECRET_TOKEN
 *   (لازم تضيف CLEANUP_SECRET_TOKEN بملف .env قبل تستخدم هاد الخيار)
 * ──────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/includes/Agent.php';

$isCli = (php_sapi_name() === 'cli');

// ── حماية الوصول عبر المتصفح/HTTP — السطر التالي مطلوب فقط لو رح تشغّل
//    السكريبت بطريقة URL، أما لو هتشغّله من الـ cron بالسيرفر مباشرة (CLI)
//    فما في حاجة لأي توكن ولا حماية إضافية ────────────────────────────────
if (!$isCli) {
    $expectedToken = env('CLEANUP_SECRET_TOKEN', '');
    $givenToken    = $_GET['token'] ?? '';

    header('Content-Type: application/json; charset=utf-8');

    if ($expectedToken === '' || !hash_equals($expectedToken, (string)$givenToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── عدد أيام الاحتفاظ بالملفات قبل الحذف (افتراضياً 7 أيام) ────────────────
//    يمكن تغييره من .env بإضافة: CLEANUP_MEDIA_DAYS=7
$days = (int)env('CLEANUP_MEDIA_DAYS', 7);
if ($days < 1) $days = 7;

$agent  = new Agent();
$result = $agent->cleanupOldMedia($days);

$summary = sprintf(
    "[cleanup_old_media] %s — أقدم من %d يوم: حذف %d ملف، %d سجل غير موجود الملف أصلاً، "
    . "%d سجل محذوف من messages، %d فشل بالحذف",
    date('Y-m-d H:i:s'),
    $result['days_threshold'],
    $result['deleted_files'],
    $result['missing_files'],
    $result['deleted_rows'],
    $result['failed_deletes']
);
error_log($summary);

if ($isCli) {
    echo $summary . PHP_EOL;
} else {
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
}
