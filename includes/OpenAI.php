<?php
require_once __DIR__ . '/../config/settings.php';

class OpenAI {

    private const BASE_URL    = 'https://api.openai.com/v1/';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 500;

    // ─── حدود التوكن لكل وظيفة ───────────────────────────────────
    private const MAX_TOKENS_CHAT        = 500;  // ✅ إصلاح ١: رُفع من 400 → 500 عشان JSON محمد ما ينقطع
    private const MAX_TOKENS_INTENT      = 10;   // تحديد النية
    private const MAX_TOKENS_VISION      = 300;  // تحليل صورة
    private const MAX_TOKENS_PART        = 200;  // تحليل قطعة
    private const MAX_TOKENS_GENERAL_Q   = 150;  // سؤال جانبي (محتفظ به بس مش مستخدم بـ handleMohammad)
    private const MAX_TOKENS_ISSUE_CHECK = 5;    // فحص المشكلة
    private const MAX_TOKENS_LOCATION    = 5;    // فحص الموقع

    private string $defaultModel;

    public function __construct() {
        $this->defaultModel = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini';
    }

    // ═══════════════════════════════════════════════════════════════
    //  HTTP Core
    // ═══════════════════════════════════════════════════════════════

    private function post(string $endpoint, array $data): array {
        $ch = curl_init(self::BASE_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . OPENAI_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException("OpenAI cURL: $curlErr");

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log("[OpenAI::post] Raw: " . substr((string)$raw, 0, 500));
            throw new RuntimeException("OpenAI invalid JSON response");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException(
                "OpenAI HTTP $httpCode ($endpoint): " . ($decoded['error']['message'] ?? 'Unknown')
            );
        }

        return $decoded;
    }

    private function postWithRetry(string $endpoint, array $data): array {
        $attempt = 0;
        $delay   = self::RETRY_DELAY;

        while (true) {
            try {
                return $this->post($endpoint, $data);
            } catch (RuntimeException $e) {
                $attempt++;
                preg_match('/HTTP (\d{3})/', $e->getMessage(), $m);
                $httpCode    = isset($m[1]) ? (int)$m[1] : 0;
                $isRetryable = $httpCode === 429 || $httpCode >= 500 || str_contains($e->getMessage(), 'cURL');
                if (!$isRetryable || $attempt >= self::MAX_RETRIES) throw $e;
                error_log("[OpenAI] Retry $attempt: " . $e->getMessage());
                usleep($delay * 1000);
                $delay *= 2;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  Chat — المحادثة العامة
    // ═══════════════════════════════════════════════════════════════

    public function chat(
        array $messages,
        float $temp      = 0.4,
        int   $maxTokens = self::MAX_TOKENS_CHAT,
        bool  $jsonMode  = false
    ): string {
        if (empty($messages)) throw new InvalidArgumentException('chat() requires at least one message.');

        $payload = [
            'model'       => $this->defaultModel,
            'messages'    => $messages,
            'temperature' => max(0.0, min(2.0, $temp)),
            // ✅ إصلاح ١: min() الآن بيسمح بـ 500 بدل ما يحدّ عند 400
            'max_tokens'  => min($maxTokens, self::MAX_TOKENS_CHAT),
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $res     = $this->postWithRetry('chat/completions', $payload);
        $content = $res['choices'][0]['message']['content'] ?? null;
        if ($content === null) throw new RuntimeException('OpenAI returned no content');
        return trim($content);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Intent Classification
    // ═══════════════════════════════════════════════════════════════

    public function intent(string $msg, array $history = []): string {
        static $allowed = [
            'product_search','price_inquiry','availability',
            'part_request','maintenance',
            'delivery','payment','warranty',
            'greeting','goodbye','cancel_order',
            'follow_up','complaint','store_info',
            'unknown',
        ];

        if (trim($msg) === '') return 'unknown';

        $quick = $this->quickIntent($msg);
        if ($quick !== null) return $quick;

        $contextMessages = array_slice($history, -2);
        $reply = $this->postWithRetry('chat/completions', [
            'model'       => $this->defaultModel,
            'temperature' => 0.0,
            'max_tokens'  => self::MAX_TOKENS_INTENT,
            'messages'    => [
                ['role' => 'system', 'content'
                    => 'صنّف الرسالة بكلمة واحدة فقط: ' . implode(' | ', $allowed)],
                ...$contextMessages,
                ['role' => 'user', 'content' => $msg],
            ],
        ]);

        $intent = strtolower(trim($reply['choices'][0]['message']['content'] ?? 'unknown'));
        return in_array($intent, $allowed, true) ? $intent : 'unknown';
    }

    private function quickIntent(string $msg): ?string {
        $t = mb_strtolower(trim($msg));

        if (preg_match('/^(مرحبا|هلا|اهلين|السلام|هلو|الو|صباح|مساء|hi|hello|hey)/u', $t))
            return 'greeting';
        if (preg_match('/(مع السلامة|باي|شكرا|شكراً|bye|goodbye|thanks)/u', $t))
            return 'goodbye';
        if (preg_match('/(الغاء|إلغاء|cancel)/u', $t))
            return 'cancel_order';
        if (preg_match('/(قطعة\s*غيار|بدي\s*قطعة|عندك\s*قطعة|part|spare\s*part)/u', $t))
            return 'part_request';
        if (preg_match('/(بكم|كم\s*سعر|قديش|price|how\s*much)/u', $t))
            return 'price_inquiry';
        if (preg_match('/(صيانة|صيانه|يصلح|تصليح|عطل|خربان|maintenance|repair)/u', $t))
            return 'maintenance';
        if (preg_match('/(تتبع|متابعة|وين\s*الطلب|رقم\s*الطلب|track\s*order)/u', $t))
            return 'follow_up';
        if (preg_match('/(شكوى|اشتكي|complaint)/u', $t))
            return 'complaint';
        if (preg_match('/(ساعات|الدوام|أوقات|عنوان|وين\s*موقعكم|working\s*hours)/u', $t))
            return 'store_info';

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  Vision — تحليل صورة قطعة غيار
    // ═══════════════════════════════════════════════════════════════

    public function analyzePartImage(string $imageBase64, string $mimeType = 'image/jpeg'): array {
        if (trim($imageBase64) === '') throw new RuntimeException('analyzePartImage: empty image');

        $prompt = "أنت خبير قطع غيار أجهزة منزلية. حلل الصورة وأرجع JSON فقط:\n"
                . '{"part_name_ar":"","part_name_en":"","device_type":"","model_code":""}' . "\n"
                . "إذا ظهر كود على القطعة ضعه في model_code. لا تضف markdown.";

        $model = defined('OPENAI_VISION_MODEL') ? OPENAI_VISION_MODEL : $this->defaultModel;

        $res = $this->postWithRetry('chat/completions', [
            'model'           => $model,
            'max_tokens'      => self::MAX_TOKENS_VISION,
            'temperature'     => 0,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => [
                        'url'    => "data:{$mimeType};base64,{$imageBase64}",
                        'detail' => 'low',
                    ]],
                ],
            ]],
        ]);

        $content = trim((string)($res['choices'][0]['message']['content'] ?? ''));
        $parsed  = json_decode($content, true);

        if (!is_array($parsed)) {
            error_log("[OpenAI::analyzePartImage] Invalid JSON: $content");
            return ['part_name_ar'=>'','part_name_en'=>'','device_type'=>'','model_code'=>''];
        }

        return [
            'part_name_ar' => trim((string)($parsed['part_name_ar'] ?? '')),
            'part_name_en' => trim((string)($parsed['part_name_en'] ?? '')),
            'device_type'  => trim((string)($parsed['device_type']  ?? '')),
            'model_code'   => trim((string)($parsed['model_code']   ?? '')),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  analyzePart — تحليل نص + رابط صورة
    // ═══════════════════════════════════════════════════════════════

    public function analyzePart(?string $text, ?string $imageUrl): string {
        $content = [];
        if ($text)     $content[] = ['type' => 'text', 'text' => "Identify this part: $text"];
        if ($imageUrl) $content[] = ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']];

        if (empty($content)) return '';

        $res = $this->postWithRetry('chat/completions', [
            'model'       => $this->defaultModel,
            'max_tokens'  => self::MAX_TOKENS_PART,
            'temperature' => 0.3,
            'messages'    => [['role' => 'user', 'content' => $content]],
        ]);

        return trim($res['choices'][0]['message']['content'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════
    //  isJordanLocation — فحص الموقع
    //
    //  ✅ تم التعديل:
    //  1- إضافة الشوارع الحيوية للمصفوفة لتعمل فورا بالـ Regex (ببلاش وصفر توكنز).
    //  2- حصر الـ AI بالبيئة الأردنية لمنع الرفض العالمي للشوارع المشتركة.
    // ═══════════════════════════════════════════════════════════════

    /**
     * تصنيف الموقع بالكامل عبر الذكاء الاصطناعي — بدون أي قوائم مناطق مبرمجة مسبقاً.
     * يُرجع: هل الموقع داخل الأردن؟ وما نوع منطقة الخدمة (عمان / الزرقاء والسلط / قطع فقط)؟
     *
     * area_type:
     *  - amman   → صيانة منزلية، رسوم 10 دينار
     *  - zarqa   → صيانة منزلية، رسوم 15 دينار (الزرقاء والسلط)
     *  - parts   → خارج نطاق الصيانة المنزلية، بيع قطع غيار فقط
     *  - unknown → داخل الأردن لكن غير قادر على تحديد التصنيف الدقيق
     */
    public function classifyServiceArea(string $location): array {
        $default = ['is_jordan' => true, 'area_type' => 'unknown'];

        try {
            $res = $this->postWithRetry('chat/completions', [
                'model'            => $this->defaultModel,
                'max_tokens'       => 80,
                'temperature'      => 0.0,
                'response_format'  => ['type' => 'json_object'],
                'messages'         => [
                    [
                        'role'    => 'system',
                        'content' => 'أنت نظام تصنيف مواقع لمحل صيانة أجهزة منزلية يعمل داخل الأردن فقط، ومحصور جغرافياً بالأردن. '
                            . 'استلمت نص موقع من زبون (اسم منطقة، مدينة، شارع، أو معلم). حدد:'
                            . "\n1) is_jordan: هل هذا الموقع داخل الأردن؟ (true/false). لا ترفض أسماء شوارع أو معالم متكررة عالمياً (مثل شارع الجامعة) إلا إذا ذكر الزبون دولة أخرى صراحة."
                            . "\n2) area_type: واحدة من القيم التالية فقط:"
                            . "\n   - amman: إذا كان الموقع داخل مدينة عمان أو ضواحيها المباشرة."
                            . "\n   - zarqa: إذا كان الموقع داخل محافظة الزرقاء أو محافظة السلط/البلقاء."
                            . "\n   - parts: إذا كان الموقع داخل الأردن لكن في أي محافظة أخرى بعيدة (مثل إربد، الكرك، معان، العقبة، المفرق، جرش، عجلون، الطفيلة، الرمثا وغيرها)."
                            . "\n   - unknown: إذا كان داخل الأردن لكن لم تستطع تحديد المحافظة بدقة."
                            . "\nرد بصيغة JSON فقط وبدون أي نص خارجها، بالشكل التالي تماماً: "
                            . '{"is_jordan": true, "area_type": "amman"}',
                    ],
                    ['role' => 'user', 'content' => $location],
                ],
            ]);

            $content = $res['choices'][0]['message']['content'] ?? '';
            $parsed = json_decode((string)$content, true);
            // حماية من الهلوسة: JSON خربان أو قيمة غير متوقعة → parts
            if (!is_array($parsed)) {
                error_log('[OpenAI::classifyServiceArea] Hallucination: ' . substr($content, 0, 200));
                return ['is_jordan' => true, 'area_type' => 'parts'];
            }

            $isJordan = (bool)($parsed['is_jordan'] ?? true);
            $areaType = (string)($parsed['area_type'] ?? 'parts');
            if (!in_array($areaType, ['amman', 'zarqa', 'parts', 'unknown'], true)) {
                $areaType = 'parts';
            }

            return ['is_jordan' => $isJordan, 'area_type' => $areaType];
        } catch (Throwable $e) {
            error_log("[OpenAI::classifyServiceArea] " . $e->getMessage());
            return $default;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  answerGeneralQuestion — سؤال جانبي
    //
    //  ✅ إصلاح ٢: هاي الدالة موجودة بس ما تُستدعى من handleMohammad
    //  محمد يجاوب كل شي بطلب واحد عبر chat() مباشرة
    //  answerGeneralQuestion محتفظة بها لو احتجتها من مكان ثاني
    // ═══════════════════════════════════════════════════════════════

    public function answerGeneralQuestion(string $question): string {
        $systemPrompt = "أنت مساعد بمحل صيانة أجهزة منزلية بالأردن. أجب على أسئلة الزبائن عن المحل:\n"
            . "- نصلح: غسالات، ثلاجات، مكيفات، أفران، سخانات، مجففات، ميكرويف\n"
            . "- الموقع: عمان، الهاشمي الشمالي، بجانب المغفر\n"
            . "- أوقات العمل: 9 صباحاً - 10 مساءً (عدا الجمعة)\n"
            . "- الفحصية: 10 دنانير داخل عمان\n"
            . "أجب بأسلوب عربي بسيط، مزيج بين الفصحى والعامية العربية العامة، مختصراً. إذا السؤال ليس عن المحل رد بـ NONE فقط.";

        try {
            $res = $this->postWithRetry('chat/completions', [
                'model'       => $this->defaultModel,
                'max_tokens'  => self::MAX_TOKENS_GENERAL_Q,
                'temperature' => 0.3,
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $question],
                ],
            ]);
            $response = trim($res['choices'][0]['message']['content'] ?? '');
            return ($response === 'NONE' || mb_strlen($response) < 3) ? '' : $response;
        } catch (Throwable $e) {
            error_log("[OpenAI::answerGeneralQuestion] " . $e->getMessage());
            return '';
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  Audio Transcription — Whisper
    // ═══════════════════════════════════════════════════════════════

    public function transcribe(string $filePath): string {
        if (!file_exists($filePath)) throw new RuntimeException("Transcribe: file not found: $filePath");

        $attempt = 0;
        $delay   = self::RETRY_DELAY;

        while (true) {
            try {
                return $this->doTranscribe($filePath);
            } catch (RuntimeException $e) {
                $attempt++;
                preg_match('/HTTP (\d{3})/', $e->getMessage(), $m);
                $httpCode    = isset($m[1]) ? (int)$m[1] : 0;
                $isRetryable = $httpCode === 429 || $httpCode >= 500 || str_contains($e->getMessage(), 'cURL');
                if (!$isRetryable || $attempt >= self::MAX_RETRIES) throw $e;
                usleep($delay * 1000);
                $delay *= 2;
            }
        }
    }

    private function doTranscribe(string $filePath): string {
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . OPENAI_KEY],
            CURLOPT_POSTFIELDS     => [
                'file'  => new CURLFile($filePath),
                'model' => defined('OPENAI_WHISPER') ? OPENAI_WHISPER : 'whisper-1',
            ],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException("Whisper cURL: $curlErr");
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new RuntimeException("Whisper invalid JSON");
        if ($httpCode >= 400) throw new RuntimeException("Whisper HTTP $httpCode: " . ($decoded['error']['message'] ?? 'Unknown'));
        return trim($decoded['text'] ?? '');
    }

    /**
     * يحدد هل رسالة الزبون هي إجابة فعلية على السؤال المطروح بالخطوة الحالية،
     * أو سؤال/طلب خارجي مختلف تماماً (مثل: طلب الموقع، رقم الهاتف، الأسعار،
     * ساعات الدوام، شكوى...). تتسامح تماماً مع الأخطاء الإملائية ولا تعتبرها
     * سبباً لاعتبار الرسالة خارجة عن الموضوع.
     *
     * تُستخدم بكل خطوة بفلو عبدالله (الماركة، وصف العطل، الموقع، الاستفسار/
     * الملاحظة/الشكوى) لمنع تخزين سؤال خارجي بالغلط كقيمة فعلية للحقل.
     */
    // ═══════════════════════════════════════════════════════════════
    //  Embeddings — لنظام RAG
    // ═══════════════════════════════════════════════════════════════

    public function embed(string $text): array
    {
        if (trim($text) === '') return [];
        try {
            $res = $this->postWithRetry('embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => mb_substr(trim($text), 0, 2000),
            ]);
            return (array)($res['data'][0]['embedding'] ?? []);
        } catch (Throwable $e) {
            error_log('[OpenAI::embed] ' . $e->getMessage());
            return [];
        }
    }

    public function isOfftopicReply(string $fieldDescription, string $context, string $text): bool
    {
        try {
            $answer = trim($this->chat([
                ['role' => 'system', 'content' =>
                    "أنت مصنّف نوايا لبوت خدمة عملاء. سُئل الزبون عن: {$fieldDescription}"
                    . ($context !== '' ? " (السياق: {$context})" : '')
                    . ". مهمتك: حدد هل رسالة الزبون هي إجابة حقيقية على هذا السؤال بالتحديد "
                    . "(حتى لو فيها خطأ إملائي أو صياغة غير دقيقة أو مختصرة)، أو هي سؤال أو طلب "
                    . "مختلف تماماً عن الموضوع المطروح (مثل: طلب الموقع أو الخريطة، رقم الهاتف، "
                    . "الأسعار، ساعات الدوام، شكوى، استفسار عام، أو أي موضوع آخر غير مرتبط مباشرة "
                    . "بالسؤال). تجاهل الأخطاء الإملائية تماماً ولا تعتبرها سبباً لاعتبار الرسالة "
                    . "خارجة عن الموضوع — إجابة فيها خطأ إملائي تبقى إجابة صحيحة. "
                    . "رد بكلمة واحدة فقط: OFFTOPIC أو ANSWER."
                ],
                ['role' => 'user', 'content' => $text],
            ], temp: 0.0, maxTokens: 10));

            return str_contains(strtoupper($answer), 'OFFTOPIC');
        } catch (Throwable $e) {
            error_log('[OpenAI::isOfftopicReply] ' . $e->getMessage());
            // بالشك (فشل الاتصال)، اعتبرها إجابة عادية ولا توقف الفلو
            return false;
        }
    }
}