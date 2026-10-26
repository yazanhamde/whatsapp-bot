<?php
declare(strict_types=1);
/**
 * /api/dropoff_report.php
 * GET → تقرير الزباين اللي سألوا عن صيانة ووصلوا لخطوة التأكيد وما أكدوا
 *       (تسرب الزباين — لمعرفة وين البوت يخسر مبيعات ويسمح لمعاذ يتابعهم يدوياً)
 *
 * Query params:
 *   status (string) all | pending | recovered — افتراضي: all
 *   days   (int)    آخر كم يوم — افتراضي: 30
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';

requireAuth('dropoff_report');

$db = getDB();

$status  = $_GET['status'] ?? 'all';
$days    = max(1, (int)($_GET['days'] ?? 30));
$channel = $_GET['channel'] ?? '';

$where = ["d.created_at >= NOW() - INTERVAL ? DAY"];
$params = [$days];

if ($status === 'pending') {
    $where[] = "d.recovered = 0";
} elseif ($status === 'recovered') {
    $where[] = "d.recovered = 1";
}

$allowedChannels = ['whatsapp', 'instagram', 'messenger'];
if (in_array($channel, $allowedChannels, true)) {
    $where[] = "conv.channel = ?";
    $params[] = $channel;
}

$sql = "SELECT d.id, d.conversation_id, d.customer_phone, d.device_type, d.issue, d.stage,
               d.followup_sent_at, d.recovered, d.created_at,
               c.name AS customer_name,
               conv.channel AS channel,
               (SELECT COUNT(*) FROM service_requests sr
                WHERE sr.customer_id = d.customer_id AND sr.status = 'completed') AS completed_orders_count
        FROM dropoff_log d
        LEFT JOIN customers c ON c.id = d.customer_id
        LEFT JOIN conversations conv ON conv.id = d.conversation_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.created_at DESC
        LIMIT 500";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'query_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalPending   = 0;
$totalRecovered = 0;
foreach ($rows as $r) {
    if ((int)$r['recovered'] === 1) $totalRecovered++; else $totalPending++;
}

echo json_encode([
    'ok' => true,
    'summary' => [
        'total'           => count($rows),
        'pending'         => $totalPending,   // ما زال ما أكد — يحتاج متابعة معاذ
        'recovered'       => $totalRecovered, // رجع وأكد بعد التذكير
        'conversion_rate' => count($rows) > 0 ? round($totalRecovered / count($rows) * 100, 1) : 0,
    ],
    'rows' => $rows,
], JSON_UNESCAPED_UNICODE);
