<?php
declare(strict_types=1);

/**
 * auth_helpers.php
 * مساعدات المصادقة — يُضمَّن بعد api_helpers.php
 */

// ─────────────────────────────────────────────────────────────────────────
// Token Generation
// ─────────────────────────────────────────────────────────────────────────

/**
 * ✅ يولّد token آمن ويرجع الـ raw (للمستخدم) والـ hash (للـ DB)
 *
 * الاستخدام عند Login:
 *   $t = generateToken();
 *   // أرسل $t['raw'] للمستخدم في الـ response
 *   // خزّن $t['hash'] في قاعدة البيانات
 */
function generateToken(): array {
    $raw = bin2hex(random_bytes(32)); // 64 حرف hex
    return [
        'raw'  => $raw,
        'hash' => hash('sha256', $raw), // هذا ما يُخزَّن في DB
    ];
}

/**
 * ✅ [FIX #2] hash الـ token الوارد للمقارنة مع الـ DB
 */
function hashToken(string $rawToken): string {
    return hash('sha256', $rawToken);
}

// ─────────────────────────────────────────────────────────────────────────
// Auth Guards
// ─────────────────────────────────────────────────────────────────────────

/**
 * تحقق من الـ Bearer token ورجّع بيانات المستخدم
 * ✅ [FIX #4] يضبط Content-Type قبل أي response
 * ✅ [FIX #3] يعمل trim() على الـ token
 * ✅ [FIX #2] يقارن hash الـ token مش الـ raw
 * ✅ [IMPROVEMENT] يجدد صلاحية الـ token عند كل طلب ناجح
 */
function requireUserAuth(PDO $db): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!str_starts_with($header, 'Bearer ')) {
        authFail(401, 'Unauthorized');
    }

    // ✅ [FIX #3] trim يمنع فشل الـ lookup بسبب مسافة زائدة
    $rawToken  = trim(substr($header, 7));
    // ✅ [FIX #2] قارن الـ hash في DB مش الـ raw token
    $tokenHash = hashToken($rawToken);

    $stmt = $db->prepare("
        SELECT * FROM users
        WHERE token_hash = ? AND token_expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        authFail(401, 'Token invalid or expired');
    }

    // ✅ [IMPROVEMENT] جدد الصلاحية عند كل طلب ناجح — يمنع انتهاء غير متوقع
    try {
        $db->prepare("
            UPDATE users SET token_expires_at = NOW() + INTERVAL 8 HOUR WHERE id = ?
        ")->execute([$user['id']]);
    } catch (Throwable) {
        // non-critical — لا نوقف التنفيذ
    }

    return $user;
}

function requireAdmin(array $user): void {
    if ($user['role'] !== 'admin') {
        authFail(403, 'Admin only');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Response Helpers
// ─────────────────────────────────────────────────────────────────────────

/**
 * ✅ [FIX #1] يستخدم نفس شكل respond() في api_helpers.php
 *    {'ok': bool, 'data': ..., 'message': ...}
 *    بدل {'success': bool} — توحيد الـ API response shape
 */
function jsonResponse(int $code, bool $ok, mixed $data = null, string $message = ''): never {
    http_response_code($code);
    // ✅ [FIX #4] Content-Type دائماً موجود حتى لو api_helpers.php مش محمّل
    header('Content-Type: application/json; charset=utf-8');

    $res = ['ok' => $ok];
    if ($message !== '') $res['message'] = $message;
    if ($data    !== null) $res['data']  = $data;

    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * داخلي — رد فشل Auth ويوقف التنفيذ
 * ✅ [FIX #4] Content-Type محدد قبل الـ echo
 */
function authFail(int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => false, 'message' => $message],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}