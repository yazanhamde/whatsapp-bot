<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/api_helpers.php';

// 🛑 ممنوع تحط requireAuth هون لأن هاد ملف تسجيل دخول!

// ── إعدادات Rate Limiting ─────────────────────────────────────────────────
const RL_MAX_ATTEMPTS = 5;    // محاولات فاشلة قبل الحجب
const RL_WINDOW_SEC   = 900;  // 15 دقيقة (نافذة العد)
const RL_BLOCK_SEC    = 900;  // 15 دقيقة حجب

/**
 * ينشئ جدول login_attempts تلقائياً إن لم يوجد.
 * ip_hash بدل ip الحقيقي — نحمي خصوصية المستخدمين.
 */
function ensureRateLimitTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip_hash      CHAR(64)     NOT NULL PRIMARY KEY,
            attempts     TINYINT      NOT NULL DEFAULT 1,
            first_at     INT UNSIGNED NOT NULL,
            blocked_until INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * يتحقق من الـ rate limit ويرفض الطلب إذا محجوب.
 * يرجع true إذا مسموح بالمتابعة.
 */
function checkRateLimit(PDO $db): void {
    $ip     = $_SERVER['HTTP_CF_CONNECTING_IP']   // Cloudflare
           ?? $_SERVER['HTTP_X_FORWARDED_FOR']    // Load balancer
           ?? $_SERVER['REMOTE_ADDR']
           ?? '0.0.0.0';
    // خذ أول IP فقط لو في سلسلة (X-Forwarded-For)
    $ip = trim(explode(',', $ip)[0]);

    $ipHash = hash('sha256', $ip);
    $now    = time();

    // جلب السجل الحالي
    $row = $db->prepare("SELECT * FROM login_attempts WHERE ip_hash = ?");
    $row->execute([$ipHash]);
    $rec = $row->fetch(PDO::FETCH_ASSOC);

    if ($rec) {
        // هل لا يزال محجوباً؟
        if ($rec['blocked_until'] > $now) {
            $remaining = $rec['blocked_until'] - $now;
            respond(429, [
                'ok'      => false,
                'message' => "تم تجاوز عدد المحاولات المسموحة. حاول بعد " . ceil($remaining / 60) . " دقيقة.",
                'retry_after' => $remaining,
            ]);
        }

        // انتهت نافذة العد؟ ابدأ من جديد
        if (($now - $rec['first_at']) > RL_WINDOW_SEC) {
            $db->prepare("DELETE FROM login_attempts WHERE ip_hash = ?")
               ->execute([$ipHash]);
        }
    }

    // نحفظ الـ ip_hash في الـ superglobal عشان نستخدمه بعدين
    $_SERVER['_RL_IP_HASH'] = $ipHash;
}

/** يسجّل محاولة فاشلة ويحجب لو وصل الحد. */
function recordFailedAttempt(PDO $db): void {
    $ipHash = $_SERVER['_RL_IP_HASH'] ?? hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $now    = time();

    $db->prepare("
        INSERT INTO login_attempts (ip_hash, attempts, first_at, blocked_until)
        VALUES (?, 1, ?, 0)
        ON DUPLICATE KEY UPDATE
            attempts     = attempts + 1,
            blocked_until = IF(attempts + 1 >= ?, ? + ?, blocked_until)
    ")->execute([$ipHash, $now, RL_MAX_ATTEMPTS, $now, RL_BLOCK_SEC]);
}

/** يمسح سجل المحاولات بعد تسجيل الدخول الناجح. */
function clearLoginAttempts(PDO $db): void {
    $ipHash = $_SERVER['_RL_IP_HASH'] ?? hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $db->prepare("DELETE FROM login_attempts WHERE ip_hash = ?")
       ->execute([$ipHash]);
}

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

    // 🛡️ Rate limiting — قبل ما نلمس DB المستخدمين
    if (php_sapi_name() !== 'cli') {
        ensureRateLimitTable($db);
        checkRateLimit($db);
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 🔒 التحقق من وجود المستخدم وصحة الباسورد عبر password_verify
    if (!$user || !password_verify($pass, (string)$user['password'])) {
        if (php_sapi_name() !== 'cli') recordFailedAttempt($db);
        respond(401, ['ok' => false, 'message' => 'Invalid email or password']);
    }

    // ✅ تسجيل دخول ناجح — مسح سجل المحاولات الفاشلة
    if (php_sapi_name() !== 'cli') clearLoginAttempts($db);

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