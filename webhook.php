<?php
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/includes/Agent.php';

header('Content-Type: application/json; charset=utf-8');

// ── GET: التحقق من الـ Webhook ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (
        ($_GET['hub_mode']         ?? '') === 'subscribe' &&
        ($_GET['hub_verify_token'] ?? '') === WA_VERIFY_TOKEN
    ) {
        http_response_code(200);
        echo (int)($_GET['hub_challenge'] ?? 0);
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}

// ── POST فقط ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = (string)file_get_contents('php://input');

// ── التحقق من توقيع Meta (تم تعطيله لتخطي قيود السيرفر والـ cPanel) ──
/*
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if ($sigHeader === '') {
    http_response_code(401);
    error_log('[webhook] Missing X-Hub-Signature-256 header');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $rawBody, WA_APP_SECRET);

if (!hash_equals($expected, $sigHeader)) {
    http_response_code(403);
    error_log('[webhook] Invalid signature from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
*/

// ── ارجع 200 بسرعة ───────────────────────────────────────────────────────
http_response_code(200);
echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_end_flush();
    flush();
}

// ── استخرج الـ payload ────────────────────────────────────────────────────
$input = json_decode($rawBody, true);
if (!is_array($input)) { error_log('[webhook] Invalid JSON body'); exit; }

$value = $input['entry'][0]['changes'][0]['value'] ?? null;
if (!is_array($value)) exit;

if (!empty($value['statuses']) && empty($value['messages'])) exit;

$msgData = $value['messages'][0] ?? null;
if (!is_array($msgData)) exit;

$phone = $msgData['from'] ?? '';
$waId  = $msgData['id']   ?? null;
$type  = $msgData['type'] ?? 'text';

if ($phone === '' || $waId === null) exit;

// ── فحص إذا الرسالة من مجموعة ────────────────────────────────────────────
// في WhatsApp Cloud API، رسائل المجموعات بتيجي بـ chat_id ينتهي بـ @g.us
// و$phone بيحمل رقم المُرسِل الفعلي (مش الـ group ID)
$groupId      = null;
$isGroupMsg   = false;
$participantPhone = $phone; // رقم الشخص الفعلي داخل المجموعة

if (isset($value['metadata']['phone_number_id'])) {
    // الـ group_id بيجي في contacts أو من الـ recipient في messages
    $rawTo = $msgData['to'] ?? '';
    // WhatsApp بيحط الـ group ID في $msgData لما تكون رسالة مجموعة
    // بعض الـ payloads بتحطه في 'group_id' مباشرة
    if (!empty($msgData['group_id'])) {
        $groupId    = $msgData['group_id'];
        $isGroupMsg = true;
    } elseif (str_ends_with($rawTo, '@g.us')) {
        $groupId    = $rawTo;
        $isGroupMsg = true;
    }
}

// لو رسالة مجموعة: $phone بيصير هو الـ group ID مش رقم المرسل
// عشان نتعامل معها بشكل منفصل بالـ Agent
// (الـ participantPhone محتفظ فيه للاستخدام لاحقاً)


// ── deduplication ─────────────────────────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/wa_msg_' . preg_replace('/\W/', '', $waId) . '.lock';
if (file_exists($lockFile)) { error_log("[webhook] Duplicate waId=$waId"); exit; }
file_put_contents($lockFile, time());
register_shutdown_function(fn() => @unlink($lockFile));

// ── استخراج محتوى الرسالة ────────────────────────────────────────────────
$text    = '';
$mediaId = null;

// ── استخراج الـ quoted message (لو رمزي سحب على رسالة) ──────────────────
// WhatsApp بيحط الرسالة المسحوبة عليها في context.quoted_message أو context
$quotedMsgId   = $msgData['context']['id']             ?? null;   // ID الرسالة المسحوبة
$quotedMsgBody = $msgData['context']['quoted_message']['text']['body'] // نص الرسالة المسحوبة
              ?? $msgData['context']['quoted_message']['body']
              ?? null;

switch ($type) {
    case 'text':
        $text = trim($msgData['text']['body'] ?? '');
        break;

    case 'audio':
    case 'voice':
        $mediaId = $msgData[$type]['id'] ?? null;
        $type    = 'audio';
        break;

    case 'image':
        $mediaId = $msgData['image']['id'] ?? null;
        $text    = trim($msgData['image']['caption'] ?? 'أرسل صورة');
        $type    = 'image';
        break;

    case 'location':
        $text  = json_encode([
            'lat'     => $msgData['location']['latitude']  ?? null,
            'lng'     => $msgData['location']['longitude'] ?? null,
            'name'    => $msgData['location']['name']      ?? '',
            'address' => $msgData['location']['address']   ?? '',
        ]);
        $type  = 'location';
        break;

    case 'button':
        $text = trim($msgData['button']['text'] ?? '');
        $type = 'text';
        break;

    case 'interactive':
        $text = trim(
            $msgData['interactive']['button_reply']['id']
            ?? $msgData['interactive']['list_reply']['id']
            ?? $msgData['interactive']['button_reply']['title']
            ?? $msgData['interactive']['list_reply']['title']
            ?? ''
        );
        $type = 'text';
        break;

    default:
        $text = "[رسالة من نوع {$type}]";
        $type = 'text';
        break;
}

// ── فحص global_bot_status ─────────────────────────────────────────────────
try {
    $db        = getDB();
    $botStatus = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'global_bot_status' LIMIT 1")
                    ->fetchColumn();

    // تم حذف الاستثناء OWNER_PHONE ليشمل الإيقاف الجميع
    if ($botStatus === 'off') {
        // ── البوت مطفأ: حفظ الرسالة فقط لتظهر في الداشبورد ─────────────────
        // 1. البحث عن customer موجود أو إنشاؤه
        $custStmt = $db->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
        $custStmt->execute([$phone]);
        $customerId = $custStmt->fetchColumn();

        if (!$customerId) {
            $db->prepare("INSERT INTO customers (phone, created_at) VALUES (?, NOW())")
               ->execute([$phone]);
            $customerId = $db->lastInsertId();
        }

        // 2. البحث عن محادثة مفتوحة أو إنشاء واحدة جديدة
        $convStmt = $db->prepare("
            SELECT id FROM conversations
            WHERE customer_id = ? AND status != 'closed'
            ORDER BY id DESC LIMIT 1
        ");
        $convStmt->execute([$customerId]);
        $conversationId = $convStmt->fetchColumn();

        if (!$conversationId) {
            $db->prepare("
                INSERT INTO conversations (customer_id, status, bot_paused, last_msg_at)
                VALUES (?, 'pending', 1, NOW())
            ")->execute([$customerId]);
            $conversationId = $db->lastInsertId();
        }

        // 3. حفظ الرسالة
        $db->prepare("
            INSERT INTO messages (conversation_id, sender, msg_type, body)
            VALUES (?, 'customer', ?, ?)
        ")->execute([$conversationId, $type, $text]);

        // 4. تحديث وقت آخر رسالة في المحادثة
        $db->prepare("
            UPDATE conversations SET last_msg_at = NOW() WHERE id = ?
        ")->execute([$conversationId]);

        error_log('[webhook] Global bot is OFF — message saved for dashboard, phone=' . $phone);

        // نخرج لمنع الـ Agent من العمل والرد
        exit;
    }
} catch (Throwable $e) {
    error_log('[webhook] Failed to check/save global_bot_status: ' . $e->getMessage());
}

// ── الحل الجذري لمشكلة نافذة الـ 24 ساعة (Customer Service Window) ────────
// أي رسالة توصل من أي رقم من أرقام المالك (OWNER_PHONE قد يحوي أكثر من رقم
// مفصولة بفاصلة) بتسجل "آخر ظهور" له بجدول settings. هاد التوقيت بيستخدمه
// Agent::sendToOwners() لاحقاً ليقرر يبعت رسالة حرة عادية أو Template.
$ownersListForTracking = array_map('trim', explode(',', OWNER_PHONE));
if (in_array($phone, $ownersListForTracking, true)) {
    try {
        $dbTrack = getDB();
        $dbTrack->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = NOW()
        ")->execute(["owner_last_msg_at:$phone"]);
    } catch (Throwable $e) {
        error_log('[webhook] Failed to update owner_last_msg_at: ' . $e->getMessage());
    }
}

// ── تحقق إذا الرسالة من صاحب المتجر بصيغة رد#ID ─────────────────────────
if (in_array($phone, $ownersListForTracking, true) && preg_match('/^(?:(?:عبدالله|محمد)\s*:\s*)?رد#(\d+)\s*:\s*(.+)/su', trim($text), $m)) {
    try {
        $agent  = new Agent();
        $result = $agent->sendOwnerAnswer((int)$m[1], trim($m[2]), true);
        error_log('[webhook] Owner reply — notifId=' . $m[1] . ' ok=' . ($result['ok'] ? '1' : '0'));
    } catch (Throwable $e) {
        error_log('[webhook] Owner reply failed: ' . $e->getMessage());
    }
    exit;
}

// ── رمزي سحب على رسالة زبون وكتب رده مباشرة (Quote Reply) ──────────────
// الآلية: البوت يبحث في messages عن الرسالة المسحوبة، يطلع phone الزبون،
// ويرد عليه مباشرة — بدون ما رمزي يحتاج يعرف الـ notification_id.
if (
    in_array($phone, $ownersListForTracking, true) &&
    $quotedMsgId !== null &&
    trim($text) !== ''
) {
    try {
        $agent  = new Agent();
        $result = $agent->sendOwnerAnswerByQuote($quotedMsgId, trim($text));
        if ($result['ok'] ?? false) {
            error_log('[webhook] Owner quote-reply sent — quotedWaId=' . $quotedMsgId);
            exit;
        }
        if (($result['error'] ?? '') === 'تم الرد مسبقاً') {
            $agent->sendDirectMessage($phone, '⚠️ هالسؤال انرد عليه من قبل، ما بعتّ ردك للزبون.');
            exit;
        }
        error_log('[webhook] Quote reply: no matching record for waId=' . $quotedMsgId . ' — continuing');
    } catch (Throwable $e) {
        error_log('[webhook] Owner quote-reply failed: ' . $e->getMessage());
    }
}

// ── معالجة رسائل المجموعات ────────────────────────────────────────────────
// البوت يرد بس لو حدا mention رقم البوت في الرسالة
if ($isGroupMsg) {
    $botPhone  = defined('WA_BOT_PHONE') ? WA_BOT_PHONE : '';
    $mentions  = $msgData['text']['mentioned_jids'] ?? $msgData['mentions'] ?? [];
    $botMentioned = false;
    foreach ($mentions as $jid) {
        $mentionedNum  = preg_replace('/[^0-9]/', '', $jid);
        $botPhoneClean = preg_replace('/[^0-9]/', '', $botPhone);
        if ($botPhoneClean !== '' && str_contains($mentionedNum, $botPhoneClean)) {
            $botMentioned = true;
            break;
        }
    }
    if (!$botMentioned) {
        error_log('[webhook] Group message ignored (no bot mention) — group=' . $groupId);
        exit;
    }
    try {
        $agent = new Agent();
        $agent->handle([
            'phone'       => $groupId,
            'participant' => $participantPhone,
            'text'        => $text,
            'type'        => $type,
            'media_id'    => $mediaId,
            'wa_id'       => $waId,
            'is_group'    => true,
        ]);
    } catch (Throwable $e) {
        error_log('[webhook] Group Agent::handle failed — ' . $e->getMessage());
    }
    exit;
}

// ── تحقق إذا الرسالة من رقم بوت المحاسبة (رقم رمزي المخصص) ───────────────
// أي رسالة (نص أو صورة) جاية من هالرقم بتتحول كاملة لنظام المحاسبة (مشروع
// منفصل تماماً وقاعدة بيانات منفصلة)، ونظام المحاسبة بيرد على واتساب لحاله
// مباشرة - ما في أي تعامل إضافي هون، فقط تمرير الـ payload الخام زي ما هو.
$accountingOwnerPhone = getenv('ACCOUNTING_OWNER_PHONE') ?: '';
if ($accountingOwnerPhone !== '' && $phone === $accountingOwnerPhone) {
    try {
        $originalSignature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $ch = curl_init('https://goldensketch.com/accounting-system/api/whatsapp_webhook.php');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_HTTPHEADER     => array_filter([
                'Content-Type: application/json',
                $originalSignature !== '' ? ('X-Hub-Signature-256: ' . $originalSignature) : null,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        error_log('[webhook] Forwarded to accounting bot — httpCode=' . $httpCode);
    } catch (Throwable $e) {
        error_log('[webhook] Forward to accounting bot failed: ' . $e->getMessage());
    }
    exit;
}

// ── تشغيل الـ Agent ───────────────────────────────────────────────────────
try {
    $agent = new Agent();
    $agent->handle([
        'phone'           => $phone,
        'text'            => $text,
        'type'            => $type,
        'media_id'        => $mediaId,
        'wa_id'           => $waId,
        'quoted_wa_id'    => $quotedMsgId,    // ID الرسالة المسحوبة (لو موجودة)
        'quoted_body'     => $quotedMsgBody,  // نص الرسالة المسحوبة (لو موجودة)
    ]);
} catch (Throwable $e) {
    error_log('[webhook] Agent::handle failed — waId=' . $waId . ' : ' . $e->getMessage());
}