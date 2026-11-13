<?php
/**
 * index_products.php — فهرسة صور المنتجات بالـ Vision + Embeddings
 *
 * شغّله مرة وحدة من المتصفح أو CLI:
 *   https://goldensketch.com/whatsapp-bot/index_products.php?secret=RamziIndex2026
 *
 * بيعمل:
 * 1. يجيب كل منتج عنده صورة حقيقية
 * 2. يبعث الصورة لـ GPT-4o-mini Vision → يحفظ الوصف البصري
 * 3. يعمل embedding للوصف → يحفظه للبحث الذكي لاحقاً
 * 4. يكمل من حيث وقف لو انقطع الاتصال
 */

declare(strict_types=1);

// ── حماية ──────────────────────────────────────────────────
$secret = $_GET['secret'] ?? '';
if ($secret !== 'RamziIndex2026') {
    http_response_code(403);
    die('Forbidden');
}

// ── إعداد ──────────────────────────────────────────────────
set_time_limit(0);
ini_set('memory_limit', '256M');
ob_implicit_flush(true);

require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/db.php';

$db        = getDB();
$openaiKey = defined('OPENAI_KEY') ? OPENAI_KEY : (getenv('OPENAI_KEY') ?: '');

if (!$openaiKey) die('❌ OPENAI_KEY غير موجود');

// ── جيب المنتجات اللي ما اتفهرسوا بعد ──────────────────────
$stmt = $db->query("
    SELECT p.id, p.name, p.image_url
    FROM products p
    LEFT JOIN product_visual_index vi ON vi.product_id = p.id
    WHERE p.image_url IS NOT NULL
      AND p.image_url NOT LIKE '%no_image%'
      AND vi.id IS NULL
    ORDER BY p.id ASC
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total     = count($products);
$done      = 0;
$failed    = 0;
$batchSize = 10; // نتوقف كل 10 عشان ما يموت السيرفر

echo "<!DOCTYPE html><html><body style='font-family:monospace;direction:rtl;padding:20px'>";
echo "<h2>🔍 فهرسة صور المنتجات</h2>";
echo "<p>إجمالي غير مفهرس: <strong>{$total}</strong></p><hr>";

if ($total === 0) {
    echo "<p>✅ كل المنتجات مفهرسة!</p>";
    echo "</body></html>";
    exit;
}

$insertStmt = $db->prepare("
    INSERT INTO product_visual_index
        (product_id, product_name, image_url, visual_desc, part_type, device_type, brand, embedding)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        visual_desc=VALUES(visual_desc),
        part_type=VALUES(part_type),
        device_type=VALUES(device_type),
        brand=VALUES(brand),
        embedding=VALUES(embedding),
        analyzed_at=NOW()
");

foreach ($products as $i => $product) {
    if ($i >= $batchSize) {
        echo "<hr><p>⏸ توقفت بعد {$batchSize} — أعد تشغيل الصفحة لتكمل الباقي (" . ($total - $done) . " متبقي)</p>";
        break;
    }

    $id      = $product['id'];
    $name    = $product['name'];
    $imgUrl  = trim($product['image_url']);

    echo "<p>🔄 [{$id}] {$name}...</p>";

    // ── جيب الصورة وحولها لـ base64 ────────────────────────
    $imgData = @file_get_contents($imgUrl);
    if (!$imgData) {
        echo "<p>⚠️ فشل تحميل الصورة: {$imgUrl}</p>";
        $failed++;
        continue;
    }

    $imgBase64 = base64_encode($imgData);
    $mimeType  = 'image/jpeg';
    if (str_ends_with(strtolower($imgUrl), '.png'))  $mimeType = 'image/png';
    if (str_ends_with(strtolower($imgUrl), '.webp')) $mimeType = 'image/webp';

    // ── Vision: حلل الصورة ──────────────────────────────────
    $analysis = analyzeWithVision($imgBase64, $mimeType, $name, $openaiKey);
    if (!$analysis) {
        echo "<p>⚠️ فشل تحليل Vision للمنتج: {$name}</p>";
        $failed++;
        continue;
    }

    // ── Embedding للوصف البصري ──────────────────────────────
    $embText  = "{$name} — {$analysis['visual_desc']} — {$analysis['part_type']} {$analysis['device_type']}";
    $embedding = getEmbedding($embText, $openaiKey);

    // ── احفظ بالـ DB ────────────────────────────────────────
    try {
        $insertStmt->execute([
            $id,
            $name,
            $imgUrl,
            $analysis['visual_desc'],
            $analysis['part_type']   ?: null,
            $analysis['device_type'] ?: null,
            $analysis['brand']       ?: null,
            $embedding ? json_encode($embedding) : null,
        ]);
        $done++;
        echo "<p>✅ [{$id}] {$name} — {$analysis['part_type']} {$analysis['device_type']}</p>";
    } catch (Throwable $e) {
        echo "<p>❌ DB Error: {$e->getMessage()}</p>";
        $failed++;
    }

    // تأخير بسيط عشان ما نضغط على OpenAI
    usleep(200000); // 0.2 ثانية
}

echo "<hr>";
echo "<p>✅ مفهرس: <strong>{$done}</strong> | ❌ فشل: <strong>{$failed}</strong> | متبقي: <strong>" . ($total - $done) . "</strong></p>";

if ($done < $total) {
    $url = '?secret=RamziIndex2026';
    echo "<p><a href='{$url}' style='background:#2ecc71;color:white;padding:10px 20px;border-radius:6px;text-decoration:none'>▶ تابع الفهرسة</a></p>";
} else {
    echo "<p style='color:green;font-size:1.2em'>🎉 اكتملت الفهرسة!</p>";
}

echo "</body></html>";

// ── دوال مساعدة ─────────────────────────────────────────────

function analyzeWithVision(string $imgBase64, string $mimeType, string $productName, string $apiKey): ?array
{
    $payload = [
        'model'      => 'gpt-4o-mini',
        'max_tokens' => 200,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => [
                    'url'    => "data:{$mimeType};base64,{$imgBase64}",
                    'detail' => 'low',
                ]],
                ['type' => 'text', 'text' =>
                    "اسم المنتج في مخزننا: \"{$productName}\"\n\n" .
                    "أنت خبير قطع غيار أجهزة منزلية أردني. حلل الصورة واستخرج:\n" .
                    "١. نوع القطعة (مثل: مروحة، ماتور، قشاط، مكثف، لوحة...)\n" .
                    "٢. الجهاز (غسالة، ثلاجة، مكيف، فرن، جلاية، ميكرويف، كولر)\n" .
                    "٣. الماركة إن ظهرت\n" .
                    "٤. وصف بصري مختصر: الشكل، اللون، المادة، أي مميزات مرئية\n\n" .
                    "رد بـ JSON فقط:\n" .
                    "{\"part_type\":\"نوع القطعة\",\"device_type\":\"الجهاز\",\"brand\":null,\"visual_desc\":\"وصف مختصر\"}"
                ],
            ],
        ]],
    ];

    try {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 25,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $code >= 400) return null;

        $data    = json_decode($raw, true);
        $content = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '');
        $parsed  = json_decode(trim($content), true);

        if (!is_array($parsed)) return null;

        return [
            'part_type'   => trim((string)($parsed['part_type']   ?? '')),
            'device_type' => trim((string)($parsed['device_type'] ?? '')),
            'brand'       => trim((string)($parsed['brand']       ?? '')) ?: null,
            'visual_desc' => trim((string)($parsed['visual_desc'] ?? '')),
        ];
    } catch (Throwable $e) {
        error_log('[analyzeWithVision] ' . $e->getMessage());
        return null;
    }
}

function getEmbedding(string $text, string $apiKey): ?array
{
    try {
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model' => 'text-embedding-3-small',
                'input' => mb_substr($text, 0, 1000),
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $code >= 400) return null;

        $data = json_decode($raw, true);
        return (array)($data['data'][0]['embedding'] ?? []) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
