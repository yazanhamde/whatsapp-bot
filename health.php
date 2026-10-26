<?php
/**
 * health.php - API Health Check
 * الوصول: /api/health.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// منع الوصول من خارج الـ server في production
// uncommnet السطر ده لو عايز تقيد الوصول
// if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
//     http_response_code(403);
//     exit(json_encode(['status' => 'forbidden']));
// }

$health = [
    'status'    => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks'    => []
];

// ============================================
// 1. PHP Version Check
// ============================================
$health['checks']['php'] = [
    'status'  => 'ok',
    'version' => PHP_VERSION
];

// ============================================
// 2. Database Check
// ============================================
try {
    // غير الداتا دي بـ config بتاعك
    $configFile = __DIR__ . '/config/database.php';

    if (file_exists($configFile)) {
        $db = require $configFile;
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['database']};charset=utf8mb4",
            $db['username'],
            $db['password'],
            [PDO::ATTR_TIMEOUT => 3]
        );
        $pdo->query('SELECT 1');
        $health['checks']['database'] = ['status' => 'ok'];
    } else {
        $health['checks']['database'] = [
            'status'  => 'warning',
            'message' => 'Config file not found'
        ];
    }
} catch (Exception $e) {
    $health['status'] = 'degraded';
    $health['checks']['database'] = [
        'status'  => 'error',
        'message' => 'Connection failed' // مش بنعرض الـ error الحقيقي للأمان
    ];
}

// ============================================
// 3. Writable Directories Check
// ============================================
$dirsToCheck = ['uploads', 'logs', 'cache'];
foreach ($dirsToCheck as $dir) {
    $path = __DIR__ . '/../' . $dir;
    if (is_dir($path)) {
        $health['checks']['dirs'][$dir] = is_writable($path) ? 'writable' : 'not_writable';
    }
}

// ============================================
// 4. Required Extensions
// ============================================
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring'];
foreach ($requiredExtensions as $ext) {
    $health['checks']['extensions'][$ext] = extension_loaded($ext) ? 'ok' : 'missing';
    if (!extension_loaded($ext)) {
        $health['status'] = 'degraded';
    }
}

// HTTP Status Code
$httpCode = $health['status'] === 'ok' ? 200 : 503;
http_response_code($httpCode);

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);