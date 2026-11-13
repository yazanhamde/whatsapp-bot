<?php
declare(strict_types=1);

if (defined('_API_HELPERS_LOADED')) return;
define('_API_HELPERS_LOADED', true);

// ── CORS CONFIGURATION ──────────────────────────────────────────────────

// 1. قراءة الـ Origins المسموحة من الـ .env أو الثوابت
$configuredOrigins = getenv('DASHBOARD_ORIGIN') ?: (defined('DASHBOARD_ORIGIN') ? DASHBOARD_ORIGIN : '');
$_allowedOrigins = array_map(
    fn($o) => rtrim(trim($o), '/'),
    explode(',', $configuredOrigins)
);
$_allowedOrigins = array_filter($_allowedOrigins);

// 💡 [تعديل جوهري للـ Localhost] إذا كانت القائمة فارغة (الـ .env مش مقروء)، ضيف لوكال هوست تلقائياً لتسهيل التطوير
if (empty($_allowedOrigins)) {
    $_allowedOrigins[] = 'http://localhost:5173';
    $_allowedOrigins[] = 'http://localhost:3000';
}

$_requestOrigin = rtrim(trim($_SERVER['HTTP_ORIGIN'] ?? ''), '/');

// 2. التحقق إذا كان الـ Origin الحالي مسموح به
$isAllowed = false;
if ($_requestOrigin !== '' && in_array($_requestOrigin, $_allowedOrigins, true)) {
    $isAllowed = true;
}

// 3. طباعة الهيدرز بناءً على النتيجة لضمان شمولية الـ OPTIONS والطلبات العادية
if ($isAllowed) {
    header('Access-Control-Allow-Origin: ' . $_requestOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
} elseif (empty($configuredOrigins)) {
    // Fallback أخير لو السيرفر مش مبرمج بالكامل
    header('Access-Control-Allow-Origin: *');
}

// ✅ [FIX] معالجة طلب الـ OPTIONS (Preflight) فوراً وإنهاء التنفيذ بـ 204
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// ── Security Headers ──────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

unset($_allowedOrigins, $_requestOrigin, $configuredOrigins, $isAllowed);

// ─────────────────────────────────────────────────────────────────────────
// FUNCTIONS
// ─────────────────────────────────────────────────────────────────────────

function respond(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clientIp(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') return trim(explode(',', $forwarded)[0]);
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function logRequest(string $channel, string $level, string $msg, array $ctx = []): void {
    error_log("[$channel] " . json_encode(array_merge([
        'ts'    => date('Y-m-d H:i:s'),
        'level' => $level,
        'msg'   => $msg,
        'ip'    => clientIp(),
    ], $ctx), JSON_UNESCAPED_UNICODE));
}

/**
 * 🔒 دالة التحقق الديناميكي من الـ Token عبر قاعدة البيانات
 */
function requireAuth(string $channel): array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    $providedKey = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : '';

    if ($providedKey === '') {
        logRequest($channel, 'warn', 'Missing or malformed Authorization header', ['ip' => clientIp()]);
        respond(401, ['ok' => false, 'error' => 'Unauthorized - Missing Token']);
    }

    $tokenHash = hash('sha256', $providedKey);

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, email, role FROM users WHERE token_hash = ? LIMIT 1");
        $stmt->execute([$tokenHash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            logRequest($channel, 'warn', 'Invalid or expired token provided', ['ip' => clientIp()]);
            respond(401, ['ok' => false, 'error' => 'Unauthorized - Invalid Token']);
        }

        return $user;

    } catch (Exception $e) {
        logRequest($channel, 'critical', 'Database error during authentication: ' . $e->getMessage());
        respond(500, ['ok' => false, 'error' => 'Internal Server Error']);
    }
}

function requireMethod(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        respond(405, ['ok' => false, 'error' => 'Method Not Allowed']);
    }
}

function readJsonBody(int $maxBytes = 1_048_576): array {
    $raw = file_get_contents('php://input', length: $maxBytes + 1);
    if ($raw === false) respond(400, ['ok' => false, 'error' => 'Could not read request body']);
    if (strlen($raw) > $maxBytes) respond(413, ['ok' => false, 'error' => 'Request body too large']);
    $input = json_decode($raw, true);
    if (!is_array($input)) respond(400, ['ok' => false, 'error' => 'Request body must be valid JSON']);
    return $input;
}