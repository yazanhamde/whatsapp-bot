<?php
/**
 * send_followups.php
 * ──────────────────────────────────────────────────────────────────────────
 * سكريبت متابعة تلقائي (تحسين #4 من خطة المبيعات):
 *
 * 1) زباين سألوا عن صيانة ووصلوا لخطوة "هل تريد تثبيت الطلب؟" بس ما ردوا —
 *    نرسلهم تذكير لطيف بعد ساعة، وثاني تذكير بعد يوم إذا لسا ما ردوا.
 * 2) زباين خلصوا طلب صيانة من 90 يوم أو أكثر — نرسلهم تذكير صيانة دورية
 *    لطيف (إعادة تفعيل الزبون القديم).
 *
 * طريقة التشغيل (Cron يومي، أو كل ساعة أفضل للمتابعة السريعة):
 *   php /path/to/whatsapp-bot/send_followups.php
 * ──────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/WhatsApp.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $expectedToken = env('CLEANUP_SECRET_TOKEN', '');
    $givenToken    = $_GET['token'] ?? '';
    if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$wa = new WhatsApp();

$stats = ['drafts_reminded' => 0, 'revisits_reminded' => 0];

// ── 1) تذكير الطلبات الناقصة (سأل وما أكد) ─────────────────────────────
// أول تذكير: بعد ساعة من آخر رسالة، إذا ما انبعث قبل
$stmt = $db->prepare(
    "SELECT d.id AS dropoff_id, d.conversation_id, d.customer_phone, d.device_type, d.issue,
            c.state, c.last_msg_at
     FROM dropoff_log d
     JOIN conversations c ON c.id = d.conversation_id
     WHERE d.recovered = 0
       AND d.followup_sent_at IS NULL
       AND c.state = 'idle'
       AND c.status = 'bot'
       AND c.last_msg_at <= NOW() - INTERVAL 1 HOUR
     LIMIT 200"
);
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $device = trim((string)$row['device_type']) ?: 'الجهاز';
    $issue  = trim((string)$row['issue']);
    $msg = "أستاذي، لسا ناوي نصلحلك {$device}"
         . ($issue !== '' ? " (" . $issue . ")" : "")
         . "؟ 🙏\nقلي بس \"تمام\" ونثبت الطلب ونبعتلك الفني بأقرب وقت.";
    try {
        $wa->sendText($row['customer_phone'], $msg);
        $db->prepare("UPDATE dropoff_log SET followup_sent_at = NOW() WHERE id = ?")
           ->execute([$row['dropoff_id']]);
        $stats['drafts_reminded']++;
    } catch (Throwable $e) {
        error_log('[send_followups][draft] ' . $e->getMessage());
    }
}

// ── 2) تذكير صيانة دورية للزباين القدام (بعد 90 يوم من إغلاق آخر طلب) ──
$stmt = $db->prepare(
    "SELECT sr.id, sr.customer_phone, sr.product_name, dt.name_ar AS device_name
     FROM service_requests sr
     LEFT JOIN device_types dt ON dt.id = sr.device_type_id
     WHERE sr.status = 'completed'
       AND sr.revisit_reminder_sent_at IS NULL
       AND sr.completed_date IS NOT NULL
       AND sr.completed_date <= NOW() - INTERVAL 90 DAY
     LIMIT 200"
);
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $device = trim((string)($row['device_name'] ?? $row['product_name'] ?? '')) ?: 'جهازك';
    $msg = "أهلين 👋 مضى وقت منيح على آخر صيانة لـ{$device} عندك.\n"
         . "لو حابب فحص دوري أو صيانة وقائية، إحنا موجودين — بس اكتبلنا 🙌";
    try {
        $wa->sendText($row['customer_phone'], $msg);
        $db->prepare("UPDATE service_requests SET revisit_reminder_sent_at = NOW() WHERE id = ?")
           ->execute([$row['id']]);
        $stats['revisits_reminded']++;
    } catch (Throwable $e) {
        error_log('[send_followups][revisit] ' . $e->getMessage());
    }
}

echo "تم إرسال " . $stats['drafts_reminded'] . " تذكير لطلبات ناقصة و"
    . $stats['revisits_reminded'] . " تذكير صيانة دورية.\n";
