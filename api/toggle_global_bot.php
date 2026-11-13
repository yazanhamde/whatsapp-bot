<?php

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/db.php';

try {
    $db  = getDB();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ─── ١. GET — جلب الحالة الحقيقية من DB ──────────────────────────────────
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT setting_value
            FROM settings
            WHERE setting_key = 'global_bot_status'
            LIMIT 1
        ");
        $stmt->execute();
        $currentStatus = $stmt->fetchColumn();

        // ✅ إذا السطر غير موجود أصلاً → اعتبر البوت شغال وأنشئه
        if ($currentStatus === false) {
            $db->exec("
                INSERT IGNORE INTO settings (setting_key, setting_value)
                VALUES ('global_bot_status', 'on')
            ");
            $currentStatus = 'on';
        }

        echo json_encode([
            'bot_is_active' => ($currentStatus !== 'off')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── ٢. POST — تحديث الحالة ───────────────────────────────────────────────
    if ($method === 'POST') {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        // ✅ تحقق صريح: لازم تكون القيمة 'on' أو 'off' بالضبط
        $requested = $data['status'] ?? '';
        if ($requested !== 'on' && $requested !== 'off') {
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'message' => 'قيمة status يجب أن تكون on أو off'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ✅ INSERT ... ON DUPLICATE KEY UPDATE — يضمن وجود السطر دايماً
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES ('global_bot_status', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$requested]);

        // ✅ تحقق فعلي إن الـ DB فيها الحالة الصحيحة الآن
        $verify = $db->query("
            SELECT setting_value FROM settings
            WHERE setting_key = 'global_bot_status' LIMIT 1
        ")->fetchColumn();

        if ($verify !== $requested) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => 'فشل التحديث — القيمة لم تتغير في قاعدة البيانات'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'status'       => 'success',
            'bot_status'   => $requested,
            'bot_is_active'=> ($requested === 'on'),
            'message'      => $requested === 'on' ? 'تم تشغيل البوت' : 'تم إيقاف البوت'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Method غير مدعومة
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
