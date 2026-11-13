<?php
declare(strict_types=1);
/**
 * ProductSearch.php — النسخة النهائية
 *
 * منطق البحث النصي:
 * ١. كود مباشر
 * ٢. product_aliases → product_id مباشر
 * ٣. تحويل الاسم العامي → رسمي عبر aliases → FULLTEXT + LIKE
 * ٤. FULLTEXT بالاستعلام الأصلي
 * ٥. LIKE fallback
 *
 * منطق البحث بالصورة:
 * ١. GPT يحلل الصورة → نوع القطعة + جهاز + ماركة + كود
 * ٢. product_aliases → product_id مباشر
 * ٣. مرشحين بالاسم الرسمي → مقارنة بصرية بالصور
 * ٤. fallback نصي
 */

require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/db.php';

class ProductSearch
{
    private PDO    $db;
    private string $openaiKey       = '';
    private string $_lastPartName   = '';
    private string $_lastDevice     = '';
    private string $_lastBrand      = '';
    private string $_lastCode       = '';
    private string $_lastVisualDesc = '';
    private array  $_lastEmbedding  = [];

    // حد التشابه — فوقه نعتبر مرشّح الفهرس البصري "مطابقة محتملة"
    private const VISUAL_SIMILARITY_THRESHOLD = 0.45;

    private const COLS       = 'id, code, name, condition_status, image_url, quantity';
    private const LIMIT      = 5;
    private const MIN_WORD   = 2;
    private const STOP_WORDS = [
        'من','في','على','عن','مع','هل','ما','هو','هي','الى','إلى',
        'and','the','for','with','from',
    ];

    public function __construct()
    {
        $this->db        = getDB();
        $this->openaiKey = defined('OPENAI_KEY') ? OPENAI_KEY : (getenv('OPENAI_KEY') ?: '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: البحث النصي الرئيسي
    // ═══════════════════════════════════════════════════════════════════════

    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') return [];

        // ١. كود مباشر
        $byCode = $this->searchByCode($query);
        if (!empty($byCode)) return $byCode;

        // ٢. ابحث في product_aliases بالنص كاملاً → product_id مباشر
        $byAlias = $this->searchByAlias($query);
        if (!empty($byAlias)) return $byAlias;

        // ٣. حوّل الاسم العامي → رسمي وابحث
        $resolved = $this->resolveToOfficial($query);
        if ($resolved && $resolved !== $query) {
            $ft = $this->searchFullText($resolved);
            if (!empty($ft)) return $ft;
            $like = $this->searchLikeAllWords($resolved);
            if (!empty($like)) return $like;
        }

        // ٤. FULLTEXT بالأصل
        $ft = $this->searchFullText($query);
        if (!empty($ft)) return $ft;

        // ٥. LIKE بكل الكلمات
        $like = $this->searchLikeAllWords($query);
        if (!empty($like)) return $like;

        // ٦. LIKE بأفضل كلمة
        return $this->searchLikeBestWord($query);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: البحث بالصورة
    // ═══════════════════════════════════════════════════════════════════════

    public function searchByImage(string $imageBase64, string $mimeType = 'image/jpeg'): array
    {
        // ٠. ابحث أولاً بالـ Visual Index إذا كان مبني
        $fromIndex = $this->searchVisualIndex($imageBase64, $mimeType);
        if (!empty($fromIndex)) return $fromIndex;

        // ١. GPT يحلل الصورة (fallback)
        $analysis = $this->analyzeImage($imageBase64, $mimeType);
        if (empty($analysis)) return [];

        $partName = $this->_lastPartName;
        $device   = $this->_lastDevice;
        $brand    = $this->_lastBrand;
        $code     = $this->_lastCode;

        // ٢. كود مباشر
        if ($code) {
            $r = $this->searchByCode($code);
            if (!empty($r)) return $r;
        }

        // ٣. ابحث في aliases بالاسم الكامل → product_id مباشر
        $fullQuery = trim("$partName $device $brand");
        $byAlias   = $this->searchByAlias($fullQuery);
        if (!empty($byAlias)) return $this->visualVerify($imageBase64, $mimeType, $byAlias, $analysis['visual_desc']);

        // جرب بدون ماركة
        $byAlias2 = $this->searchByAlias(trim("$partName $device"));
        if (!empty($byAlias2)) return $this->visualVerify($imageBase64, $mimeType, $byAlias2, $analysis['visual_desc']);

        // جرب الاسم فقط
        $byAlias3 = $this->searchByAlias($partName);
        if (!empty($byAlias3)) return $this->visualVerify($imageBase64, $mimeType, $byAlias3, $analysis['visual_desc']);

        // ٤. حوّل عبر aliases وابحث نصياً
        $resolved = $this->resolveToOfficial($fullQuery);
        $candidates = [];

        foreach (array_filter([$resolved, $fullQuery, trim("$partName $device"), $partName]) as $term) {
            $r = $this->searchFullTextLimit($term, 8);
            foreach ($r as $item) {
                $url = trim($item['image_url'] ?? '');
                if ($url && !str_contains($url, 'no_image'))
                    $candidates[$item['id']] = $item;
            }
            $r = $this->searchLikeAllWordsLimit($term, 8);
            foreach ($r as $item) {
                $url = trim($item['image_url'] ?? '');
                if ($url && !str_contains($url, 'no_image') && !isset($candidates[$item['id']]))
                    $candidates[$item['id']] = $item;
            }
            if (count($candidates) >= 10) break;
        }

        if (!empty($candidates)) {
            $verified = $this->visualVerify($imageBase64, $mimeType, array_values($candidates), $analysis['visual_desc']);
            if (!empty($verified)) return $verified;
        }

        // ٥. fallback نصي بدون مقارنة بصرية
        if (!empty($candidates)) return array_slice(array_values($candidates), 0, self::LIMIT);

        return [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: البحث في product_aliases بالنص → product_id مباشر
    // ═══════════════════════════════════════════════════════════════════════

    private function searchByAlias(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];

        $norm = $this->normalizeArabicText(mb_strtolower($query));

        try {
            // ابحث بالنص الكامل أولاً
            $stmt = $this->db->prepare("
                SELECT DISTINCT p." . self::COLS . "
                FROM product_aliases pa
                JOIN products p ON p.id = pa.product_id
                WHERE (pa.alias = :q1 OR pa.alias = :q2)
                  AND pa.product_id IS NOT NULL
                ORDER BY (p.quantity > 0) DESC, p.name ASC
                LIMIT " . self::LIMIT
            );
            $stmt->execute([':q1' => $query, ':q2' => $norm]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($rows)) return $rows;

            // ابحث بـ LIKE على الـ alias
            $stmt2 = $this->db->prepare("
                SELECT DISTINCT p." . self::COLS . "
                FROM product_aliases pa
                JOIN products p ON p.id = pa.product_id
                WHERE pa.alias LIKE :q
                  AND pa.product_id IS NOT NULL
                ORDER BY (p.quantity > 0) DESC, p.name ASC
                LIMIT " . self::LIMIT
            );
            $stmt2->execute([':q' => '%' . $query . '%']);
            return $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        } catch (Throwable $e) {
            error_log('[searchByAlias] ' . $e->getMessage());
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: تحويل الاسم العامي → رسمي عبر product_aliases
    // ═══════════════════════════════════════════════════════════════════════

    private function resolveToOfficial(string $query): string
    {
        $words    = explode(' ', trim($query));
        $resolved = [];
        $changed  = false;

        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) < 2) { $resolved[] = $word; continue; }

            $official = $this->lookupOfficial($word);
            if ($official && $official !== $word) {
                $resolved[] = $official;
                $changed    = true;
            } else {
                $resolved[] = $word;
            }
        }

        if (!$changed) {
            $official = $this->lookupOfficial($query);
            if ($official && $official !== $query) return $official;
        }

        return implode(' ', $resolved);
    }

    private function lookupOfficial(string $term): ?string
    {
        try {
            $norm = $this->normalizeArabicText(mb_strtolower(trim($term)));
            $stmt = $this->db->prepare("
                SELECT official_name FROM product_aliases
                WHERE alias = ? OR alias = ?
                LIMIT 1
            ");
            $stmt->execute([$term, $norm]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (string)$row['official_name'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: GPT يحلل الصورة
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * تصنيف أولي وسريع لأي صورة توصل من الزبون — قبل ما نقرر شو نسوي فيها.
     * يرجّع وحدة من: part | device | ads | bank
     *   part   → قطعة غيار مفردة (كلوز أب) → يكمل بمسار البحث بالمخزون الحالي
     *   device → جهاز منزلي كامل (غسالة، ثلاجة...) → يسأل الزبون قطعة ولا عطل
     *   ads    → سكرين شوت / إعلان / صورة غير متعلقة → رد توضيحي بسيط
     *   bank   → معلومات حساسة (حساب بنكي، تحويل، هوية، بطاقة) → تصعيد فوري لرمزي
     */
    public function classifyImageCategory(string $imageBase64, string $mimeType, string $caption = ''): string
    {
        if ($this->openaiKey === '') return 'part'; // fallback آمن — نفس السلوك القديم

        $captionHint = $caption !== '' ? "\nملاحظة: الزبون كتب مع الصورة: \"{$caption}\"" : '';

        $payload = [
            'model'      => defined('OPENAI_VISION_MODEL') ? OPENAI_VISION_MODEL : 'gpt-4o-mini',
            'max_tokens' => 20,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$imageBase64}", 'detail' => 'high']],
                    ['type' => 'text', 'text' =>
                        "صنّف هاي الصورة لواحدة بس من الفئات التالية، ورد بكلمة وحدة بس بدون أي شرح:\n\n" .
                        "part → قطعة غيار مفردة لجهاز منزلي (كلوز أب على قطعة، خرطوم، لوحة، ماتور، صمام...)\n" .
                        "device → جهاز منزلي كامل بمنظره العام (غسالة، ثلاجة، مكيف، فرن كامل...)\n" .
                        "bank → أي صورة فيها معلومات مالية أو شخصية حساسة، بما فيها: شاشة أي تطبيق بنكي أو محفظة إلكترونية (مثل CliQ)، بروفايل أو Alias بنكي، سكرين شوت تحويل أو إيصال دفع، رقم حساب أو IBAN، فاتورة، هوية شخصية، بطاقة بنكية أو ائتمانية. لو الصورة فيها شعار بنك أو تطبيق مالي أو كلمة زي (CliQ, Alias, IBAN, Account, حساب, تحويل) فهي bank دايماً حتى لو ما فيها أرقام واضحة بالصورة.\n" .
                        "ads → أي شي تاني: سكرين شوت محادثة، إعلان، صورة من قوقل/سوشال ميديا، صورة غير واضحة أو غير متعلقة بأجهزة منزلية ولا بمعلومات مالية\n" .
                        $captionHint . "\n\nرد بكلمة وحدة فقط: part أو device أو bank أو ads"
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
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->openaiKey, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$raw || $code >= 400) { error_log("[classifyImageCategory] HTTP {$code}"); return 'part'; }

            $data   = json_decode($raw, true);
            $answer = strtolower(trim((string)($data['choices'][0]['message']['content'] ?? '')));

            foreach (['bank', 'device', 'ads', 'part'] as $valid) {
                if (str_contains($answer, $valid)) return $valid;
            }
            return 'part'; // fallback آمن لو الرد غير متوقع
        } catch (Throwable $e) {
            error_log('[classifyImageCategory] ' . $e->getMessage());
            return 'part';
        }
    }

    private function analyzeImage(string $imageBase64, string $mimeType): array
    {
        if ($this->openaiKey === '') return [];

        $payload = [
            'model'      => defined('OPENAI_VISION_MODEL') ? OPENAI_VISION_MODEL : 'gpt-4o-mini',
            'max_tokens' => 300,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$imageBase64}", 'detail' => 'high']],
                    ['type' => 'text', 'text' =>
                        "أنت خبير متخصص في قطع غيار الأجهزة المنزلية في السوق الأردني والعربي.\n" .
                        "حلل هذه الصورة بدقة عالية جداً واستخرج:\n\n" .
                        "١. نوع القطعة الدقيق — استخدم الأسماء التجارية المعروفة عند تجار قطع الغيار:\n" .
                        "   أمثلة مهمة:\n" .
                        "   - الخرطوم المطاطي المجعّد/الأكورديون الأسود → 'بربيش جرار'\n" .
                        "   - الخرطوم المطاطي المستقيم → 'بربيش تصريف' أو 'بربيش تزويد'\n" .
                        "   - الخرطوم الرفيع البلاستيكي → 'بربيش درين'\n" .
                        "   - اللوح الإلكتروني → 'لوحة' أو 'بورد'\n" .
                        "   - الجزء الدوار في الغسالة → 'ماتور' أو 'صنوبرص'\n" .
                        "   - الصمام البلاستيكي الأبيض الأسطواني مع سلكين كهربائيين → 'صمام مياه غسالة' أو 'إلكتروفالف'\n" .
                        "   - الصمام البلاستيكي الأبيض بدون أسلاك كهربائية → 'صمام مياه ميكانيكي'\n" .
                        "   - الصمام المزدوج مع ملفّين كهربائيين أسودين → 'صمام مزدوج غسالة'\n" .
                        "   - القطعة المعدنية الأسطوانية → 'مكثف' أو 'ماتور'\n\n" .
                        "٢. الجهاز المرتبط بدقة: غسالة، ثلاجة، مكيف، فرن، كولر، جلاية، ميكرويف، مكنسة\n" .
                        "٣. الماركة إن ظهرت بوضوح على القطعة\n" .
                        "٤. أي كود أو رقم مطبوع على القطعة\n" .
                        "٥. وصف بصري دقيق: الشكل، اللون، المادة، الحجم، عدد الفتحات، الأسلاك\n\n" .
                        "قواعد صارمة:\n" .
                        "- لو الصورة وسخة أو مكسورة: ركز على الشكل الأساسي\n" .
                        "- لا تخمن إذا ما كنت متأكد — قل 'غير معروف'\n" .
                        "- brand و code يكونوا null إذا ما ظهروا بوضوح\n\n" .
                        "رد بـ JSON فقط:\n" .
                        "{\"part_name\":\"نوع القطعة الدقيق\",\"device\":\"الجهاز\",\"brand\":null,\"code\":null,\"visual_desc\":\"وصف بصري دقيق\"}\n" .
                        "ممنوع أي نص خارج JSON."
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
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->openaiKey, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$raw || $code >= 400) { error_log("[analyzeImage] HTTP {$code}"); return []; }

            $data    = json_decode($raw, true);
            $content = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '');
            $parsed  = json_decode(trim($content), true);
            if (!is_array($parsed)) return [];

            $this->_lastPartName   = trim((string)($parsed['part_name']  ?? ''));
            $this->_lastDevice     = trim((string)($parsed['device']     ?? ''));
            $this->_lastBrand      = trim((string)($parsed['brand']      ?? ''));
            $this->_lastCode       = trim((string)($parsed['code']       ?? ''));
            $this->_lastVisualDesc = trim((string)($parsed['visual_desc'] ?? ''));

            // ── احسب embedding لوصف الصورة — يُستخدم بالمطابقة الذكية وبالتعلّم ──
            $embText = trim("{$this->_lastPartName} {$this->_lastDevice} {$this->_lastBrand} {$this->_lastVisualDesc}");
            $this->_lastEmbedding = $embText !== '' ? $this->embedText($embText) : [];

            return [
                'part_name'   => $this->_lastPartName,
                'device'      => $this->_lastDevice,
                'brand'       => $this->_lastBrand,
                'code'        => $this->_lastCode,
                'visual_desc' => $this->_lastVisualDesc,
            ];
        } catch (Throwable $e) {
            error_log('[analyzeImage] ' . $e->getMessage());
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: مقارنة بصرية — GPT يقارن صورة الزبون بصور المرشحين
    // ═══════════════════════════════════════════════════════════════════════

    private function visualVerify(string $imageBase64, string $mimeType, array $candidates, string $visualDesc): array
    {
        // فلتر المرشحين اللي عندهم صور
        $withImg = array_filter($candidates, function($c) {
            $url = trim($c['image_url'] ?? '');
            return $url && !str_contains($url, 'no_image');
        });

        if ($this->openaiKey === '') {
            // بدون مفتاح API → ارجع الأول فقط
            return [array_values($candidates)[0]];
        }

        // ✅ لو ما في مرشحين بصور → ارجع فاضي (أفضل من جواب غلط)
        if (empty($withImg)) {
            return [];
        }

        $withImg = array_slice(array_values($withImg), 0, 6);

        // ابنِ قائمة المرشحين
        $list = '';
        foreach ($withImg as $i => $c)
            $list .= ($i + 1) . ". ID:{$c['id']} — {$c['name']}\n";

        $content = [
            ['type' => 'text', 'text' =>
                "أنت خبير متخصص في قطع غيار الأجهزة المنزلية.\n" .
                "مهمتك: تحديد إذا كانت قطعة الزبون موجودة فعلاً في قائمة المرشحين.\n\n" .
                "صورة قطعة الزبون مرفقة.\n" .
                "وصفها: {$visualDesc}\n\n" .
                "المرشحون من المخزن:\n{$list}\n" .
                "قواعد صارمة جداً:\n" .
                "- قارن المادة: بلاستيك vs معدن vs مطاط — لو مختلفة → لا تطابق\n" .
                "- قارن الشكل: عدد الفتحات، الحجم، التصميم العام بدقة\n" .
                "- قارن اللون الأساسي للقطعة\n" .
                "- قارن عدد الوصلات/المنافذ بالضبط\n" .
                "- التطابق يجب أن يكون بصرياً حقيقياً وليس فقط اشتراك في الاسم\n" .
                "- إذا القطعة صمام مياه بلاستيكي → لا يطابق صمام كهربائي معدني\n" .
                "- إذا في شك → matches فارغة [] أفضل من جواب غلط\n" .
                "- يجب أن تكون نسبة ثقتك 80%+ حتى تضيف للـ matches\n" .
                "- إذا لم تجد تطابقاً بصرياً واضحاً → أعطني matches فارغة []\n\n" .
                "رد بـ JSON فقط: {\"matches\":[{\"id\":123,\"confidence\":90}]} أو {\"matches\":[]}\n" .
                "ممنوع أي نص خارج JSON."
            ],
            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$imageBase64}", 'detail' => 'high']],
        ];

        foreach ($withImg as $i => $c) {
            $content[] = ['type' => 'text', 'text' => "مرشح " . ($i+1) . " (ID:{$c['id']}, {$c['name']}):"];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => trim($c['image_url']), 'detail' => 'low']];
        }

        try {
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'model'      => defined('OPENAI_VERIFY_MODEL') ? OPENAI_VERIFY_MODEL : 'gpt-4o',
                    'max_tokens' => 80,
                    'messages'   => [['role' => 'user', 'content' => $content]],
                ]),
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->openaiKey, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$raw || $code >= 400) return [];

            $data   = json_decode($raw, true);
            $text   = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '');
            $parsed = json_decode(trim($text), true);

            if (empty($parsed['matches'])) return [];

            // ✅ فحص الـ confidence — لو أقل من 75% نرفض
            $firstMatch  = $parsed['matches'][0];
            $confidence  = (int)($firstMatch['confidence'] ?? 100);
            if ($confidence < 75) {
                error_log("[visualVerify] rejected match confidence={$confidence}");
                return [];
            }

            // خذ أول نتيجة فقط — الأدق بصرياً
            $byId = array_column($candidates, null, 'id');
            $id   = (int)($firstMatch['id'] ?? 0);
            if (!isset($byId[$id])) return [];
            // ✅ علّم النتيجة بأنها تحققت بصرياً — Agent.php يستخدم هذا للتحقق
            $result = $byId[$id];
            $result['visual_verified'] = true;
            $result['visual_confidence'] = $confidence;
            return [$result];

        } catch (Throwable $e) {
            error_log('[visualVerify] ' . $e->getMessage());
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: البحث بالـ Visual Index (الأسرع والأدق)
    // ═══════════════════════════════════════════════════════════════════════

    private function searchVisualIndex(string $imageBase64, string $mimeType): array
    {
        // حلل صورة الزبون أولاً (هذا بيحسب الـ embedding كمان ويخزّنه بـ _lastEmbedding)
        $analysis = $this->analyzeImage($imageBase64, $mimeType);
        if (empty($analysis)) return [];

        $partType   = $this->_lastPartName;
        $deviceType = $this->_lastDevice;

        $candidates = [];

        // ── أ. مطابقة ذكية بالـ embeddings (تفهم المعنى/المرادفات، أدق وأشمل) ──
        if (!empty($this->_lastEmbedding)) {
            $candidates = $this->searchVisualIndexByEmbedding($this->_lastEmbedding);
        }

        // ── ب. fallback: فلترة نصية (LIKE) بنوع القطعة/الجهاز — الطريقة القديمة ──
        if (empty($candidates)) {
            try {
                $conditions = [];
                $params     = [];

                if ($partType) {
                    $conditions[] = "vi.part_type LIKE ?";
                    $params[]     = "%{$partType}%";
                }
                if ($deviceType) {
                    $conditions[] = "vi.device_type LIKE ?";
                    $params[]     = "%{$deviceType}%";
                }

                if (!empty($conditions)) {
                    $where = implode(' AND ', $conditions);
                    $stmt  = $this->db->prepare("
                        SELECT p." . self::COLS . ", vi.visual_desc
                        FROM product_visual_index vi
                        JOIN products p ON p.id = vi.product_id
                        WHERE {$where}
                        ORDER BY (p.quantity > 0) DESC, p.name ASC
                        LIMIT 8
                    ");
                    $stmt->execute($params);
                    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Throwable $e) {
                error_log('[searchVisualIndex:fallback] ' . $e->getMessage());
            }
        }

        if (empty($candidates)) return [];

        // مقارنة بصرية نهائية مع المرشحين (تأكيد قبل ما نرسلها للزبون)
        $verified = $this->visualVerify($imageBase64, $mimeType, $candidates, $analysis['visual_desc']);
        return !empty($verified) ? $verified : $candidates;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: مطابقة الفهرس البصري بالـ embeddings (cosine similarity)
    // ═══════════════════════════════════════════════════════════════════════

    private function searchVisualIndexByEmbedding(array $queryEmbedding, int $limit = 8): array
    {
        try {
            $stmt = $this->db->query("
                SELECT vi.product_id, vi.visual_desc, vi.embedding
                FROM product_visual_index vi
                WHERE vi.embedding IS NOT NULL AND vi.embedding != ''
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($rows)) return [];

            $scored = [];
            foreach ($rows as $r) {
                $vec = json_decode((string)$r['embedding'], true);
                if (!is_array($vec) || empty($vec)) continue;

                $sim = $this->cosineSimilarity($queryEmbedding, $vec);
                if ($sim >= self::VISUAL_SIMILARITY_THRESHOLD) {
                    $scored[] = [
                        'product_id'  => (int)$r['product_id'],
                        'visual_desc' => $r['visual_desc'],
                        'similarity'  => $sim,
                    ];
                }
            }

            if (empty($scored)) return [];

            usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
            $scored = array_slice($scored, 0, $limit);

            // اجلب بيانات المنتجات الكاملة بنفس ترتيب التشابه
            $ids = array_column($scored, 'product_id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT " . self::COLS . " FROM products WHERE id IN ($in)");
            $stmt->execute($ids);
            $byId = array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], null, 'id');

            $result = [];
            foreach ($scored as $s) {
                if (isset($byId[$s['product_id']])) {
                    $row = $byId[$s['product_id']];
                    $row['visual_desc'] = $s['visual_desc'];
                    $result[] = $row;
                }
            }
            return $result;

        } catch (Throwable $e) {
            error_log('[searchVisualIndexByEmbedding] ' . $e->getMessage());
            return [];
        }
    }

    // البحث بالصورة + الجهاز (أدق من searchByImage)
    /**
     * لما ما قدرنا نتعرف على القطعة من الصورة لحالها، وسألنا الزبون عن اسمها التقريبي —
     * هاي الدالة بتستخدم الاسم كـ"تلميح" وترجع تحلل الصورة مع بعض، وبعدين تتحقق بصرياً
     * من المرشحين بدل ما ترجع قائمة عامة بكل اللي فيهم نفس الكلمة (زي "باب غسالة").
     * ترجع نتيجة وحدة موثوقة بصرياً لو لقت، وإلا مصفوفة فاضية (يعني: صعّد لرمزي).
     */
    public function searchByImageWithHint(string $imageBase64, string $mimeType, string $hint): array
    {
        $refinedName = $this->analyzeImageWithDevice($imageBase64, $mimeType, $hint);
        $queryTerm   = $refinedName !== '' ? $refinedName : $hint;

        $candidates = [];
        foreach (array_filter([$queryTerm, $hint]) as $term) {
            foreach ($this->searchFullTextLimit($term, 10) as $r) {
                $url = trim($r['image_url'] ?? '');
                if ($url && !str_contains($url, 'no_image')) $candidates[$r['id']] = $r;
            }
            foreach ($this->searchLikeAllWordsLimit($term, 10) as $r) {
                $url = trim($r['image_url'] ?? '');
                if ($url && !str_contains($url, 'no_image') && !isset($candidates[$r['id']])) $candidates[$r['id']] = $r;
            }
            if (count($candidates) >= 12) break;
        }

        if (empty($candidates)) return [];

        // ✅ تحقق بصري صارم — نرجع بس لو في تطابق واثق، وإلا نرجع فاضي (يعني نصعّد)
        return $this->visualVerify($imageBase64, $mimeType, array_values($candidates), $queryTerm);
    }

    public function searchByImageWithDevice(string $imageBase64, string $mimeType, string $device): array
    {
        // ١. GPT يحلل الصورة مع معرفة الجهاز مسبقاً
        $partName = $this->analyzeImageWithDevice($imageBase64, $mimeType, $device);
        if (!$partName) return [];

        // ٢. ابحث في aliases بالاسم + الجهاز
        $fullQuery = trim("$partName $device");
        $byAlias   = $this->searchByAlias($fullQuery);
        if (!empty($byAlias)) return $this->visualVerify($imageBase64, $mimeType, $byAlias, "$partName $device");

        $byAlias2 = $this->searchByAlias($partName);
        if (!empty($byAlias2)) return $this->visualVerify($imageBase64, $mimeType, $byAlias2, "$partName $device");

        // ٣. بحث نصي بالاسم الرسمي + الجهاز
        $resolved   = $this->resolveToOfficial($fullQuery);
        $candidates = [];

        foreach (array_filter([$resolved, $fullQuery, $partName]) as $term) {
            foreach ($this->searchFullTextLimit($term, 8) as $r) {
                $url = trim($r['image_url'] ?? '');
                if ($url && !str_contains($url, 'no_image'))
                    $candidates[$r['id']] = $r;
            }
            foreach ($this->searchLikeAllWordsLimit($term, 8) as $r) {
                $url = trim($r['image_url'] ?? '');
                if ($url && !str_contains($url, 'no_image') && !isset($candidates[$r['id']]))
                    $candidates[$r['id']] = $r;
            }
            if (count($candidates) >= 10) break;
        }

        if (!empty($candidates)) {
            $verified = $this->visualVerify($imageBase64, $mimeType, array_values($candidates), "$partName $device");
            if (!empty($verified)) return $verified;
            return array_slice(array_values($candidates), 0, self::LIMIT);
        }

        return [];
    }

    // GPT يحلل الصورة مع معرفة الجهاز مسبقاً → دقة أعلى
    private function analyzeImageWithDevice(string $imageBase64, string $mimeType, string $device): string
    {
        if ($this->openaiKey === '') return '';

        $payload = [
            'model'      => defined('OPENAI_VISION_MODEL') ? OPENAI_VISION_MODEL : 'gpt-4o-mini',
            'max_tokens' => 100,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$imageBase64}", 'detail' => 'high']],
                    ['type' => 'text', 'text' =>
                        "أنت خبير قطع غيار أجهزة منزلية في السوق الأردني.\n" .
                        "هذه قطعة من جهاز: *{$device}*\n\n" .
                        "ما هو اسم هذه القطعة بالعربية كما يعرفها تجار قطع الغيار؟\n" .
                        "أمثلة لـ {$device}:\n" .
                        "- غسالة: مضخة، ماتور، قشاط، جلدة، قفل، لوحة، مروحة، صنوبرص، بربيش جرار، بربيش تصريف، مصلب، بيلية\n" .
                        "- ثلاجة: ماتور، مروحة، حساس، ديفروست، نبل، مكثف، توماتيك، ساعة\n" .
                        "- مكيف: مروحة، مكثف، فراشة، ريموت، كت، حساس\n" .
                        "- فرن: عظمه غاز، شعلة، مفتاح، هيتر، حساس غاز، فصالة\n" .
                        "- كولر: حنفية، مضخة، فلتر\n" .
                        "- جلاية: مضخة، مرش، هيتر، فلتر\n\n" .
                        "رد باسم القطعة فقط بالعربية — كلمة أو كلمتين.\n" .
                        "لا تكتب أي شرح إضافي."
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
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->openaiKey, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$raw || $code >= 400) return '';

            $data = json_decode($raw, true);
            return trim($data['choices'][0]['message']['content'] ?? '');

        } catch (Throwable $e) {
            error_log('[analyzeImageWithDevice] ' . $e->getMessage());
            return '';
        }
    }

    public function getImageKeywords(string $imageBase64, string $mimeType = 'image/jpeg'): array
    {
        $analysis = $this->analyzeImage($imageBase64, $mimeType);
        $parts    = array_filter([
            $this->_lastPartName,
            $this->_lastDevice,
            $this->_lastBrand,
        ]);
        $label = implode(' ', $parts);
        return $label ? [$label] : [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: البحث بالكود
    // ═══════════════════════════════════════════════════════════════════════

    public function searchByCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') return [];
        try {
            $stmt = $this->db->prepare("SELECT " . self::COLS . " FROM products WHERE LOWER(TRIM(code)) = ? LIMIT 1");
            $stmt->execute([mb_strtolower($code)]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r ? [$r] : [];
        } catch (Throwable $e) {
            error_log('[searchByCode] ' . $e->getMessage());
            return [];
        }
    }

    public function searchBySku(string $sku): array { return $this->searchByCode($sku); }
    public function searchExact(string $q): array   { return $this->search($q); }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: FULLTEXT + LIKE
    // ═══════════════════════════════════════════════════════════════════════

    private function searchFullText(string $query): array
    {
        $words = $this->extractWords($query);
        if (empty($words)) return [];
        $ftq = implode(' ', $words);
        try {
            $stmt = $this->db->prepare("
                SELECT " . self::COLS . ", MATCH(name) AGAINST(:q1 IN BOOLEAN MODE) AS s
                FROM products WHERE MATCH(name) AGAINST(:q2 IN BOOLEAN MODE)
                ORDER BY (quantity>0) DESC, s DESC, name ASC
                LIMIT " . self::LIMIT);
            $stmt->execute([':q1' => $ftq, ':q2' => $ftq]);
            return array_map(fn($r) => array_diff_key($r, ['s'=>1]), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { error_log('[searchFullText] '.$e->getMessage()); return []; }
    }

    private function searchFullTextLimit(string $query, int $limit): array
    {
        $words = $this->extractWords($query);
        if (empty($words)) return [];
        $ftq = implode(' ', $words);
        try {
            $stmt = $this->db->prepare("
                SELECT " . self::COLS . ", MATCH(name) AGAINST(:q1 IN BOOLEAN MODE) AS s
                FROM products WHERE MATCH(name) AGAINST(:q2 IN BOOLEAN MODE)
                ORDER BY (quantity>0) DESC, s DESC LIMIT {$limit}");
            $stmt->execute([':q1' => $ftq, ':q2' => $ftq]);
            return array_map(fn($r) => array_diff_key($r, ['s'=>1]), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) { return []; }
    }

    private function searchLikeAllWords(string $query): array
    {
        $words = $this->extractWords($query);
        if (empty($words)) return [];
        $conds = []; $params = [];
        foreach ($words as $w) { $conds[] = '(name LIKE ? OR code LIKE ?)'; $params[] = "%$w%"; $params[] = "%$w%"; }
        try {
            $stmt = $this->db->prepare("SELECT " . self::COLS . " FROM products WHERE " . implode(' AND ', $conds) . " ORDER BY (quantity>0) DESC, name ASC LIMIT " . self::LIMIT);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    private function searchLikeAllWordsLimit(string $query, int $limit): array
    {
        $words = $this->extractWords($query);
        if (empty($words)) return [];
        $conds = []; $params = [];
        foreach ($words as $w) { $conds[] = '(name LIKE ? OR code LIKE ?)'; $params[] = "%$w%"; $params[] = "%$w%"; }
        try {
            $stmt = $this->db->prepare("SELECT " . self::COLS . " FROM products WHERE " . implode(' AND ', $conds) . " ORDER BY (quantity>0) DESC, name ASC LIMIT {$limit}");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    private function searchLikeBestWord(string $query): array
    {
        $words = $this->extractWords($query);
        if (empty($words)) return [];
        usort($words, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        foreach ($words as $word) {
            try {
                $stmt = $this->db->prepare("SELECT " . self::COLS . " FROM products WHERE name LIKE ? OR code LIKE ? ORDER BY (quantity>0) DESC, name ASC LIMIT " . self::LIMIT);
                $stmt->execute(["%$word%", "%$word%"]);
                $r = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($r)) return $r;
            } catch (Throwable $e) {}
        }
        return [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: Helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function normalizeArabicText(string $text): string
    {
        return str_replace(['أ','إ','آ','ة','ى'], ['ا','ا','ا','ه','ي'], $text);
    }

    private function extractWords(string $text): array
    {
        $text  = mb_strtolower(trim($text));
        $words = preg_split('/[\s\-\/\+\*]+/u', $text) ?: [];
        return array_values(array_filter($words, function(string $w): bool {
            $w = trim($w);
            return mb_strlen($w) >= self::MIN_WORD && !in_array($w, self::STOP_WORDS, true);
        }));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: Helpers للعرض
    // ═══════════════════════════════════════════════════════════════════════

    public function hasAvailableProducts(array $products): bool
    {
        foreach ($products as $p) {
            if ((int)($p['quantity'] ?? 0) > 0) return true;
        }
        return false;
    }

    public function formatProduct(array $product): string
    {
        $name      = $product['name']             ?? 'قطعة';
        $code      = $product['code']             ?? '';
        $condition = $product['condition_status'] ?? '';
        $qty       = (int)($product['quantity']   ?? 0);
        $avail     = $qty > 0 ? '✅ متوفرة' : '❌ غير متوفرة حالياً';
        $lines     = ["🧩 *{$name}*"];
        if ($code)      $lines[] = "🔢 الكود: {$code}";
        if ($condition) $lines[] = "🏷️ الحالة: {$condition}";
        $lines[]                 = "📦 التوفر: {$avail}";
        return implode("\n", $lines);
    }

    public function formatCaption(array $product): string
    {
        $name  = $product['name']           ?? 'قطعة';
        $qty   = (int)($product['quantity'] ?? 0);
        $avail = $qty > 0 ? '✅ متوفرة' : '❌ غير متوفرة';
        return "🧩 *{$name}*\n📦 {$avail}";
    }

    public function formatList(array $products): string
    {
        $lines = [];
        foreach ($products as $i => $p) {
            $qty     = (int)($p['quantity'] ?? 0);
            $status  = $qty > 0 ? '✅' : '❌';
            $lines[] = ($i + 1) . ". {$status} *{$p['name']}*";
        }
        return implode("\n", $lines);
    }

    public function formatAvailabilityOnly(array $product): string
    {
        $name = $product['name']           ?? 'القطعة';
        $qty  = (int)($product['quantity'] ?? 0);
        return $qty > 0
            ? "✅ القطعة *{$name}* متوفرة لدينا الآن."
            : "❌ القطعة *{$name}* غير متوفرة حالياً.";
    }

    public function getImageUrl(array $product): ?string
    {
        $url = $product['image_url'] ?? null;
        if (!$url || trim($url) === '' || str_contains($url, 'no_image')) return null;
        return trim($url);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: نتيجة آخر تحليل صورة (مفيد للّوغ والتعلّم)
    // ═══════════════════════════════════════════════════════════════════════

    public function getLastAnalysis(): array
    {
        return [
            'part_name'   => $this->_lastPartName,
            'device'      => $this->_lastDevice,
            'brand'       => $this->_lastBrand,
            'code'        => $this->_lastCode,
            'visual_desc' => $this->_lastVisualDesc,
            'embedding'   => $this->_lastEmbedding,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: تسجيل صورة لم يتم التعرف عليها (لإعادة التعلّم لاحقاً)
    //  بتُستدعى بعد تصعيد الصورة لرمزي، عشان لما رمزي يرد نقدر نربط
    //  جوابه بالمنتج الصحيح ونغذّي الفهرس البصري + قاموس الأسماء العامية.
    // ═══════════════════════════════════════════════════════════════════════

    public function logUnresolvedImage(
        ?int   $notificationId,
        ?int   $convId,
        ?int   $custId,
        string $imagePath,
        array  $analysis = [],  // ✅ بيانات التحليل من searchByImage
        string $category = 'part' // part | device | ads | bank
    ): ?int {
        try {
            // ✅ انسخ الصورة لمجلد ثابت ما بينمسح (training_images)
            // uploads/images بتنمسح كل أسبوع — training_images تبقى للأبد
            $persistentUrl  = null;
            $persistentDir  = __DIR__ . '/../uploads/training_images/';

            if ($imagePath && file_exists($imagePath)) {
                if (!is_dir($persistentDir)) {
                    mkdir($persistentDir, 0755, true);
                }
                $ext      = pathinfo($imagePath, PATHINFO_EXTENSION) ?: 'jpg';
                $newName  = 'unresolved_' . time() . '_' . uniqid() . '.' . $ext;
                $destPath = $persistentDir . $newName;

                if (copy($imagePath, $destPath)) {
                    // حوّل المسار لـ URL
                    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
                    if ($docRoot && str_starts_with($destPath, $docRoot)) {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host   = $_SERVER['HTTP_HOST'] ?? '';
                        $rel    = substr($destPath, strlen($docRoot));
                        $persistentUrl = $scheme . '://' . $host . '/' . ltrim(str_replace('\\', '/', $rel), '/');
                    }
                }
            }

            $stmt = $this->db->prepare("
                INSERT INTO unresolved_images
                    (notification_id, conversation_id, customer_id, image_path, image_url,
                     part_name, device_type, brand, code, visual_desc, embedding, status, category)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            // ✅ استخدم analysis المررة من Agent بدل _last* الفاضية
            $partName   = $analysis['part_name']   ?? $this->_lastPartName   ?: null;
            $deviceType = $analysis['device']      ?? $this->_lastDevice     ?: null;
            $brand      = $analysis['brand']       ?? $this->_lastBrand      ?: null;
            $code       = $analysis['code']        ?? $this->_lastCode       ?: null;
            $visualDesc = $analysis['visual_desc'] ?? $this->_lastVisualDesc ?: null;
            $embedding  = $analysis['embedding']   ?? ($this->_lastEmbedding ? json_encode($this->_lastEmbedding) : null);
            if (is_array($embedding)) $embedding = json_encode($embedding);

            $stmt->execute([
                $notificationId,
                $convId,
                $custId,
                $imagePath,
                $persistentUrl,
                $partName   ?: null,
                $deviceType ?: null,
                $brand      ?: null,
                $code       ?: null,
                $visualDesc ?: null,
                $embedding  ?: null,
                $category,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[logUnresolvedImage] ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC: التعلّم من تصحيح رمزي — يضيف alias + سطر بالفهرس البصري
    //  بحيث القطعة المشابهة تالياً تنعرف فوراً (بدون ما يصعّد البوت تاني)
    // ═══════════════════════════════════════════════════════════════════════

    public function learnFromCorrection(int $unresolvedImageId, int $productId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM unresolved_images WHERE id = ? LIMIT 1");
            $stmt->execute([$unresolvedImageId]);
            $unresolved = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unresolved) return false;

            $stmt = $this->db->prepare("SELECT id, name, image_url FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) return false;

            $partName   = trim((string)($unresolved['part_name']   ?? ''));
            $device     = trim((string)($unresolved['device_type'] ?? ''));
            $brand      = trim((string)($unresolved['brand']       ?? ''));
            $visualDesc = trim((string)($unresolved['visual_desc'] ?? ''));
            $embedding  = $unresolved['embedding'] ?? null;

            $this->db->beginTransaction();

            // ١. أضف/حدّث اسم عامي → الاسم الرسمي للمنتج الصحيح
            if ($partName !== '') {
                $this->db->prepare("
                    INSERT INTO product_aliases (product_id, alias, official_name, device)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        product_id = VALUES(product_id),
                        official_name = VALUES(official_name),
                        device = VALUES(device)
                ")->execute([$productId, $partName, $product['name'], $device ?: null]);
            }

            // ٢. أضف/حدّث سطر بالفهرس البصري — يغني embedding المنتج بوصف
            //    حقيقي اتأكد منه رمزي، فيصير التعرف عليه أسرع وأدق بالمرة الجاية
            if ($embedding) {
                $imgUrl = $product['image_url'] ?: ($unresolved['image_path'] ?? '');
                $this->db->prepare("
                    INSERT INTO product_visual_index
                        (product_id, product_name, image_url, visual_desc, part_type, device_type, brand, embedding)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        visual_desc = VALUES(visual_desc),
                        part_type   = VALUES(part_type),
                        device_type = VALUES(device_type),
                        brand       = VALUES(brand),
                        embedding   = VALUES(embedding),
                        analyzed_at = NOW()
                ")->execute([
                    $productId, $product['name'], $imgUrl, $visualDesc, $partName ?: null, $device ?: null, $brand ?: null, $embedding,
                ]);
            }

            // ٣. علّم الصورة كمتعلَّمة
            $this->db->prepare("
                UPDATE unresolved_images
                SET status='learned', matched_product_id=?, resolved_at=NOW()
                WHERE id = ?
            ")->execute([$productId, $unresolvedImageId]);

            $this->db->commit();
            return true;

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[learnFromCorrection] ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PRIVATE: تحويل نص لـ embedding (نفس منطق RAG، مكرّر هون عمداً
    //  لتبقى ProductSearch مستقلة بدون اعتمادية دائرية)
    // ═══════════════════════════════════════════════════════════════════════

    private function embedText(string $text): array
    {
        if ($this->openaiKey === '') return [];

        try {
            $ch = curl_init('https://api.openai.com/v1/embeddings');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'model' => 'text-embedding-3-small',
                    'input' => mb_substr(trim($text), 0, 2000),
                ]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->openaiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $code >= 400) return [];

            $data = json_decode($raw, true);
            return (array)($data['data'][0]['embedding'] ?? []);

        } catch (Throwable $e) {
            error_log('[ProductSearch::embedText] ' . $e->getMessage());
            return [];
        }
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) return 0.0;

        $dot = 0.0; $normA = 0.0; $normB = 0.0;
        foreach ($a as $i => $val) {
            $dot   += $val * $b[$i];
            $normA += $val * $val;
            $normB += $b[$i] * $b[$i];
        }
        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? (float)($dot / $denom) : 0.0;
    }
}
