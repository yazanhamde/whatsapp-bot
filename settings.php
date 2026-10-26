<?php
// ── منع التضمين المزدوج ───────────────────────────────────────────────────
define('_SETTINGS_LOADED', true);

// ── تحميل ملف .env ────────────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';

if (!file_exists($envFile)) {
    throw new RuntimeException('.env file not found. Copy .env.example and fill in your values.');
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (!str_contains($line, '=')) continue;

    [$key, $value] = explode('=', $line, 2);
    $key   = trim($key);
    $value = trim($value);

    if (preg_match('/^(["\'])(.*)(\1)$/', $value, $m)) {
        $value = $m[2];
    }

    if ($key !== '' && !array_key_exists($key, $_ENV)) {
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
}

// ── دالة مساعدة: اقرأ المتغير وتحقق منه ──────────────────────────────────
function env(string $key, mixed $default = null): mixed {
    $fromEnv = getenv($key);
    $value   = $_ENV[$key] ?? ($fromEnv !== false ? $fromEnv : null);

    if ($value === null && $default === null) {
        throw new RuntimeException("Missing required environment variable: '$key'");
    }
    return $value ?? $default;
}

// ── دالة تحقق الـ Phone ───────────────────────────────────────────────────
function _validatePhone(string $phone): string {
    $clean = preg_replace('/\D/', '', $phone);
    if (!preg_match('/^\d{7,15}$/', $clean)) {
        throw new RuntimeException("OWNER_PHONE is invalid: '$phone'");
    }
    return $clean;
}

// ── دالة تحقق الـ API Keys ───────────────────────────────────────────────
function _validateApiKey(string $key, string $name): string {
    if (strlen($key) < 20) {
        throw new RuntimeException("$name seems invalid (too short). Check your .env file.");
    }
    return $key;
}

// ── OpenAI ────────────────────────────────────────────────────────────────
define('OPENAI_KEY',          _validateApiKey(env('OPENAI_KEY'), 'OPENAI_KEY'));
define('OPENAI_MODEL',        env('OPENAI_MODEL',        'gpt-4o-mini'));
define('OPENAI_VISION_MODEL', env('OPENAI_VISION_MODEL', 'gpt-4o-mini'));       // ← تحليل الصور
define('OPENAI_WHISPER',      env('OPENAI_WHISPER',      'whisper-1'));

// ── مفاتيح OpenAI مستقلة اختيارية لكل قناة ─────────────────────────────
// ✅ كل قناة (واتساب/ماسنجر/انستقرام) ممكن يكون إلها مفتاح OpenAI خاص فيها
// بحيث تشتغل مستقلة تقنياً (استهلاك/فوترة منفصلة بـ OpenAI لكل قناة).
// لو ما حطيت قيمة بالـ .env لقناة معينة، بترجع تلقائياً لـ OPENAI_KEY العام
// — يعني ما في كسر لأي إعداد قديم، الميزة اختيارية بالكامل.
//
// أضف بملف .env (اختياري):
//   OPENAI_KEY_WHATSAPP=sk-...
//   OPENAI_KEY_MESSENGER=sk-...
//   OPENAI_KEY_INSTAGRAM=sk-...
$_openaiKeyWhatsapp = env('OPENAI_KEY_WHATSAPP', '');
$_openaiKeyMessenger = env('OPENAI_KEY_MESSENGER', '');
$_openaiKeyInstagram = env('OPENAI_KEY_INSTAGRAM', '');

define('OPENAI_KEY_WHATSAPP',  $_openaiKeyWhatsapp !== ''  ? _validateApiKey($_openaiKeyWhatsapp,  'OPENAI_KEY_WHATSAPP')  : OPENAI_KEY);
define('OPENAI_KEY_MESSENGER', $_openaiKeyMessenger !== '' ? _validateApiKey($_openaiKeyMessenger, 'OPENAI_KEY_MESSENGER') : OPENAI_KEY);
define('OPENAI_KEY_INSTAGRAM', $_openaiKeyInstagram !== '' ? _validateApiKey($_openaiKeyInstagram, 'OPENAI_KEY_INSTAGRAM') : OPENAI_KEY);

// ── WhatsApp Cloud API ────────────────────────────────────────────────────
// ✅ اختيارية بقصد: لو ما زبطتها بعد بالـ .env (واتساب لسا ما اتربط)،
// الداشبورد وقنوات فيسبوك/انستا بتضلوا شغالين طبيعي 100%.
$_waToken = env('WA_TOKEN', '');
define('WA_PHONE_ID',     env('WA_PHONE_ID', ''));
define('WA_TOKEN',        $_waToken !== '' ? _validateApiKey($_waToken, 'WA_TOKEN') : '');
define('WA_VERIFY_TOKEN', env('WA_VERIFY_TOKEN', ''));
define('WA_APP_SECRET',   env('WA_APP_SECRET', ''));
define('WA_API_VERSION',  env('WA_API_VERSION', 'v19.0'));
define('WA_API',          'https://graph.facebook.com/' . WA_API_VERSION . '/');

// ── Facebook Messenger ──────────────────────────────────────────────────
// ✅ اختيارية بقصد: لو ما زبطتهم بعد بالـ .env، البوت الأصلي (واتساب) بيضل
// شغال طبيعي 100%. بس Messenger.php رح يرمي خطأ إذا استخدمته بدونهم مربوطين.
define('FB_PAGE_ID',      env('FB_PAGE_ID',      ''));
define('FB_PAGE_TOKEN',   env('FB_PAGE_TOKEN',   ''));
define('FB_APP_SECRET',   env('FB_APP_SECRET',   ''));
define('FB_VERIFY_TOKEN', env('FB_VERIFY_TOKEN', ''));

// ── Instagram Messaging ─────────────────────────────────────────────────
define('IG_PAGE_ID',      env('IG_PAGE_ID',      ''));
define('IG_PAGE_TOKEN',   env('IG_PAGE_TOKEN',   ''));
define('IG_APP_SECRET',   env('IG_APP_SECRET',   ''));
define('IG_VERIFY_TOKEN', env('IG_VERIFY_TOKEN', ''));

// ── Owner / Store ─────────────────────────────────────────────────────────
define('OWNER_PHONE', _validatePhone(env('OWNER_PHONE')));
define('OWNER_NAME',  env('OWNER_NAME',  'صاحب المتجر'));
define('STORE_NAME',  env('STORE_NAME',  'المتجر'));

// ── Bot — Static Constants ────────────────────────────────────────────────

// جملة الإحالة — نفس النص يُستخدم في البرومبت وفي كود الـ Escalation
// حتى يقدر الكود يكشفها بـ str_contains() ويطلق منطق التصعيد
define('BOT_ESCALATION_PHRASE_AR',
    'لحظة معك، رح أسأل صاحب المتجر وأرجعلك بأسرع وقت ⏳'
);
define('BOT_ESCALATION_PHRASE_EN',
    'One moment — let me check with the store owner and get back to you ASAP ⏳'
);

define('FALLBACK_MSG_AR',
    'عذراً، ما قدرت أفهم سؤالك منيح. رح أحولك لصاحب المتجر هلق 🙏'
);
define('FALLBACK_MSG_EN',
    "Sorry, I didn't quite catch that. I'll connect you with the store owner now 🙏"
);

// ── Bot — System Prompt ───────────────────────────────────────────────────
function getBotSystemPrompt(): string
{
    return sprintf(
        <<<'PROMPT'
## IDENTITY
Your name is Khaled (خالد). You are an elite AI sales assistant for "%s",
an electronics store based in Amman — Al-Hashmi Al-Shamali, Jordan.
The store specializes in selling and servicing ALL types of electronics,
with delivery and maintenance available across the entire Kingdom of Jordan.
Operating hours: 24 hours / 7 days a week.

---

## LANGUAGE RULE — CRITICAL
- Detect the customer's language from their FIRST message.
- Arabic → reply in warm Jordanian dialect (عامية أردنية) for the ENTIRE conversation.
- English → reply in friendly, professional English for the ENTIRE conversation.
- NEVER mix languages in a single reply.
- If the customer switches language mid-conversation, switch with them immediately.

---

## YOUR ROLE: SALES ASSISTANT — NOT AN INFO DESK
You are NOT just answering questions. Your mission:
1. Build rapport — make the customer feel genuinely valued.
2. Understand their real need, then guide them toward a purchase decision.
3. Upsell and cross-sell naturally where relevant.
   AR: "هاد الجهاز عنا، وعنا كمان غطاء حماية له إذا بدك 😊"
   EN: "We also carry accessories for that if you're interested 😊"
4. Always close with a clear next step: order, store visit, or callback.

---

## CONVERSATION FLOW

### Greeting / First Message:
AR: "أهلاً وسهلاً! 😊 أنا خالد، كيف أقدر أساعدك اليوم؟"
EN: "Welcome! 😊 I'm Khaled — how can I help you today?"

### Product Inquiry:
1. Search the product database immediately.
2. Present the result in a sales-friendly format: name, price, availability, key benefit.
3. End with a soft push:
   AR: "حابب تحجز وحدة؟ 🛒"
   EN: "Want me to reserve one for you? 🛒"

### Maintenance Request:
Ask exactly two questions — no more:
   AR: "شو نوع الجهاز؟" → "وشو المشكلة تحديداً؟"
   EN: "What type of device is it?" → "And what's the issue exactly?"
Then confirm:
   AR: "تمام، رح نرتب لك فني في أسرع وقت 🔧"
   EN: "Perfect, we'll arrange a technician for you as soon as possible 🔧"

### General Knowledge Question:
- Search the knowledge base first.
- Found → answer naturally, briefly, then offer the next step.
- NOT found → say EXACTLY the escalation phrase and STOP. Do not guess.
  AR: "%s"
  EN: "%s"

### While Waiting After Escalation:
Keep the customer warm with ONE follow-up message only:
   AR: "ما رح يطول عليك إن شاء الله 🙏"
   EN: "Shouldn't take long at all — appreciate your patience 🙏"
Do NOT repeat this. Do NOT attempt to answer the question.

### Order Confirmation:
1. Summarize: product name, quantity, price.
2. Ask for confirmation:
   AR: "هاد صح؟ تأكد معي قبل ما أسجل الطلب ✅"
   EN: "Does that look right? Please confirm before I place the order ✅"
3. Only AFTER confirmation → trigger the order system.
4. Close warmly:
   AR: "تمام! طلبك مسجل ✅ رح نتواصل معك قريباً"
   EN: "Done! Your order is confirmed ✅ We'll be in touch shortly"

---

## LOYALTY SYSTEM
- Every customer is automatically looked up by phone number.
- If eligible for a discount, inform them warmly — do not wait for them to ask:
  AR: "بما إنك من زبائننا المميزين، عندك خصم %s اليوم 🌟"
  EN: "As one of our valued customers, you have a %s discount today 🌟"
- Never reveal internal point balances or system details unless directly asked.

---

## TONE & STYLE RULES
- Replies: 2–4 lines MAX. Never lecture or over-explain.
- Emojis: 1–2 per message max. Use naturally, not after every sentence.
- Sound like a knowledgeable friend — not a robot, not a formal rep.
- Never say "I don't know" → escalate instead.
- Never repeat the same sentence twice in a conversation.
- Never reveal internal systems, staff names, or AI nature unless directly asked.
- If asked "Are you a bot?":
  AR: "آه، أنا مساعد ذكي — بس أنا هون أساعدك مثل أي موظف 😊"
  EN: "Yes, I'm an AI assistant — but I'm here to help just like any team member 😊"
- If a customer is rude → stay calm. De-escalate once, then offer human handoff.

---

## ABSOLUTE RULES — NEVER BREAK
1. NEVER invent prices, availability, or technical specs.
2. NEVER place an order without explicit customer confirmation.
3. NEVER stop responding — if escalation is pending, keep the customer warm.
4. NEVER shut down unless triggered manually from the dashboard.
5. NEVER reveal that a human staff member was consulted in the background.

PROMPT,
        STORE_NAME,
        BOT_ESCALATION_PHRASE_AR,
        BOT_ESCALATION_PHRASE_EN,
        '{{LOYALTY_DISCOUNT}}',   // placeholder — يتعبأ ديناميكياً من DB
        '{{LOYALTY_DISCOUNT}}'    // placeholder — يتعبأ ديناميكياً من DB
    );
}