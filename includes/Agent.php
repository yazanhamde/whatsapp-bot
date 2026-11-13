<?php
// تعريف رقم المالك ديناميكياً من ملف .env
if (!defined("OWNER_PHONE")) {
    $ownerPhone = getenv("OWNER_PHONE");
    if (!$ownerPhone) {
        error_log("[Agent] OWNER_PHONE غير معرّف في .env — البوت متوقف");
        http_response_code(500);
        exit;
    }
    define("OWNER_PHONE", $ownerPhone);
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/settings.php";
require_once __DIR__ . "/WhatsApp.php";
require_once __DIR__ . "/OpenAI.php";
require_once __DIR__ . "/KnowledgeBase.php";
require_once __DIR__ . "/ProductSearch.php";
require_once __DIR__ . "/RAG.php";
// Traits الأساسية — مرتّبة حسب الاعتماد
require_once __DIR__ . "/traits/ConversationTrait.php";
require_once __DIR__ . "/traits/OwnerNotifyTrait.php";
require_once __DIR__ . "/traits/OwnerCommandsTrait.php";
require_once __DIR__ . "/traits/MediaHandlerTrait.php";
require_once __DIR__ . "/traits/MaintenanceFlowTrait.php";
// بوتات المحادثة
require_once __DIR__ . "/bots/AbdullahBotTrait.php";
require_once __DIR__ . "/bots/MohammadBotTrait.php";

/**
 * Agent.php — الموجّه الرئيسي (Router)
 * كل الـ business logic انتقل للـ Traits.
 * هنا فقط: constants, properties, __construct, handle()
 *
 * الترتيب مهم: ConversationTrait أولاً لأن الباقي يعتمد عليه.
 */
class Agent
{
    use ConversationTrait;
    use OwnerNotifyTrait;
    use OwnerCommandsTrait;
    use MediaHandlerTrait;
    use MaintenanceFlowTrait;
    use AbdullahBotTrait;
    use MohammadBotTrait;


    private PDO           $db;
    private WhatsApp      $wa;
    private OpenAI        $ai;
    private RAG           $rag;
    private KnowledgeBase $kb;
    private ProductSearch $ps;

    // الرسالة المسحوبة من الزبون (لو سحب على رسالة) — بتتعبى مؤقتاً بـ handle()
    // وبيستخدمها handleMohammad وhandleAbdullah كـ context إضافي للـ AI
    private ?string $_quotedBody = null;

    // رقم الزبون الحالي — بيتعبى بـ handle() عشان نربط كل إشعار لرمزي بالزبون اللي سبّبه
    private ?string $_alertPhone = null;

    // نتيجة آخر فحص موقع عبر OpenAI (هل هو داخل الأردن؟) — تُحدَّث من detectServiceArea()
    private bool $lastLocationIsJordan = true;

    // كاش بالذاكرة للتعليمات الإضافية اللي بيضيفها الأدمن من الداشبورد (تعليمات → بدون كود)
    private ?string $customInstructionsCache = null;

    /** يجيب التعليمات الإضافية اللي كتبها الأدمن من الداشبورد (قسم "تعليمات") */
    private function getCustomInstructions(): string
    {
        if ($this->customInstructionsCache !== null) {
            return $this->customInstructionsCache;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM settings WHERE setting_key = 'custom_bot_instructions' LIMIT 1"
            );
            $stmt->execute();
            $value = $stmt->fetchColumn();
            $this->customInstructionsCache = $value !== false ? trim((string)$value) : '';
        } catch (Throwable $e) {
            error_log('[getCustomInstructions] ' . $e->getMessage());
            $this->customInstructionsCache = '';
        }
        return $this->customInstructionsCache;
    }

    // ─── أسماء البوتين ───────────────────────────────────────────
    private const BOT_ABDULLAH = 'عبدالله';
    private const BOT_MOHAMMAD = 'محمد';

    // ─── حالات الفلو ─────────────────────────────────────────────
    private const STATE_IDLE                   = 'idle';
    private const STATE_MAINT_CONFIRM          = 'maint_confirm';
    private const STATE_MAINT_NAME             = 'maint_name';
    private const STATE_MAINT_PHONE            = 'maint_phone';
    private const STATE_MAINT_DEVICE           = 'maint_device';
    private const STATE_MAINT_BRAND            = 'maint_brand';
    private const STATE_MAINT_ISSUE_CUSTOM     = 'maint_issue_custom';
    private const STATE_MAINT_LOCATION         = 'maint_location';
    private const STATE_WAITING_TRACK_NO       = 'waiting_track_number';
    private const STATE_WAITING_INQUIRY        = 'waiting_inquiry';         // عرض أزرار: استفسار/ملاحظة/شكوى
    private const STATE_WAITING_INQUIRY_TEXT   = 'waiting_inquiry_text';    // استفسار → نص
    private const STATE_WAITING_NOTE_TEXT      = 'waiting_note_text';       // ملاحظة → نص
    private const STATE_WAITING_COMPLAINT_TEXT = 'waiting_complaint_text';  // شكوى → نص
    private const STATE_WAITING_PART_TEXT      = 'waiting_part_text';
    private const STATE_WAITING_PART_IMAGE     = 'waiting_part_image';
    private const STATE_WAITING_PART_DEVICE    = 'waiting_part_device'; // بعد الصورة → يسأل عن الجهاز
    private const STATE_WAITING_IMAGE_CONFIRM  = 'waiting_image_confirm'; // بعد اقتراح صورة → ينتظر نعم/لا
    private const STATE_WAITING_DEVICE_IMG_INTENT = 'waiting_device_img_intent'; // بعد صورة جهاز كامل → ينتظر: قطعة ولا عطل؟
    private const STATE_WAITING_PART_NAME_FALLBACK = 'waiting_part_name_fallback'; // ما تعرفنا على الصورة → نسأل عن اسم القطعة قبل التصعيد

    // ─── مفتاح تحديد البوت النشط بالـ Draft ─────────────────────
    private const DRAFT_ACTIVE_BOT = 'active_bot';

    // ─── ثوابت الأسعار والمعلومات (مصدر وحيد للحقيقة) ───────────
    // ملاحظة: VISIT_PRICE_MIN/MAX و SUPPORT_PHONE صارت public لأن
    // api/bot_instructions.php محتاجتهم لبناء نص القواعد الافتراضي
    // (defaultMohammadRules) اللي يترزرع بالداشبورد أول مرة.
    public const VISIT_PRICE_MIN  = 10;
    public const VISIT_PRICE_MAX  = 15;
    private const WORKING_HOURS   = '10 صباحاً حتى 9 مساءً، طوال أيام الأسبوع';
    private const SHOP_LOCATION   = 'عمان، الهاشمي الشمالي';
    private const SERVICE_AREA    = 'كل الأردن';
    public const SUPPORT_PHONE    = '+962 7 9693 2863';

    // ─── مناطق الصيانة وأسعارها ──────────────────────────────────
    // تحديد المنطقة (عمان / الزرقاء والسلط / قطع فقط) والتحقق من كونها
    // داخل الأردن يتم بالكامل عبر OpenAI (انظر detectServiceArea أدناه)
    // بدون أي قوائم مناطق مبرمجة هنا.

    // ─── أسماء المالك المعروفة للبوت ─────────────────────────────
    private const OWNER_NAMES     = ['رمزي'];

    /**
     * فحص أرقام المسؤولين من .env أو قاعدة البيانات (نسخة مرنة)
     */
    private function isOwner(string $phone): bool
    {
        $ownerPhonesString = getenv('OWNER_PHONE') ?: '+962796932863,+962781692856';
        $allowedPhones = explode(',', $ownerPhonesString);
        
        $cleanReceived = preg_replace('/[^0-9]/', '', $phone);
        
        foreach ($allowedPhones as $owner) {
            $cleanOwner = preg_replace('/[^0-9]/', '', trim($owner));
            if (substr($cleanReceived, -9) === substr($cleanOwner, -9)) {
                return true;
            }
        }
        
        try {
            $stmt = $this->db->prepare(
                "SELECT phone FROM users WHERE role='admin' AND phone IS NOT NULL LIMIT 10"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dbPhone) {
                $cleanDb = preg_replace('/[^0-9]/', '', (string)$dbPhone);
                if (substr($cleanReceived, -9) === substr($cleanDb, -9)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            error_log("[isOwner] DB check failed: " . $e->getMessage());
        }

        return false;
    }

    public function __construct()
    {
        $this->db = getDB();
        $this->wa = new WhatsApp();
        $this->ai = new OpenAI();
        $this->kb = new KnowledgeBase();
        $this->ps  = new ProductSearch();
        $this->rag = new RAG($this->db);
    }

    public function handle(array $msg, string $channel = 'whatsapp'): void
    {
        $phone       = trim($msg['phone'] ?? '');
        $text        = trim($msg['text'] ?? '');
        $type        = $msg['type'] ?? 'text';
        $mediaId     = $msg['media_id'] ?? null;
        $waId        = $msg['wa_id'] ?? null;
        $quotedWaId  = $msg['quoted_wa_id']  ?? null;   // ID الرسالة المسحوبة
        $quotedBody  = $msg['quoted_body']   ?? null;   // نص الرسالة المسحوبة

        // نحفظ الـ quoted context كـ property مؤقتة عشان يقدر يوصلها handleMohammad
        // و handleAbdullah بدون ما نغير signatures الدوال الكثيرة
        $this->_quotedBody = $quotedBody;

        if (!$phone || !preg_match('/^\d{7,15}$/', $phone)) return;
        if ($type === 'reaction') return;
        $this->_alertPhone = $phone;

        try {
            $globalStatus = $this->db
                ->query("SELECT setting_value FROM settings WHERE setting_key='global_bot_status' LIMIT 1")
                ->fetchColumn();
            if ($globalStatus === 'off') return;

            // ══════════════════════════════════════════════════════
            //  👑 أوامر المالك رمزي الموسّعة
            // ══════════════════════════════════════════════════════
            if ($this->isOwner($phone)) {

                // ═══════════════════════════════════════════════════
                //  ✅ [FIX-20] معالجة الرسائل الصوتية من رمزي
                //  إذا رمزي بعت صوت: نفرّغه ونعامله كنص كامل
                // ═══════════════════════════════════════════════════
                if ($type === 'audio' && $mediaId) {
                    $transcribed = $this->transcribeOwnerAudio($mediaId);
                    if ($transcribed === '') {
                        $this->wa->sendText($phone, "⚠️ لم أتمكن من فهم الرسالة الصوتية، حاول كتابة الأمر نصاً.");
                        return;
                    }
                    // أخبر رمزي شو فهمنا منه (شفافية)
                    $this->wa->sendText($phone, "🎙️ فهمت: \"{$transcribed}\"");
                    // تحويل النص المفرّغ لمتغير $text وإكمال المعالجة
                    $text = $transcribed;
                    $type = 'text';
                    // سقط للأسفل → بكمل معالجة $text بشكل طبيعي
                }

                // معالجة صورة من المالك (للقطع)
                if ($type === 'image' && $mediaId) {
                    $this->handleOwnerPartImage($phone, $mediaId);
                    return;
                }

                if ($text === '') return;

                // ✅ لما رمزي يكتب "مرحبا" أو تحية → إرسال قائمة المالك
                if ($this->isSimpleGreeting($text)) {
                    $this->sendOwnerMenu($phone);
                    return;
                }

                // ✅ تصحيح الأخطاء الإملائية (ما عدا الرد على الزبائن)
                if (!preg_match('/رد#\d+/u', $text)) {
                    $text = $this->normalizeOwnerCommand($text);
                }

                // ✅ أوامر المالك بالأزرار
                if ($this->handleOwnerButtonCommand($phone, $text)) return;

                // ✅ محادثة حرة مع عبدالله أو محمد
                if ($this->handleOwnerChat($phone, $text)) return;

                // ── قائمة الأوامر ──────────────────────────────────
                if (str_contains($text, 'مساعدة') || str_contains($text, 'أوامر') || $text === 'help') {
                    $this->sendOwnerMenu($phone);
                    return;
                }

                // ── رد على زبون ────────────────────────────────────
                if (preg_match('/^(عبدالله|محمد)?\s*:?\s*رد#(\d+)\s*:\s*(.+)$/su', trim($text), $m)) {
                    $botName = trim($m[1] ?? '');
                    $notifId = (int)$m[2];
                    $answer  = trim($m[3]);
                    $this->sendOwnerAnswer($notifId, $answer, true, $botName);
                    return;
                }

                return;

            } // نهاية أوامر المالك

            $customer = $this->getOrCreateCustomer($phone);
            $conv     = $this->getOrCreateConversation((int)$customer['id']);
            $convId   = (int)$conv['id'];

            // ── [FIX-13] ميزة النسيان التلقائي (24 ساعة) ───────────
            $lastActivity = strtotime($conv['last_msg_at'] ?? 'now');
            if ((time() - $lastActivity) > 86400) {
                $this->updateConvState($convId, self::STATE_IDLE, []);
                $this->resumeConversation($convId);
                $this->sendWelcome($phone, $convId);
                $this->db->prepare("UPDATE conversations SET last_msg_at=NOW() WHERE id=?")->execute([$convId]);
                return;
            }

            if ($waId) $this->safeMarkRead($waId);

            if (!empty($conv['bot_paused']) || ($conv['status'] ?? '') === 'waiting_owner_answer') {
                $this->saveMessage($convId, 'customer', $type, $text ?: '[رسالة]');
                return;
            }

            if ($waId) {
                $dup = $this->db->prepare("SELECT id FROM messages WHERE conversation_id=? AND wa_message_id=? LIMIT 1");
                $dup->execute([$convId, $waId]);
                if ($dup->fetchColumn()) return;
            }

            // 🛡️ [FIX] كشف تكرار على مستوى محتوى الرسالة (مش بس wa_message_id) —
            // لو الزبون ضغط إرسال مرتين بالغلط (دبل-تاب/تأخر شبكة)، الرسالة الثانية
            // بتوصل بـ wa_message_id مختلف فما كانت تنكشف بالفحص فوق، وكانت تتعالج
            // كأنها رد جديد على سؤال تالي (مثلاً تتفسر كمحاولة رقم هاتف) وتطلع رسالة
            // تحذير مربكة تحس الزبون إنه البوت "عالق". هون نتجاهلها بصمت لو نفس النص
            // بالضبط وصل خلال أقل من 8 ثواني من آخر رسالة نص من نفس الزبون.
            if ($type === 'text' && trim((string)$text) !== '') {
                $lastMsg = $this->db->prepare(
                    "SELECT body, created_at FROM messages
                     WHERE conversation_id=? AND sender='customer' AND msg_type='text'
                     ORDER BY id DESC LIMIT 1"
                );
                $lastMsg->execute([$convId]);
                $last = $lastMsg->fetch(PDO::FETCH_ASSOC);
                if ($last && trim((string)$last['body']) === trim((string)$text)
                    && (time() - strtotime($last['created_at'])) < 8) {
                    $this->saveMessage($convId, 'customer', 'text', $text, null, null, $waId);
                    return;
                }
            }

            $this->checkSessionTimeout($conv, $convId);
            $conv  = $this->getOrCreateConversation((int)$customer['id']);
            $state = $conv['state'] ?? self::STATE_IDLE;
            $draft = json_decode($conv['order_draft'] ?? '{}', true) ?: [];

            if ($state !== self::STATE_IDLE && $type === 'text') {
                $normalized = $this->normalizeArabicText($text);
                if (in_array($normalized, ['الغاء','إلغاء','تراجع','خروج','cancel','0'], true)) {
                    $this->updateConvState($convId, self::STATE_IDLE, []);
                    $this->reply($phone, $convId, "تمام، تم الإلغاء 👍\nكيف أقدر أساعدك؟");
                    return;
                }
            }

            if ($type === 'audio' && $mediaId) {
                $text = $this->processAudio($mediaId, $convId, $waId);
                if ($text === '') {
                    $this->reply($phone, $convId,
                        "⚠️ ما قدرت افهم الرسالة الصوتية بوضوح 😅\nممكن تكتبها لي نص أو تعيد تسجيلها بصوت أوضح؟"
                    );
                    return;
                }
                $type = 'text';

                // 🎙️ الاسم لازم يكون كتابة (التفريغ الصوتي غلط كتير بالأسماء: "الله يحكي معك")
                if ($state === self::STATE_MAINT_NAME) {
                    $this->reply($phone, $convId, "🙏 اكتب اسمك كتابة من فضلك (مش صوت) عشان نسجله صح.");
                    return;
                }
                // 🎙️ نعرض للزبون شو فهمنا منه ليتأكد أو يصحح — بدل ما نبني على تفريغ ممكن يكون غلط
                if (mb_strlen($text) > 3) {
                    $this->reply($phone, $convId, "🎙️ فهمت منك: \"{$text}\"\n_(إذا مو صح اكتبها نص وبصححها)_");
                }
            }

            if ($type === 'location') {
                $this->saveMessage($convId, 'customer', 'location', $text, null, null, $waId);
                $this->db->prepare('UPDATE conversations SET last_msg_at=NOW() WHERE id=?')->execute([$convId]);
                if ($state === self::STATE_MAINT_LOCATION) {
                    $locationData      = json_decode($text, true);
                    $draft['pin_lat']  = $locationData['lat'] ?? null;
                    $draft['pin_lng']  = $locationData['lng'] ?? null;
                    $draft['location'] = 'تم إرسال الموقع الجغرافي (GPS)';
                    $this->finalizeMaintenanceRequest($phone, $convId, (int)$customer['id'], $draft);
                    return;
                }
                // ✅ موقع مُرسل خارج فلو الصيانة (زبون بعت لوكيشن من غير ما نطلبه) —
                // نشكره، نحوّله لرمزي مباشرة، ونعطيه رقم التواصل لو حب يرن مباشرة.
                $locationData = json_decode($text, true);
                $lat = (float)($locationData['lat'] ?? $locationData['latitude'] ?? 0);
                $lng = (float)($locationData['lng'] ?? $locationData['longitude'] ?? 0);
                if ($lat && $lng) {
                    $this->sendLocationToOwners($lat, $lng, 'موقع زبون', $phone);
                    $this->notifyAdmins("📍 *زبون بعت موقعه (بدون طلب صيانة)*\n📱 رقم الزبون: {$phone}\n🕐 الوقت: " . date('Y-m-d H:i'));
                }
                $this->reply($phone, $convId,
                    "شكراً لمشاركة موقعك 🙏\nرح أقوم بتوصيله لفريق الصيانة مباشرة.\n"
                    . "📞 إذا حبيت تتواصل مباشرة أو تتصل: *" . self::SUPPORT_PHONE . "*"
                );
                return;
            }

            if ($type === 'image' && $mediaId) {
                // ✅ تنزيل الصورة مرة واحدة هون، وتخزين مسارها الحقيقي بقاعدة البيانات
                // (بدل ما تتسجل الرسالة بمسار فاضي وتتحذف الصورة لاحقاً) — هيك رمزي
                // يقدر يشوف الصورة من لوحة التحكم، ومافي تنزيل مكرر للصورة نفسها.
                // 🛡️ ضد سبام الصور: لو الزبون بعت عدة صور ورا بعض (خلال 25 ثانية) نعالج الأولى بس
                // ونحفظ الباقي بصمت — قبل هيك كل صورة كانت تجيب "جاري البحث" + "ما تأكدت" مكررة.
                $burst = $this->db->prepare(
                    "SELECT id FROM messages WHERE conversation_id=? AND sender='customer' AND msg_type='image'
                     AND created_at >= NOW() - INTERVAL 25 SECOND LIMIT 1"
                );
                $burst->execute([$convId]);
                $isImageBurst = (bool)$burst->fetchColumn();

                $imagePath = $this->wa->downloadMedia($mediaId);
                $this->saveMessage($convId, 'customer', 'image', $text ?: '[صورة]', $imagePath, null, $waId);
                if ($isImageBurst) {
                    $this->db->prepare('UPDATE conversations SET last_msg_at=NOW() WHERE id=?')->execute([$convId]);
                    return;
                }
                $this->db->prepare('UPDATE conversations SET last_msg_at=NOW() WHERE id=?')->execute([$convId]);

                if ($state === self::STATE_WAITING_PART_IMAGE) {
                    // الزبون اختار صراحة "استفسار عن قطعة" من القائمة → ما في داعي تصنيف
                    $this->handlePartImage($phone, $convId, $mediaId, $draft, $imagePath);
                    return;
                }

                // 🔎 خطوة تصنيف أولى: قطعة / جهاز كامل / إعلان-سكرين شوت / معلومات بنكية حساسة
                $this->routeClassifiedImage($phone, $convId, $mediaId, $text, (int)$customer['id'], $draft, $imagePath);
                return;
            }

            $this->saveMessage($convId, 'customer', $type, $text, null, null, $waId);
            if ($text === '') {
                $this->reply($phone, $convId, 'ما فهمت رسالتك، ابعتها نص من فضلك 🙏');
                return;
            }
            $this->db->prepare('UPDATE conversations SET last_msg_at=NOW() WHERE id=?')->execute([$convId]);

            // 📎 فيديو / ستيكر / ملف: ما بنرجّع قائمة الترحيب — بنرد مرة وحدة بس
            if ($this->isUnsupportedMediaText($text)) {
                if (!$this->botRecentlySaid($convId, 'ما قدرت أفتح هالملف', 120)) {
                    $this->reply($phone, $convId,
                        "وصلتني رسالتك 🙏 بس ما قدرت أفتح هالملف.\nممكن تكتب لي المشكلة نص، أو تبعت صورة أو رسالة صوتية؟"
                    );
                }
                return;
            }

            // 🙏 "اوك / تمام / إن شاء الله / شكراً" بعد أي رد → رد قصير، بدون قائمة الترحيب
            // (بس لو الزبون مش بنص خطوة بتسأله سؤال — ما نبلع جواب مطلوب)
            if ($state === self::STATE_IDLE && empty($draft['awaiting_confirm_reply'])
                && !$this->botRecentlySaid($convId, 'تثبيت الطلب', 180)
                && $this->isAcknowledgment($text)) {
                if (!$this->botRecentlySaid($convId, 'العفو', 90)) {
                    $this->reply($phone, $convId, "العفو 🙏 إذا احتجت أي شي ثاني احنا موجودين.");
                }
                return;
            }

            // ════════════════════════════════════════════════════════
            //  التوجيه الرئيسي
            // ════════════════════════════════════════════════════════
            $isButton  = ($type === 'interactive' || $this->isButtonPayload($text));

            // 🛑✏️ إلغاء / تعديل طلب مفتوح — بيشتغل مع محمد وعبدالله (نص فقط، مو أزرار،
            // ومو وسط خطوات جمع بيانات طلب جديد).
            if (!$isButton && ($state === self::STATE_IDLE || !empty($draft['edit_field']))) {
                if ($this->handleModifyRequest($phone, $convId, (int)$customer['id'], $text, $draft)) return;
                if ($this->handleCancelOrUrgent($phone, $convId, (int)$customer['id'], $text)) return;
            }
            $activeBot = $draft[self::DRAFT_ACTIVE_BOT] ?? null;

            if ($state === self::STATE_WAITING_DEVICE_IMG_INTENT) {
                $this->handleDeviceImageIntent($phone, $convId, $text, (int)$customer['id'], $draft);
                return;
            }

            if ($activeBot === self::BOT_ABDULLAH || $isButton) {
                $this->handleAbdullah($phone, $convId, $text, (int)$customer['id'], $state, $conv, $draft);
                return;
            }

            if ($activeBot === self::BOT_MOHAMMAD) {
                $this->handleMohammad($phone, $convId, $text, (int)$customer['id'], $state, $conv, $draft);
                return;
            }

            $welcomeStmt = $this->db->prepare(
                "SELECT id FROM messages WHERE conversation_id=? AND sender='bot' AND body LIKE '%قائمة عبدالله%' LIMIT 1"
            );
            $welcomeStmt->execute([$convId]);
            $welcomeSent = $welcomeStmt->fetchColumn();

            // أول رسالة بالمحادثة: لو تحية → قائمة الترحيب. لو فيها محتوى فعلي (وصف عطل، سؤال،
            // تفريغ صوتي) → منجاوب عليها مباشرة (محمد) بدل ما نتجاهل كلام الزبون ونرمي قائمة.
            if (!$welcomeSent && $this->isSimpleGreeting($text)) {
                $this->sendWelcome($phone, $convId);
                return;
            }

            // ✅ شبكة أمان: ما نسمح لفحص "تحية قصيرة" يصفّر محادثة عندها state نشط
            // (لو حصل بكرا نفس خطأ نسيان DRAFT_ACTIVE_BOT بحالة جديدة، هذا بيمنع تكرار نفس العطل)
            if ($state === self::STATE_IDLE && $this->isSimpleGreeting($text)) {
                $this->sendWelcome($phone, $convId);
                return;
            }

            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
            $this->handleMohammad($phone, $convId, $text, (int)$customer['id'], $state, $conv, $draft);

        } catch (Throwable $e) {
            error_log("[Agent::handle] $phone: " . $e->getMessage());
            try { $this->wa->sendText($phone, 'صار خطأ مؤقت، رح نرجعلك قريباً 🙏'); } catch (Throwable) {}
        }
    }

    // ═══════════════════════════════════════════════════════════════

}
