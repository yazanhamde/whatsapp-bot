<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/api_helpers.php';

// 1. التحقق من الميثود (يجب أن يكون GET)
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET') {
    respond(405, ['ok' => false, 'message' => 'Method Not Allowed - ملف me يستقبل GET فقط']);
}

// 2. 🔐 التحقق من التوكن وجلب بيانات المستخدم الحالي تلقائياً
// الدالة الجديدة بتستخرج الـ Bearer، بتشفره، وبتشيك بالـ DB، وبترجع بيانات الحساب
$currentUser = requireAuth('auth_me');

// 3. إرجاع النتيجة بشكل موحد ونظيف (نفس الحقول اللي بملفك)
respond(200, [
    'ok'   => true,
    'user' => [
        'id'    => $currentUser['id'],
        'name'  => $currentUser['name'],
        'email' => $currentUser['email'],
        'phone' => $currentUser['phone'] ?? null, // احتياط لو الحقل مش موجود
        'role'  => $currentUser['role']
    ]
]);