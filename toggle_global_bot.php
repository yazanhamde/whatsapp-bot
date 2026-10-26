<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// المنصات المدعومة وربطها بمفتاح الإعداد المطابق لي webhook.php / webhook_instagram.php / webhook_messenger.php
// واتساب بيستخدم المفتاح القديم 'global_bot_status' (بدون لاحقة) حفاظاً على التوافق مع الكود الحالي.
function botSettingKey(string $channel): string {
    return $channel === 'whatsapp' ? 'global_bot_status' : ('global_bot_status:' . $channel);
}

try {
    // الاتصال بقاعدة البيانات
    $db = getDB();

    $allowedChannels = ['whatsapp', 'instagram', 'messenger'];
    $channel = $_GET['channel'] ?? 'whatsapp';
    if (!in_array($channel, $allowedChannels, true)) {
        $channel = 'whatsapp';
    }
    $settingKey = botSettingKey($channel);

    // معرفة نوع الطلب (GET أو POST)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ─── ١. في حال كان الطلب GET (جلب حالة البوت) ───────────────────────────
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT setting_value 
            FROM settings 
            WHERE setting_key = ? 
            LIMIT 1
        ");
        $stmt->execute([$settingKey]);
        $currentStatus = $stmt->fetchColumn();

        // تحويل الحالة إلى Boolean (إذا كانت القيمة لا تساوي 'off' يعني البوت شغال true)
        // ملاحظة: إذا ما في صف بعد لهاي المنصة، منعتبره شغال افتراضياً (نفس سلوك الـ webhooks).
        $isActive = ($currentStatus !== 'off');

        echo json_encode([
            'channel'       => $channel,
            'bot_is_active' => $isActive
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── ٢. في حال كان الطلب POST (تحديث حالة البوت) ──────────────
    if ($method === 'POST') {
        // استقبال البيانات القادمة من الـ Dashboard
        $data = json_decode(file_get_contents('php://input'), true);

        // القناة ممكن تجي بالـ query أو بالـ body
        if (isset($data['channel']) && in_array($data['channel'], $allowedChannels, true)) {
            $channel = $data['channel'];
            $settingKey = botSettingKey($channel);
        }

        // تحديد حالة البوت بناءً على القيمة المرسلة
        $status = (
            isset($data['status']) && 
            $data['status'] === 'off'
        ) ? 'off' : 'on';

        // تحديث (أو إنشاء) حالة البوت لهاي المنصة تحديداً
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$settingKey, $status]);

        // نجاح عملية التحديث
        echo json_encode([
            'status'     => 'success',
            'channel'    => $channel,
            'bot_status' => $status,
            'message'    => 'تم تحديث مفتاح الطوارئ بنجاح'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // في حال تم استخدام Method غير مدعومة
    http_response_code(405);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Method Not Allowed'
    ]);

} catch (Throwable $e) {
    // فشل العملية
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
