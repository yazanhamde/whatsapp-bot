<?php
require_once __DIR__ . '/config/db.php';

// هذا الملف يستقبل طلب من الزر ويقوم بتحديث الحالة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = ($_POST['status'] === 'on') ? 'on' : 'off';
    
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'global_bot_status'");
        $stmt->execute([$newStatus]);
        
        echo json_encode(['status' => 'success', 'current' => $newStatus]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}