<?php
// تعريف رقم المالك ديناميكياً من ملف .env (تبقى كما هي للتوافق)
if (!defined('OWNER_PHONE')) {
    define('OWNER_PHONE', getenv('OWNER_PHONE') ?: '+962797996581');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/ChannelInterface.php';
require_once __DIR__ . '/WhatsApp.php';
require_once __DIR__ . '/Messenger.php';
require_once __DIR__ . '/Instagram.php';
require_once __DIR__ . '/OpenAI.php';
require_once __DIR__ . '/bots/KhaledBotTrait.php';
require_once __DIR__ . '/bots/AbdulrahmanBotTrait.php';

/**
 * ═══════════════════════════════════════════════════════════════
 *  Agent.php — الموجّه (Router) + الخدمات المشتركة
 *  منطق خالد بالكامل صار بملف: bots/KhaledBotTrait.php
 *  منطق عبدالرحمن بالكامل صار بملف: bots/AbdulrahmanBotTrait.php
 *  هون بس التوجيه، أوامر معاذ، وكل شي مشترك بين البوتين.
 *
 *  ─── سجل التعديلات ───────────────────────────────────────────
 *  FIX-1..19: (سابقة - راجع النسخة الأصلية)
 *  FIX-20: دعم الرسائل الصوتية من معاذ — البوت يفرّغ الصوت
 *          ويعالجه تماماً كأمر نصي (أوامر لوحة التحكم + محادثة
 *          حرة مع خالد أو عبدالرحمن)
 *  FIX-21: إصلاح قسم "قطع طلبوها وما عندنا" — يجيب القطع
 *          الحقيقية من جدول notifications (حقل question) بدل
 *          البحث بكلمة "قطعة" اللي ما بتطلع نتائج عملية،
 *          + يعرض آخر 10 notifications بدل تصفية فارغة
 * ═══════════════════════════════════════════════════════════════
 */
class Agent
{
    use KhaledBotTrait;
    use AbdulrahmanBotTrait;

    private PDO             $db;
    private ChannelInterface $wa;   // القناة الحالية (واتساب/ماسنجر/انستقرام) — نفس الاسم $wa
                                     // لتجنب تعديل عشرات الاستدعاءات $this->wa-> بالملف والـ traits
    private string           $channel = 'whatsapp'; // 'whatsapp' | 'messenger' | 'instagram'
    private OpenAI        $ai;

    // ✅ قناة ثابتة مخصصة فقط لإشعارات معاذ/الأدمنز — دايماً واتساب بغض النظر
    // من وين جاءت رسالة الزبون (واتساب/ماسنجر/انستقرام). السبب: رقم معاذ
    // (OWNER_PHONE) هو رقم هاتف عادي، ومش صالح كـ recipient id بـ Graph API
    // لماسنجر/انستقرام (بيحتاجوا PSID/IGSID حقيقي من محادثة فعلية بينه وبين
    // الصفحة) — فلو استخدمنا $this->wa (المتغيرة حسب القناة) بترجع خطأ صامتة
    // ("Invalid recipient id") وتضيع الإشعارات القادمة من فيسبوك/انستقرام.
    private WhatsApp $ownerWa;

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
    private const BOT_KHALED = 'خالد';
    private const BOT_ABDULRAHMAN = 'عبدالرحمن';

    // ─── حالات الفلو ─────────────────────────────────────────────
    private const STATE_IDLE                   = 'idle';
    private const STATE_MAINT_CONFIRM          = 'maint_confirm';
    private const STATE_MAINT_NAME             = 'maint_name';          // اسم الزبون (بعد التأكيد، قبل رقم الهاتف/الجهاز)
    private const STATE_MAINT_PHONE_EARLY      = 'maint_phone_early';   // رقم هاتف الزبون — بيتسأل بداية الفلو (قبل الموقع)
    private const STATE_MAINT_DEVICE           = 'maint_device';
    private const STATE_MAINT_BRAND            = 'maint_brand';
    private const STATE_MAINT_ISSUE_CUSTOM     = 'maint_issue_custom';
    private const STATE_MAINT_LOCATION         = 'maint_location';
    // ✅ خطوة إضافية: لو الزبون جانا من فيسبوك ماسنجر أو انستقرام (مو واتساب)،
    // معرّفه بالنظام (PSID/IGSID) مش رقم هاتف حقيقي — فلازم نسأله عن رقمه
    // صراحة قبل ما نثبّت الطلب، عشان الفني يقدر يتواصل معه.
    private const STATE_MAINT_CONTACT_PHONE    = 'maint_contact_phone';
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
    // (defaultAbdulrahmanRules) اللي يترزرع بالداشبورد أول مرة.
    public const VISIT_PRICE_MIN  = 10;
    public const VISIT_PRICE_MAX  = 15;
    private const WORKING_HOURS   = '10 صباحاً حتى 9 مساءً، طوال أيام الأسبوع';
    private const SHOP_LOCATION   = 'عمان، الهاشمي الشمالي';
    private const SERVICE_AREA    = 'كل الأردن';
    public const SUPPORT_PHONE    = '+962 7 9799 6581'; // الرقم الموحّد لكل تواصل فوري أو شراء قطعة

    // ─── مناطق الصيانة وأسعارها ──────────────────────────────────
    // تحديد المنطقة (عمان / الزرقاء والسلط / قطع فقط) والتحقق من كونها
    // داخل الأردن يتم بالكامل عبر OpenAI (انظر detectServiceArea أدناه)
    // بدون أي قوائم مناطق مبرمجة هنا.

    // ─── أسماء المالك المعروفة للبوت ─────────────────────────────
    private const OWNER_NAMES     = ['معاذ'];

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

    /**
     * @param string $channel 'whatsapp' | 'messenger' | 'instagram'
     *                        افتراضياً 'whatsapp' حفاظاً على التوافق مع كل
     *                        الأماكن يلي بتعمل `new Agent()` بدون بارامتر
     *                        (webhook.php الحالي، send_followups.php، إلخ).
     */
    public function __construct(string $channel = 'whatsapp')
    {
        $this->db      = getDB();
        $this->channel = in_array($channel, ['whatsapp', 'messenger', 'instagram'], true) ? $channel : 'whatsapp';
        $this->wa      = match ($this->channel) {
            'messenger' => new Messenger(),
            'instagram' => new Instagram(),
            default     => new WhatsApp(),
        };
        // ✅ إشعارات معاذ دايماً عبر واتساب — راجع تعليق تعريف $ownerWa فوق
        $this->ownerWa = ($this->channel === 'whatsapp') ? $this->wa : new WhatsApp();
        $this->ai = new OpenAI($this->channel);
    }

    // ═══════════════════════════════════════════════════════════════
    //  دوال مساعدة لإرسال الإشعارات لأرقام المالك المتعددة
    // ═══════════════════════════════════════════════════════════════
    
    // ── إعدادات الحل الجذري لمشكلة نافذة الـ 24 ساعة ──────────────────────
    // بعد ما تعتمد Meta الـ Template، حط اسمه هون. لحد ما ينعتمد، خليه
    // فاضي '' وبيستمر النظام يشتغل زي القديم (رسائل حرة فقط) دون أي كسر.
    private const OWNER_TEMPLATE_NAME = 'owner_notify_generic'; // ✅ معتمد من Meta بتاريخ 2026-08-02
    private const OWNER_TEMPLATE_LANG = 'ar';

    /**
     * هل آخر رسالة استلمناها من هاد الرقم (رقم المالك) خلال 24 ساعة؟
     * لو ما في أي سجل سابق، نعتبره خارج النافذة (أمان أكبر: يفضّل Template).
     */
    /** يرجّع كائن القناة الصحيح (واتساب/ماسنجر/انستقرام) حسب قيمة channel المخزنة بجدول conversations،
     *  يُستخدم لما نرد على الزبون من سياق تاني غير قناته الأصلية (مثلاً: معاذ بيرد من واتسابه
     *  على زبون جاء أصلاً من فيسبوك) — الرد لازم يرجع لنفس قناة الزبون الأصلية. */
    private function resolveChannelInstance(string $channel): ChannelInterface
    {
        return match ($channel) {
            'messenger' => new Messenger(),
            'instagram' => new Instagram(),
            default     => new WhatsApp(),
        };
    }

    private function isOwnerWithinWindow(string $ownerPhone): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1"
            );
            $stmt->execute(["owner_last_msg_at:$ownerPhone"]);
            $lastSeen = $stmt->fetchColumn();

            if (!$lastSeen) return false;

            $diffHours = (time() - strtotime($lastSeen)) / 3600;
            return $diffHours < 24;
        } catch (Throwable $e) {
            error_log("[isOwnerWithinWindow] $ownerPhone: " . $e->getMessage());
            return false; // بحالة الشك، الأفضل استخدام Template (أضمن)
        }
    }

    /**
     * الدالة الموحدة لإشعار المالك — الحل الجذري:
     * - داخل نافذة الـ 24 ساعة → رسالة حرة عادية (زي ما كان الوضع).
     * - خارج النافذة → Template معتمد من Meta (يوصل دايماً، بلا استثناء).
     * إذا الـ Template لسا ما انسجل/انعتمد (OWNER_TEMPLATE_NAME فاضي)،
     * بيرجع تلقائياً لمحاولة الرسالة الحرة (سلوك النظام القديم) بدل ما
     * يفشل بصمت.
     */
    private function sendToOwners(string $message): void
    {
        $owners = explode(',', OWNER_PHONE);
        foreach ($owners as $owner) {
            $owner = trim($owner);
            if ($owner === '') continue;

            try {
                if (self::OWNER_TEMPLATE_NAME !== '' && !$this->isOwnerWithinWindow($owner)) {
                    // خارج النافذة + Template معتمد → استخدمه (مضمون الوصول)
                    $this->ownerWa->sendTemplate(
                        $owner,
                        self::OWNER_TEMPLATE_NAME,
                        self::OWNER_TEMPLATE_LANG,
                        [$message]
                    );
                } else {
                    $this->ownerWa->sendText($owner, $message);
                }
            } catch (Throwable $e) {
                error_log("[sendToOwners] Failed to send to $owner: " . $e->getMessage());
                // محاولة أخيرة: لو فشلت الرسالة الحرة تحديداً بسبب انتهاء
                // النافذة (كود 131047) ونعتمد Template، جرب Template كـ fallback
                if (self::OWNER_TEMPLATE_NAME !== '' && str_contains($e->getMessage(), '131047')) {
                    try {
                        $this->ownerWa->sendTemplate($owner, self::OWNER_TEMPLATE_NAME, self::OWNER_TEMPLATE_LANG, [$message]);
                    } catch (Throwable $e2) {
                        error_log("[sendToOwners] Template fallback also failed for $owner: " . $e2->getMessage());
                    }
                }
            }
        }
    }

    private function sendLocationToOwners(float $lat, float $lng, string $device, string $phone): void
    {
        $owners = explode(',', OWNER_PHONE);
        foreach ($owners as $owner) {
            $owner = trim($owner);
            if ($owner === '') continue;
            try {
                // رسالة الموقع (type: location) نوع محتوى غني ما بينبعت عبر
                // Template — إذا المالك خارج نافذة الـ 24 ساعة، أول شي نبعتله
                // Template نصي يفتح النافذة عملياً (تنبيه)، وبعدها نحاول
                // الموقع الفعلي (غالباً برضو برفض إذا فعلاً برا النافذة، لكن
                // ما رح يضيع أي إشعار لأن sendToOwners فوق بترسل نص بديل دايماً).
                if (self::OWNER_TEMPLATE_NAME !== '' && !$this->isOwnerWithinWindow($owner)) {
                    $this->ownerWa->sendTemplate(
                        $owner,
                        self::OWNER_TEMPLATE_NAME,
                        self::OWNER_TEMPLATE_LANG,
                        ["طلب صيانة جديد فيه موقع GPS — الجهاز: $device، الزبون: $phone. افتح لوحة التحكم لمشاهدة الموقع."]
                    );
                }
                $this->ownerWa->sendLocation($owner, $lat, $lng, $device, $phone);
            } catch (Throwable $e) {
                error_log("[sendLocationToOwners] Failed to send location to $owner: " . $e->getMessage());
            }
        }
    }

    /** يبعت الصورة الفعلية (مش بس نص) لمعاذ وباقي الأدمنز على واتساب مباشرة، عشان يقدر يشوفها ويرد */
    private function forwardImageToOwners(string $imagePath, string $caption): void
    {
        try {
            $imageUrl = $this->localPathToPublicUrl($imagePath);
            if (!$imageUrl) {
                // ما قدرنا نبني رابط عام للصورة → على الأقل نص واضح بدل ما نسكت
                $this->notifyAdmins($caption . "\n⚠️ (تعذّر إرفاق الصورة تلقائياً، راجع لوحة التحكم)");
                return;
            }

            $owners = explode(',', OWNER_PHONE);
            foreach ($owners as $owner) {
                $owner = trim($owner);
                if ($owner === '') continue;
                try {
                    // نفس مبدأ الموقع: الصورة نفسها ما بتنبعت عبر Template،
                    // فإذا المالك خارج النافذة نبعتله تنبيه Template أولاً
                    // (يوصل مضمون)، وبعدين نحاول إرسال الصورة الفعلية.
                    if (self::OWNER_TEMPLATE_NAME !== '' && !$this->isOwnerWithinWindow($owner)) {
                        $this->ownerWa->sendTemplate(
                            $owner,
                            self::OWNER_TEMPLATE_NAME,
                            self::OWNER_TEMPLATE_LANG,
                            [$caption !== '' ? $caption : 'وصلت صورة جديدة من زبون، افتح لوحة التحكم لمشاهدتها.']
                        );
                    }
                    $this->ownerWa->sendImage($owner, $imageUrl, $caption);
                } catch (Throwable $e) {
                    error_log("[forwardImageToOwners] Failed to send to $owner: " . $e->getMessage());
                }
            }

            $ownersList = array_map('trim', explode(',', OWNER_PHONE));
            $stmt = $this->db->query("SELECT phone FROM users WHERE role='admin' AND phone IS NOT NULL AND phone!=''");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminPhone) {
                $adminPhone = trim($adminPhone);
                if (in_array($adminPhone, $ownersList, true)) continue;
                try {
                    $this->ownerWa->sendImage($adminPhone, $imageUrl, $caption);
                } catch (Throwable $e) {
                    error_log("[forwardImageToOwners] Failed to send to admin $adminPhone: " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[forwardImageToOwners] ' . $e->getMessage());
            $this->notifyAdmins($caption);
        }
    }

    /** يحوّل مسار محلي للصورة (uploads/...) لرابط عام يقدر واتساب يوصله */
    private function localPathToPublicUrl(string $imagePath): ?string
    {
        if ($imagePath === '' || !file_exists($imagePath)) return null;

        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $realPath = realpath($imagePath);
        if ($docRoot && $realPath && str_starts_with($realPath, $docRoot)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? (defined('APP_HOST') ? APP_HOST : '');
            if ($host === '') return null;
            $rel = substr($realPath, strlen($docRoot));
            return $scheme . '://' . $host . '/' . ltrim(str_replace('\\', '/', $rel), '/');
        }

        // ✅ خارج سياق ويب (مثلاً استدعاء من Cron) → ابني الرابط من إعداد ثابت لو موجود
        if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
            $webhookDir = realpath(__DIR__ . '/..');
            if ($webhookDir && $realPath && str_starts_with($realPath, $webhookDir)) {
                $rel = substr($realPath, strlen($webhookDir));
                return rtrim(APP_BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            }
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  تصحيح أوامر المالك باستخدام الذكاء الاصطناعي
    // ═══════════════════════════════════════════════════════════════
    private function normalizeOwnerCommand(string $input): string
    {
        try {
            $response = trim((string)$this->ai->chat([
                ['role' => 'system', 'content' =>
                    "أنت مساعد يفهم الأوامر العربية المكتوبة بأخطاء إملائية أو عامية عربية.\n"
                    . "مهمتك: اقرأ الرسالة وحوّلها لأقرب أمر من القائمة التالية فقط، بدون أي إضافة:\n\n"
                    . "- شو بعنا اليوم\n"
                    . "- شو طلبوا الزبائن\n"
                    . "- في مشاكل أو ملاحظات\n"
                    . "- أكثر الأجهزة اللي طلبوا صيانتها\n"
                    . "- أكثر القطع اللي طلبوها وما كانت عندنا\n"
                    . "- أكثر استفسار الزبائن\n"
                    . "- كم عندي حبة من [اسم القطعة]\n"
                    . "- رد#[رقم]: [الجواب]\n"
                    . "- أوامر\n\n"
                    . "إذا الرسالة تشبه أمراً من القائمة، رجّعه بنفس الصيغة.\n"
                    . "إذا فيها اسم قطعة مثل 'كم قطعه ميزان غاز عنا'، رجّع 'كم عندي حبة من ميزان غاز'.\n"
                    . "إذا ما تشابهت مع أي أمر، رجّع النص الأصلي كما هو بدون تغيير."
                ],
                ['role' => 'user', 'content' => $input],
            ], temp: 0.0, maxTokens: 60));

            return ($response !== '' && mb_strlen($response) < 200) ? $response : $input;
        } catch (Throwable $e) {
            return $input;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  قائمة المالك معاذ
    // ═══════════════════════════════════════════════════════════════
    private function sendOwnerMenu(string $phone): void
    {
        $msg = "أهلاً أستاذ معاذ 👋\n\n"
             . "هاي قائمة اللوحة الخاصة فيك:\n\n"
             . "📊 كم طلب صار اليوم\n"
             . "📅 كم طلب هالشهر\n"
             . "📋 آخر الطلبات\n"
             . "👤 كم زبون جديد\n"
             . "📈 أكثر مشاكل الأجهزة\n"
             . "📦 قطع طلبوها وما عندنا\n"
             . "📝 ملاحظات الزباين\n"
             . "🔍 كم قطعة [اسم] أو ابعت صورة قطعة\n\n"
             . "━━━━━━━━━━━━━━━━━━━━\n"
             . "أو كلم *خالد* أو *عبدالرحمن* مباشرة 👇";

        try {
            $this->wa->sendList(
                $phone,
                $msg,
                "☰ اختر أمر",
                [[
                    'title' => 'لوحة التحكم',
                    'rows'  => [
                        ['id' => 'owner_today_orders',   'title' => 'كم طلب اليوم',         'description' => 'عدد طلبات الصيانة اليوم'],
                        ['id' => 'owner_month_orders',   'title' => 'كم طلب هالشهر',        'description' => 'إجمالي طلبات الشهر'],
                        ['id' => 'owner_last_orders',    'title' => 'آخر الطلبات',          'description' => 'آخر 5 طلبات مسجلة'],
                        ['id' => 'owner_new_customers',  'title' => 'كم زبون جديد',         'description' => 'الزبائن الجدد اليوم والشهر'],
                        ['id' => 'owner_top_devices',    'title' => 'أكثر مشاكل الأجهزة',  'description' => 'أكثر الأجهزة طلباً للصيانة'],
                        ['id' => 'owner_missing_parts',  'title' => 'قطع طلبوها ما عندنا', 'description' => 'القطع المطلوبة وغير المتوفرة'],
                        ['id' => 'owner_customer_notes', 'title' => 'ملاحظات الزباين',     'description' => 'آخر ملاحظات واستفسارات'],
                        ['id' => 'owner_parts_inquiry',  'title' => 'استفسار عن قطعة',     'description' => 'ابعت اسم قطعة أو صورتها'],
                    ],
                ]],
                'لوحة معاذ'
            );
        } catch (Throwable $e) {
            error_log("[sendOwnerMenu] sendList failed: " . $e->getMessage());
            try { $this->wa->sendText($phone, $msg); } catch (Throwable) {}
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  ✅ [FIX-20] تفريغ الصوت الوارد من معاذ
    //  يُستدعى في بداية معالجة رسائل المالك عند type=audio
    // ═══════════════════════════════════════════════════════════════
    private function transcribeOwnerAudio(string $mediaId): string
    {
        try {
            $path = $this->wa->downloadMedia($mediaId);
            if (!$path) {
                error_log("[transcribeOwnerAudio] downloadMedia returned null for mediaId=$mediaId");
                return '';
            }
            $transcribed = trim($this->ai->transcribe($path));
            @unlink($path);
            return $transcribed;
        } catch (Throwable $e) {
            error_log("[transcribeOwnerAudio] " . $e->getMessage());
            return '';
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  معالجة أوامر المالك بالأزرار والنصوص
    // ═══════════════════════════════════════════════════════════════
    private function handleOwnerButtonCommand(string $phone, string $text): bool
    {
        // ── أوامر الأزرار ─────────────────────────────────────────
        $buttonCommands = [
            'owner_today_orders'   => 'كم طلب صار اليوم',
            'owner_month_orders'   => 'كم طلب هالشهر',
            'owner_last_orders'    => 'آخر الطلبات',
            'owner_new_customers'  => 'كم زبون جديد',
            'owner_top_devices'    => 'أكثر مشاكل الأجهزة',
            'owner_missing_parts'  => 'قطع طلبوها ما عندنا',
            'owner_customer_notes' => 'شو ملاحظات الزباين',
            'owner_parts_inquiry'  => 'استفسار قطعة',
        ];
        if (isset($buttonCommands[$text])) {
            $text = $buttonCommands[$text];
        }

        // ── كم طلب صار اليوم ───────────────────────────────────────
        if (str_contains($text, 'كم طلب صار اليوم') || str_contains($text, 'كم طلب اليوم') || str_contains($text, 'شو بعنا اليوم')) {
            $count = $this->db->query("SELECT COUNT(*) FROM service_requests WHERE DATE(created_at)=CURDATE()")->fetchColumn();
            $this->wa->sendText($phone, "📊 اليوم وصلنا *{$count}* طلب صيانة حتى اللحظة! 💪");
            return true;
        }

        // ── كم طلب هالشهر ──────────────────────────────────────────
        if (str_contains($text, 'كم طلب هالشهر') || str_contains($text, 'كم طلب الشهر')) {
            $count = $this->db->query("SELECT COUNT(*) FROM service_requests WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
            $this->wa->sendText($phone, "📅 هالشهر وصلنا *{$count}* طلب صيانة 💼");
            return true;
        }

        // ── آخر الطلبات ────────────────────────────────────────────
        if (str_contains($text, 'آخر الطلبات') || str_contains($text, 'اخر الطلبات') || str_contains($text, 'شو طلبوا الزبائن')) {
            $rows = $this->db->query(
                "SELECT order_number, customer_phone, product_name, issue_description, customer_location, status, created_at
                 FROM service_requests ORDER BY id DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) { $this->wa->sendText($phone, "ما في طلبات لحد الآن 🙂"); return true; }
            $msg2 = "📋 *آخر 5 طلبات:*\n\n";
            foreach ($rows as $r) {
                $msg2 .= "🔢 {$r['order_number']}\n"
                      . "📱 {$r['customer_phone']}\n"
                      . "🖥️ {$r['product_name']} — {$r['issue_description']}\n"
                      . "📍 {$r['customer_location']}\n"
                      . "📌 " . $this->mapServiceStatus((string)$r['status']) . "\n"
                      . "─────────────\n";
            }
            $this->wa->sendText($phone, rtrim($msg2));
            return true;
        }

        // ── كم زبون جديد ───────────────────────────────────────────
        if (str_contains($text, 'كم زبون جديد') || str_contains($text, 'كم زبون')) {
            $todayC = $this->db->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at)=CURDATE()")->fetchColumn();
            $monthC = $this->db->query("SELECT COUNT(*) FROM customers WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
            $totalC = $this->db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
            $this->wa->sendText($phone,
                "👤 *إحصائيات الزبائن:*\n\n"
                . "• اليوم: *{$todayC}* زبون جديد\n"
                . "• هالشهر: *{$monthC}* زبون\n"
                . "• الإجمالي: *{$totalC}* زبون 📊"
            );
            return true;
        }

        // ── أكثر مشاكل الأجهزة ─────────────────────────────────────
        if (str_contains($text, 'أكثر مشاكل الأجهزة') || str_contains($text, 'اكثر الاجهزه') || str_contains($text, 'أكثر الأجهزة')) {
            $data = $this->db->query(
                "SELECT product_name, COUNT(*) as c FROM service_requests GROUP BY product_name ORDER BY c DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (empty($data)) { $this->wa->sendText($phone, "ما في بيانات كافية بعد 📊"); return true; }
            $msg2   = "📈 *أكثر الأجهزة اللي طلبوا صيانتها:*\n\n";
            $emojis = ['🥇','🥈','🥉','4️⃣','5️⃣'];
            foreach ($data as $i => $d) {
                $msg2 .= ($emojis[$i] ?? '•') . " {$d['product_name']}: *{$d['c']}* طلب\n";
            }
            $issues = $this->db->query(
                "SELECT issue_description, COUNT(*) as c FROM service_requests WHERE issue_description IS NOT NULL AND issue_description!='' GROUP BY issue_description ORDER BY c DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($issues)) {
                $msg2 .= "\n🔧 *أكثر المشاكل تكراراً:*\n";
                foreach ($issues as $i => $iss) {
                    $msg2 .= ($emojis[$i] ?? '•') . " " . mb_substr($iss['issue_description'], 0, 40) . ": *{$iss['c']}* مرة\n";
                }
            }
            $this->wa->sendText($phone, $msg2);
            return true;
        }

        // ═══════════════════════════════════════════════════════════
        //  ✅ [FIX-21] قطع طلبوها وما كانت عندنا — منطق مُصلَح
        //
        //  المشكلة القديمة: كان البحث بكلمة "قطعة" في حقل question
        //  من notifications — وهذا ما بيطلع شي لأن الزبائن يسألون
        //  "عندكم مكثف 35؟" مش "قطعة مكثف"
        //
        //  الحل الجديد:
        //  1. notifications كلها (pending) = استفسارات زبائن لم تُجب
        //  2. notifications اللي ما كان فيها products = أسئلة مش
        //     موجودة بالمخزن (نجيب آخر 10)
        //  3. products اللي quantity=0 = نفدت من المخزن
        //  4. customer_notes اللي فيها بحث عن قطع
        // ═══════════════════════════════════════════════════════════
        if (str_contains($text, 'قطع طلبوها وما عندنا') || str_contains($text, 'قطع طلبوها ما عندنا') || str_contains($text, 'أكثر القطع') || str_contains($text, 'قطع ناقصة') || str_contains($text, 'قطع مش موجودة')) {

            $msg2 = "📦 *القطع اللي طلبها الزباين وما كانت عندنا:*\n\n";
            $hasData = false;

            // ── ١. آخر استفسارات pending من notifications ──────────
            try {
                $pendingInquiries = $this->db->query(
                    "SELECT customer_phone, question, created_at
                     FROM notifications
                     WHERE status = 'pending'
                     ORDER BY id DESC
                     LIMIT 10"
                )->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($pendingInquiries)) {
                    $hasData = true;
                    $msg2 .= "⏳ *استفسارات لسا ما تجاوب عليها:*\n";
                    foreach ($pendingInquiries as $n) {
                        $dateStr = date('d/m H:i', strtotime($n['created_at']));
                        $msg2 .= "• 📱 {$n['customer_phone']} — "
                              . mb_substr($n['question'], 0, 60)
                              . " ({$dateStr})\n";
                    }
                    $msg2 .= "\n";
                }
            } catch (Throwable $e) {
                error_log("[owner_missing_parts] pending: " . $e->getMessage());
            }

            // ── ٢. قطع نفدت من المخزن (quantity = 0) ──────────────
            try {
                $outOfStock = $this->db->query(
                    "SELECT name, code
                     FROM products
                     WHERE quantity = 0
                     ORDER BY id DESC
                     LIMIT 10"
                )->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($outOfStock)) {
                    $hasData = true;
                    $msg2 .= "❌ *قطع نفدت من المخزن:*\n";
                    foreach ($outOfStock as $p) {
                        $codeStr = $p['code'] ? " (كود: {$p['code']})" : '';
                        $msg2 .= "• {$p['name']}{$codeStr}\n";
                    }
                    $msg2 .= "\n";
                }
            } catch (Throwable $e) {
                error_log("[owner_missing_parts] outOfStock: " . $e->getMessage());
            }

            if (!$hasData) {
                $this->wa->sendText($phone, "📦 لا توجد سجلات لقطع مطلوبة وغير متوفرة حتى الآن 👍");
            } else {
                $this->wa->sendText($phone, $msg2);
            }
            return true;
        }

        // ── ملاحظات الزباين ─────────────────────────────────────────
        if (str_contains($text, 'ملاحظات الزباين') || str_contains($text, 'ملاحظات') || str_contains($text, 'شو ملاحظات')) {
            $notes = $this->db->query(
                "SELECT phone, message, created_at FROM customer_notes ORDER BY id DESC LIMIT 7"
            )->fetchAll(PDO::FETCH_ASSOC);
            $complaints = $this->db->query(
                "SELECT customer_id, complaint_text, created_at FROM complaints ORDER BY id DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (empty($notes) && empty($complaints)) {
                $this->wa->sendText($phone, "الوضع هادي، ما في ملاحظات جديدة! 👍");
                return true;
            }
            $msg2 = "📝 *آخر ملاحظات الزباين:*\n\n";
            foreach ($notes as $n) {
                $msg2 .= "📱 {$n['phone']}\n"
                      . "💬 \"" . mb_substr($n['message'], 0, 80) . "\"\n"
                      . "🕐 " . date('d/m H:i', strtotime($n['created_at'])) . "\n"
                      . "─────────────\n";
            }
            if (!empty($complaints)) {
                $msg2 .= "\n📋 *آخر الشكاوي:*\n";
                foreach ($complaints as $c) {
                    $msg2 .= "• " . mb_substr($c['complaint_text'], 0, 80) . "\n";
                }
            }
            $this->wa->sendText($phone, rtrim($msg2));
            return true;
        }

        // ── استفسار عن قطعة (نصي) ──────────────────────────────────
        if (str_contains($text, 'استفسار قطعة')) {
            $this->wa->sendText($phone, "🔍 أرسل اسم القطعة أو صورتها وسأوضح لك الكمية المتوفرة فوراً!");
            return true;
        }

        // ── كم قطعة [اسم] ─────────────────────────────────────────
        if (preg_match('/كم قطعة?\s+(.+)/u', $text, $m) || preg_match('/كم عندي حبة من (.+)/u', $text, $m) || preg_match('/كم حبة\s+(.+)/u', $text, $m)) {
            $partQuery = trim($m[1]);
            $this->searchAndReplyParts($phone, $partQuery);
            return true;
        }

        return false;
    }


    // ═══════════════════════════════════════════════════════════════
    //  🔍 بحث ذكي عن قطعة بالمخزن مع Fuzzy Match
    //  يبحث بالعبارة الكاملة أولاً، ثم بكل كلمة على حدة،
    //  ثم يجيب أقرب النتائج حتى لو ما طابق كلياً
    // ═══════════════════════════════════════════════════════════════
    private function searchAndReplyParts(string $phone, string $partQuery): void
    {
        // ── ١. بحث مباشر بالعبارة الكاملة أو الكود ─────────────
        $stmt = $this->db->prepare(
            "SELECT name, code, quantity, condition_status
             FROM products
             WHERE name LIKE ? OR code LIKE ?
             ORDER BY quantity DESC LIMIT 7"
        );
        $stmt->execute(['%'.$partQuery.'%', '%'.$partQuery.'%']);
        $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── ٢. لو ما لقى → بحث بكل كلمة OR ────────────────────
        if (empty($parts)) {
            $words = array_filter(
                preg_split('/\s+/u', $partQuery),
                fn($w) => mb_strlen($w) > 1
            );
            if (!empty($words)) {
                $conditions = array_fill(0, count($words), "name LIKE ?");
                $params = array_map(fn($w) => '%'.$w.'%', array_values($words));
                $stmt2 = $this->db->prepare(
                    "SELECT name, code, quantity, condition_status
                     FROM products WHERE " . implode(' OR ', $conditions) .
                    " ORDER BY quantity DESC LIMIT 10"
                );
                $stmt2->execute($params);
                $parts = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // ── ٣. لو ما لقى بعد → جيب أقرب 5 قطع بالمخزن (للمساعدة) ─
        if (empty($parts)) {
            $allParts = $this->db->query(
                "SELECT name, code, quantity, condition_status
                 FROM products ORDER BY quantity DESC LIMIT 50"
            )->fetchAll(PDO::FETCH_ASSOC);

            // نرتبهم حسب similarity مع الاستعلام
            $scored = [];
            foreach ($allParts as $p) {
                similar_text(
                    $this->normalizeArabicText($partQuery),
                    $this->normalizeArabicText($p['name']),
                    $pct
                );
                if ($pct > 20) {
                    $scored[] = ['pct' => $pct, 'part' => $p];
                }
            }
            usort($scored, fn($a, $b) => $b['pct'] <=> $a['pct']);
            $parts = array_column(array_slice($scored, 0, 5), 'part');
        }

        // ── عرض النتيجة ─────────────────────────────────────────
        if (empty($parts)) {
            $this->wa->sendText($phone,
                "🔍 لم أجد شيئاً قريباً من \"{$partQuery}\" في المخزن.\n"
                . "جرب أن ترسل صورة القطعة وسأتحقق منها! 📷"
            );
            return;
        }

        $msg2 = "📦 *نتائج البحث عن \"{$partQuery}\":*\n\n";
        foreach ($parts as $p) {
            $qty  = (int)$p['quantity'];
            $icon = $qty > 0 ? '✅' : '❌';
            $cond = $p['condition_status'] ? " ({$p['condition_status']})" : '';
            $msg2 .= "{$icon} {$p['name']} — *{$qty}* حبة{$cond}\n";
            if ($p['code']) $msg2 .= "    كود: {$p['code']}\n";
        }
        $this->wa->sendText($phone, rtrim($msg2));
    }

    private function handleOwnerChat(string $phone, string $text): bool
    {
        $targetBot = null;
        if (preg_match('/^يا?\s*(خالد|عبد\s*الله)/u', $text)) {
            $targetBot = self::BOT_KHALED;
        } elseif (preg_match('/^يا?\s*(عبدالرحمن|عبدو)/u', $text)) {
            $targetBot = self::BOT_ABDULRAHMAN;
        } elseif (str_starts_with($text, 'خالد') || str_starts_with($text, 'خالد')) {
            $targetBot = self::BOT_KHALED;
        } elseif (str_starts_with($text, 'عبدالرحمن') || str_starts_with($text, 'عبدو')) {
            $targetBot = self::BOT_ABDULRAHMAN;
        }

        if (!$targetBot) return false;

        $data = $this->gatherDataForOwnerQuestion($text);

        $personality = $targetBot === self::BOT_KHALED
            ? "أنت خالد، مساعد رسمي ومحترم لصاحب المحل معاذ. ردودك مختصرة ودقيقة ومنظمة."
            : "أنت عبدالرحمن، مساعد ودود لصاحب المحل معاذ. تحكي بأسلوب عربي بسيط، مزيج بين الفصحى والعامية العربية المفهومة لكل الناطقين بالعربية، بدون لهجة محلية مميزة. كن لطيفاً ومباشراً.";

        $systemPrompt = $personality . "\n\n"
            . "صاحب المحل اسمه معاذ، وهو يسألك سؤالاً عن المحل.\n"
            . "عندك هاي البيانات الحقيقية من النظام:\n\n"
            . $data . "\n\n"
            . "أجب على سؤاله بشكل طبيعي وذكي بناءً على البيانات.\n"
            . "إذا ما في بيانات كافية، قول له بصراحة.\n"
            . "ممنوع تخترع أرقام أو معلومات مش موجودة بالبيانات.";

        try {
            $reply = trim((string)$this->ai->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text],
            ], temp: 0.3, maxTokens: 400));

            if ($reply !== '') {
                $this->wa->sendText($phone, $reply);
            }
        } catch (Throwable $e) {
            error_log("[handleOwnerChat] " . $e->getMessage());
            $this->wa->sendText($phone, "عذراً، حدث خطأ مؤقت 🙏");
        }

        return true;
    }

    private function gatherDataForOwnerQuestion(string $text): string
    {
        $norm   = $this->normalizeArabicText($text);
        $chunks = [];

        if (str_contains($norm, 'طلب') || str_contains($norm, 'صيانه') || str_contains($norm, 'صيانة') || str_contains($norm, 'بعنا') || str_contains($norm, 'سجلنا')) {
            try {
                $todayCount = $this->db->query("SELECT COUNT(*) FROM service_requests WHERE DATE(created_at)=CURDATE()")->fetchColumn();
                $monthCount = $this->db->query("SELECT COUNT(*) FROM service_requests WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
                $totalCount = $this->db->query("SELECT COUNT(*) FROM service_requests")->fetchColumn();

                $lastRows = $this->db->query(
                    "SELECT order_number, customer_phone, product_name, issue_description, customer_location, status, created_at
                     FROM service_requests ORDER BY id DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $topDevices = $this->db->query(
                    "SELECT product_name, COUNT(*) as c FROM service_requests GROUP BY product_name ORDER BY c DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $chunk = "📋 إحصائيات الطلبات:\n"
                    . "- اليوم: {$todayCount} طلب\n"
                    . "- هالشهر: {$monthCount} طلب\n"
                    . "- الإجمالي: {$totalCount} طلب\n\n";

                if (!empty($lastRows)) {
                    $chunk .= "آخر 5 طلبات:\n";
                    foreach ($lastRows as $r) {
                        $chunk .= "• {$r['order_number']} | {$r['product_name']} | {$r['issue_description']} | {$r['customer_location']} | " . $this->mapServiceStatus($r['status']) . "\n";
                    }
                }

                if (!empty($topDevices)) {
                    $chunk .= "\nأكثر الأجهزة:\n";
                    foreach ($topDevices as $d) {
                        $chunk .= "• {$d['product_name']}: {$d['c']} طلب\n";
                    }
                }

                $chunks[] = $chunk;
            } catch (Throwable $e) {}
        }

        if (str_contains($norm, 'مخزن') || str_contains($norm, 'قطعه') || str_contains($norm, 'قطعة') || str_contains($norm, 'حبه') || str_contains($norm, 'حبة') || str_contains($norm, 'موجود')) {
            try {
                $totalParts     = $this->db->query("SELECT COUNT(*) FROM products")->fetchColumn();
                $availableParts = $this->db->query("SELECT COUNT(*) FROM products WHERE quantity > 0")->fetchColumn();
                $outOfStock     = $this->db->query("SELECT COUNT(*) FROM products WHERE quantity = 0")->fetchColumn();

                $lowStock = $this->db->query(
                    "SELECT name, code, quantity FROM products WHERE quantity > 0 ORDER BY quantity ASC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $chunk = "📦 إحصائيات المخزن:\n"
                    . "- إجمالي القطع: {$totalParts}\n"
                    . "- متوفرة: {$availableParts}\n"
                    . "- نفدت: {$outOfStock}\n";

                if (!empty($lowStock)) {
                    $chunk .= "\nأقل القطع كمية:\n";
                    foreach ($lowStock as $p) {
                        $chunk .= "• {$p['name']} (كود: {$p['code']}): {$p['quantity']} حبة\n";
                    }
                }

                $partMatch = [];
                if (preg_match('/(?:قطعه|قطعة|حبه|حبة|موجود|عندكم|عندنا)\s+(.+)/u', $text, $partMatch)) {
                    $partName = trim($partMatch[1]);
                    // بحث مباشر
                    $stmt = $this->db->prepare("SELECT name, code, quantity, condition_status FROM products WHERE name LIKE ? OR code LIKE ? LIMIT 5");
                    $stmt->execute(['%'.$partName.'%', '%'.$partName.'%']);
                    $specific = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    // لو ما لقى → بحث بكل كلمة
                    if (empty($specific)) {
                        $words = array_filter(preg_split('/\s+/u', $partName), fn($w) => mb_strlen($w) > 1);
                        if (!empty($words)) {
                            $conds  = array_fill(0, count($words), "name LIKE ?");
                            $params = array_map(fn($w) => '%'.$w.'%', array_values($words));
                            $stmt2  = $this->db->prepare("SELECT name, code, quantity, condition_status FROM products WHERE " . implode(' OR ', $conds) . " ORDER BY quantity DESC LIMIT 7");
                            $stmt2->execute($params);
                            $specific = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                    if (!empty($specific)) {
                        $chunk .= "\nنتائج البحث عن '{$partName}':\n";
                        foreach ($specific as $p) {
                            $avail = (int)$p['quantity'] > 0 ? 'متوفرة' : 'نفدت';
                            $chunk .= "• {$p['name']}: {$p['quantity']} حبة — {$avail}\n";
                        }
                    } else {
                        $chunk .= "\n❌ ما لقينا '{$partName}' بالمخزن.\n";
                    }
                }

                $chunks[] = $chunk;
            } catch (Throwable $e) {}
        }

        if (str_contains($norm, 'شكو') || str_contains($norm, 'ملاحظ') || str_contains($norm, 'مشاكل') || str_contains($norm, 'زباين') || str_contains($norm, 'زبائن')) {
            try {
                $complaintsCount = $this->db->query("SELECT COUNT(*) FROM complaints WHERE DATE(created_at)=CURDATE()")->fetchColumn();
                $lastComplaints  = $this->db->query(
                    "SELECT complaint_text, created_at FROM complaints ORDER BY id DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $lastNotes = $this->db->query(
                    "SELECT phone, message, created_at FROM customer_notes ORDER BY id DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $chunk = "📝 الشكاوي والملاحظات:\n"
                    . "- شكاوي اليوم: {$complaintsCount}\n";

                if (!empty($lastComplaints)) {
                    $chunk .= "\nآخر الشكاوي:\n";
                    foreach ($lastComplaints as $c) {
                        $chunk .= "• " . mb_substr($c['complaint_text'], 0, 60) . "\n";
                    }
                }

                if (!empty($lastNotes)) {
                    $chunk .= "\nآخر ملاحظات الزبائن:\n";
                    foreach ($lastNotes as $n) {
                        $chunk .= "• {$n['phone']}: " . mb_substr($n['message'], 0, 60) . "\n";
                    }
                }

                $chunks[] = $chunk;
            } catch (Throwable $e) {}
        }

        if (str_contains($norm, 'استفسار') || str_contains($norm, 'سالو') || str_contains($norm, 'سألوا') || str_contains($norm, 'يسالون') || str_contains($norm, 'يسألون') || str_contains($norm, 'طلبو')) {
            try {
                $pendingCount   = $this->db->query("SELECT COUNT(*) FROM notifications WHERE status='pending'")->fetchColumn();
                $todayInquiries = $this->db->query(
                    "SELECT COUNT(*) FROM notifications WHERE DATE(created_at)=CURDATE()"
                )->fetchColumn();

                $lastInquiries = $this->db->query(
                    "SELECT customer_phone, question, status, created_at FROM notifications ORDER BY id DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $topQuestions = $this->db->query(
                    "SELECT question, COUNT(*) as c FROM notifications GROUP BY question ORDER BY c DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $chunk = "❓ الاستفسارات:\n"
                    . "- اليوم: {$todayInquiries} استفسار\n"
                    . "- انتظار رد: {$pendingCount}\n";

                if (!empty($lastInquiries)) {
                    $chunk .= "\nآخر الاستفسارات:\n";
                    foreach ($lastInquiries as $n) {
                        $status = $n['status'] === 'pending' ? '⏳' : '✅';
                        $chunk .= "• {$status} {$n['customer_phone']}: " . mb_substr($n['question'], 0, 60) . "\n";
                    }
                }

                if (!empty($topQuestions)) {
                    $chunk .= "\nأكثر الأسئلة تكراراً:\n";
                    foreach ($topQuestions as $q) {
                        $chunk .= "• " . mb_substr($q['question'], 0, 50) . ": {$q['c']} مرة\n";
                    }
                }

                $chunks[] = $chunk;
            } catch (Throwable $e) {}
        }

        if (str_contains($norm, 'زبون') || str_contains($norm, 'زباين') || str_contains($norm, 'زبائن') || str_contains($norm, 'عميل') || str_contains($norm, 'جديد')) {
            try {
                $todayCustomers = $this->db->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at)=CURDATE()")->fetchColumn();
                $monthCustomers = $this->db->query("SELECT COUNT(*) FROM customers WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
                $totalCustomers = $this->db->query("SELECT COUNT(*) FROM customers")->fetchColumn();

                $chunks[] = "👤 الزبائن:\n"
                    . "- جديد اليوم: {$todayCustomers}\n"
                    . "- هالشهر: {$monthCustomers}\n"
                    . "- الإجمالي: {$totalCustomers}\n";
            } catch (Throwable $e) {}
        }

        if (str_contains($norm, 'ناقصه') || str_contains($norm, 'ناقصة') || str_contains($norm, 'مش موجوده') || str_contains($norm, 'مش موجودة') || str_contains($norm, 'طلبوها') || (str_contains($norm, 'قطع') && (str_contains($norm, 'ما عندنا') || str_contains($norm, 'ما كانت') || str_contains($norm, 'اكثر') || str_contains($norm, 'أكثر')))) {
            try {
                // ✅ [FIX-21] نفس المنطق الجديد — pending notifications
                $pendingInquiries = $this->db->query(
                    "SELECT customer_phone, question FROM notifications WHERE status='pending' ORDER BY id DESC LIMIT 7"
                )->fetchAll(PDO::FETCH_ASSOC);

                $outOfStock = $this->db->query(
                    "SELECT name FROM products WHERE quantity = 0 ORDER BY id DESC LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                $chunk = "📦 القطع المطلوبة وغير المتوفرة:\n";

                if (!empty($pendingInquiries)) {
                    $chunk .= "استفسارات pending:\n";
                    foreach ($pendingInquiries as $d) {
                        $chunk .= "• {$d['customer_phone']}: " . mb_substr($d['question'], 0, 50) . "\n";
                    }
                }
                if (!empty($outOfStock)) {
                    $chunk .= "❌ نفدت من المخزن:\n";
                    foreach ($outOfStock as $p) {
                        $chunk .= "• {$p['name']}\n";
                    }
                }
                if (empty($pendingInquiries) && empty($outOfStock)) {
                    $chunk .= "لا توجد سجلات بعد.\n";
                }
                $chunks[] = $chunk;
            } catch (Throwable $e) {}
        }

        return !empty($chunks)
            ? implode("\n─────────────\n", $chunks)
            : "لا توجد بيانات محددة متاحة للإجابة على هذا السؤال.";
    }

    // ═══════════════════════════════════════════════════════════════
    //  ENTRY POINT — البوابة المركزية
    // ═══════════════════════════════════════════════════════════════
    public function handle(array $msg, string $channel = 'whatsapp'): void
    {
        $phone   = trim($msg['phone'] ?? '');
        $text    = trim($msg['text'] ?? '');
        $type    = $msg['type'] ?? 'text';
        $mediaId = $msg['media_id'] ?? null;
        $waId    = $msg['wa_id'] ?? null;

        // واتساب: المعرّف رقم هاتف بحت (7-15 رقم).
        // ماسنجر/انستقرام: المعرّف PSID/IGSID، رقمي بس ممكن يتجاوز 15 خانة أحياناً
        // → نتحقق فقط إنه رقمي وموجود، بدون تقييد الطول لهاي القنوات.
        $idIsValid = $this->channel === 'whatsapp'
            ? (bool)preg_match('/^\d{7,15}$/', $phone)
            : (bool)preg_match('/^\d{5,30}$/', $phone);

        if (!$phone || !$idIsValid) return;
        if ($type === 'reaction') return;

        try {
            $globalStatus = $this->db
                ->query("SELECT setting_value FROM settings WHERE setting_key='global_bot_status' LIMIT 1")
                ->fetchColumn();
            if ($globalStatus === 'off') return;

            // ══════════════════════════════════════════════════════
            //  👑 أوامر المالك معاذ الموسّعة
            // ══════════════════════════════════════════════════════
            if ($this->isOwner($phone)) {

                // ═══════════════════════════════════════════════════
                //  ✅ [FIX-20] معالجة الرسائل الصوتية من معاذ
                //  إذا معاذ بعت صوت: نفرّغه ونعامله كنص كامل
                // ═══════════════════════════════════════════════════
                if ($type === 'audio' && $mediaId) {
                    $transcribed = $this->transcribeOwnerAudio($mediaId);
                    if ($transcribed === '') {
                        $this->wa->sendText($phone, "⚠️ لم أتمكن من فهم الرسالة الصوتية، حاول كتابة الأمر نصاً.");
                        return;
                    }
                    // أخبر معاذ شو فهمنا منه (شفافية)
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

                // ✅ لما معاذ يكتب "مرحبا" أو تحية → إرسال قائمة المالك
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

                // ✅ محادثة حرة مع خالد أو عبدالرحمن
                if ($this->handleOwnerChat($phone, $text)) return;

                // ── قائمة الأوامر ──────────────────────────────────
                if (str_contains($text, 'مساعدة') || str_contains($text, 'أوامر') || $text === 'help') {
                    $this->sendOwnerMenu($phone);
                    return;
                }

                // ── رد على زبون ────────────────────────────────────
                if (preg_match('/^(خالد|عبدالرحمن)?\s*:?\s*رد#(\d+)\s*:\s*(.+)$/su', trim($text), $m)) {
                    $botName = trim($m[1] ?? '');
                    $notifId = (int)$m[2];
                    $answer  = trim($m[3]);
                    $this->sendOwnerAnswer($notifId, $answer, $botName);
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
            }

            if ($type === 'location') {
                $this->saveMessage($convId, 'customer', 'location', $text, null, null, $waId);
                $this->db->prepare('UPDATE conversations SET last_msg_at=NOW() WHERE id=?')->execute([$convId]);
                if ($state === self::STATE_MAINT_LOCATION) {
                    $locationData      = json_decode($text, true);
                    $draft['pin_lat']  = $locationData['lat'] ?? null;
                    $draft['pin_lng']  = $locationData['lng'] ?? null;
                    $draft['location'] = 'تم إرسال الموقع الجغرافي (GPS)';
                    $this->finalizeOrAskContactPhone($phone, $convId, (int)$customer['id'], $draft);
                    return;
                }
                // ✅ موقع مُرسل خارج فلو الصيانة (زبون بعت لوكيشن من غير ما نطلبه) —
                // نشكره، نحوّله لمعاذ مباشرة، ونعطيه رقم التواصل لو حب يرن مباشرة.
                $locationData = json_decode($text, true);
                $lat = (float)($locationData['lat'] ?? $locationData['latitude'] ?? 0);
                $lng = (float)($locationData['lng'] ?? $locationData['longitude'] ?? 0);
                if ($lat && $lng) {
                    $this->sendLocationToOwners($lat, $lng, 'موقع زبون', $phone);
                    $this->notifyAdmins("📍 *زبون بعت موقعه (بدون طلب صيانة)*\n📱 رقم الزبون: {$phone}\n🕐 الوقت: " . date('Y-m-d H:i'));
                }
                $this->reply($phone, $convId,
                    "شكراً لمشاركة موقعك 🙏\nرح أقوم بتوصيله لمعاذ مباشرة.\n"
                    . "📞 إذا حبيت تتواصل مباشرة أو تتصل: *" . self::SUPPORT_PHONE . "*"
                );
                return;
            }

            if ($type === 'image' && $mediaId) {
                // ✅ تنزيل الصورة مرة واحدة هون، وتخزين مسارها الحقيقي بقاعدة البيانات
                // (بدل ما تتسجل الرسالة بمسار فاضي وتتحذف الصورة لاحقاً) — هيك معاذ
                // يقدر يشوف الصورة من لوحة التحكم، ومافي تنزيل مكرر للصورة نفسها.
                $imagePath = $this->wa->downloadMedia($mediaId);
                $this->saveMessage($convId, 'customer', 'image', $text ?: '[صورة]', $imagePath, null, $waId);
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

            // ════════════════════════════════════════════════════════
            //  التوجيه الرئيسي
            // ════════════════════════════════════════════════════════
            $isButton  = ($type === 'interactive' || $this->isButtonPayload($text));
            $activeBot = $draft[self::DRAFT_ACTIVE_BOT] ?? null;

            if ($state === self::STATE_WAITING_DEVICE_IMG_INTENT) {
                $this->handleDeviceImageIntent($phone, $convId, $text, (int)$customer['id'], $draft);
                return;
            }

            if ($activeBot === self::BOT_KHALED || $isButton) {
                $this->handleKhaled($phone, $convId, $text, (int)$customer['id'], $state, $conv, $draft);
                return;
            }

            if ($activeBot === self::BOT_ABDULRAHMAN) {
                $this->handleAbdulrahman($phone, $convId, $text, (int)$customer['id'], $state, $conv, $draft);
                return;
            }

            $welcomeStmt = $this->db->prepare(
                "SELECT id FROM messages WHERE conversation_id=? AND sender='bot' AND body LIKE '%قائمة خالد%' LIMIT 1"
            );
            $welcomeStmt->execute([$convId]);
            $welcomeSent = $welcomeStmt->fetchColumn();

            if (!$welcomeSent) {
                $this->sendWelcome($phone, $convId);
                return;
            }

            // ✅ شبكة أمان: ما نسمح لفحص "تحية قصيرة" يصفّر محادثة عندها state نشط
            // (لو حصل بكرا نفس خطأ نسيان DRAFT_ACTIVE_BOT بحالة جديدة، هذا بيمنع تكرار نفس العطل)
            if ($state === self::STATE_IDLE && $this->isSimpleGreeting($text)) {
                $this->sendWelcome($phone, $convId);
                return;
            }

            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULRAHMAN;
            $this->handleAbdulrahman($phone, $convId, $text, (int)$customer['id'], $state, $conv, $draft);

        } catch (Throwable $e) {
            error_log("[Agent::handle] $phone: " . $e->getMessage());
            try { $this->wa->sendText($phone, 'صار خطأ مؤقت، رح نرجعلك قريباً 🙏'); } catch (Throwable) {}
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  معالجة صورة قطعة يبعتها المالك معاذ
    // ═══════════════════════════════════════════════════════════════
    private function handleOwnerPartImage(string $phone, string $mediaId): void
    {
        try {
            $imagePath = $this->wa->downloadMedia($mediaId);
            if (!$imagePath) {
                $this->wa->sendText($phone, "❌ لم أتمكن من تحميل الصورة، حاول مرة أخرى.");
                return;
            }
            $imageData = base64_encode((string)file_get_contents($imagePath));
            $mimeType  = $this->detectMimeType($imagePath);
            @unlink($imagePath);

            $this->wa->sendText($phone, "🔍 جاري تحليل الصورة...");
            $analysis   = $this->ai->analyzePartImage($imageData, $mimeType);
            $partNameAr = trim((string)($analysis['part_name_ar'] ?? ''));
            $partNameEn = trim((string)($analysis['part_name_en'] ?? ''));
            $modelCode  = trim((string)($analysis['model_code'] ?? ''));
            $deviceType = trim((string)($analysis['device_type'] ?? ''));

            if ($partNameAr === '' && $partNameEn === '' && $modelCode === '') {
                $this->wa->sendText($phone, "🤔 لم أتمكن من التعرف على القطعة بالصورة، حاول صورة أوضح.");
                return;
            }

            $identified = "🔍 *تحليل الصورة:*\n"
                . ($partNameAr ? "📦 القطعة: {$partNameAr}" . ($partNameEn ? " ({$partNameEn})" : '') . "\n" : '')
                . ($deviceType ? "🔧 الجهاز: {$deviceType}\n" : '')
                . ($modelCode  ? "🏷️ الكود: {$modelCode}\n" : '');

            $this->wa->sendText($phone, $identified);
        } catch (Throwable $e) {
            error_log("[handleOwnerPartImage] " . $e->getMessage());
            $this->wa->sendText($phone, "عذراً، حدث خطأ أثناء تحليل الصورة 🙏");
        }
    }


    private function sendTopPartsInquiries(string $phone, string $period): void
    {
        $dateCondition = ($period === 'today')
            ? "DATE(created_at) = CURDATE()"
            : "MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";

        $periodAr = ($period === 'today') ? 'اليوم' : 'هالشهر';

        try {
            $stmt = $this->db->query(
                "SELECT complaint_text, COUNT(*) as c
                 FROM complaints
                 WHERE {$dateCondition}
                   AND (complaint_text LIKE '%قطعة%'
                        OR complaint_text LIKE '%قطعه%'
                        OR complaint_text LIKE '%استفسار%'
                        OR complaint_text LIKE '%لوحة%'
                        OR complaint_text LIKE '%موتور%'
                        OR complaint_text LIKE '%ضاغط%'
                        OR complaint_text LIKE '%مكثف%'
                        OR complaint_text LIKE '%فلتر%')
                 GROUP BY complaint_text
                 ORDER BY c DESC LIMIT 5"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtP = $this->db->query(
                "SELECT name, code, quantity
                 FROM products
                 ORDER BY last_sync DESC LIMIT 10"
            );
            $topProducts = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            $msg = "🔥 *أكثر القطع المستفسَر عنها {$periodAr}:*\n\n";

            if (!empty($rows)) {
                foreach ($rows as $i => $r) {
                    $icons = ['🥇','🥈','🥉','4️⃣','5️⃣'];
                    $icon  = $icons[$i] ?? '•';
                    $truncated = mb_substr($r['complaint_text'], 0, 60);
                    $msg .= "{$icon} \"{$truncated}\" — *{$r['c']}* مرة\n";
                }
            } else {
                $msg .= "ما في بيانات كافية للفترة المطلوبة 📊\n";
            }

            if (!empty($topProducts)) {
                $msg .= "\n📦 *آخر القطع المُحدَّثة بالمخزن:*\n";
                foreach (array_slice($topProducts, 0, 5) as $p) {
                    $qty  = (int)$p['quantity'];
                    $icon = $qty > 0 ? '✅' : '❌';
                    $msg .= "{$icon} {$p['name']} (كمية: {$qty})\n";
                }
            }

            $this->wa->sendText($phone, $msg);
        } catch (Throwable $e) {
            error_log("[sendTopPartsInquiries] " . $e->getMessage());
            $this->wa->sendText($phone, "حدث خطأ أثناء جلب البيانات 🙏");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  فحص التحيات البسيطة
    // ═══════════════════════════════════════════════════════════════
    private function isSimpleGreeting(string $text): bool
    {
        $greetings = [
            'مرحبا','مرحبأ','هلا','هلو','السلام','السلام عليكم',
            'اهلا','أهلا','اهلين','أهلين','هاي','hi','hello',
            'الو','آلو','وين','وين القائمه','وين القائمة',
            'كيفك','شو اخبارك','شو الاخبار','الغاء','إلغاء',
        ];
        $norm = $this->normalizeArabicText($text);
        foreach ($greetings as $g) {
            if ($norm === $this->normalizeArabicText($g)) return true;
        }
        return mb_strlen(trim($text)) < 4;
    }

    // ═══════════════════════════════════════════════════════════════
    //  رسالة الترحيب الموحدة
    // ═══════════════════════════════════════════════════════════════
    private function sendWelcome(string $phone, int $convId): void
    {
        $this->updateConvState($convId, self::STATE_IDLE, []);

        $msg = "أهلاً بكم في *مشغل جنين للصيانة وبيع قطع الغيار* 👋\n"
             . "اختر من القائمة للتحدث مع *خالد* 👇\n"
             . "أو اكتب مباشرة للتحدث مع *عبدالرحمن* ✍️";

        $sent = false;
        try {
            $this->wa->sendList(
                $phone,
                $msg,
                "☰ خدمات خالد",
                [[
                    'title' => 'خدماتنا',
                    'rows'  => [
                        ['id' => 'btn_maintenance',  'title' => '🔧 طلب صيانة',       'description' => 'حجز صيانة للأجهزة المنزلية'],
                        ['id' => 'btn_part_inquiry', 'title' => '🧩 استفسار عن قطعة', 'description' => 'صورة أو اسم قطعة الغيار'],
                        ['id' => 'btn_track',        'title' => '📦 تتبع طلب',        'description' => 'متابعة برقم الطلب'],
                        ['id' => 'btn_inquiry',      'title' => '📝 استفسار',         'description' => 'استفسار أو ملاحظة'],
                    ],
                ]],
                STORE_NAME
            );
            $sent = true;
            $this->saveMessage($convId, 'bot', 'text', $msg . ' [قائمة خالد]');
        } catch (Throwable $e) {
            error_log("[sendWelcome] sendList failed: " . $e->getMessage());
        }

        if (!$sent) {
            $fallback = $msg . "\n\n1) 🔧 طلب صيانة\n2) 🧩 استفسار عن قطعة\n3) 📦 تتبع طلب\n4) 📝 استفسار";
            try { $this->wa->sendText($phone, $fallback); } catch (Throwable $e) { error_log("[sendWelcome] fallback sendText failed: " . $e->getMessage()); }
            $this->saveMessage($convId, 'bot', 'text', $fallback . ' [قائمة خالد]');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  خالد — بوت الأزرار والفلو المنظم
    // ═══════════════════════════════════════════════════════════════
    public function notifyOwnerWithMissingPart(string $phone, string $partName): void
    {
        $msg = "📸 [تنبيه قطعة]: رقم {$phone} طلب \"{$partName}\" وهي غير متوفرة بالمخزن. يرجى المتابعة يا أستاذ معاذ!";
        $this->sendToOwners($msg);
    }

    // ═══════════════════════════════════════════════════════════════
    //  ✅ حفظ الملاحظة كـ notification رسمي عشان معاذ يقدر يرد بـ رد#
    // ═══════════════════════════════════════════════════════════════
    /** يسجّل أنه في زبون سأل عن صيانة ووصل لخطوة التأكيد بس ما أكد بعد (لتقرير معاذ + التذكير التلقائي) */
    private function saveNoteAsNotification(int $convId, int $custId, string $note, string $phone, string $botName): ?int
    {
        try {
            // تحقق إذا في notification pending بنفس المحادثة بالفعل
            $existing = $this->db->prepare(
                "SELECT id FROM notifications WHERE conversation_id=? AND status='pending' LIMIT 1"
            );
            $existing->execute([$convId]);
            if ($existing->fetchColumn()) return null; // ما نكرر

            $this->db->prepare(
                "INSERT INTO notifications (conversation_id, customer_id, question, customer_phone, status, bot_name)
                 VALUES (?, ?, ?, ?, 'pending', ?)"
            )->execute([$convId, $custId, $note, $phone, $botName]);

            return (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log("[saveNoteAsNotification] " . $e->getMessage());
            return null;
        }
    }

    public function notifyOwnerWithCustomerNote(string $phone, string $note): void
    {
        try {
            $this->db->prepare("INSERT INTO customer_notes (phone, message) VALUES (?, ?)")->execute([$phone, $note]);
        } catch (Throwable $e) {
            error_log("[notifyOwnerWithCustomerNote] DB error: " . $e->getMessage());
        }
        $msg = "📝 [ملاحظة زبون]: {$phone} قال: \"{$note}\"";
        $this->sendToOwners($msg);
    }

    // ═══════════════════════════════════════════════════════════════
    //  رسالة المالك معاذ
    // ═══════════════════════════════════════════════════════════════
    public function handleOwnerMessage(string $ownerText): void
    {
        $ownerText = trim($ownerText);

        // ✅ الصيغة الكاملة (مفضّلة): "خالد: رد#31: جوابك"
        if (preg_match('/^(خالد|عبدالرحمن)\s*:\s*رد#(\d+)\s*:\s*(.+)$/su', $ownerText, $m)) {
            $result = $this->sendOwnerAnswer((int)$m[2], trim($m[3]), botName: trim($m[1]));
            if (!($result['ok'] ?? false)) {
                $this->notifyAdmins("⚠️ ما قدرت أرسل ردك على الإشعار #{$m[2]}: " . ($result['error'] ?? 'خطأ غير معروف'));
            }
            return;
        }

        // ✅ الصيغة المختصرة (بدون اسم البوت — معاذ ناسي يحطه): "رد#31: جوابك"
        // نعتمد على bot_name المخزّن أصلاً بالإشعار نفسه بدل ما نرفض الرد بصمت.
        if (preg_match('/^رد#(\d+)\s*:\s*(.+)$/su', $ownerText, $m)) {
            $result = $this->sendOwnerAnswer((int)$m[1], trim($m[2]));
            if (!($result['ok'] ?? false)) {
                $this->notifyAdmins("⚠️ ما قدرت أرسل ردك على الإشعار #{$m[1]}: " . ($result['error'] ?? 'خطأ غير معروف'));
            }
            return;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  إرسال جواب معاذ للزبون
    // ═══════════════════════════════════════════════════════════════
    public function sendOwnerAnswer(int $notificationId, string $ownerAnswer, string $botName = ''): array
    {
        $stmt = $this->db->prepare("
            SELECT n.*, c.customer_id, c.channel AS conv_channel, cu.phone
            FROM notifications n
            JOIN conversations c ON c.id = n.conversation_id
            JOIN customers cu ON cu.id = c.customer_id
            WHERE n.id = ? LIMIT 1
        ");
        $stmt->execute([$notificationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return ['ok' => false, 'error' => 'ما لقيت الإشعار'];
        if (($row['status'] ?? '') === 'resolved') return ['ok' => false, 'error' => 'تم الرد مسبقاً'];

        $customerQuestion = (string)$row['question'];
        $activeBotName    = $botName ?: ($row['bot_name'] ?? self::BOT_ABDULRAHMAN);

        $availGuard = " 🚨 مهم جداً: اعتمد فقط على *جواب معاذ* لتحديد هل القطعة متوفرة أو لا — تجاهل تماماً أي كلمة \"غير متوفرة\" أو \"لم نجد\" وردت بنص سؤال الزبون (هاي كانت افتراضات نظام قديمة قبل ما معاذ يرد، مش الحقيقة). إذا معاذ قال \"متوفر\"، ردك لازم يقول متوفر بدون أي لبس أو عكس للمعنى. إذا قال \"غير متوفر\"، ردك يقول غير متوفر. ممنوع تقلب المعنى.";
        $systemMsg = $activeBotName === self::BOT_KHALED
            ? "أعد صياغة جواب معاذ كرسالة واتساب قصيرة ومحترمة. 2-3 جمل. إذا كان سؤال الزبون يذكر اسم قطعة أو منتج معين، اذكر اسمها صراحة بردك (مثل: \"قطعة [الاسم] سعرها كذا دينار\"). حافظ على أي رقم أو سعر ذكره معاذ بالحرف بدون تغيير أو تقريب.{$availGuard}"
            : "أعد صياغة جواب معاذ كرسالة واتساب بأسلوب عربي بسيط، مزيج بين الفصحى والعامية العربية العامة، ودود ولطيف. 2-3 جمل. إذا كان سؤال الزبون يذكر اسم قطعة أو منتج معين، اذكر اسمها صراحة بردك (مثل: \"قطعة [الاسم] سعرها كذا دينار\"). حافظ على أي رقم أو سعر ذكره معاذ بالحرف بدون تغيير أو تقريب.{$availGuard}";

        try {
            $finalReply = trim((string)$this->ai->chat([
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user', 'content' => "سؤال الزبون: $customerQuestion\nجواب معاذ: $ownerAnswer"],
            ]));
        } catch (Throwable $e) { $finalReply = ''; }

        if ($finalReply === '') $finalReply = $ownerAnswer;

        try {
            $this->db->beginTransaction();
            $customerChannel = $this->resolveChannelInstance((string)($row['conv_channel'] ?? 'whatsapp'));
            $customerChannel->sendText($row['phone'], $finalReply);
            $this->saveMessage((int)$row['conversation_id'], 'bot', 'text', $finalReply);

            $this->db->prepare("UPDATE notifications SET owner_answer=?, status='resolved', resolved_at=NOW() WHERE id=?")
                ->execute([$ownerAnswer, $notificationId]);
            $this->db->prepare("UPDATE conversations SET status='bot', last_msg_at=NOW() WHERE id=?")
                ->execute([(int)$row['conversation_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'خطأ في قاعدة البيانات'];
        }

        return ['ok' => true, 'reply' => $finalReply];
    }

    // ═══════════════════════════════════════════════════════════════
    //  تصعيد للمالك معاذ
    // ═══════════════════════════════════════════════════════════════
    private function escalateToOwner(
        string  $phone,
        int     $convId,
        string  $question,
        int     $custId,
        ?string $waId,
        string  $botName = ''
    ): ?int {
        try {
            $existing = $this->db->prepare("SELECT id FROM notifications WHERE conversation_id=? AND status='pending' LIMIT 1");
            $existing->execute([$convId]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $this->reply($phone, $convId,
                    $botName === self::BOT_KHALED
                        ? '📨 سؤالك عند معاذ وسيرد عليك قريباً، يرجى الانتظار قليلاً 🙏'
                        : 'سؤالك وصل لمعاذ من قبل وسيرد عليك بأسرع وقت 🙏'
                );
                return (int)$existingId;
            }

            $this->db->beginTransaction();
            $this->db->prepare("
                INSERT INTO notifications (conversation_id, customer_id, question, customer_phone, source_message_id, status, bot_name)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)
            ")->execute([$convId, $custId, $question, $phone, $waId, $botName]);
            $notifId = (int)$this->db->lastInsertId();
            $this->db->commit();

            $replyText = $botName === self::BOT_KHALED
                ? 'لحظة، سأسأل معاذ وأرجع إليك ⏳'
                : 'لحظة من فضلك، سأسأل معاذ وأرجع إليك بأسرع وقت ⏳';

            $this->reply($phone, $convId, $replyText);

            $ownerMsg = "{$botName} 📨 *سؤال جديد*\n📱 {$phone}\n💬 {$question}\n\n"
                . "للرد اكتب:\n*{$botName}: رد#{$notifId}: جوابك*";
            $this->notifyAdmins($ownerMsg);

            return $notifId;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("[Agent::escalateToOwner] " . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  SHARED HELPERS
    // ═══════════════════════════════════════════════════════════════
    /**
     * ✅ بوابة قبل تثبيت أي طلب صيانة: لو القناة مش واتساب (يعني ماسنجر أو
     * انستقرام) ولسا ما أخدنا رقم هاتف حقيقي من الزبون، نوقف ونسأله عن رقمه
     * أولاً بدل ما نثبّت الطلب برقم PSID/IGSID (مش رقم حقيقي وما بينفع للاتصال).
     * لو واتساب أصلاً، أو الرقم موجود بالـ draft من قبل → نكمّل ونثبّت مباشرة.
     */
    private function finalizeOrAskContactPhone(string $phone, int $convId, int $custId, array $draft): void
    {
        if ($this->channel !== 'whatsapp' && empty($draft['contact_phone'])) {
            $this->updateConvState($convId, self::STATE_MAINT_CONTACT_PHONE, $draft);
            $this->reply($phone, $convId,
                "📱 آخر شي! ممكن رقم هاتفك (واتساب) عشان الفني يقدر يتواصل معك لتأكيد الموعد؟\n" .
                "مثال: 07xxxxxxxx"
            );
            return;
        }
        $this->finalizeMaintenanceRequest($phone, $convId, $custId, $draft);
    }

    /** يتحقق من رقم أردني معقول (07xxxxxxxx أو +9627xxxxxxxx) ويرجعه بصيغة موحّدة، أو null لو غير صالح */
    private function normalizeContactPhone(string $text): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($text));
        if ($digits === null || $digits === '') return null;
        // نقبل: 07xxxxxxxx (10 أرقام) أو +9627xxxxxxxx / 9627xxxxxxxx / 009627xxxxxxxx
        if (preg_match('/^07\d{8}$/', $digits)) {
            return '+962' . substr($digits, 1);
        }
        if (preg_match('/^\+?9627\d{8}$/', $digits)) {
            return '+' . ltrim($digits, '+');
        }
        if (preg_match('/^009627\d{8}$/', $digits)) {
            return '+' . substr($digits, 2);
        }
        return null;
    }

    /** معالجة رد الزبون على سؤال "شو رقم هاتفك" (بعد قناة ماسنجر/انستقرام) */
    private function handleContactPhoneState(string $phone, int $convId, int $custId, string $text, array $draft): void
    {
        $normalized = $this->normalizeContactPhone($text);
        if ($normalized === null) {
            $this->reply($phone, $convId, "⚠️ الرقم غير واضح، اكتبه هيك مثلاً: 07xxxxxxxx 🙏");
            return;
        }
        $draft['contact_phone'] = $normalized;
        $this->finalizeMaintenanceRequest($phone, $convId, $custId, $draft);
    }

    private function finalizeMaintenanceRequest(string $phone, int $convId, int $custId, array $draft): void
    {
        $device       = $draft['device_type']    ?? 'غير محدد';
        $brand        = $draft['device_brand']   ?? '';
        $deviceFull   = $device . (!empty($brand) ? " — {$brand}" : '');
        $deviceTypeId = $draft['device_type_id'] ?? null;
        $issue        = $draft['issue']          ?? 'غير محدد';
        $issueId      = $draft['issue_id']       ?? null;
        $location     = $draft['location']       ?? 'غير محدد';
        $lat          = $draft['pin_lat']        ?? null;
        $lng          = $draft['pin_lng']        ?? null;
        $botName      = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_ABDULRAHMAN;
        $visitPrice   = (int)($draft['visit_price'] ?? self::VISIT_PRICE_MIN);
        $customerName = !empty($draft['customer_name']) ? (string)$draft['customer_name'] : null;

        // ✅ لو القناة ماسنجر/انستقرام وأخدنا رقم هاتف حقيقي من الزبون (contact_phone)،
        // نخزّنه هو بعمود customer_phone بدل PSID/IGSID (اللي مش رقم قابل للاتصال أصلاً).
        // لو واتساب، $phone أصلاً رقم حقيقي فما في فرق.
        $storedPhone = !empty($draft['contact_phone']) ? (string)$draft['contact_phone'] : $phone;

        try {
            $this->db->prepare("
                INSERT INTO service_requests
                (conversation_id, customer_id, customer_name, customer_phone, customer_location,
                 product_name, product_id, device_type_id, device_brand, issue_id, issue_description,
                 visit_price, status)
                VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 'pending')
            ")->execute([
                $convId, $custId, $customerName, $storedPhone, $location,
                $deviceFull, $deviceTypeId, ($brand !== '' ? $brand : null), $issueId, $issue,
                $visitPrice,
            ]);

            $requestId   = (int)$this->db->lastInsertId();
            $orderNumber = $this->buildOrderNumber($requestId);
            $this->db->prepare("UPDATE service_requests SET order_number=? WHERE id=?")->execute([$orderNumber, $requestId]);

            $this->updateConvState($convId, self::STATE_IDLE, []);

            $mapsUrl = ($lat && $lng) ? "https://maps.google.com/?q={$lat},{$lng}" : null;

            $this->reply($phone, $convId,
                "✅ *تم تسجيل طلبك!*\n\n"
                . "🔢 رقم الطلب: *{$orderNumber}*\n"
                . ($customerName ? "👤 الاسم: {$customerName}\n" : '')
                . "📱 الرقم: {$storedPhone}\n"
                . "🖥️ الجهاز: {$deviceFull}\n"
                . "⚠️ العطل: {$issue}\n"
                . "📍 العنوان: " . ($mapsUrl ? "GPS 🗺️" : $location) . "\n"
                . "💰 رسوم الزيارة: *{$visitPrice} دينار*\n\n"
                . "سيتواصل معك فريق مشغل جنين قريباً 📞\n"
                . "للاستفسار المباشر: *" . self::SUPPORT_PHONE . "*"
            );

            $channelLabel = match ($this->channel) {
                'messenger' => ' (ماسنجر)',
                'instagram' => ' (انستقرام)',
                default     => '',
            };
            $ownerMsg = "{$botName} 🔧 *طلب صيانة جديد*\n"
                . "🔢 {$orderNumber}\n"
                . ($customerName ? "👤 {$customerName}\n" : '')
                . "📱 {$storedPhone}{$channelLabel}\n🖥️ {$deviceFull}\n⚠️ {$issue}\n📍 {$location}"
                . ($mapsUrl ? "\n🗺️ {$mapsUrl}" : '');
            $this->notifyAdmins($ownerMsg);

            if ($lat && $lng) {
                $this->sendLocationToOwners((float)$lat, (float)$lng, $deviceFull, $phone);
            }
        } catch (Throwable $e) {
            error_log("[finalizeMaintenanceRequest] " . $e->getMessage());
            $this->updateConvState($convId, self::STATE_IDLE, []);
            $this->reply($phone, $convId, "عذراً، صار خطأ أثناء حفظ الطلب. حاول مرة ثانية 🙏");
        }
    }

    private function handlePartImage(string $phone, int $convId, string $mediaId, array $draft, ?string $preDownloadedPath = null): void
    {
        $botName = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_KHALED;

        try {
            $imagePath = $preDownloadedPath ?: $this->wa->downloadMedia($mediaId);
            if (!$imagePath) {
                $this->reply($phone, $convId, "❌ ما قدرت أحمّل الصورة، ابعتها مرة ثانية 🙏");
                return;
            }

            // ✅ الزبون اختار صراحة "استفسار عن قطعة" → نتأكد بالـ AI إنها فعلاً
            // صورة قطعة، ونرد فوراً "متوفرة" بدون ما نسأله أي سؤال إضافي.
            $imageData = base64_encode((string)file_get_contents($imagePath));
            $mimeType  = $this->detectMimeType($imagePath);
            $classification = $this->ai->classifyImage($imageData, $mimeType);

            $this->handlePartImageAvailable($phone, $convId, (int)($this->getConversation($convId)['customer_id'] ?? 0), $imagePath, $classification, $botName);

        } catch (Throwable $e) {
            error_log("[handlePartImage] " . $e->getMessage());
            $this->reply($phone, $convId, "عذراً، صار خطأ أثناء تحميل الصورة 🙏");
            $this->updateConvState($convId, self::STATE_IDLE, []);
        }
    }

    private function processPartImageWithDevice(string $phone, int $convId, array $draft): void
    {
        $botName   = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_ABDULRAHMAN;
        $imagePath = $draft['part_image_path']       ?? '';
        $device    = $draft['part_device']           ?? '';
        $partName  = $draft['part_name']             ?? '';

        if (!$imagePath || !file_exists($imagePath)) {
            $this->reply($phone, $convId, "❌ ما لقيت الصورة، ابعتها مرة ثانية 🙏");
            return;
        }

        try {
            $this->reply($phone, $convId, "🔍 جاري البحث عن القطعة في المخزن...");

            $imageData = base64_encode((string)file_get_contents($imagePath));
            $mimeType  = $this->detectMimeType($imagePath);

            $label   = trim("$partName $device");
            $conv    = $this->getConversation($convId);
            $custId  = (int)($conv['customer_id'] ?? 0);
            $this->reply($phone, $convId, "تمام، رح أوصّل سؤالك لمعاذ مباشرة معاها الصورة 🙏");
            $notifId = $this->escalateToOwner($phone, $convId, $this->buildPartEscalationMsg($phone, $label, $device, false), $custId, null, $botName);
            $forwardCaption = "🧩 صورة قطعة\n📱 {$phone}\n🔧 {$label}"
                . ($notifId ? "\n\nللرد اكتب:\n*{$botName}: رد#{$notifId}: جوابك*" : '');
            $this->forwardImageToOwners($imagePath, $forwardCaption);
            $draft['last_part_label']     = $label;
            $draft['last_part_image']     = $imagePath;
            $draft['last_part_available'] = null;
            // ✅ خلّص الفلو — رجّع idle بس خلي الزبون مرتبط بخالد (بوت القطع)، واحتفظ بسياق آخر قطعة
            $this->updateConvState($convId, self::STATE_IDLE, [
                self::DRAFT_ACTIVE_BOT => self::BOT_KHALED,
                'last_part_label'      => $draft['last_part_label']     ?? null,
                'last_part_image'      => $draft['last_part_image']     ?? null,
                'last_part_available'  => $draft['last_part_available'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log("[processPartImageWithDevice] " . $e->getMessage());
            $this->reply($phone, $convId, "عذراً، صار خطأ أثناء البحث 🙏");
        }
    }

    /**
     * 🔎 خطوة التصنيف الأولى لأي صورة توصل (غير الحالة الصريحة "استفسار عن قطعة"):
     * part → نفس مسار البحث بالمخزون الحالي (بدون تغيير)
     * device → يسأل الزبون: بدك قطعة ولا عندك مشكلة بالجهاز؟
     * ads → رد توضيحي بسيط، إحنا مختصين بصيانة الأجهزة وقطع الغيار بس
     * bank → إشعار فوري لمعاذ + رد للزبون، بدون أي تحليل إضافي للصورة
     */
    private function routeClassifiedImage(
        string $phone, int $convId, string $mediaId, string $caption,
        int $custId, array $draft, string $imagePath
    ): void {
        $botName = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_ABDULRAHMAN;

        try {
            $imageData = base64_encode((string)file_get_contents($imagePath));
            $mimeType  = $this->detectMimeType($imagePath);
            $classification = $this->ai->classifyImage($imageData, $mimeType);
        } catch (Throwable $e) {
            error_log('[routeClassifiedImage] ' . $e->getMessage());
            $classification = ['type' => 'other', 'part_name_ar' => '', 'device_type' => ''];
        }

        if ($classification['type'] === 'part') {
            $this->handlePartImageAvailable($phone, $convId, $custId, $imagePath, $classification, $botName);
            return;
        }

        if ($classification['type'] === 'device') {
            $this->handleDeviceImage($phone, $convId, $custId, $draft, $imagePath, $botName, $classification['device_type'] ?? '');
            return;
        }

        if ($classification['type'] === 'sensitive') {
            // ⚠️ صورة فيها معلومات مالية/شخصية حساسة (تحويل بنكي، فيزا، إيصال...) —
            // ممنوع نرد "متوفرة" عليها. نعتذر ونصعّدها لمعاذ بدون أي ادعاء توفر.
            $this->reply($phone, $convId,
                "شكراً 🙏 استلمنا الصورة، وحولناها لمعاذ ليتابع معك مباشرة."
            );
            $notifId = $this->escalateToOwner($phone, $convId,
                "⚠️ *صورة فيها معلومات حساسة (تحويل/دفع/مستند)*\n📱 {$phone}\n\nيرجى المراجعة والمتابعة مع الزبون مباشرة.",
                $custId, null, $botName
            );
            $forwardCaption = "⚠️ صورة حساسة (تحويل/دفع/مستند)\n📱 {$phone}"
                . ($notifId ? "\n\nللرد اكتب:\n*{$botName}: رد#{$notifId}: جوابك*" : '');
            $this->forwardImageToOwners($imagePath, $forwardCaption);
            $this->updateConvState($convId, self::STATE_IDLE, [self::DRAFT_ACTIVE_BOT => $botName]);
            return;
        }

        // "other" (الموديل مش متأكد 100% إنها قطعة) → بما إنه عندنا كل القطع تقريباً،
        // منعاملها زي "part" بالضبط: رد فوري "متوفرة" + رقم الدعم، بدل ما نسأل الزبون
        // يوضح الاسم وناخد وقت نصعّد لمعاذ. (القديم كان بيسأل ويصعّد — تم إيقافه بالكامل).
        $this->handlePartImageAvailable($phone, $convId, $custId, $imagePath, $classification, $botName);
    }

    /**
     * صورة قطعة غيار مفردة → رد فوري بدون أي سؤال إضافي للزبون:
     * "القطعة متوفرة" + رقم التواصل للطلب، وبالتوازي نبعت الصورة + الاسم المستخرج
     * تلقائياً لمعاذ للعلم والمتابعة (بدون ما ننتظر منه رد قبل ما نرد على الزبون).
     */
    private function handlePartImageAvailable(
        string $phone, int $convId, int $custId, string $imagePath, array $classification, string $botName
    ): void {
        $partName   = $classification['part_name_ar'] ?? '';
        $deviceType = $classification['device_type']  ?? '';
        $label      = $partName !== '' ? $partName : 'قطعة غيار (لم يتحدد الاسم بدقة)';

        $this->reply($phone, $convId,
            "✅ القطعة متوفرة عندنا!\n"
            . "📞 للطلب أو الاستفسار تواصل معنا مباشرة على رقم الدعم:\n*" . self::SUPPORT_PHONE . "*"
        );

        $deviceLine = $deviceType !== '' ? "🔧 الجهاز: {$deviceType}\n" : '';
        $forwardCaption = "{$botName} 🧩 *صورة قطعة — تم إخبار الزبون أنها متوفرة تلقائياً*\n"
            . "📱 {$phone}\n"
            . $deviceLine
            . "🔍 القطعة (تحليل تلقائي): {$label}\n\n"
            . "ℹ️ للعلم فقط — الزبون أُعطي رقم التواصل مباشرة، لا حاجة للرد إلا لو حابب تتابع معه.";
        $this->forwardImageToOwners($imagePath, $forwardCaption);

        $this->updateConvState($convId, self::STATE_IDLE, [
            self::DRAFT_ACTIVE_BOT => self::BOT_KHALED,
            'last_part_label'      => $label,
            'last_part_image'      => $imagePath,
            'last_part_available'  => true,
        ]);
    }

    /** جهاز كامل بالصورة (مش قطعة مفردة) → نسأل الزبون شو بالضبط بدو */
    private function handleDeviceImage(
        string $phone, int $convId, int $custId, array $draft, string $imagePath, string $botName, string $detectedDeviceType = ''
    ): void {
        $draft['device_image_path']    = $imagePath;
        $draft['device_image_type']    = $detectedDeviceType;
        $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULRAHMAN;
        $this->updateConvState($convId, self::STATE_WAITING_DEVICE_IMG_INTENT, $draft);
        $this->reply($phone, $convId,
            "شكراً على الصورة 📷\nبدك *قطعة غيار* لهالجهاز، ولا عندك *مشكلة/عطل* فيه وحاب صيانة؟"
        );
        $deviceLine = $detectedDeviceType !== '' ? "🔧 نوع الجهاز (تحليل تلقائي): {$detectedDeviceType}\n" : '';
        $this->forwardImageToOwners($imagePath, "{$botName} 📷 صورة جهاز كامل\n📱 {$phone}\n{$deviceLine}بانتظار توضيح الزبون (قطعة / عطل)");
    }

    /** رد الزبون بعد سؤال "بدك قطعة ولا عندك مشكلة؟" */
    private function handleDeviceImageIntent(string $phone, int $convId, string $text, int $custId, array $draft): void
    {
        $normalized = $this->normalizeArabicText($text);
        $wantsPart  = (bool)preg_match('/(قطعه|قطعة|غيار)/ui', $normalized);
        $wantsFix   = (bool)preg_match('/(عطل|مشكله|مشكلة|صيانه|صيانة|خربان|خراب)/ui', $normalized);

        if ($wantsPart && !$wantsFix) {
            // ✅ نفس مسار "القطعة متوفرة" الفوري — بنفس صورة الجهاز اللي وصلت،
            // مع نوع الجهاز اللي انكشف تلقائياً وقت التصنيف الأول.
            $imagePath  = $draft['device_image_path'] ?? '';
            $deviceType = $draft['device_image_type'] ?? '';
            unset($draft['device_image_path'], $draft['device_image_type']);
            if ($imagePath && file_exists($imagePath)) {
                $this->handlePartImageAvailable($phone, $convId, $custId, $imagePath, [
                    'part_name_ar' => '',
                    'device_type'  => $deviceType,
                ], self::BOT_KHALED);
            } else {
                $this->reply($phone, $convId, "ابعتلي صورة القطعة تاني من فضلك 🙏");
                $this->updateConvState($convId, self::STATE_IDLE, $draft);
            }
            return;
        }

        if ($wantsFix && !$wantsPart) {
            // نحوّلها لمسار الصيانة العادي عند عبدالرحمن — نخليه يكمل الأسئلة الاعتيادية (عطل + موقع)
            unset($draft['device_image_path'], $draft['device_image_type']);
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULRAHMAN;
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            $this->handleAbdulrahman($phone, $convId, $text, $custId, self::STATE_IDLE,
                $this->getConversation($convId) ?? [], $draft);
            return;
        }

        // رد مش واضح → نعيد السؤال بشكل أبسط
        $this->reply($phone, $convId, "تمام، بس وضحلي أكتر 🙏: اكتب *قطعة* إذا بدك تشتري قطعة غيار، أو *عطل* إذا عندك مشكلة بالجهاز.");
    }

    /** سكرين شوت / إعلان / صورة غير متعلقة بأجهزة منزلية */
    private function handleAdsImage(string $phone, int $convId, int $custId, string $imagePath, string $botName): void
    {
        $this->reply($phone, $convId,
            "أهلين 👋 إحنا هون مختصين بصيانة الأجهزة المنزلية وقطع الغيار بس.\n" .
            "إذا في جهاز أو قطعة حاب تسأل عنها، ابعتلي صورتها وبساعدك 🙏"
        );
        // إشعار خفيف مع الصورة نفسها (بدون انتظار رد) — لو التصنيف غلط، معاذ بيلاحظها بنفسه
        $this->forwardImageToOwners($imagePath, "{$botName} 📢 صورة غير متعلقة بأجهزة/قطع (سكرين شوت أو إعلان)\n📱 {$phone}");
    }

    /** معلومات مالية/حساسة (حساب بنكي، تحويل، هوية، بطاقة) → تصعيد فوري بدون أي تحليل إضافي */
    private function handleBankImage(string $phone, int $convId, int $custId, string $imagePath, string $botName): void
    {
        $this->reply($phone, $convId,
            "هاي الصورة تبدو متعلقة بمعاملات مالية — رح أوصّلها لمعاذ مباشرة، هو الأنسب يتعامل معها 🙏"
        );
        $this->forwardImageToOwners($imagePath, "{$botName} 💳 صورة تبدو متعلقة بمعاملة مالية — يرجى المتابعة\n📱 {$phone}");
    }

    private function handleImageMessage(string $phone, int $convId, string $mediaId, string $caption, int $custId, array $draft, ?string $preDownloadedPath = null): void
    {
        $botName = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_ABDULRAHMAN;

        try {
            $imagePath = $preDownloadedPath ?: $this->wa->downloadMedia($mediaId);
            if (!$imagePath) throw new RuntimeException("downloadMedia null");

            $partLabel = $caption ?: 'غير معروف';

            // ما في بحث آلي بالمخزون — نسأل الزبون عن اسم القطعة قبل ما نصعّد لمعاذ
            $draft['fallback_image_path']  = $imagePath;
            $draft['fallback_part_label']  = $partLabel;
            $draft['fallback_analysis']    = [];
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_KHALED;
            $this->updateConvState($convId, self::STATE_WAITING_PART_NAME_FALLBACK, $draft);
            $this->reply($phone, $convId,
                "📸 وصلتني الصورة!\n" .
                "تعرف اسم القطعة أو نوعها؟ (مثلاً: حساس باب، مضخة تصريف، ترموستات...)\n" .
                "لو ما بتعرف، اكتب *ما بعرف* وأنا أوصّل صورتها لمعاذ مباشرة 🙏"
            );

        } catch (Throwable $e) {
            error_log("[handleImageMessage] " . $e->getMessage());
            $this->reply($phone, $convId, "عذراً، صار خطأ أثناء تحميل الصورة.");
        }
    }

    /** رد الزبون بعد سؤال "تعرف اسم القطعة؟" — يجرب بحث نصي أول، وإلا يصعّد لمعاذ مع الصورة + الاسم */
    /**
     * يبني رسالة تصعيد موحّدة لمعاذ لما يوصل استفسار عن قطعة (بالنص أو بالصورة)
     * وما نلقاها بالمخزون — نفس أسلوب رسائل تصعيد التتبع، لتناسق أفضل عند معاذ.
     */
    private function buildPartEscalationMsg(string $phone, string $label, string $device = '', bool $confirmedNotFound = true): string
    {
        $deviceLine = $device !== '' ? "🔧 الجهاز: {$device}\n" : '';
        if ($confirmedNotFound) {
            return "🧩 *استفسار عن قطعة — غير متوفرة بالمخزون*\n"
                . "📱 رقم الزبون: {$phone}\n"
                . "🕐 وقت الاستفسار: " . date('Y-m-d H:i') . "\n"
                . $deviceLine
                . "🔍 القطعة: {$label}\n\n"
                . "⚠️ لم نجد هذه القطعة بالمخزون الحالي — يرجى مراجعة الصورة/الوصف والرد على الزبون مباشرة.";
        }
        // ✅ حالة مختلفة تماماً: لسا ما تأكدنا من هوية القطعة ولا من توفرها — بس عم نسأل
        // معاذ يتعرف عليها من الصورة. ممنوع نفترض "غير متوفرة" هون، لأنه هاد بيلخبط
        // الذكاء الاصطناعي لما يصيغ رد معاذ لاحقاً (قد يقول "متوفر" ويطلع بالعكس).
        return "🧩 *استفسار عن قطعة — نحتاج تحديد هويتها*\n"
            . "📱 رقم الزبون: {$phone}\n"
            . "🕐 وقت الاستفسار: " . date('Y-m-d H:i') . "\n"
            . $deviceLine
            . "🔍 وصف/تخمين: {$label}\n\n"
            . "⚠️ النظام ما قدر يتأكد من هوية هاي القطعة ولا من توفرها — يرجى معاينة الصورة وإخبارنا شو هي وهل هي متوفرة، للرد على الزبون.";
    }

    private function handlePartNameFallback(string $phone, int $convId, string $text, int $custId, array $draft): void
    {
        $botName   = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_KHALED;
        $imagePath = $draft['fallback_image_path'] ?? '';
        $analysis  = $draft['fallback_analysis']   ?? [];
        $oldLabel  = $draft['fallback_part_label'] ?? 'قطعة غيار';
        $partQuery = trim($text);

        // الزبون ما بيعرف اسمها → صعّد فوراً بدون بحث نصي
        if ($this->isUnknownBrand($partQuery) || mb_strlen($partQuery) < 2) {
            // ✅ الزبون صراحة قال "ما بعرف" — لازم نوضح لمعاذ إنه هاد تخمين النظام غير
            // مؤكد ومش اسم أكيد، عشان ما ينضلل ويعتمد عليه وكأنه حقيقة مؤكدة
            $honestLabel = "قطعة غير معروفة (الزبون نفسه ما بيعرف اسمها). تخمين مبدئي غير مؤكد من صورة النظام: {$oldLabel}";
            $this->escalatePartWithLabel($phone, $convId, $custId, $imagePath, $honestLabel, $analysis, $botName);
            return;
        }

        // ما في بحث آلي بالمخزون — نصعّد مباشرة لمعاذ مع الصورة + الاسم يلي عطاه الزبون
        $this->escalatePartWithLabel($phone, $convId, $custId, $imagePath, $partQuery, $analysis, $botName);
    }

    /** يصعّد صورة قطعة لمعاذ مع تسمية واضحة (اسم الزبون أو تحليل البوت) */
    private function escalatePartWithLabel(
        string $phone, int $convId, int $custId, string $imagePath, string $label, array $analysis, string $botName
    ): void {
        $this->reply($phone, $convId, "تمام، رح أوصّل سؤالك لمعاذ مباشرة معاها الصورة 🙏");
        $notifId = $this->escalateToOwner($phone, $convId, $this->buildPartEscalationMsg($phone, $label, '', false), $custId, null, $botName);
        $forwardCaption = "🧩 صورة قطعة (بدون تطابق مؤكد)\n📱 {$phone}\n🔍 وصف الزبون: {$label}"
            . ($notifId ? "\n\nللرد اكتب:\n*{$botName}: رد#{$notifId}: جوابك*" : '');
        $this->forwardImageToOwners($imagePath, $forwardCaption);
        // ✅ خلي الزبون مرتبط بخالد (بوت القطع) لحد ما معاذ يرد — مش نصفّر البوت النشط،
        // لأنه لو الزبون كتب أي شي قبل رد معاذ، لازم يضل بمسار القطع مش يوقع بمسار الصيانة الافتراضي
        // ✅ ونحفظ سياق آخر قطعة هون كمان — عشان لو الزبون سأل بعدها "كم سعرها؟" (سواء
        // قبل أو بعد رد معاذ) نقدر نكتشفها ونصعّدها بنفس الصورة، بدل ما نفقد السياق.
        $this->updateConvState($convId, self::STATE_IDLE, [
            self::DRAFT_ACTIVE_BOT => self::BOT_KHALED,
            'last_part_label'      => $label,
            'last_part_image'      => $imagePath,
            'last_part_available'  => null, // لسا ما عرفنا، معاذ هو يلي رح يحدد
        ]);
    }

    private function processAudio(string $mediaId, int $convId, ?string $waId): string
    {
        try {
            $path = $this->wa->downloadMedia($mediaId);
            if (!$path) {
                $this->saveMessage($convId, 'customer', 'audio', '[صوت - فشل التحميل]', null, null, $waId);
                return '';
            }
            $transcribed = trim($this->ai->transcribe($path));
            // ✅ تم إبقاء الملف الصوتي على السيرفر (بدل حذفه فور التحويل لنص)
            // عشان معاذ يقدر يسمعه لاحقاً من لوحة التحكم — media_path بيتخزن وبيظل صالح.
            $this->saveMessage($convId, 'customer', 'audio', $transcribed ?: '[صوت]', $path, $transcribed, $waId);
            return $transcribed;
        } catch (Throwable $e) {
            error_log("[processAudio] " . $e->getMessage());
            $this->saveMessage($convId, 'customer', 'audio', '[صوت - فشل النسخ]', null, null, $waId);
            return '';
        }
    }

    private function checkSessionTimeout(array $conv, int $convId): void
    {
        if (empty($conv['last_msg_at'])) return;
        $lastMsg = new DateTime($conv['last_msg_at']);
        $now     = new DateTime();
        $diff    = $now->getTimestamp() - $lastMsg->getTimestamp();
        if ($diff > 1800 && ($conv['state'] ?? self::STATE_IDLE) !== self::STATE_IDLE) {
            $this->updateConvState($convId, self::STATE_IDLE, []);
            error_log("[Agent] Session timeout reset for conv $convId");
        }
    }

    private function callAIWithRetry(array $messages, bool $jsonMode = false, int $maxRetries = 2): ?array
    {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            try {
                $raw     = $this->ai->chat($messages, temp: 0.3, maxTokens: 500, jsonMode: $jsonMode);
                $cleaned = $this->cleanJsonOutput((string)$raw);
                $decoded = json_decode($cleaned, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            } catch (Throwable $e) {
                error_log("[callAIWithRetry] attempt $attempt: " . $e->getMessage());
            }
            $attempt++;
            if ($attempt < $maxRetries) usleep(500000);
        }
        return null;
    }

    private function cleanJsonOutput(string $raw): string
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $cleaned = preg_replace('/\s*```\s*$/i', '', $cleaned ?? $raw);
        return trim($cleaned ?? $raw);
    }

    private function answerGeneralQuestion(string $question): string
    {
        $customInstructions = $this->getCustomInstructions();

        // ما في تعليمات مكتوبة بالداشبورد أصلاً → ما منقدر نجاوب، منرجع فاضي
        // (بيخلي الكود يكمّل بمنطق ثاني بدل ما يبعت جواب فاضي/غلط للزبون)
        if ($customInstructions === '') {
            error_log('[answerGeneralQuestion] لا توجد تعليمات بقسم "تعليمات" بالداشبورد — البوت ما رح يجاوب على الأسئلة العامة.');
            return '';
        }

        $systemPrompt = $customInstructions
            . "\n\n---\n"
            . "قاعدة تقنية إلزامية (لازم تلتزم فيها بالضبط بغض النظر عن باقي التعليمات):\n"
            . "إذا كان سؤال الزبون لا علاقة له إطلاقاً بالمعلومات الموجودة أعلاه، رد بكلمة NONE فقط بدون أي إضافة.";

        try {
            $response = trim((string)$this->ai->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $question],
            ], temp: 0.4, maxTokens: 200));
            return ($response === 'NONE' || mb_strlen($response) < 3) ? '' : $response;
        } catch (Throwable $e) {
            return '';
        }
    }

    private function escapePrompt(string $text): string
    {
        return $text;
    }

    // ═══════════════════════════════════════════════════════════════
    //  إرسال موقع المحل كـ location message واتساب
    // ═══════════════════════════════════════════════════════════════
    private function sendShopLocation(string $phone): void
    {
        try {
            $this->wa->sendLocation(
                $phone,
                31.9646027,
                35.959144,
                'مشغل جنين للصيانة وبيع قطع الغيار',
                'الهاشمي الشمالي، نزول سوق المخفر، عمان'
            );
        } catch (Throwable $e) {
            // fallback نصي لو فشل الموقع
            try {
                $this->wa->sendText($phone,
                    "📍 *موقعنا:*\n"
                    . "عمان — الهاشمي الشمالي — نزول سوق المخفر\n\n"
                    . "🗺️ https://maps.google.com/?q=31.9646027,35.959144"
                );
            } catch (Throwable) {}
        }
    }

    private function sendTrackingInfo(string $phone, int $convId, array $req): void
    {
        $createdAt = new DateTime($req['created_at']);
        // ✅ بعض دوال البحث بترجع product_name، وبعضها (المطابقة باسم الجهاز) بترجع
        // device_type + device_brand — نجمعهم هون بمكان واحد لنتجنب ظهور "null — null"
        $deviceLabel = trim((string)($req['device_type'] ?? '') . ' ' . (string)($req['device_brand'] ?? ''));
        $productLabel = $req['product_name'] ?? '';
        $label = $productLabel !== '' ? $productLabel : $deviceLabel;
        $this->reply($phone, $convId,
            "📦 *تفاصيل طلبك*\n\n"
            . "🔢 {$req['order_number']}\n"
            . "🖥️ " . ($label !== '' ? $label : 'غير محدد') . "\n"
            . "⚠️ " . ($req['issue_description'] ?: 'غير محدد') . "\n"
            . "📍 " . ($req['customer_location'] ?: 'غير محدد') . "\n"
            . "📅 " . $createdAt->format('Y-m-d H:i') . "\n"
            . (!empty($req['visit_price']) ? "💰 رسوم الزيارة: " . (int)$req['visit_price'] . " دينار\n" : '')
            . "📌 " . $this->mapServiceStatus((string)$req['status'])
        );
    }

    private function isButtonPayload(string $text): bool
    {
        $buttons = [
            // IDs
            'btn_maintenance','btn_part_inquiry','btn_track','btn_inquiry',
            'btn_inquiry_q','btn_inquiry_note','btn_inquiry_comp',
            // عناوين القائمة (titles)
            '🔧 طلب صيانة','🧩 استفسار عن قطعة','📦 تتبع طلب','📝 استفسار',
            '📋 استفسار','💬 ملاحظة أو اقتراح','⚠️ شكوى',
            // نص بدون إيموجي
            'طلب صيانة','استفسار عن قطعة','تتبع طلب','استفسار',
            // descriptions القائمة
            'حجز صيانة للأجهزة المنزلية','صورة أو اسم قطعة الغيار',
            'متابعة برقم الطلب','استفسار أو ملاحظة',
            'سؤال أو استفسار عام','ملاحظة أو اقتراح أو مقترح',
            'تقديم شكوى رسمية',
        ];
        return in_array(trim($text), $buttons, true);
    }

    private function notifyAdmins(string $message): void
    {
        try {
            $this->sendToOwners($message);
            
            $ownersList = array_map('trim', explode(',', OWNER_PHONE));
            $stmt = $this->db->query("SELECT phone FROM users WHERE role='admin' AND phone IS NOT NULL AND phone!=''");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminPhone) {
                $adminPhone = trim($adminPhone);
                if (in_array($adminPhone, $ownersList, true)) continue;
                try {
                    $this->wa->sendText($adminPhone, $message);
                } catch (Throwable $e) {
                    error_log("[notifyAdmins] Failed to send to admin $adminPhone: " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log("[notifyAdmins] " . $e->getMessage());
        }
    }

    private function findLastRequestByCustomer(int $custId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT order_number, product_name, issue_description, customer_location, status, created_at
                 FROM service_requests WHERE customer_id=? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$custId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }

    private function findRequestByNumber(string $orderNum): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT order_number, product_name, issue_description, customer_location, status, created_at
                 FROM service_requests WHERE order_number=? LIMIT 1"
            );
            $stmt->execute([$orderNum]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }

    private function findLastRequestByCustomerPhone(string $phone): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT order_number, product_name, issue_description, customer_location, status, created_at
                 FROM service_requests WHERE customer_phone = ? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$phone]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getActiveDeviceTypes(): array
    {
        $stmt = $this->db->query("SELECT id, name_ar, icon FROM device_types WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getIssuesByDeviceType(int $deviceTypeId): array
    {
        $stmt = $this->db->prepare("SELECT id, issue_text_ar FROM common_issues WHERE device_type_id=? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$deviceTypeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getRecentHistory(int $convId, int $limit = 6): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT sender, body FROM messages
                 WHERE conversation_id=? AND msg_type='text' AND body!=''
                 ORDER BY id DESC LIMIT ?"
            );
            $stmt->execute([$convId, $limit]);
            return array_map(
                fn($r) => ['role' => $r['sender'] === 'customer' ? 'user' : 'assistant', 'content' => $r['body']],
                array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC))
            );
        } catch (Throwable $e) { return []; }
    }

    private function updateConvState(int $convId, string $state, array $draft): void
    {
        try {
            $this->db->prepare("UPDATE conversations SET state=?, order_draft=?, last_msg_at=NOW() WHERE id=?")
                ->execute([$state, json_encode($draft, JSON_UNESCAPED_UNICODE), $convId]);
        } catch (Throwable $e) { error_log("[updateConvState] " . $e->getMessage()); }
    }

    /**
     * ✅ متعدد القنوات: عمود `phone` صار يخزّن معرّف العميل حسب القناة
     * (رقم هاتف بواتساب، PSID بماسنجر، IGSID بانستقرام)، وعمود `channel`
     * الجديد بيميّز بينهم عشان نفس الرقم النظري ما يتلخبط بين قناتين.
     */
    private function getOrCreateCustomer(string $phone): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE phone=? AND channel=? LIMIT 1');
        $stmt->execute([$phone, $this->channel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $this->db->prepare('INSERT IGNORE INTO customers (phone, channel) VALUES (?, ?)')->execute([$phone, $this->channel]);
        $stmt->execute([$phone, $this->channel]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$this->db->lastInsertId(), 'phone' => $phone, 'channel' => $this->channel];
    }

    private function getOrCreateConversation(int $custId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM conversations WHERE customer_id=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$custId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $this->db->prepare(
            "INSERT INTO conversations (customer_id, channel, status, bot_paused, state, last_msg_at) VALUES (?, ?, 'bot', 0, 'idle', NOW())"
        )->execute([$custId, $this->channel]);
        $stmt->execute([$custId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$this->db->lastInsertId(), 'customer_id' => $custId, 'status' => 'bot', 'state' => 'idle'];
    }

    private function saveMessage(int $convId, string $sender, string $type, string $body, ?string $mediaPath = null, ?string $transcription = null, ?string $waMessageId = null): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO messages (conversation_id, sender, msg_type, body, media_path, transcription, wa_message_id) VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$convId, $sender, $type, $body, $mediaPath, $transcription, $waMessageId]);
        } catch (Throwable $e) { error_log("[saveMessage] " . $e->getMessage()); }
    }

    private function reply(string $phone, int $convId, string $text): void
    {
        try { $this->wa->sendText($phone, $text); } catch (Throwable $e) { error_log("[reply] " . $e->getMessage()); }
        $this->saveMessage($convId, 'bot', 'text', $text);
    }

    private function safeMarkRead(string $waId): void
    {
        try { $this->wa->markRead($waId); } catch (Throwable $e) {}
    }

    private function normalizeDigits(string $text): string
    {
        return str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], ['0','1','2','3','4','5','6','7','8','9'], $text);
    }

    /**
     * تحديد نوع الخدمة المتاحة حسب الموقع — بالكامل عبر OpenAI، بدون أي
     * قوائم مناطق مبرمجة مسبقاً. يُستخدم أيضاً لمعرفة هل الموقع داخل
     * الأردن أم لا (عبر $lastLocationIsJordan).
     * 'amman'  → صيانة بـ 10 دينار
     * 'zarqa'  → صيانة بـ 15 دينار
     * 'parts'  → بيع قطع فقط، لا صيانة
     * 'unknown'→ غير معروف
     */
    private function detectServiceArea(string $location): string
    {
        $result = $this->ai->classifyServiceArea($location);
        $this->lastLocationIsJordan = (bool)($result['is_jordan'] ?? true);
        return (string)($result['area_type'] ?? 'unknown');
    }

    private function normalizeArabicText(string $text): string
    {
        $text = trim(mb_strtolower($text));
        $text = strtr($text, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ة'=>'ه','ى'=>'ي','ؤ'=>'و','ئ'=>'ي']);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function detectMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'image/jpeg',
        };
    }

    private function buildOrderNumber(int $requestId): string
    {
        return 'SR-' . date('Y') . '-' . str_pad((string)$requestId, 6, '0', STR_PAD_LEFT);
    }

    private function mapServiceStatus(string $status): string
    {
        return match ($status) {
            'pending'     => '⏳ قيد الانتظار',
            'assigned'    => '👨‍🔧 تم تخصيص فني',
            'in_progress' => '🔧 قيد التنفيذ',
            'completed'   => '✅ تم الإنجاز',
            'cancelled'   => '❌ ملغي',
            default       => '📌 قيد المعالجة',
        };
    }

    // ═══════════════════════════════════════════════════════════════
    //  API عامة للاستخدام الخارجي
    // ═══════════════════════════════════════════════════════════════
    public function sendDirectMessage(string $phone, string $text): array
    {
        try { $this->wa->sendText($phone, $text); return ['ok' => true]; }
        catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    public function resumeConversation(int $conversationId): bool
    {
        return (bool)$this->db->prepare(
            "UPDATE conversations SET status='bot', bot_paused=0 WHERE id=?"
        )->execute([$conversationId]);
    }

    public function pauseBot(int $conversationId): bool
    {
        return (bool)$this->db->prepare(
            "UPDATE conversations SET status='waiting_owner_answer', bot_paused=1, last_msg_at=NOW() WHERE id=?"
        )->execute([$conversationId]);
    }

    public function getPendingNotifications(): array
    {
        return $this->db->query(
            "SELECT n.*, cu.phone FROM notifications n JOIN customers cu ON cu.id=n.customer_id WHERE n.status='pending' ORDER BY n.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getConversation(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM conversations WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  تنظيف ملفات الميديا القديمة (صور وصوت) — صيانة دورية للسيرفر
    // ═══════════════════════════════════════════════════════════════
    /**
     * يحذف رسائل الصور والصوت الأقدم من $days يوماً: يحذف الملف الفعلي من
     * السيرفر (uploads/images, uploads/audio) ثم يحذف سجل الرسالة نفسه من
     * جدول messages نهائياً (حذف كامل ومتزامن، بدون ترك سجلات بدون ملف).
     *
     * يُستدعى عبر سكريبت cron مستقل (cleanup_old_media.php) — لا علاقة له
     * بالداشبورد أو الـ API.
     */
    public function cleanupOldMedia(int $days = 7): array
    {
        $deletedFiles   = 0;
        $missingFiles   = 0;
        $deletedRows    = 0;
        $failedDeletes  = 0;

        try {
            $stmt = $this->db->prepare("
                SELECT id, media_path
                FROM messages
                WHERE msg_type IN ('image', 'audio', 'video')
                  AND media_path IS NOT NULL
                  AND media_path != ''
                  AND created_at < (NOW() - INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $path = (string)$row['media_path'];

                if ($path !== '' && is_file($path)) {
                    if (@unlink($path)) {
                        $deletedFiles++;
                    } else {
                        $failedDeletes++;
                        error_log("[cleanupOldMedia] فشل حذف الملف: {$path}");
                        continue; // لا نحذف السجل لو فشل حذف الملف، عشان نقدر نحاول ثاني مرة لاحقاً
                    }
                } else {
                    // الملف غير موجود من الأساس (محذوف مسبقاً أو المسار غير صحيح) — نحذف السجل برضو
                    $missingFiles++;
                }

                $this->db->prepare("DELETE FROM messages WHERE id = ?")->execute([$row['id']]);
                $deletedRows++;
            }
        } catch (Throwable $e) {
            error_log("[cleanupOldMedia] " . $e->getMessage());
        }

        return [
            'days_threshold' => $days,
            'deleted_files'  => $deletedFiles,
            'missing_files'  => $missingFiles,
            'deleted_rows'   => $deletedRows,
            'failed_deletes' => $failedDeletes,
        ];
    }
}