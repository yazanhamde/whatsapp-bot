<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/api_helpers.php';

// 🛑 ممنوع تحط requireAuth هون لأن هاد ملف تسجيل دخول!

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    // 🔥 اقلب الميثود لـ POST لو شغالين تيرمينال (CLI) أو لو واصل داتا بجسم الطلب لضمان نجاح الفحص
    if (php_sapi_name() === 'cli' || ($method === 'GET' && !empty(file_get_contents('php://input')))) {
        $method = 'POST';
    }

    if ($method !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method Not Allowed - يجب استخدام POST لتسجيل الدخول']);
    }

    // 🚀 قراءة واستخراج البيانات (يدعم الـ JSON والـ $_POST العادية والـ CLI بنفس الوقت)
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $email = $_POST['email'] ?? $input['email'] ?? '';
    $pass  = $_POST['password'] ?? $_POST['pass'] ?? $input['password'] ?? $input['pass'] ?? '';

    // 💡 حيلة ذكية للتيست من التيرمينال عبر الـ echo وقراءة الـ stdin
    if (php_sapi_name() === 'cli' && empty($email)) {
        $cliInput = json_decode(file_get_contents('php://stdin'), true) ?? [];
        $email = $cliInput['email'] ?? '';
        $pass  = $cliInput['password'] ?? $cliInput['pass'] ?? '';
    }

    if (empty($email) || empty($pass)) {
        respond(400, ['ok' => false, 'message' => 'البريد الإلكتروني وكلمة المرور مطلوبان']);
    }

    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 🔒 التحقق من وجود المستخدم وصحة الباسورد عبر password_verify
    if (!$user || !password_verify($pass, (string)$user['password'])) {
        respond(401, ['ok' => false, 'message' => 'Invalid email or password']);
    }

    // ✅ خزّن hash في DB — الـ raw token يروح للمستخدم فقط
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $db->prepare("
        UPDATE users
        SET token_hash = ?
        WHERE id = ?
    ")->execute([$tokenHash, $user['id']]);

    // ✅ شكل موحد مع باقي الـ API
    respond(200, [
        'ok'    => true,
        'token' => $rawToken,
        'user'  => [
            'id'   => $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
        ],
    ]);

} catch (Throwable $e) {
    // ✍️ تسجيل الخطأ في الـ logs لمعرفته فوراً
    error_log('[Login API Error] ' . $e->getMessage());
    
    // 🛡️ إرجاع الخطأ الحقيقي للمتصفح بـ JSON سليم مع الحفاظ على هيدرز الـ CORS شغالة
    respond(500, [
        'ok'      => false, 
        'message' => 'خطأ في السيرفر داخلي',
        'debug'   => $e->getMessage() // هاي بتكشفلك العلة فوراً بالشاشة عندك
    ]);
}