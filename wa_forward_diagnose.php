<?php
/**
 * صفحة تشخيص مؤقتة - افتحها من المتصفح:
 * https://goldensketch.com/whatsapp-bot/wa_forward_diagnose.php
 * احذفها بعد ما تخلص التشخيص.
 */
require_once __DIR__ . '/config/settings.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='direction:ltr;font-family:monospace;font-size:14px;line-height:1.8'>";

function step($label, $ok, $detail = '') {
    echo ($ok ? "✅ " : "❌ ") . $label . ($detail ? " -- $detail" : '') . "\n";
}

echo "=== 1) قراءة المتغيرات من .env ===\n";
$accPhone = getenv('ACCOUNTING_OWNER_PHONE') ?: '';
step('ACCOUNTING_OWNER_PHONE via getenv()', $accPhone !== '', $accPhone !== '' ? $accPhone : '(EMPTY / not found)');

$accPhone2 = env('ACCOUNTING_OWNER_PHONE', '');
step('ACCOUNTING_OWNER_PHONE via env() helper', $accPhone2 !== '', $accPhone2 !== '' ? $accPhone2 : '(EMPTY)');

step('OWNER_PHONE (بوت المبيعات نفسه)', defined('OWNER_PHONE'), defined('OWNER_PHONE') ? OWNER_PHONE : 'not defined');

echo "\n=== 2) اختبار الاتصال الفعلي بملف whatsapp_webhook.php تاع نظام المحاسبة ===\n";
$fakePayload = json_encode([
    'entry' => [[
        'changes' => [[
            'value' => [
                'messages' => [[
                    'id' => 'wamid.DIAGNOSTIC_TEST_' . time(),
                    'from' => $accPhone ?: '962782260535',
                    'type' => 'text',
                    'text' => ['body' => '🔧 رسالة تشخيص تحويلة تلقائية'],
                ]],
            ],
        ]],
    ]],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://goldensketch.com/accounting-system/api/whatsapp_webhook.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $fakePayload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_VERBOSE => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$errno = curl_errno($ch);
curl_close($ch);

step('Forward POST succeeded', $errno === 0, $errno !== 0 ? "curl errno=$errno / $err" : "HTTP $code / body: " . substr($resp, 0, 300));

echo "\n=== 3) تأكيد: هل هاي القيمة نفسها يلي بيقارنها webhook.php وقت وصول رسالة حقيقية؟ ===\n";
echo "لو الفحص رقم 2 نجح (HTTP 200)، لازم تكون وصلتك رسالة تجريبية جديدة عالواتساب خلال ثواني.\n";
echo "لو نجح هالفحص بس الرسايل الحقيقية من واتساب لسا ما بتوصل، يبقى المشكلة إنو دالة\n";
echo "webhook.php الأساسية (يلي ميتا بتناديها) مش نفس هالمسار، أو صار كاش قديم للملف على السيرفر.\n";

echo "</pre>";
