<?php

/**
 * OwnerCommandsTrait — أوامر رمزي من واتساب + لوحة التحكم النصية
 *
 * تحتوي على:
 *  - normalizeOwnerCommand: تصحيح الأوامر العربية بالـ AI
 *  - sendOwnerMenu: قائمة أوامر رمزي
 *  - transcribeOwnerAudio: تحويل صوت رمزي لنص
 *  - handleOwnerButtonCommand: معالجة أوامر الأزرار والنصية
 *  - searchAndReplyParts: بحث القطع والرد
 *  - handleOwnerChat: محادثة حرة مع رمزي + AI
 *  - gatherDataForOwnerQuestion: تجميع بيانات لأسئلة رمزي
 *
 * تعتمد على: ConversationTrait + OwnerNotifyTrait
 */
trait OwnerCommandsTrait
{
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
    //  قائمة المالك رمزي
    // ═══════════════════════════════════════════════════════════════
    private function sendOwnerMenu(string $phone): void
    {
        $msg = "أهلاً أستاذ رمزي 👋\n\n"
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
             . "أو كلم *عبدالله* أو *محمد* مباشرة 👇";

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
                'لوحة رمزي'
            );
        } catch (Throwable $e) {
            error_log("[sendOwnerMenu] sendList failed: " . $e->getMessage());
            try { $this->wa->sendText($phone, $msg); } catch (Throwable) {}
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  ✅ [FIX-20] تفريغ الصوت الوارد من رمزي
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
        if (preg_match('/^يا?\s*(عبدالله|عبد\s*الله)/u', $text)) {
            $targetBot = self::BOT_ABDULLAH;
        } elseif (preg_match('/^يا?\s*(محمد|محمود)/u', $text)) {
            $targetBot = self::BOT_MOHAMMAD;
        } elseif (str_starts_with($text, 'عبدالله') || str_starts_with($text, 'عبد الله')) {
            $targetBot = self::BOT_ABDULLAH;
        } elseif (str_starts_with($text, 'محمد') || str_starts_with($text, 'محمود')) {
            $targetBot = self::BOT_MOHAMMAD;
        }

        if (!$targetBot) return false;

        $data = $this->gatherDataForOwnerQuestion($text);

        $personality = $targetBot === self::BOT_ABDULLAH
            ? "أنت عبدالله، مساعد رسمي ومحترم لصاحب المحل رمزي. ردودك مختصرة ودقيقة ومنظمة."
            : "أنت محمد، مساعد ودود لصاحب المحل رمزي. تحكي بأسلوب عربي بسيط، مزيج بين الفصحى والعامية العربية المفهومة لكل الناطقين بالعربية، بدون لهجة محلية مميزة. كن لطيفاً ومباشراً.";

        $systemPrompt = $personality . "\n\n"
            . "صاحب المحل اسمه رمزي، وهو يسألك سؤالاً عن المحل.\n"
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
}
