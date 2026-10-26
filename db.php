<?php
/**
 * config/db.php
 * الاتصال بقاعدة البيانات — يُقرأ من .env فقط
 */

// ── منع التضمين المباشر ───────────────────────────────────────────────────
if (!defined('_SETTINGS_LOADED')) {
    require_once __DIR__ . '/settings.php';
}

// ── الاتصال ───────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;

    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        env('DB_HOST',    'localhost'),
        env('DB_PORT',    '3306'),
        env('DB_NAME'),
        env('DB_CHARSET', 'utf8mb4'),
    );

    try {
        $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
            // ✅ [ERROR] استثناء فوري عند أي خطأ
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // ✅ [SECURITY] تعطيل emulated prepares — يمنع SQL injection
            PDO::ATTR_EMULATE_PREPARES   => false,
            // ✅ [PERF] stringify_fetches=false يرجع int/float كأرقام حقيقية
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // ✅ [PERF] timeout الاتصال بالـ DB
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } catch (PDOException $e) {
        // ✅ [SECURITY] إخفاء تفاصيل الـ DSN والـ credentials من الـ error
        error_log('[DB] Connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Please try again later.');
    }

    return $pdo;
}