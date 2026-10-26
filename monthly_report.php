<?php
declare(strict_types=1);
/**
 * /api/monthly_report.php
 * GET → جرد شهري: طلبات الصيانة، أكثر الأجهزة عطلاً، أكثر القطع استفساراً، الشكاوى
 *
 * ⚠️ نسخة محسّنة (مقاومة للأعطال الجزئية):
 * كل استعلام محاط بـ try/catch مستقل — إذا فشل استعلام واحد (مثلاً بسبب عمود أو
 * جدول غير موجود)، الباقي بيستمر يشتغل بدل ما الـ endpoint كامل يرجع خطأ 500.
 * كل قسم فاشل بيرجع فاضي مع رسالة خطأ مسجلة بـ error_log بدل ما يوقف الباقي.
 *
 * Query params:
 *   month   (int) 1-12  — افتراضي: الشهر الحالي
 *   year    (int)       — افتراضي: السنة الحالية
 *   channel (string)    — whatsapp | instagram | messenger — اختياري، لو فاضي بيرجع تقرير كل المنصات مع بعض
 *   debug   (1)          — لو موجود، الرد بيرجع debug_errors فيها رسائل الأخطاء الفعلية
 *                          (للتشخيص فقط — احذف هاد الباراميتر أو امسحه بعد ما تخلص فحص)
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helpers.php';

requireAuth('monthly_report');

$db = getDB();

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
$debug = isset($_GET['debug']);

// فلترة اختيارية حسب المنصة: whatsapp | instagram | messenger — فاضي = كل المنصات مع بعض
$allowedChannels = ['whatsapp', 'instagram', 'messenger'];
$channel = $_GET['channel'] ?? '';
if (!in_array($channel, $allowedChannels, true)) {
    $channel = '';
}

if ($month < 1 || $month > 12) {
    respond(400, ['ok' => false, 'message' => 'month يجب أن يكون بين 1 و 12']);
}

$debugErrors = [];

/**
 * مساعد: ينفذ كولباك جوا try/catch، ويرجع $fallback لو صار خطأ،
 * مع تسجيل الخطأ بـ error_log (وبـ $debugErrors لو debug=1).
 */
function safeRun(string $label, callable $fn, $fallback, array &$debugErrors) {
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log("[monthly_report:$label] " . $e->getMessage());
        $debugErrors[$label] = $e->getMessage();
        return $fallback;
    }
}

// ── ١. إجمالي طلبات الصيانة + توزيع الحالات ─────────────────────────────
$totals = safeRun('maintenance_totals', function() use ($db, $month, $year, $channel) {
    $channelJoin  = $channel !== '' ? 'JOIN conversations conv ON conv.id = sr.conversation_id' : '';
    $channelWhere = $channel !== '' ? ' AND conv.channel = ?' : '';
    $params = [$month, $year];
    if ($channel !== '') $params[] = $channel;

    $stmt = $db->prepare("
        SELECT
            COUNT(*)                                              AS total,
            SUM(sr.status = 'pending')                            AS pending,
            SUM(sr.status = 'assigned')                           AS assigned,
            SUM(sr.status = 'in_progress')                        AS in_progress,
            SUM(sr.status = 'completed')                          AS completed,
            SUM(sr.status = 'cancelled')                          AS cancelled
        FROM service_requests sr
        $channelJoin
        WHERE MONTH(sr.created_at) = ? AND YEAR(sr.created_at) = ?$channelWhere
    ");
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}, ['total'=>0,'pending'=>0,'assigned'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0], $debugErrors);

// ── ٢+٣. تصنيف الأعطال حسب كل جهاز (مدموجة مع بعض، بدل قائمتين منفصلتين) ──
// كل جهاز بيطلع وتحته الأعطال يلي صارت فيه هاد الشهر. الأعطال يلي اتكتبت
// بنص حر (بدون اختيار من القائمة الجاهزة) بنحاول نطابقها مع الكلمات
// المفتاحية (issue_keywords) المخزنة لأعطال نفس الجهاز بقاعدة البيانات —
// عشان "تسرب مي" و"تسريب ماء" و"بتسرب" ينضموا تحت نفس العطل تلقائياً.
$deviceBreakdown = safeRun('device_issue_breakdown', function() use ($db, $month, $year, $channel) {

    // أ) كل أنواع الأجهزة (حتى لو صفر طلبات هاد الشهر)
    $deviceRows = $db->query("SELECT id, name_ar, icon, sort_order FROM device_types ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $devicesById = [];
    foreach ($deviceRows as $d) { $devicesById[(int)$d['id']] = $d; }

    // ب) كل الأعطال الجاهزة، مجمّعة حسب الجهاز يلي تتبعله
    $issueRows = $db->query("SELECT id, device_type_id, issue_text_ar, issue_keywords FROM common_issues ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $issuesByDevice = []; // device_type_id => [ {id, text, keywords[]}, ... ]
    $issueTextById  = []; // issue_id => issue_text_ar (للمطابقة المباشرة لما issue_id محدد)
    foreach ($issueRows as $i) {
        $devId = (int)$i['device_type_id'];
        $keywords = array_filter(array_map('trim', explode('،', (string)($i['issue_keywords'] ?? ''))));
        if (empty($keywords)) {
            // بعض الكلمات المفتاحية ممكن تكون مفصولة بفاصلة إنجليزية لو حصل خطأ إدخال
            $keywords = array_filter(array_map('trim', explode(',', (string)($i['issue_keywords'] ?? ''))));
        }
        $issuesByDevice[$devId][] = [
            'id'       => (int)$i['id'],
            'text'     => $i['issue_text_ar'],
            'keywords' => $keywords,
        ];
        $issueTextById[(int)$i['id']] = $i['issue_text_ar'];
    }

    // ج) طلبات الصيانة الفعلية لهاد الشهر (مفلترة حسب المنصة لو محددة)
    $channelJoin  = $channel !== '' ? 'JOIN conversations conv ON conv.id = sr.conversation_id' : '';
    $channelWhere = $channel !== '' ? ' AND conv.channel = ?' : '';
    $params = [$month, $year];
    if ($channel !== '') $params[] = $channel;

    $stmt = $db->prepare("
        SELECT sr.device_type_id, sr.issue_id, sr.issue_description
        FROM service_requests sr
        $channelJoin
        WHERE MONTH(sr.created_at) = ? AND YEAR(sr.created_at) = ?$channelWhere
    ");
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // تطبيع بسيط للنص الحر (همزات، تاء مربوطة، مسافات) عشان المطابقة أدق
    $normalize = function(string $text): string {
        $text = trim($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = str_replace(['أ','إ','آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        return mb_strtolower($text);
    };

    // د) تجميع: لكل جهاز → لكل عطل (نص العرض) → عدد مرات التكرار
    // المفتاح الداخلي لكل عطل هو نص معطل (normalized) عشان "تسرب مي" و"تسريب ماء"
    // ينضموا تحت بعض حتى لو وصلوا بطريق مختلف (issue_id مباشر أو تطابق كلمات مفتاحية).
    $grouped = []; // device_type_id => [ normalized_issue_key => ['label' => display_text, 'count' => n] ]

    foreach ($requests as $r) {
        $devId = $r['device_type_id'] !== null ? (int)$r['device_type_id'] : 0; // 0 = غير محدد

        $issueLabel = null;

        // 1) لو فيه issue_id محدد (اختار من القائمة الجاهزة) — نستخدم نصه مباشرة
        if ($r['issue_id'] !== null && isset($issueTextById[(int)$r['issue_id']])) {
            $issueLabel = $issueTextById[(int)$r['issue_id']];
        }
        // 2) وإلا، لو فيه نص حر، نحاول نطابقه مع الكلمات المفتاحية لأعطال نفس الجهاز
        elseif (!empty($r['issue_description'])) {
            $freeText = $normalize($r['issue_description']);
            $matched = null;
            foreach (($issuesByDevice[$devId] ?? []) as $candidate) {
                foreach ($candidate['keywords'] as $kw) {
                    $kwNorm = $normalize($kw);
                    if ($kwNorm !== '' && mb_strpos($freeText, $kwNorm) !== false) {
                        $matched = $candidate['text'];
                        break 2;
                    }
                }
            }
            $issueLabel = $matched ?? ('📝 ' . $r['issue_description']); // ما انطابق مع شي معروف — نعرض نصه الأصلي
        }
        // 3) ما في issue_id ولا نص — نعتبره عطل غير مذكور
        else {
            $issueLabel = 'لم يُحدَّد نوع العطل';
        }

        $key = $normalize($issueLabel);
        if (!isset($grouped[$devId][$key])) {
            $grouped[$devId][$key] = ['label' => $issueLabel, 'count' => 0];
        }
        $grouped[$devId][$key]['count']++;
    }

    // هـ) بناء الناتج النهائي: كل الأجهزة (حتى الفاضية) + جهاز "غير محدد" بالآخر لو فيه طلبات بدونه
    $result = [];
    foreach ($devicesById as $devId => $dev) {
        $issuesForDevice = array_values($grouped[$devId] ?? []);
        usort($issuesForDevice, fn($a, $b) => $b['count'] <=> $a['count']);
        $totalForDevice = array_sum(array_column($issuesForDevice, 'count'));
        $result[] = [
            'device' => $dev['name_ar'],
            'icon'   => $dev['icon'],
            'total'  => $totalForDevice,
            'issues' => $issuesForDevice,
        ];
    }
    // جهاز "غير محدد" (device_type_id فاضي) — بس لو فيه طلبات فعلية بدونه
    if (!empty($grouped[0])) {
        $issuesForDevice = array_values($grouped[0]);
        usort($issuesForDevice, fn($a, $b) => $b['count'] <=> $a['count']);
        $result[] = [
            'device' => 'غير محدد',
            'icon'   => '❓',
            'total'  => array_sum(array_column($issuesForDevice, 'count')),
            'issues' => $issuesForDevice,
        ];
    }

    // الأجهزة يلي عندها طلبات فعلية تطلع فوق، والباقي (صفر) تحت بترتيبهم الأصلي
    usort($result, fn($a, $b) => $b['total'] <=> $a['total']);

    return $result;
}, [], $debugErrors);

// ── ٤. أكثر القطع استفساراً ──────────────────────────────────────────────
$parts = safeRun('top_parts', function() use ($db, $month, $year, $channel) {
    $channelWhere = $channel !== '' ? ' AND c.channel = ?' : '';
    $params = [$month, $year];
    if ($channel !== '') $params[] = $channel;

    $stmt = $db->prepare("
        SELECT
            m.body      AS message,
            COUNT(*)    AS count
        FROM messages m
        JOIN conversations c ON c.id = m.conversation_id
        WHERE m.sender = 'customer'
          AND m.msg_type = 'text'
          AND MONTH(m.created_at) = ? AND YEAR(m.created_at) = ?
          AND c.state IN ('waiting_part_text', 'waiting_part_image', 'idle')
          AND m.body IS NOT NULL
          AND m.body != ''
          AND m.body NOT IN ('part_inquiry','maintenance','complaint','order_track','نعم','لا','1','2','3','4','5','6','7','8')
          AND EXISTS (
              SELECT 1 FROM messages prev
              WHERE prev.conversation_id = m.conversation_id
                AND prev.sender = 'bot'
                AND prev.body LIKE '%استفسار عن قطعة%'
                AND prev.created_at < m.created_at
          )$channelWhere
        GROUP BY m.body
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}, [], $debugErrors);

// ── ٥. الشكاوى والملاحظات ────────────────────────────────────────────────
$complaints = safeRun('complaints_totals', function() use ($db, $month, $year, $channel) {
    $channelJoin  = $channel !== '' ? 'JOIN conversations conv ON conv.id = cp.conversation_id' : '';
    $channelWhere = $channel !== '' ? ' AND conv.channel = ?' : '';
    $params = [$month, $year];
    if ($channel !== '') $params[] = $channel;

    $stmt = $db->prepare("
        SELECT
            COUNT(*)                        AS total,
            SUM(cp.status = 'new')          AS new_count,
            SUM(cp.status = 'under_review') AS under_review,
            SUM(cp.status = 'resolved')     AS resolved,
            SUM(cp.status = 'closed')       AS closed
        FROM complaints cp
        $channelJoin
        WHERE MONTH(cp.created_at) = ? AND YEAR(cp.created_at) = ?$channelWhere
    ");
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}, ['total'=>0,'new_count'=>0,'under_review'=>0,'resolved'=>0,'closed'=>0], $debugErrors);

$recentComplaints = safeRun('complaints_recent', function() use ($db, $month, $year, $channel) {
    $channelWhere = $channel !== '' ? ' AND conv.channel = ?' : '';
    $params = [$month, $year];
    if ($channel !== '') $params[] = $channel;

    $stmt = $db->prepare("
        SELECT
            c.complaint_text,
            c.status,
            c.created_at,
            cu.phone AS customer_phone,
            conv.channel AS channel
        FROM complaints c
        JOIN customers cu ON cu.id = c.customer_id
        LEFT JOIN conversations conv ON conv.id = c.conversation_id
        WHERE MONTH(c.created_at) = ? AND YEAR(c.created_at) = ?$channelWhere
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}, [], $debugErrors);

// ── ٦. زباين المنصة (العدد الكلي + الجداد هاد الشهر) ─────────────────────
// لو ما في channel محدد، بترجع تفصيل لكل منصة على حدة (لعرضها بتقرير "كل المنصات")
$customersSummary = safeRun('customers_summary', function() use ($db, $month, $year, $channel) {
    if ($channel !== '') {
        $totalStmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE channel = ?");
        $totalStmt->execute([$channel]);
        $newStmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE channel = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
        $newStmt->execute([$channel, $month, $year]);
        return [
            'channel'        => $channel,
            'total'          => (int)$totalStmt->fetchColumn(),
            'new_this_month' => (int)$newStmt->fetchColumn(),
        ];
    }

    $byChannel = [];
    foreach (['whatsapp', 'instagram', 'messenger'] as $ch) {
        $totalStmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE channel = ?");
        $totalStmt->execute([$ch]);
        $newStmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE channel = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?");
        $newStmt->execute([$ch, $month, $year]);
        $byChannel[] = [
            'channel'        => $ch,
            'total'          => (int)$totalStmt->fetchColumn(),
            'new_this_month' => (int)$newStmt->fetchColumn(),
        ];
    }
    return ['by_channel' => $byChannel];
}, [], $debugErrors);

// ── الرد ─────────────────────────────────────────────────────────────────
$response = [
    'ok'      => true,
    'month'   => $month,
    'year'    => $year,
    'channel' => $channel,
    'data'    => [

        'customers' => $customersSummary,

        'maintenance' => [
            'total'      => (int)($totals['total'] ?? 0),
            'pending'    => (int)($totals['pending'] ?? 0),
            'assigned'   => (int)($totals['assigned'] ?? 0),
            'in_progress'=> (int)($totals['in_progress'] ?? 0),
            'completed'  => (int)($totals['completed'] ?? 0),
            'cancelled'  => (int)($totals['cancelled'] ?? 0),
        ],

        'device_breakdown' => array_map(fn($d) => [
            'device' => $d['device'],
            'icon'   => $d['icon'],
            'total'  => (int)$d['total'],
            'issues' => array_map(fn($i) => [
                'label' => $i['label'],
                'count' => (int)$i['count'],
            ], $d['issues']),
        ], $deviceBreakdown),

        'top_parts' => array_map(fn($r) => [
            'part'  => $r['message'],
            'count' => (int)$r['count'],
        ], $parts),

        'complaints' => [
            'total'        => (int)($complaints['total'] ?? 0),
            'new'          => (int)($complaints['new_count'] ?? 0),
            'under_review' => (int)($complaints['under_review'] ?? 0),
            'resolved'     => (int)($complaints['resolved'] ?? 0),
            'closed'       => (int)($complaints['closed'] ?? 0),
            'recent'       => $recentComplaints,
        ],

    ],
];

// وضع تشخيص اختياري: زيدي ?debug=1 على الرابط لتشوفي رسائل الأخطاء الفعلية
// (مفيد مؤقتاً فقط — لا تنسي تشيلي الباراميتر بعد ما تخلصي فحص)
if ($debug && !empty($debugErrors)) {
    $response['debug_errors'] = $debugErrors;
}

respond(200, $response);
