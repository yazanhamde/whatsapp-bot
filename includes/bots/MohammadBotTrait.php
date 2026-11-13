<?php

/**
 * MohammadBotTrait.php — منطق بوت محمد (المحادثة الحرة بالذكاء الاصطناعي)
 *
 * كل الدوال هون خاصة بشخصية محمد فقط: الـ system prompt الكامل،
 * استخراج بيانات الطلب من المحادثة الحرة، تأكيد الطلب، تتبعه،
 * ومتابعة العملاء اللي تركوا المحادثة بدون تأكيد (dropoff).
 *
 * هاد trait مو كلاس مستقل — بينضم لكلاس Agent (see includes/Agent.php)
 * لنفس سبب AbdullahBotTrait: وصول مباشر للخدمات المشتركة بدون تعقيد.
 */
trait MohammadBotTrait
{
    private function handleMohammad(
        string $phone,
        int    $convId,
        string $text,
        int    $custId,
        string $state,
        array  $conv,
        array  $draft
    ): void {
        // ⛔ [معطّل] فحص قاعدة المعرفة (KB) — كان هون: tryKBReply()
        // تم تعطيله بناءً على طلب رمزي، البوت هلق بيروح مباشرة للذكاء الاصطناعي.

        // 👤📱 [جديد] جمع اسم الزبون ورقم هاتفه — قبل ما نعرض له رسوم الزيارة ونسأل
        // "هل تريد تثبيت الطلب؟" مش بعدها. نفس أسلوب عبدالله بالضبط، عشان الفني
        // يعرف مين الزبون ويتواصل معه مباشرة. هاي خطوتين كودياً (مش AI) عشان
        // تكون موثوقة 100% زي بقية خطوات التأكيد.
        // 🛑 إلغاء طلب موجود / حالة مستعجلة — أولوية قبل أي خطوة ثانية (وقبل الـ AI)
        if ($this->handleCancelOrUrgent($phone, $convId, $custId, $text)) return;

        if ($state === self::STATE_MAINT_NAME) {
            $custName = trim($text);
            // 🛡️ ما نحفظ أي جملة كاسم: لو الزبون سأل سؤال بنجاوبه ونرجع نسأل عن الاسم،
            // ولو النص مش شكل اسم بنعيد الطلب.
            if (!$this->looksLikeName($custName)) {
                if ($this->answerMidFlowQuestion($phone, $convId, $custName, $draft)) {
                    $this->reply($phone, $convId, "👤 *شو اسمك الكريم؟*");
                } else {
                    $this->reply($phone, $convId, "⚠️ اكتب اسمك الكريم بس (مثلاً: أحمد) 🙏");
                }
                return;
            }
            $draft['customer_name']        = $custName;
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
            $this->updateConvState($convId, self::STATE_MAINT_PHONE, $draft);
            $this->reply($phone, $convId,
                "تشرفنا يا *{$custName}* 🙏\n\n" .
                "📱 *شو رقمك عشان يتواصل معك الفني؟*\n" .
                "_(اكتب الرقم، أو اكتب \"نفس الرقم\" إذا هاد رقمك)_"
            );
            return;
        }

        if ($state === self::STATE_MAINT_PHONE) {
            $phoneInput = trim($this->normalizeDigits($text));
            $n2 = $this->normalizeArabicText(mb_strtolower($phoneInput));
            if ((bool)preg_match('/(نفس\s*(الرقم|رقمي|رقم)|نفسه|نفسو|نفسي|هاد\s*(الرقم|رقمي)|هذا\s*(الرقم|رقمي)|نفس)/u', $n2)
                && !preg_match('/\d{7,}/', $phoneInput)) {
                $draft['customer_phone'] = $phone;
            } else {
                $digitsOnly = preg_replace('/\D/', '', $phoneInput);
                if (!preg_match('/^\d{7,15}$/', $digitsOnly)) {
                    if ($this->answerMidFlowQuestion($phone, $convId, $text, $draft)) {
                        $this->reply($phone, $convId, "📱 *شو رقمك عشان يتواصل معك الفني؟*\n_(أو اكتب \"نفس الرقم\")_");
                    } else {
                        $this->reply($phone, $convId, "⚠️ اكتب رقم هاتف صحيح، أو اكتب \"نفس الرقم\" 🙏");
                    }
                    return;
                }
                $draft['customer_phone'] = $digitsOnly;
            }
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;

            // ✅ خدنا الاسم والرقم — هلق نعرض رسوم الزيارة ونسأل "هل تريد تثبيت الطلب؟"
            // مع أزرار (بدل ما ننتظر رد الذكاء الاصطناعي، عشان نضمن نفس الصياغة دايماً).
            // ✅ إذا سعر الزيارة تحدد فعلياً لمنطقة هذا الزبون (احتُسب أصلاً بمرحلة تحديد
            // الموقع)، نذكره صراحة بين قوسين بدل ما نبقى بالنطاق العام — بطلب رمزي.
            $feePriceNote = !empty($draft['visit_price'])
                ? " (منطقتك: {$draft['visit_price']} دينار)"
                : '';
            $feeMsg = "للعلم، هناك رسوم فحص وزيارة تختلف حسب منطقتك{$feePriceNote}، وأجر الكشف يُحسب "
                . "إذا لم ترغب بالتصليح، أما إذا تم التصليح فتُدفع حسب الاتفاق مع الفني. "
                . "هل تريد تثبيت الطلب؟";
            $draft['awaiting_confirm_reply'] = true;
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            try {
                $this->wa->sendButtons(
                    $phone,
                    $feeMsg,
                    [
                        ['id' => 'btn_confirm_order', 'title' => 'تمام، ثبّت ✅'],
                        ['id' => 'btn_cancel_order',  'title' => 'لأ، شكراً'],
                    ]
                );
                $this->saveMessage($convId, 'bot', 'text', $feeMsg);
            } catch (Throwable $e) {
                $this->reply($phone, $convId, $feeMsg);
            }
            $this->logDropoffIfNew($convId, $custId, $phone, $draft);
            return;
        }

        // 🎯 حسم مباشر من الكود (بدون انتظار رأي الـ AI) لما الزبون يكون قدام
        // خطوة "هل تريد تثبيت الطلب؟" — هيك الزر يشتغل 100% من أول ضغطة دايماً،
        // بدل ما نعتمد على تفسير الموديل لنص الزر (اللي ممكن يتلخبط أحياناً).
        if (!empty($draft['awaiting_confirm_reply'])) {
            $handled = $this->resolvePendingConfirmation($phone, $convId, $custId, $text, $draft);
            if ($handled) return;
            // ما انطبق عليها تأكيد ولا رفض واضح → كمل عادي واخلي الـ AI يتعامل معها
        }

        $history = $this->getRecentHistory($convId, 6);

        $priceText = self::VISIT_PRICE_MIN . " إلى " . self::VISIT_PRICE_MAX . " دينار";

        // ✅ قواعد وشخصية محمد بالكامل تنقرا هلق من قسم "تعليمات" بالداشبورد
        // (setting_key = custom_bot_instructions) بدل ما تكون مثبتة بالكود.
        // لو الأدمن ما كتب/ما عدّل شي بعد، منستخدم نفس القواعد الافتراضية الأصلية
        // (defaultMohammadRules) عشان ما ينكسر سلوك البوت لحد ما الأدمن يراجعها.
        $dashboardRules = $this->getCustomInstructions();
        $rulesBlock     = $dashboardRules !== '' ? $dashboardRules : self::defaultMohammadRules($priceText);

        // 🔒 الجزء التقني الثابت: عقد الإخراج JSON اللي الكود بيعتمد عليه حرفياً
        // (extracted_data / suggest_state) — ما بينعدل من الداشبورد لأنه لو الأدمن
        // غيّره أو حذفه بالغلط، بينكسر تحليل رد الموديل بالكامل.
        $outputContract = <<<PROMPT

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📋 ملخص سريع لتصنيف نوع رسالة الزبون (ارجع له لما تتردد):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- سؤال عادي تقدر تجاوبه أنت مباشرة (أسعار، أوقات، موقع، توضيح بسيط، تشخيص أولي، فحص سريع) → intent="general", suggest_state="idle"
- استفسار أو ملاحظة ما تقدر تجاوبها أنت (شكوى شغل سابق، تعديل موعد/جدولة، طلب يحتاج قرار رمزي، سعر قطعة سابقة) → intent="inquiry", suggest_state="waiting_owner_answer"
- زبون بس قال "عندي سؤال/ملاحظة" بدون تفاصيل → intent="general", suggest_state="idle" (اسأله شو الموضوع أولاً، لا تحوّل لرمزي فاضي)
- زبون يسأل عن حالة طلب قديم → intent="track_order", suggest_state="idle"
- زبون يفتح طلب صيانة جديد كامل المعلومات → intent="maintenance"
- زبون يسأل عن قطعة غيار → intent="part_request"
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

مهم جداً:
- إذا الزبون ذكر الجهاز والمشكلة والموقع في رسالة وحدة، اجمعها كلها وحدد intent=maintenance
- لا تطلب معلومة موجودة بالرسالة مرة ثانية
- الماركة مهمة (القطعة بتعتمد عليها): لما تسأل عن الجهاز اسأل عن *الماركة* (LG، Samsung...) وليس عن "النوع"
- اسأل عن المدينة أولاً، وبعدين عن الحي أو المعلم القريب بسؤال منفصل (مش بنفس السؤال)
- ممنوع تكتب "هل تريد تثبيت الطلب" أو تذكر رسوم الزيارة أو تقول "رح نرسل فني" قبل ما تكتمل: نوع الجهاز + المشكلة + الموقع (مدينة وحي)
- اجمع الموقع كما يكتبه الزبون، أياً كان، والنظام سيتحقق منه تلقائياً

رد دائماً بـ JSON فقط بهذا الشكل (بدون أي نص خارج الـ JSON):
{
  "reply": "نص ردك هون، بأسلوب عربي بسيط مزيج بين الفصحى والعامية العربية العامة",
  "extracted_data": {
    "device": "نوع الجهاز إذا ذكره الزبون، وإلا استخدم null بدون علامات تنصيص (JSON null الحقيقية، مش كلمة null كنص بين علامتي تنصيص)",
    "brand": "ماركة الجهاز مثل LG أو Samsung إذا ذكرها، وإلا null بدون علامات تنصيص",
    "issue": "وصف المشكلة إذا ذكرها، وإلا null بدون علامات تنصيص",
    "location": "الموقع إذا ذكره، وإلا null بدون علامات تنصيص",
    "intent": "قيمة واحدة فقط من: maintenance أو part_request أو inquiry أو tracking أو track_order أو general"
  },
  "suggest_state": "قيمة واحدة فقط من: idle أو maint_location أو waiting_part_text أو waiting_track_number أو waiting_owner_answer"
}
🚨 مهم جداً: لما القيمة غير معروفة، اكتب null بدون علامات تنصيص (JSON null الحقيقية) — ممنوع تكتب كلمة "null" كنص بين علامتي تنصيص، لأنها بتتخزن كقيمة حقيقية غلط بدل ما تُعتبر فاضية.
PROMPT;

        $systemPrompt = $rulesBlock . "\n" . $outputContract;

        // ── RAG: حوّل الأسماء العامية + ابحث عن تجارب سابقة وأعطال ──
        $resolvedText = $this->rag->resolveAlias($text);
        $deviceHint   = (string)($draft['device_type'] ?? '');
        $ragContext   = $this->rag->buildFullContext($resolvedText ?: $text, $deviceHint);

        // ✅ إذا كانت رسوم الزيارة تحددت مسبقاً لمنطقة هذا الزبون (بعد ما بعت موقعه)،
        // مرّرها بالسياق عشان يقدر يجاوب برقم دقيق لو سأل "كم الرسوم؟" مباشرة
        $priceContext = !empty($draft['visit_price'])
            ? "\n\n📌 رسوم الزيارة المحسوبة فعلياً لمنطقة هذا الزبون: {$draft['visit_price']} دينار."
            : '';

        // ✅ آخر قطعة سأل عنها الزبون بصورة (لو موجودة) — عشان يربط أسئلة السعر/الشراء فيها
        if (!empty($draft['last_part_label'])) {
            $availText = !empty($draft['last_part_available']) ? 'متوفرة' : 'غير متوفرة حالياً';
            $priceContext .= "\n\n🧩 آخر قطعة سأل عنها الزبون بالصورة: {$draft['last_part_label']} ({$availText}).";
        }

        // 🧠 [مهم جداً] ذكّر الموديل صراحة بالمعلومات يلي أصلاً اتجمعت من الزبون بهاي
        // المحادثة (جهاز/ماركة/مشكلة/موقع) — عشان ما "ينسى" ويرجع يسأل من الصفر
        // بعد كذا رسالة أو رسالة صوتية. هاي مو موجودة بالـ history العادي بشكل صريح.
        $knownInfo = [];
        if (!empty($draft['device_type']))  $knownInfo[] = "الجهاز: {$draft['device_type']}";
        if (!empty($draft['device_brand'])) $knownInfo[] = "الماركة: {$draft['device_brand']}";
        if (!empty($draft['issue']))        $knownInfo[] = "المشكلة: {$draft['issue']}";
        if (!empty($draft['location']))     $knownInfo[] = "الموقع: {$draft['location']}";
        if (empty($draft['device_type']) && (!empty($draft['issue']) || !empty($draft['location']))) {
            $priceContext .= "\n\n⚠️ نوع الجهاز لسا غير معروف — لازم تسأل عنه صراحة (غسالة/ثلاجة/مكيف/...) قبل أي خطوة ثانية.";
        }
        if (!empty($knownInfo)) {
            $priceContext .= "\n\n📌 معلومات الطلب اللي أصلاً جمعتها من الزبون بهاي المحادثة (هاي معلومات مؤكدة وثابتة، ممنوع تسأل عنها ثانية أو تتجاهلها إلا إذا الزبون غيّرها بنفسه): "
                . implode('، ', $knownInfo) . ".";
        }

        // 🚦 [مهم جداً] حالة الطلب الحالية بالمحادثة — عشان الموديل ما يخلط بين "زبون
        // بس بيسأل" و"زبون بنص فتح طلب فعلي". لو ما في جهاز/عطل/موقع مجموعين حالياً
        // (يعني الطلب السابق خلص وانثبت، أو لسا ما بلش زبون يفتح طلب)، ممنوع يظهر أي
        // سؤال أو زر "هل تريد تثبيت الطلب؟" إلا لو الزبون فعلاً عبّر عن نيته يفتح طلب جديد.
        $hasActiveDraft = !empty($draft['device_type']) && !empty($draft['issue']) && !empty($draft['location']);
        $priceContext .= $hasActiveDraft
            ? "\n\n🟢 يوجد الآن طلب صيانة قيد الجمع/التأكيد بهاي المحادثة (بيانات الطلب فوق)."
            : "\n\n🔴 لا يوجد حالياً أي طلب صيانة مفتوح أو قيد التأكيد بهاي المحادثة (إما لسا ما بلش الزبون يفتح طلب، أو طلب سابق خلص وانثبت). ممنوع تسأله أو تذكره بـ\"هل تريد تثبيت الطلب؟\" إلا إذا هو صراحة عبّر عن رغبته يفتح طلب صيانة جديد بهاي الرسالة أو الرسايل الأخيرة.";

        // 📦 [جديد] آخر طلب صيانة للزبون + حالته الفعلية من الداتابيز — عشان يجاوب على
        // "الفني جاي؟ / وين طلبي؟ / لسا ما اجا حدا" بمعلومة حقيقية بدل التخمين.
        $priceContext .= $this->buildLatestOrderContext($phone, $custId);

        $finalPrompt  = $ragContext !== ''
            ? $systemPrompt . $priceContext . "\n\n" . $ragContext
            : $systemPrompt . $priceContext;

        // ── quoted message: لو الزبون سحب على رسالة وكتب سؤاله ──────────────
        // نحطها صراحةً بالـ context عشان الـ AI يعرف الزبون عم يرد على شو بالضبط
        $userContent = $text;
        if (!empty($this->_quotedBody)) {
            $userContent = "[الزبون سحب على هالرسالة: \"{$this->_quotedBody}\"]\n{$text}";
        }

        $messages = [
            ['role' => 'system', 'content' => $finalPrompt],
            ...$history,
            ['role' => 'user', 'content' => $userContent],
        ];

        $aiResponse = $this->callAIWithRetry($messages, true);

        if (!$aiResponse) {
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
            $this->updateConvState($convId, $state, $draft);
            $this->reply($phone, $convId,
                "لم أفهم سؤالك جيداً الآن 😅\n"
                . "اكتب سؤالك ثاني أو اتصل بنا مباشرة،\n"
                . "أو اختر من القائمة لعبدالله 👇"
            );
            $this->sendWelcome($phone, $convId);
            return;
        }

        $reply        = $aiResponse['reply']          ?? '';
        $extracted    = $aiResponse['extracted_data'] ?? [];
        $suggestState = $aiResponse['suggest_state']  ?? 'null';

        if ($reply === '') {
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
            $this->updateConvState($convId, $state, $draft);
            $this->reply($phone, $convId,
                "لم أفهم سؤالك جيداً 😅\nجرب تكتبه ثاني أو اختر من القائمة 👇"
            );
            $this->sendWelcome($phone, $convId);
            return;
        }

        $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;

        // ✅ حماية: أحياناً الموديل بيرجع كلمة "null" كنص حرفي (مش JSON null الحقيقية)
        // — لازم نرفضها هون قبل ما تنخزن كأنها قيمة فعلية (سبب باغ "null — null")
        $isRealValue = function ($v): bool {
            if (empty($v)) return false;
            $n = mb_strtolower(trim((string)$v));
            return $n !== 'null' && $n !== 'none' && $n !== 'n/a';
        };

        // 🔄 لو الزبون غيّر نوع الجهاز (مثلاً من غسالة لثلاجة) نمسح العطل/الماركة القديمة
        if ($isRealValue($extracted['device'] ?? null) && !empty($draft['device_type_id'])) {
            $newDev = $this->resolveDeviceTypeId((string)$extracted['device']);
            if ($newDev && (int)$newDev['id'] !== (int)$draft['device_type_id']) {
                unset($draft['issue'], $draft['issue_id'], $draft['device_brand'], $draft['brand_asked'], $draft['device_type_id']);
            }
        }
        if ($isRealValue($extracted['device'] ?? null))   $draft['device_type']  = $extracted['device'];
        if ($isRealValue($extracted['brand'] ?? null))    $draft['device_brand'] = $extracted['brand'];
        if ($isRealValue($extracted['location'] ?? null)) $draft['location']     = $extracted['location'];

        // 📍 لو سألنا الزبون عن المنطقة داخل المحافظة (مثلاً "عمان") وجاوب بالحي بس،
        // ندمجهم عشان الطلب ينحفظ "عمان - الهاشمي الشمالي" مش "الهاشمي الشمالي" لحاله.
        if (!empty($draft['location_gov']) && !empty($draft['location'])) {
            $gov = trim((string)$draft['location_gov']);
            $locNow = trim((string)$draft['location']);
            if (!$this->isGovernorateOnly($locNow)
                && mb_strpos($this->normalizeArabicText($locNow), $this->normalizeArabicText($gov)) === false) {
                $draft['location'] = $gov . ' - ' . $locNow;
            }
            unset($draft['location_gov']);
        }

        // ✅ المشاكل: لو الزبون ذكر أكتر من عطل بأكتر من رسالة، نجمعهم بدل ما نستبدل
        // (كان قبل: كل عطل جديد يمسح القديم — فيوصل بس آخر مشكلة انذكرت)
        if ($isRealValue($extracted['issue'] ?? null)) {
            $newIssue = trim((string)$extracted['issue']);
            $existingIssue = trim((string)($draft['issue'] ?? ''));
            if ($existingIssue === '') {
                $draft['issue'] = $newIssue;
            } elseif (mb_strtolower($existingIssue) !== mb_strtolower($newIssue)
                && !str_contains(mb_strtolower($existingIssue), mb_strtolower($newIssue))) {
                $draft['issue'] = $existingIssue . ' و' . $newIssue;
            }
        }

        if (!empty($draft['device_type'])) {
            $resolvedDevice = $this->resolveDeviceTypeId((string)$draft['device_type']);
            if ($resolvedDevice) {
                $draft['device_type_id'] = (int)$resolvedDevice['id'];
                $draft['device_type']    = (string)$resolvedDevice['name_ar'];
            }
        }
        if (!empty($draft['issue']) && !empty($draft['device_type_id'])) {
            $resolvedIssue = $this->resolveIssueId((int)$draft['device_type_id'], (string)$draft['issue']);
            if ($resolvedIssue) {
                $draft['issue_id'] = (int)$resolvedIssue['id'];
                $draft['issue']    = (string)$resolvedIssue['issue_text_ar'];
            }
        }

        $intent        = $extracted['intent'] ?? 'general';
        $currentStatus = $conv['status'] ?? 'bot';

        // 🖥️ [بوابة إلزامية — نوع الجهاز] نوع الجهاز معلومة أساسية للفني. إذا الزبون فتح
        // طلب صيانة (أو الموديل بدأ يلخّص/يحكي رسوم/يسأل تثبيت) وما في جهاز محدد بعد،
        // نسأل عنه من الكود مباشرة بدل ما نعتمد على الموديل يتذكر يسأل.
        $deviceGeneric = ['جهاز','الجهاز','اجهزة','أجهزة','الاجهزة','الأجهزة','شي','شيء','غرض'];
        $deviceMissing = empty($draft['device_type'])
            || in_array(trim((string)$draft['device_type']), $deviceGeneric, true);
        $skipIntents   = ['track_order','tracking','inquiry','part_request'];
        $replyJumpsAhead = str_contains($reply, 'تثبيت الطلب') || str_contains($reply, 'رسوم فحص') || str_contains($reply, 'شو اسمك');
        if ($deviceMissing
            && !in_array($intent, $skipIntents, true)
            && ($aiResponse['suggest_state'] ?? '') !== 'waiting_owner_answer'
            && ($intent === 'maintenance' || $replyJumpsAhead || !empty($draft['issue']) || !empty($draft['location']))
        ) {
            $draft['device_type'] = null; // نظّف أي قيمة عامة مثل "جهاز"
            $draft['device_asks'] = (int)($draft['device_asks'] ?? 0) + 1;
            $draft['awaiting_confirm_reply'] = false;
            $ask = $draft['device_asks'] <= 1
                ? "تمام 🙏 شو *نوع الجهاز* اللي بدك نصلحه؟\n_(مثلاً: غسالة، ثلاجة، مكيف، فرن، جلاية، ميكرويف، مجفف)_"
                : "عشان أقدر أفتحلك الطلب لازم أعرف *نوع الجهاز* بالضبط 🙏 شو هو (غسالة / ثلاجة / مكيف / فرن ...)؟";
            $this->reply($phone, $convId, $ask);
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            return;
        }

        // 🏷️ [بوابة — ماركة الجهاز] لكل الأجهزة (ثلاجة/مكيف/فرن/غسالة/...) نسأل عن الماركة
        // (LG، Samsung، ...) مرة وحدة. مش إلزامية: لو الزبون قال "ما بعرف" أو تجاهلها بنكمل.
        if (!$deviceMissing
            && empty($draft['device_brand'])
            && empty($draft['brand_asked'])
            && !in_array($intent, ['track_order','tracking','inquiry','part_request'], true)
            && ($aiResponse['suggest_state'] ?? '') !== 'waiting_owner_answer'
            && ($intent === 'maintenance' || !empty($draft['issue']) || !empty($draft['location']))
        ) {
            $draft['brand_asked'] = true;
            $draft['awaiting_confirm_reply'] = false;
            $devName = trim((string)$draft['device_type']);
            $ask = "تمام 🙏 شو *ماركة* {$devName}؟\n_(مثلاً: LG، Samsung، Bosch، Sharp... أو اكتب \"ما بعرف\")_";
            if (empty($draft['location'])) {
                $ask .= "\n\nوبأي *منطقة* أنت؟";
            }
            $this->reply($phone, $convId, $ask);
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            return;
        }

        // 📍 [بوابة إلزامية — المنطقة داخل المحافظة] إذا الزبون كتب اسم المحافظة/المدينة بس
        // (مثلاً "عمان" أو "الزرقاء") بدون حي أو منطقة، نسأل عن المنطقة بالضبط لأنه
        // رسوم الزيارة بتتحدد على أساسها. بعد سؤالين بنقبل اللي كتبه عشان ما نعلق الزبون.
        if (!empty($draft['location'])
            && $this->isGovernorateOnly((string)$draft['location'])
            && (int)($draft['area_asks'] ?? 0) < 2
            && !in_array($intent, ['track_order','tracking','inquiry','part_request'], true)
            && ($aiResponse['suggest_state'] ?? '') !== 'waiting_owner_answer'
            && ($intent === 'maintenance' || !empty($draft['issue']))
        ) {
            $govName = trim((string)$draft['location']);
            $draft['area_asks'] = (int)($draft['area_asks'] ?? 0) + 1;
            $draft['location_gov'] = $govName;
            $draft['location'] = null;
            $draft['awaiting_confirm_reply'] = false;
            $ask = $draft['area_asks'] <= 1
                ? "تمام 🙏 بأي *منطقة أو حي* في {$govName} بالضبط؟\n_(اكتب اسم المنطقة، أو ابعت موقعك GPS)_"
                : "عشان أحدد رسوم الزيارة بدقة لازم أعرف *المنطقة أو الحي* بالضبط 🙏 اكتب اسمها أو ابعت موقعك GPS.";
            $this->reply($phone, $convId, $ask);
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            return;
        }

        // 🚧 [بوابة — اكتمال البيانات] ممنوع أي رد فيه "تثبيت الطلب" أو "رسوم فحص" أو تأكيد
        // وهمي ("رح نرسل فني"...) قبل ما يكون عندنا: جهاز + عطل + موقع (حي/منطقة).
        // لو ناقص شي نسأل عنه من الكود. لو كله موجود نرجّعه لمسار التثبيت الحقيقي
        // (حساب الرسوم ← الاسم ← الرقم ← التأكيد).
        $gateSkip = in_array($intent, ['track_order','tracking','inquiry','part_request'], true)
            || ($aiResponse['suggest_state'] ?? '') === 'waiting_owner_answer';
        $replyHasFeeOrConfirm = str_contains($reply, 'تثبيت الطلب')
            || str_contains($reply, 'رسوم فحص')
            || $this->looksLikeFakeConfirmation($reply)
            || (bool)preg_match('/(رح نرسل|رح يتواصل|فني صيانة ليتفقد|رح نبعت)/u', $reply);
        if (!$gateSkip && $replyHasFeeOrConfirm) {
            $locOk = !empty($draft['location'])
                && (!$this->isGovernorateOnly((string)$draft['location']) || (int)($draft['area_asks'] ?? 0) >= 2);
            if (empty($draft['issue'])) {
                $draft['awaiting_confirm_reply'] = false;
                $this->reply($phone, $convId, "تمام 🙏 شو *المشكلة* اللي عندك بالجهاز؟");
                $this->updateConvState($convId, self::STATE_IDLE, $draft);
                return;
            }
            if (!$locOk) {
                $draft['awaiting_confirm_reply'] = false;
                $this->reply($phone, $convId, empty($draft['location_gov'])
                    ? "تمام 🙏 بأي *مدينة* أنت؟"
                    : "تمام 🙏 بأي *منطقة أو حي* في {$draft['location_gov']} بالضبط؟");
                $this->updateConvState($convId, self::STATE_IDLE, $draft);
                return;
            }
            // كل البيانات موجودة → مسار التثبيت الحقيقي
            $intent = 'maintenance';
            $aiResponse['suggest_state'] = 'idle';
        }

        if (isset($aiResponse['suggest_state'])) {

            if ($aiResponse['suggest_state'] === 'idle') {

                if ($intent === 'track_order' || $intent === 'tracking') {
                    $this->reply($phone, $convId, $reply);
                    $this->trackLatestOrderByPhone($phone, $convId, $custId, $text);
                    $this->notifyRamziCustomerIsTracking($phone);
                    $this->updateConvState($convId, self::STATE_IDLE, $draft);
                    return;
                }

                if ($intent === 'inquiry') {
                    $this->reply($phone, $convId, $reply);
                    // ✅ فقط نحوّل لرمزي إذا محمد ما عرف يجاوب (suggest_state=waiting_owner_answer)
                    // إذا جاوب بنفسه (suggest_state=idle) → ما نزعج رمزي
                    $suggestIsEscalate = ($aiResponse['suggest_state'] ?? '') === 'waiting_owner_answer';
                    if ($suggestIsEscalate) {
                        $notifId = $this->saveNoteAsNotification($convId, $custId, $text, $phone, self::BOT_MOHAMMAD);
                        $replyLine = $notifId ? "\n\nللرد اكتب:\n*" . self::BOT_MOHAMMAD . ": رد#{$notifId}: جوابك*" : '';
                        // ✅ إشعار واحد مدموج (بدل رسالتين): الملاحظة + سطر الرد إن وُجد
                        $this->notifyRamziWithCustomerNote(
                            $phone, $text,
                            self::BOT_MOHAMMAD . " 💬 *ملاحظة/استفسار" . ($notifId ? " — يحتاج رد رمزي" : "") . "*\n📱 {$phone}\n📝 {$text}{$replyLine}"
                        );
                    }
                    $this->updateConvState($convId, self::STATE_IDLE, $draft);
                    return;
                }

                if ($intent === 'maintenance'
                    && !empty($draft['device_type'])
                    && !empty($draft['issue'])
                    && !empty($draft['location'])
                ) {
                    // ── فحص الموقع (داخل الأردن؟) وتحديد المنطقة — عبر OpenAI ─────
                    $areaType = $this->detectServiceArea((string)$draft['location']);
                    // 📍 لو رفضنا الموقع قبل هيك (مثلاً "قريب من عمان") ما نكرر الرفض — نسأل عن المنطقة بالضبط
                    $refusedBefore = (int)($draft['loc_refusals'] ?? 0);
                    if ($refusedBefore >= 1 && ($areaType === 'unknown' || $areaType === 'parts' || !$this->lastLocationIsJordan)) {
                        $draft['loc_refusals'] = $refusedBefore + 1;
                        $draft['location'] = null;
                        $this->reply($phone, $convId,
                            "ما قدرت أحدد منطقتك بالضبط 🙏\nاكتب لي *اسم المنطقة أو الحي* (مثلاً: طبربور، ماركا، الزرقاء - الهاشمية) أو ابعت موقعك GPS."
                        );
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }
                    if (!$this->lastLocationIsJordan) {
                        $this->reply($phone, $convId,
                            "⚠️ خدمة الصيانة متوفرة داخل *الأردن* فقط 🇯🇴\nاكتب عنوانك داخل الأردن."
                        );
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }
                    if ($areaType === 'parts') {
                        $draft['loc_refusals'] = (int)($draft['loc_refusals'] ?? 0) + 1;
                        $this->reply($phone, $convId,
                            "عذراً، خدمة الصيانة بالبيت متوفرة بعمان والسلط والزرقاء فقط حالياً.\n"
                            . "بس عندنا قطع غيار لكل محافظات المملكة!\n"
                            . "للطلب اتصل على: 📞 *+96278001678*\n(مكالمة فقط — لا رسائل واتساب)"
                        );
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }
                    // تحديد سعر الزيارة
                    $draft['visit_price'] = ($areaType === 'zarqa') ? 15 : 10;

                    // 👤📱 [جديد] لازم اسم الزبون ورقمه *قبل* ما نعرض رسوم الزيارة ونسأل
                    // "هل تريد تثبيت الطلب؟" — مش بعدها. هيك الفني بياخد المعلومات
                    // كاملة أول ما يوصله الطلب، والزبون ما يتفاجئ بسؤال بعد ما وافق.
                    if (empty($draft['customer_name'])) {
                        $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
                        $this->updateConvState($convId, self::STATE_MAINT_NAME, $draft);
                        // ✅ ملخص ديناميكي فيه تفاصيل الطلب الفعلية بدل جملة عامة ثابتة —
                        // عشان الزبون يحس إنه مسموع ونتأكد سوا قبل ما نكمل لأخذ اسمه.
                        $deviceLine = trim(($draft['device_type'] ?? '') . (!empty($draft['device_brand']) ? ' ' . $draft['device_brand'] : ''));
                        $summary = "تمام، يعني عندك {$deviceLine} والمشكلة {$draft['issue']}، والموقع {$draft['location']}. 🙏\n\nقبل ما نكمل، 👤 *شو اسمك الكريم؟*";
                        $this->reply($phone, $convId, $summary);
                        return;
                    }
                    if (empty($draft['customer_phone'])) {
                        $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
                        $this->updateConvState($convId, self::STATE_MAINT_PHONE, $draft);
                        $this->reply($phone, $convId,
                            "📱 *شو رقمك عشان يتواصل معك الفني؟*\n_(اكتب الرقم، أو اكتب \"نفس الرقم\" إذا هاد رقمك)_"
                        );
                        return;
                    }

                    $wasAwaitingConfirm = !empty($draft['awaiting_confirm_reply']);
                    $userApproved = $wasAwaitingConfirm
                        && (bool)preg_match('/(اه|نعم|نهم|تمام|^تم$|اوكي|أوكي|موافق|ثبت|توكل|ياريت|يا ريت|امشي)/ui', $text);
                    $isConfirmationAsk = str_contains($reply, 'تثبيت الطلب');
                    $draft['awaiting_confirm_reply'] = $isConfirmationAsk; // للرسالة الجاية

                    if (!$userApproved) {
                        if ($isConfirmationAsk) {
                            // ✅ زر تأكيد جاهز بدل ما الزبون يكتب يدوي — بيرفع نسبة إتمام الطلب
                            // (يظهر فقط لما محمد فعلياً يسأل "هل تريد تثبيت الطلب؟"، مش على أي سؤال توضيحي)
                            try {
                                $this->wa->sendButtons(
                                    $phone,
                                    $reply,
                                    [
                                        ['id' => 'btn_confirm_order', 'title' => 'تمام، ثبّت ✅'],
                                        ['id' => 'btn_cancel_order',  'title' => 'لأ، شكراً'],
                                    ]
                                );
                                $this->saveMessage($convId, 'bot', 'text', $reply);
                            } catch (Throwable $e) {
                                $this->reply($phone, $convId, $reply);
                            }
                            $this->logDropoffIfNew($convId, $custId, $phone, $draft);
                        } else {
                            $this->reply($phone, $convId, $reply);
                        }
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }

                    // ── RAG: احفظ المحادثة الناجحة ────────────────────────
                    if (!empty($draft['device_type']) && !empty($draft['issue'])) {
                        $ragQ = "صيانة {$draft['device_type']} - {$draft['issue']}";
                        $ragA = $reply;
                        $ragTags = implode(',', array_filter([
                            $draft['device_type'] ?? null,
                            $draft['device_brand'] ?? null,
                            $draft['issue'] ?? null,
                        ]));
                        $this->rag->saveAuto($ragQ, $ragA, $ragTags ?: null);
                    }

                    // ✅ اسم الزبون ورقمه أصلاً مجموعين هلق (من الكتلة فوق) → نثبت مباشرة
                    $this->finalizeMohammadOrder($phone, $convId, $custId, $draft);
                    return;
                }

                if ($intent === 'part_request' && !empty($extracted['device'])) {
                    $results = $this->ps->search($extracted['device']);
                    if (!empty($results)) {
                        $this->reply($phone, $convId, $reply);
                        if (count($results) === 1) {
                            $this->sendProductResult($phone, $convId, $results[0], $extracted['device']);
                        } else {
                            $this->reply($phone, $convId, $this->ps->formatList($results));
                        }
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }
                }

                // ── إذا سأل عن الموقع → ابعت location message ──────
                if ($intent === 'general' && (
                    str_contains($text, 'وين') ||
                    str_contains($text, 'موقع') ||
                    str_contains($text, 'عنوان') ||
                    str_contains($text, 'كيف أجي') ||
                    str_contains($text, 'كيف اجي') ||
                    str_contains($text, 'لوكيشن') ||
                    str_contains($text, 'خريطة')
                )) {
                    $this->reply($phone, $convId, $reply);
                    $this->sendShopLocation($phone);
                    $this->updateConvState($convId, self::STATE_IDLE, $draft);
                    return;
                }

                // 🛡️🛡️ [شبكة أمان حرجة] وصلنا هون يعني النظام ما ثبّت أي طلب فعلي (الفرع
                // البرمجي الحقيقي للتثبيت ما اتنفذ). لو الموديل رغم كل التعليمات كتب جملة
                // توحي بتثبيت طلب وهمي (مخالفة لقاعدة 4.5)، لازم نمنعها من توصل للزبون
                // نهائياً — لأنه هاد بيخلق طلب "وهمي" الزبون مقتنع فيه بس مافي إشي حقيقي
                // بالداتابيز ولا حد رح يتواصل معه. منستبدلها برسالة آمنة بدل ما نكمل غلط.
                if ($this->looksLikeFakeConfirmation($reply)) {
                    error_log("[MohammadBot][FAKE_CONFIRMATION_BLOCKED] convId={$convId} phone={$phone} raw_reply=" . $reply);
                    $missing = empty($draft['location']) ? 'الموقع' : (empty($draft['issue']) ? 'وصف المشكلة' : 'تفاصيل الطلب');
                    $reply = "تمام 🙏 بس لسا محتاج منك {$missing} قبل ما أقدر أفتحلك الطلب فعلياً، ممكن تعطيني ياها؟";
                }

                $this->reply($phone, $convId, $reply);
                $this->updateConvState($convId, self::STATE_IDLE, $draft);
                return;
            }

            if ($aiResponse['suggest_state'] === 'waiting_owner_answer') {
                if ($currentStatus === 'waiting_owner_answer') {
                    $this->reply($phone, $convId, $reply);
                    $this->updateConvState($convId, self::STATE_IDLE, $draft);
                    return;
                }

                // ✅ ما نبعت رد الـ AI هون — escalateToOwner() بتبعت "لحظة أسأل فريق الصيانة" لحالها
                // (قبل هيك الزبون كان يستلم نفس الجملة مرتين/تلات بنفس الثانية).
                $partImage = (string)($draft['last_part_image'] ?? '');
                $ownerQuestion = !empty($draft['last_part_label'])
                    ? "🧩 الزبون بسأل عن سعر قطعة\n🔧 " . $draft['last_part_label'] . "\n💬 نص رسالته: {$text}"
                    : $text;
                $notifId = $this->escalateToOwner($phone, $convId, $ownerQuestion, $custId, null, self::BOT_MOHAMMAD);
                if ($partImage && file_exists($partImage)) {
                    $forwardCaption = "🧩 استفسار سعر قطعة\n📱 {$phone}\n🔧 " . ($draft['last_part_label'] ?? '')
                        . ($notifId ? "\n\nللرد اكتب:\n*" . self::BOT_MOHAMMAD . ": رد#{$notifId}: جوابك*" : '');
                    $this->forwardImageToOwners($partImage, $forwardCaption);
                }
                $this->updateConvState($convId, self::STATE_IDLE, $draft);
                return;
            }
        }

        $this->reply($phone, $convId, $reply);
        $this->updateConvState($convId, $state, $draft);
    }


    // ═══════════════════════════════════════════════════════════════
    //  دوال مساعدة: فحص الاسم، أسئلة وسط الطلب، إلغاء/استعجال
    // ═══════════════════════════════════════════════════════════════

    /** هل النص شكله اسم شخص فعلاً (وليس سؤال/جملة)؟ */
    private function looksLikeName(string $t): bool
    {
        $t = trim($t);
        if ($t === '' || mb_strlen($t) < 2 || mb_strlen($t) > 30) return false;
        if (preg_match('/[?؟!؛:\/@\d\x{0660}-\x{0669}]/u', $t)) return false;
        $words = preg_split('/\s+/u', $this->normalizeArabicText($t)) ?: [];
        if (count($words) > 4) return false;
        $stop = ['كم','متى','امتى','شو','ايش','ليش','كيف','وين','هل','بدي','بدنا','اريد','ابغي','ممكن','مبلغ','سعر','تكلف','بتكلف',
                 'قبل','بعد','الزياره','رسوم','الاسم','اعرف','اعطيك','يحكي','معك','اوك','تمام','نعم','ايوه','لا','مش','ما','الغاء',
                 'الغي','شكرا','مرحبا','هلا','السلام','عندي','ثلاجه','غساله','جهاز','مشكله','فني','صيانه','طلب'];
        foreach ($words as $w) {
            if (in_array($w, $stop, true)) return false;
        }
        return true;
    }

    /**
     * لو الزبون سأل سؤال وسط جمع البيانات (مبلغ الزيارة، متى بتجوا...) نجاوبه ونرجع يكمل.
     * يرجّع true إذا النص كان سؤال وتم الرد عليه.
     */
    private function answerMidFlowQuestion(string $phone, int $convId, string $text, array $draft): bool
    {
        $n = $this->normalizeArabicText($text);
        $isQuestion = (bool)preg_match('/[?؟]/u', $text)
            || (bool)preg_match('/(^|\s)(كم|متى|امتى|شو|ايش|ليش|كيف|وين|هل|بدي اعرف|ممكن)(\s|$)/u', $n)
            || str_contains($n, 'مبلغ') || str_contains($n, 'تكلف') || str_contains($n, 'سعر');
        if (!$isQuestion) return false;

        $price = !empty($draft['visit_price'])
            ? "{$draft['visit_price']} دينار"
            : (self::VISIT_PRICE_MIN . " إلى " . self::VISIT_PRICE_MAX . " دينار حسب المنطقة");

        if (preg_match('/(كم|مبلغ|سعر|تكلف|رسوم|اجره|اجر)/u', $n)) {
            $ans = "رسوم الفحص والزيارة: *{$price}*، وبتنحسب إذا ما رغبت بالتصليح. أما إذا تم التصليح فبتنحسب الأجرة حسب الاتفاق مع الفني 🙏";
        } elseif (preg_match('/(متى|امتى|وقت|اليوم|بكرا|كم ساعه)/u', $n)) {
            $ans = "بعد تثبيت الطلب بيتواصل معك فريق الصيانة مباشرة لتحديد الموعد المناسب 🙏";
        } else {
            $ans = '';
            try { $ans = trim($this->answerGeneralQuestion($text)); } catch (Throwable $e) {}
            if ($ans === '') $ans = "بعد تثبيت الطلب بيتواصل معك الفريق ويجاوبك على كل استفساراتك 🙏";
        }
        $this->reply($phone, $convId, $ans);
        return true;
    }

    /**
     * 🛑 إلغاء طلب صيانة مفتوح + تصعيد الحالات المستعجلة.
     * يرجّع true لو تعامل مع الرسالة. ما يتدخل إذا ما في طلب مفتوح للزبون.
     */
    private function handleCancelOrUrgent(string $phone, int $convId, int $custId, string $text): bool
    {
        $n = $this->normalizeArabicText($text);
        $wantsCancel = (bool)preg_match('/(الغ[يو]|الغاء|كنسل|cancel|لا\s*تجي|لا\s*تجو|روحت\s*لفني|جبت\s*فني|صلحتها)/u', $n);
        $isUrgent = (bool)preg_match('/(مستعجل|عاجل|طارئ|ما\s*بتحتمل|ما\s*بتتحمل|بسرعه|الحقو|بتخرب|بتخرب|بتفسد|رح\s*تخرب)/u', $n);
        if (!$wantsCancel && !$isUrgent) return false;

        $req = $this->findLastRequestByCustomerPhone($phone, $custId, true);
        if (!$req) return false; // ما في طلب مفتوح → خلي الفلو العادي يكمل

        $order = $req['order_number'] ?? '---';

        if ($wantsCancel) {
            $status = (string)$req['status'];
            if ($status === 'pending') {
                try {
                    $this->db->prepare("UPDATE service_requests SET status='cancelled' WHERE id=? AND status='pending'")
                        ->execute([(int)$req['id']]);
                } catch (Throwable $e) { error_log('[cancel] ' . $e->getMessage()); }
                $this->reply($phone, $convId, "تمام، تم إلغاء طلبك رقم *{$order}* ✅\nإذا احتجت صيانة بأي وقت احنا موجودين 🙏");
                $this->notifyAdmins("🛑 *الزبون ألغى الطلب {$order}* (كان بانتظار تعيين فني)\n📱 {$phone}\n📝 {$text}");
            } else {
                // الفني عُيّن أو بدأ الشغل → ما بنلغي لحالنا، بنبلّغ الفريق فوراً
                $this->reply($phone, $convId,
                    "وصّلت طلب الإلغاء للفريق فوراً 🙏 رح يتواصلوا معك بأسرع وقت لتأكيده.\n📞 للتواصل المباشر: *" . self::SUPPORT_PHONE . "*"
                );
                $this->notifyAdmins("🛑🚨 *طلب إلغاء عاجل — الفني معيّن أو بالطريق*\n🔢 {$order}\n📱 {$phone}\n📝 {$text}\nيرجى التواصل مع الزبون فوراً.");
            }
            return true;
        }

        // مستعجل
        $this->reply($phone, $convId, "فهمت إنو الموضوع مستعجل 🙏 بلّغت الفريق الآن وبيتواصلوا معك بأسرع وقت.\n📞 *" . self::SUPPORT_PHONE . "*");
        $this->notifyAdmins("🚨 *حالة مستعجلة*\n🔢 {$order}\n📱 {$phone}\n📝 {$text}");
        return true;
    }


    // ═══════════════════════════════════════════════════════════════
    //  ✏️ تعديل طلب مفتوح (اسم / رقم / عنوان / جهاز / عطل)
    //  pending  → بنعدّل مباشرة بالداتابيز + بنبلّغ رمزي
    //  assigned / in_progress → ما بنعدّل لحالنا، بنبلّغ رمزي بالتعديل المطلوب فقط
    // ═══════════════════════════════════════════════════════════════
    private function editFields(): array
    {
        return [
        'location' => ['col' => 'customer_location',  'label' => 'العنوان'],
        'phone'    => ['col' => 'customer_phone',     'label' => 'رقم التواصل'],
        'name'     => ['col' => 'customer_name',      'label' => 'الاسم'],
        'device'   => ['col' => 'product_name',       'label' => 'الجهاز'],
        'issue'    => ['col' => 'issue_description',  'label' => 'العطل'],
        ];
    }

    private function detectEditField(string $normText): ?string
    {
        if (preg_match('/رقم\s*(ال)?طلب/u', $normText)) return null; // هاد تتبع مش تعديل
        // الترتيب مهم: العطل قبل الجهاز (عشان "عدل العطل: الثلاجة ما بتبرد" ما تنفهم كتعديل جهاز)
        $map = [
            'issue'    => '/(عطل|مشكله)/u',
            'location' => '/(عنوان|موقع|منطقه|شارع|(^|\s)(ال)?حي(\s|$))/u',
            'phone'    => '/(رقم|هاتف|تلفون|موبايل|جوال)/u',
            'name'     => '/(اسم)/u',
            'device'   => '/(جهاز)/u',
        ];
        foreach ($map as $f => $re) if (preg_match($re, $normText)) return $f;
        return null;
    }

    private function handleModifyRequest(string $phone, int $convId, int $custId, string $text, array $draft): bool
    {
        $n = $this->normalizeArabicText($text);

        // ── 1) الزبون كنا سألناه "شو القيمة الجديدة؟" ─────────────────────
        if (!empty($draft['edit_field']) && !empty($draft['edit_req_id'])) {
            $field = (string)$draft['edit_field'];
            $reqId = (int)$draft['edit_req_id'];
            unset($draft['edit_field'], $draft['edit_req_id']);
            $this->updateConvState($convId, self::STATE_IDLE, $draft);

            if (preg_match('/^(لا|لأ|الغاء|الغي|خلص|بلاش|ما بدي|بطلت)\b/u', $n)) {
                $this->reply($phone, $convId, "تمام، ما تغيّر شي 🙏");
                return true;
            }
            return $this->applyEdit($phone, $convId, $custId, $reqId, $field, trim($text));
        }

        // ── 2) طلب تعديل جديد ────────────────────────────────────────────
        if (!preg_match('/(عدل|تعديل|غير|تغيير|بدل|تبديل|صحح|تصحيح|غلط|خطا|مو صح|مش صح)/u', $n)) return false;
        // "غيّر" ممكن تكون مش عن الطلب — لازم نفس الرسالة تذكر حقل واضح
        $field = $this->detectEditField($n);
        if ($field === null) return false;

        $req = $this->findLastRequestByCustomerPhone($phone, $custId, true);
        if (!$req) return false; // ما في طلب مفتوح → خلي الفلو العادي يكمل

        // نحاول نستخرج القيمة الجديدة من نفس الرسالة
        $value = '';
        if ($field === 'phone') {
            $d = preg_replace('/\D/', '', $this->normalizeDigits($text));
            if (preg_match('/(07\d{8}|9627\d{8}|\d{9,12})/', $d, $m)) $value = $m[1];
        } else {
            if (preg_match('/(?:الى|إلى|ليصير|يصير|صار|الجديد(?:ه)?(?:\s*هو|\s*هي)?|هو|هي|:)\s*(.{2,120})$/u', $text, $m)) {
                $value = trim($m[1]);
            }
        }

        if ($value === '') {
            // ما عطانا القيمة → نسأله
            $ask = [
                'location' => "تمام، شو *العنوان الجديد* (المنطقة والحي)؟ 📍",
                'phone'    => "تمام، شو *رقم التواصل الجديد*؟ 📱",
                'name'     => "تمام، شو *الاسم الصحيح*؟ 👤",
                'device'   => "تمام، شو *الجهاز الصحيح*؟ 🖥️",
                'issue'    => "تمام، اكتب لي *وصف العطل الصحيح* 🛠️",
            ][$field];
            $draft['edit_field']  = $field;
            $draft['edit_req_id'] = (int)$req['id'];
            $draft[self::DRAFT_ACTIVE_BOT] = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_MOHAMMAD;
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            $this->reply($phone, $convId, $ask . "\n_(للتراجع اكتب: لا)_");
            return true;
        }

        return $this->applyEdit($phone, $convId, $custId, (int)$req['id'], $field, $value);
    }

    private function applyEdit(string $phone, int $convId, int $custId, int $reqId, string $field, string $value): bool
    {
        $cfg = ($this->editFields()[$field] ?? null);
        if (!$cfg) return false;

        // نجيب الطلب ونتأكد إنه للزبون هاد ولسا مفتوح (ما نعتمد على اللي انخزن بالـ draft)
        try {
            $st = $this->db->prepare("SELECT * FROM service_requests WHERE id=? AND customer_id=? LIMIT 1");
            $st->execute([$reqId, $custId]);
            $req = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $req = null; }
        if (!$req || in_array($req['status'], ['completed', 'cancelled'], true)) {
            $this->reply($phone, $convId, "ما لقيت طلب مفتوح أقدر أعدّله 🙏 إذا بدك تفتح طلب جديد اكتب لي.");
            return true;
        }

        // ── تحقق من القيمة ──────────────────────────────────────────────
        if ($field === 'phone') {
            $digits = preg_replace('/\D/', '', $this->normalizeDigits($value));
            if (!preg_match('/^\d{9,15}$/', $digits)) {
                $this->reply($phone, $convId, "⚠️ الرقم مش واضح، اكتب رقم هاتف صحيح 🙏");
                return true;
            }
            $value = $digits;
        } elseif ($field === 'name') {
            if (!$this->looksLikeName($value)) {
                $this->reply($phone, $convId, "⚠️ اكتب الاسم بس (مثلاً: أحمد) 🙏");
                return true;
            }
        } elseif (mb_strlen($value) < 2 || mb_strlen($value) > 200) {
            $this->reply($phone, $convId, "⚠️ ما فهمت القيمة الجديدة، اكتبها بشكل أوضح 🙏");
            return true;
        }

        $extraOwnerNote = '';
        if ($field === 'location') {
            $areaType = $this->detectServiceArea($value);
            if (!$this->lastLocationIsJordan) {
                $this->reply($phone, $convId, "⚠️ خدمة الصيانة داخل *الأردن* فقط 🇯🇴 اكتب عنوان داخل الأردن.");
                return true;
            }
            if ($areaType === 'parts') {
                $this->reply($phone, $convId,
                    "عذراً، الصيانة بالبيت متوفرة بعمان والسلط والزرقاء فقط. ما قدرت أعدّل العنوان لهاي المنطقة 🙏\n📞 *" . self::SUPPORT_PHONE . "*"
                );
                return true;
            }
            $newFee = ($areaType === 'zarqa') ? 15 : 10;
            $extraOwnerNote = "\n💰 رسوم الزيارة للعنوان الجديد: {$newFee} دينار";
        }

        $order = $req['order_number'] ?? '---';
        $old   = (string)($req[$cfg['col']] ?? '');
        $lbl   = $cfg['label'];

        // ── pending → تعديل مباشر ───────────────────────────────────────
        if ($req['status'] === 'pending') {
            try {
                $this->db->prepare("UPDATE service_requests SET {$cfg['col']}=? WHERE id=? AND status='pending'")
                    ->execute([$value, $reqId]);
            } catch (Throwable $e) {
                error_log('[applyEdit] ' . $e->getMessage());
                $this->reply($phone, $convId, "صار خطأ وأنا بعدّل الطلب 🙏 بلّغت الفريق وبيتواصلوا معك.");
                $this->notifyAdmins("⚠️ فشل تعديل تلقائي للطلب {$order}\n📱 {$phone}\n{$lbl} المطلوب: {$value}");
                return true;
            }
            $this->reply($phone, $convId, "تمام، تم تعديل *{$lbl}* بطلبك رقم *{$order}* ✅\n\nالجديد: {$value}");
            $this->notifyAdmins("✏️ *تعديل على الطلب {$order}* (بانتظار تعيين فني)\n📱 {$phone}\n🔧 {$lbl}\n❌ قبل: " . ($old ?: '—') . "\n✅ بعد: {$value}{$extraOwnerNote}");
            return true;
        }

        // ── assigned / in_progress → ما نعدّل، نبلّغ رمزي ───────────────
        $this->reply($phone, $convId,
            "طلبك رقم *{$order}* صار عند الفني، فبلّغت الفريق بالتعديل ({$lbl}: {$value}) وبيأكدوه معك بأسرع وقت 🙏\n📞 *" . self::SUPPORT_PHONE . "*"
        );
        $this->notifyAdmins("✏️🚨 *طلب تعديل — الفني معيّن/بالطريق*\n🔢 {$order}\n📱 {$phone}\n🔧 {$lbl}\n❌ الحالي: " . ($old ?: '—') . "\n✅ المطلوب: {$value}{$extraOwnerNote}\nيرجى التنسيق مع الفني وتأكيد الزبون.");
        return true;
    }


    /**
     * يبني كتلة سياق عن آخر طلب للزبون (الحالة الفعلية) + تعليمات كيف يجاوب حسب الحالة.
     * بيرجّع نص فاضي لو ما في طلبات.
     */
    private function buildLatestOrderContext(string $phone, int $custId): string
    {
        $req = $this->findLastRequestByCustomerPhone($phone, $custId);
        if (!$req) return "\n\n📦 هاد الزبون ما عنده أي طلب صيانة مسجّل عنا.";

        $status = (string)($req['status'] ?? '');
        $label = match ($status) {
            'pending'     => 'قيد الانتظار (استلمنا الطلب وبانتظار تعيين فني)',
            'assigned'    => 'تم تعيين فني للطلب',
            'in_progress' => 'قيد التنفيذ (الطلب عند الفني)',
            'completed'   => 'مكتمل (تم الإنجاز)',
            'cancelled'   => 'ملغي',
            default       => 'قيد المعالجة',
        };
        $how = match ($status) {
            'pending'     => 'الطلب مستلم ولسا بانتظار تعيين فني. طمّنه إنه الفريق رح يتواصل معه، ممنوع تعطي موعد أو وقت وصول محدد. إذا اشتكى من التأخير أو قال ما حدا اتصل فيه → intent="inquiry" و suggest_state="waiting_owner_answer".',
            'assigned',
            'in_progress' => 'الطلب عند الفني/قيد التنفيذ. قله إنه الفريق بيتواصل معه، ممنوع تحدد وقت وصول أو تذكر اسم فني. إذا اشتكى من التأخير → intent="inquiry" و suggest_state="waiting_owner_answer".',
            'completed'   => 'الطلب انتهى. إذا عنده مشكلة بعد التصليح أو شكوى → intent="inquiry" و suggest_state="waiting_owner_answer". إذا بدو صيانة جديدة اعتبرها طلب جديد.',
            'cancelled'   => 'الطلب ملغي، ما في فني رح يجي. لو بدو صيانة اعرض عليه فتح طلب جديد.',
            default       => 'اجاوب بحذر بدون وعود.',
        };
        $when = '';
        try { $when = (new DateTime((string)$req['created_at']))->format('Y-m-d H:i'); } catch (Throwable $e) {}

        return "\n\n📦 آخر طلب صيانة للزبون (معلومات حقيقية من النظام — استخدمها لما يسأل عن طلبه، وما تخترع أي تفصيل غيرها):\n"
             . "- رقم الطلب: " . ($req['order_number'] ?? '---') . "\n"
             . "- الجهاز: " . (($req['product_name'] ?? '') ?: 'غير محدد') . "\n"
             . "- العطل: " . (($req['issue_description'] ?? '') ?: 'غير محدد') . "\n"
             . "- العنوان: " . (($req['customer_location'] ?? '') ?: 'غير محدد') . "\n"
             . "- تاريخ التسجيل: {$when}\n"
             . "- الحالة الحالية: {$label}\n"
             . "طريقة الرد: {$how}";
    }

    // ═══════════════════════════════════════════════════════════════
    //  دوال الإشعارات الداخلية لرمزي
    // ═══════════════════════════════════════════════════════════════
    private function trackLatestOrderByPhone(string $phone, int $convId, ?int $custId = null, string $text = ''): void
    {
        $latestReq = $this->findLastRequestByCustomerPhone($phone, $custId);
        // 😠 شكوى "ما حدا اتواصل معي / متى بيرد" = مش بس تتبع → نبلّغ رمزي فوراً مع رقم الطلب
        if ($latestReq && preg_match('/(ما\s*حد|ما\s*رد|متى\s*(سي|ب)?رد|لسا\s*ما|تأخر|تاخر|ما\s*اتصل|ما\s*تواصل|ما\s*اجا|ما\s*وصل)/ui', $text)) {
            $this->notifyAdmins("⚠️ *زبون بيشتكي من التأخير/عدم التواصل*\n📱 {$phone}\n🔢 " . ($latestReq['order_number'] ?? '---') . "\n📝 {$text}");
        }
        if ($latestReq) {
            $statusMap = [
                'pending'     => 'جاري تعيين فني صيانة ⏳',
                'assigned'    => 'تم تعيين فني لطلبك 👨‍🔧',
                'in_progress' => 'قيد التنفيذ 🛠️',
                'completed'   => 'تم التصليح وإغلاق الطلب بنجاح ✅',
                'cancelled'   => 'ملغي ❌',
            ];
            $currentStatus = $statusMap[$latestReq['status']] ?? 'قيد المتابعة 🛠️';

            $msg = "🔍 تفاصيل آخر طلب لك عندنا:\n\n";
            $msg .= "🔢 رقم الطلب: " . ($latestReq['order_number'] ?? '---') . "\n";
            $msg .= "🖥️ الجهاز: " . ($latestReq['product_name'] ?: 'غير محدد') . "\n";
            $msg .= "📍 العنوان: " . ($latestReq['customer_location'] ?: 'غير محدد') . "\n";
            $msg .= "📊 حالة الطلب: " . $currentStatus . "\n\n";
            $msg .= match ($latestReq['status']) {
                'cancelled' => "هاد الطلب ملغي. إذا بدك نفتح لك طلب صيانة جديد اكتب لي 🙏",
                'completed' => "الحمد لله تم إنجاز الطلب ❤️",
                default     => "فريق مؤسسة فلسطين يتابع الموضوع باهتمام، لا تقلق! ❤️",
            };

            $this->reply($phone, $convId, $msg);

            // ⭐ [جديد] لو الطلب مكتمل فعلاً، اسأل الزبون عن رأيه بتجربته — بدون إلحاح،
            // ومرة وحدة بس (مانع التكرار: نتأكد إنه ما تبعتش له نفس السؤال قبل قليل بنفس المحادثة).
            if (($latestReq['status'] ?? '') === 'completed') {
                $this->reply($phone, $convId,
                    "بالمناسبة، إذا عندك دقيقة، شو رأيك بتجربتك مع الفني؟ رأيك بيهمنا كتير 🙏"
                );
            }
        } else {
            $this->reply($phone, $convId,
                "بحثت برقم هاتفك ولا يوجد لديّ أي طلب صيانة مفتوح حالياً، تريد أن نفتح لك طلباً جديداً؟"
            );
        }
    }

    private function notifyRamziCustomerIsTracking(string $phone): void
    {
        $msg = "⚠️ 🔔 [تنبيه صيانة]: الزبون صاحب الرقم ({$phone}) قام الآن بالاستفسار وتتبع حالة طلبه عبر البوت. يرجى المتابعة معه يا أستاذ رمزي 🙏";
        $this->sendToOwners($msg);
    }

    private function logDropoffIfNew(int $convId, int $custId, string $phone, array $draft): void
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM dropoff_log WHERE conversation_id=? AND recovered=0 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$convId]);
            if ($stmt->fetchColumn()) return; // موجود مسبقاً، ما نكرر

            $this->db->prepare(
                "INSERT INTO dropoff_log (conversation_id, customer_id, customer_phone, device_type, issue, stage)
                 VALUES (?, ?, ?, ?, ?, 'awaiting_confirmation')"
            )->execute([
                $convId, $custId, $phone,
                (string)($draft['device_type'] ?? ''),
                (string)($draft['issue'] ?? ''),
            ]);
        } catch (Throwable $e) { error_log("[logDropoffIfNew] " . $e->getMessage()); }
    }

    /** يعلّم أنه الزبون رجع وأكد الطلب بعد ما كان متردد (عشان تقرير رمزي يعرف الفرق بين اللي رجع واللي ما رجع) */
    private function markDropoffRecovered(int $convId): void
    {
        try {
            $this->db->prepare(
                "UPDATE dropoff_log SET recovered=1 WHERE conversation_id=? AND recovered=0"
            )->execute([$convId]);
        } catch (Throwable $e) { error_log("[markDropoffRecovered] " . $e->getMessage()); }
    }

    /**
     * يحسم رد الزبون على سؤال "هل تريد تثبيت الطلب؟" مباشرة من الكود
     * (بدون الاعتماد على تفسير الـ AI)، سواء دوس زر أو كتب كلمة موافقة/رفض يدوياً.
     * يرجّع true لو تعامل مع الرسالة (وبالتالي ما في داعي نكمل لـ AI)، أو false لو الرسالة مش واضحة.
     */
    private function resolvePendingConfirmation(string $phone, int $convId, int $custId, string $text, array $draft): bool
    {
        $trimmed = trim($text);

        $isDecline = $trimmed === 'btn_cancel_order'
            || str_contains($trimmed, 'شكراً') || str_contains($trimmed, 'شكرا')
            || (bool)preg_match('/^(لأ|لا|لأه|مش هلأ|بعدين)\b/ui', $trimmed);

        if ($isDecline) {
            // 🧹 الزبون رفض التثبيت → نمسح بيانات الطلب (جهاز/ماركة/عطل/موقع) عشان ما تتراكم على طلب جديد
            $this->updateConvState($convId, self::STATE_IDLE, [
                self::DRAFT_ACTIVE_BOT => self::BOT_MOHAMMAD,
            ]);
            $this->reply($phone, $convId, "تمام، لو غيرت رأيك أو حاب تسأل أي سؤال احنا موجودين 🙏");
            return true;
        }

        $isConfirm = $trimmed === 'btn_confirm_order'
            || (bool)preg_match('/(اه|نعم|نهم|تمام|^تم$|اوكي|أوكي|موافق|ثبت|توكل|ياريت|يا ريت|امشي)/ui', $trimmed);

        if (!$isConfirm) return false; // رسالة مش واضحة (سؤال جديد مثلاً) → خلي الـ AI يتعامل معها

        $draft['awaiting_confirm_reply'] = false;

        // 🛡️ شبكة أمان: بالمسار العادي اسم/رقم الزبون بيكونوا اتجمعوا قبل ما توصل هون
        // (شوف الكتلة الجديدة تحت "لازم اسم الزبون ورقمه..." بفرع الصيانة). بس لو لأي
        // سبب ناقصين (حالة قديمة محفوظة مثلاً)، ناخدهم هلق قبل التثبيت.
        if (empty($draft['customer_name'])) {
            $this->updateConvState($convId, self::STATE_MAINT_NAME, $draft);
            $this->reply($phone, $convId, "👌 ممتاز! بس قبل ما نثبت الطلب،\n\n👤 *شو اسمك الكريم؟*");
            return true;
        }
        if (empty($draft['customer_phone'])) {
            $this->updateConvState($convId, self::STATE_MAINT_PHONE, $draft);
            $this->reply($phone, $convId,
                "📱 *شو رقمك عشان يتواصل معك الفني؟*\n_(اكتب الرقم، أو اكتب \"نفس الرقم\" إذا هاد رقمك)_"
            );
            return true;
        }

        $this->finalizeMohammadOrder($phone, $convId, $custId, $draft);
        return true;
    }

    /**
     * يثبّت طلب الصيانة فعلياً بعد ما جمعنا اسم الزبون ورقمه (آخر خطوة بالمسار).
     * موحّدة بين مسار الأزرار ومسار الكتابة اليدوية للتأكيد، عشان ما يتكرر نفس المنطق مرتين.
     */
    private function finalizeMohammadOrder(string $phone, int $convId, int $custId, array $draft): void
    {
        $this->markDropoffRecovered($convId);

        // ── RAG: احفظ المحادثة الناجحة ────────────────────────
        if (!empty($draft['device_type']) && !empty($draft['issue'])) {
            $ragQ = "صيانة {$draft['device_type']} - {$draft['issue']}";
            $ragTags = implode(',', array_filter([
                $draft['device_type'] ?? null,
                $draft['device_brand'] ?? null,
                $draft['issue'] ?? null,
            ]));
            $this->rag->saveAuto($ragQ, 'تم تثبيت الطلب', $ragTags ?: null);
        }

        // ✅ منع تكرار الطلب لو انضغط الزر مرتين بسرعة أو تكرر الإرسال
        $hasRecentTicket = false;
        try {
            $checkStmt = $this->db->prepare(
                "SELECT id FROM service_requests
                 WHERE customer_phone=? AND device_type_id <=> ? AND issue_description <=> ?
                   AND status <> 'cancelled'
                   AND created_at >= NOW() - INTERVAL 5 MINUTE LIMIT 1"
            );
            $checkStmt->execute([
                $phone,
                !empty($draft['device_type_id']) ? (int)$draft['device_type_id'] : null,
                $draft['issue'] ?? null,
            ]);
            $hasRecentTicket = (bool)$checkStmt->fetchColumn();
        } catch (Throwable $e) {}

        if ($hasRecentTicket) {
            $this->reply($phone, $convId,
                "النظام يظهر أنه قد أكّد لك طلباً قبل قليل، وحتى لا يتكرر الطلب، فإن طلبك الأول الفني يعمل عليه الآن وسنستعجله لك 🙏"
            );
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            return;
        }

        $this->reply($phone, $convId, "تمام، تم تثبيت طلبك ✅");
        $this->finalizeMaintenanceRequest($phone, $convId, $custId, $draft);
        $supportMsg = "💡 للمتابعة أو الاستفسار لاحقاً، يمكنك التواصل مع الدعم الفني مباشرة على الرقم: " . self::SUPPORT_PHONE . " 📞";
        $this->reply($phone, $convId, $supportMsg);

        // 🛡️ [إصلاح مهم] finalizeMaintenanceRequest() فوق بيصفّر الحالة والـ draft بالكامل
        // (updateConvState(..., [])) عشان الطلب المفتوح ينتهي فعلياً. هون لازم *ما نكتب*
        // فوق هاد التصفير بالـ $draft القديم (اللي لسا فيه device_type/issue/location) —
        // قبل هيك كان هاد بالضبط سبب باغ "هل تريد تثبيت الطلب؟" يرجع يظهر على أي سؤال
        // عادي بعد إغلاق الطلب، لأنه الجهاز/العطل/الموقع كانوا يفضلوا محفوظين غلط بالـ
        // draft حتى بعد التثبيت. منحافظ فقط على هوية البوت النشط، وبنمسح كل بيانات
        // الطلب المنتهي (جهاز/ماركة/عطل/موقع/سعر الزيارة) عشان أي سؤال جديد يبلش نظيف.
        $this->updateConvState($convId, self::STATE_IDLE, [
            self::DRAFT_ACTIVE_BOT => self::BOT_MOHAMMAD,
        ]);
    }

    /**
     * القيمة الافتراضية لقواعد وشخصية محمد — نفس النص الأصلي اللي كان مثبّت بالكود
     * حرفياً. تُستخدم فقط إذا كان حقل "تعليمات" بالداشبورد فاضي (لسا الأدمن ما
     * حفظ شي). أول ما الأدمن يفتح قسم "تعليمات" وما يلاقي شي، هاد النص هو اللي
     * لازم يترزرع بالحقل تلقائياً (شوف bot_instructions.php) عشان يعدّل عليه
     * مباشرة بدل ما يبلش من صفر.
     */
    public static function defaultMohammadRules(string $priceText): string
    {
        $supportPhone = self::SUPPORT_PHONE;
        return <<<PROMPT
أنت "محمد"، مساعد ذكي بمؤسسة فلسطين لصيانة الأجهزة المنزلية وقطع الغيار بالأردن.
صاحب المحل اسمه "رمزي"، وهو معلّم بالصنعة وإنسان محترم.

شخصيتك:
- تحكي بأسلوب عربي بسيط وواضح، مزيج بين الفصحى والعامية العربية المفهومة لكل الناطقين بالعربية.
- ذكي وتفهم المشاكل التقنية للأجهزة المنزلية.
- تقدر تفاصل بالأسعار بأسلوب لطيف.
- تجاوب على أسئلة المحل البسيطة (أوقات، أسعار، موقع) مباشرة بدون أن تطلب مساعدة إضافية.

🚫 قواعد سلبية على الأسلوب (ممنوعات ثابتة — الباقي بأسلوبك الحر بالكامل):
- التعبيرات المحلية المسموحة فقط هي: "تمام"، "خاطرك"، "ولا يهمك" — وبس. ممنوع منعاً باتاً أي تعبير محلي أو عامي غيرهم، خصوصاً "يا غالي"، "أبشر"، "ع راسي"، "حياك الله"، "يا زلمة"، "شباب"، "اخوي"، "حبيبي"، أو أي تعبير مشابه.
- ممنوع الرموز التعبيرية الزايدة عن واحد بنفس الرسالة.
- ممنوع الوعود القاطعة بحل أكيد قبل ما الفني يعاين الجهاز فعلياً.
- ممنوع تكرار نفس الجملة أو الأسلوب حرفياً أكتر من مرة بنفس المحادثة (نوّع بالصياغة حتى لو نفس المعنى).

معلومات المحل والاتصال (Knowledge Base):
- اسم المحل: مؤسسة فلسطين لصيانة الأجهزة المنزلية وقطع الغيار (فريق مؤسسة فلسطين).
- 📍 الموقع الصحيح: عمان - الهاشمي الشمالي - نزول سوق المخفر. رابط اللوكيشن: https://maps.google.com/?q=31.9646027,35.959144
- ⏰ أوقات الدوام والعمل الرسمية: من الساعة 10:00 صباحاً وحتى الساعة 9:00 مساءً طيلة أيام الأسبوع. (ممنوع تماماً ذكر أي وقت آخر).
- 📞 رقم الدعم الفني: {$supportPhone} (أعطه فوراً لأي زبون يطلب رقم الدعم أو التواصل).
- 🛡️ الضمان: كل صيانة عنا مضمونة، وإذا سأل الزبون عن مدة الضمان أو شروطه ولا عندك رقم دقيق مؤكد بالسياق، لا تخترع رقم — قله بثقة إن في ضمان على الشغل وإن الفني رح يأكدلّه مدة الضمان بالضبط وقت المعاينة.
- 💳 طرق الدفع: كاش عند الفني وقت التصليح (ولو سأل عن تحويل/كليك، قله إنك رح تتأكد له من فريق الصيانة).

🗺️ مناطق الخدمة (مهم جداً):
- اجمع الموقع من الزبون كما هو (اسم منطقة، مدينة، أو موقع GPS) دون أن تفترض بنفسك تصنيف المنطقة أو سعرها.
- النظام يحدد تلقائياً بعد ذلك — عبر الذكاء الاصطناعي — نوع المنطقة: صيانة منزلية برسوم 10 دينار، أو صيانة منزلية برسوم 15 دينار، أو بيع قطع غيار فقط (إذا كانت المنطقة بعيدة عن نطاق الصيانة المنزلية)، وكذلك التحقق من كون الموقع داخل الأردن أصلاً.
- لا تخمّن تصنيف المنطقة أو ترفض موقعاً بنفسك؛ فقط اذكر للزبون أن هناك رسوم زيارة تختلف حسب منطقته، وسيتم تأكيد السعر الدقيق تلقائياً بعد إرسال الموقع.

مهام محددة وقواعد صارمة:

🚨🚨 [قاعدة التأكيد الصارمة]:
ممنوع منعاً باتاً تحويل نية الزبون إلى intent="maintenance" أو حفظ الطلب بالنظام إلا إذا كتب الزبون كلمة موافقة صريحة وحرفية بعد سؤالك له "هل تريد تثبيت الطلب؟".
الكلمات المقبولة للتثبيت هي فقط: (نعم، نهم، أه، تمام، تم، موافق، اوكي، ثبت، توكل على الله). إذا لم يكتب أحد هذه الكلمات في رسالته الأخيرة، اجعل دائماً intent="general" و suggest_state="idle".

🚨🚨 [القاعدة الذهبية الصارمة للتحويل ومنع الإزعاج]:
ممنوع منعاً باتاً إرجاع suggest_state="waiting_owner_answer" إذا كنت قد قمت بالإجابة على سؤال الزبون بنجاح وصحة (حتى لو كان الجواب هو الاعتذار عن صيانة جهاز خارج التخصص مثل الـ PC). اعتبر نفسك مسيطراً، واجعل دائماً suggest_state="idle" و intent="general".
تستخدم suggest_state="waiting_owner_answer" في حالتين فقط لا غير:
1. إذا أرسل الزبون صورة (سواء لقطعة متوفرة أو غير متوفرة أو جهاز خربان)، رد فوراً: "تمام، سأحوّل الصورة إلى فريق الصيانة ليتحققوا منها ويردوا عليك فوراً!"، واجعل intent="general".
2. إذا طلب الزبون صراحة وبكلمات واضحة التحدث مع "رمزي" شخصياً أو تقديم شكوى عن شغل سابق لا تريد الإجابة عليها.

1. لو ذكر الزبون اسم "رمزي": رد بحرارة "تحت أمرك!"
2. 🚨 (تتبع الطلبات والمراجعات): إذا قال الزبون (وين صار طلبي، لحد الآن ما إجاني حد، تأخرتوا علي، بدي أشوف طلبي)، أخبره فوراً: "لا تقلق، سأتابع طلبك الآن من رقم هاتفك، لحظات فقط لأرى أين أصبح الفني". ويجب حتماً إرجاع intent="track_order" و suggest_state="idle". ممنوع فتح طلب جديد هنا.
3. 🛑 (تعدد الطلبات ومنع التكرار): إذا طلب صيانة لجهاز جديد في نفس المحادثة بعد تأكيد طلب سابق، ذكّره بالطلب الأول صراحة (نص حرفي بدون ذكر أي اسم جهاز محدد: "لقد أكدنا لك طلباً قبل قليل لجهاز، تريد أن نفتح لك طلباً جديداً ومنفصلاً لجهاز آخر أيضاً؟")، واجعل intent="general" و suggest_state="idle" حتى يوافق صراحة بكلمات التأكيد.
3.5. 🛑🚨 (طلبان بنفس الرسالة أو بنفس المحادثة قبل التثبيت): إذا ذكر الزبون جهازين أو أكثر (سواء بنفس الرسالة أو بأكثر من رسالة) قبل ما يتم تثبيت أي طلب بعد، **ممنوع تتجاهل الجهاز الثاني وممنوع توقف الرد بانتظار رسالة جديدة من الزبون**. عالج الجهاز الأول لحد ما يوصل لخطوة التثبيت (قاعدة 5)، وبعد ما يوافق الزبون ويتثبت الطلب الأول تلقائياً من النظام، انتقل مباشرة وبنفس التسلسل لجمع معلومات الجهاز الثاني (نوع + ماركة + عطل + موقع) بدون ما تنتظر الزبون يبادر أو يكتب من جديد — كمل الحوار بشكل طبيعي متل لو أنه هو يلي طلب منك تكمل. لا تكتفي بكلمة "تمام" وتسكت.
4. لو الزبون يريد صيانة جديدة: اجمع منه (نوع الجهاز + ماركته مثل LG أو Samsung أو غيرها + وصف المشكلة + الموقع) بأسلوب محادثة ودود وبسيط. اسأل عن الماركة دايماً مرة وحدة لكل الأجهزة (LG أو Samsung أو غيرها)، وإذا قال الزبون ما بعرف أو ما ذكرها كمّل بدونها. 🚨 نوع الجهاز إلزامي: إذا الزبون ما ذكره صراحة (مثلاً قال "عندي مشكلة" أو "بدي فني" أو "ما بيشتغل" بدون ما يحدد الجهاز) اسأله أول شي "شو نوع الجهاز؟" — ممنوع تنتقل للموقع أو الرسوم أو التثبيت قبل ما يكون نوع الجهاز معروف. 🚨 الموقع إلزامي ولازم يكون *منطقة أو حي محدد* مش اسم المحافظة بس: إذا قال \"عمان\" أو \"الزرقاء\" فقط اسأله \"بأي منطقة أو حي؟\" قبل ما تنتقل لجملة الرسوم. ولازم تسأل عنه صراحة إذا ما ذكره الزبون بنفسه — ممنوع تنتقل لجملة رسوم الزيارة (قاعدة 5) أو أي خطوة بعدها قبل ما يكون عندك الموقع فعلياً مكتوب من الزبون.

4.5. 🚫🚫 [حماية حرجة — ممنوع تدّعي تثبيت طلب بنفسك]: أنت **ممنوع منعاً باتاً** تكتب بردك أي جملة توحي بإن الطلب تثبت أو تسجل أو صار له رقم طلب (زي "تم تثبيت الطلب"، "تسجيل طلبك"، "رقم الطلب"، "الفني رح يوصلك"، أو أي معنى مشابه) — هاي جمل يولّدها النظام تلقائياً وبس بعد تثبيت حقيقي بالداتابيز، مش من عندك أبداً. أقصى شي تقدر تقوله لما الزبون يوافق هو تكرار أو تأكيد قصير ("تمام 🙏") وخلي النظام يكمل الباقي. لو حسيت الزبون وافق ولسا ناقص معلومة (جهاز/عطل/موقع)، اسأل عن الناقص بس ولا تدّعي أي تثبيت.
5. ⚠️ (شرط تجاري - رسوم الزيارة): عندما تتوفر معلومات الطلب الجديد (جهاز + عطل + موقع)، ابدأ أولاً بجملة تلخيص قصيرة تؤكد فيها للزبون شو فهمت منه (مثلاً: "تمام، يعني عندك {الجهاز}{و/الماركة إذا موجودة} والمشكلة {العطل} بمنطقة {الموقع}، وهاي المشكلة محتاجة فني صيانة مختص من عنا.")، ثم بعدها مباشرة يجب إخبار الزبون صراحة بوجود رسوم فحص وزيارة تختلف حسب منطقته، وأن أجر الكشف يُحسب إذا لم يرغب بالتصليح، وإذا تم التصليح يدفع حسب الاتفاق مع الفني. 🚨 اكتب جملة الرسوم هذه حرفياً بدون أي تعديل إملائي أو دمج مع جمل أخرى بنفس الجملة، واجعلها جملة منفصلة قائمة بذاتها بعد جملة التلخيص: "للعلم، هناك رسوم فحص وزيارة تختلف حسب منطقتك، وأجر الكشف يُحسب إذا لم ترغب بالتصليح، أما إذا تم التصليح فتدفع حسب الاتفاق مع الفني. هل تريد تثبيت الطلب؟". الاستثناء الوحيد المسموح: إذا كان سعر منطقة الزبون محسوباً فعلياً ومذكوراً بالسياق أسفل ("رسوم الزيارة المحسوبة فعلياً لمنطقة هذا الزبون")، يمكنك إضافة السعر بين قوسين مباشرة بعد كلمة "منطقتك" وبس (مثال: "منطقتك (10 دينار)")، بدون أي تعديل إضافي على باقي الجملة. في هذه الخطوة اجعل حتماً intent="general" و suggest_state="idle".
6. 🖥️ (المنتجات خارج التخصص): إذا سأل عن PC، كمبيوتر، شاشات، لابتوبات، اعتذر منه بلطف وأخبره أننا نصلح فقط الأجهزة المنزلية الكبيرة (غسالات، ثلاجات، مكيفات، أفران، جلايات، ميكرويف، مجففات ملابس 🌀)، واجعل حتماً intent="general" و suggest_state="idle".
6.5. 🔧 (قطع الغيار — مهم جداً): إذا سأل الزبون إذا كنتم تبيعون قطع أو قطعة أو قطع غيار، لا تسأله أي سؤال ولا تطلب منه اسم الجهاز — رد فوراً ومباشرة: "نعم، نبيع قطع الغيار لكل محافظات المملكة 📦 للطلب اتصل على +96278001678 (مكالمة فقط، لا رسائل واتساب)". واجعل intent="general" و suggest_state="idle".
7. ⚠️ (تنبيه الموقع والهلوسة): لا تقل للزبون "خدماتنا داخل الأردن فقط" إذا أعطاك اسم مدينة أو منطقة أو اسم جهاز فقط؛ تعامل معه بشكل طبيعي واترك النظام يتحقق من الموقع تلقائياً بعد ذلك.
8. 🛑 (الشكاوى والملاحظات والأسئلة):
   - إذا كانت رسالة الزبون مجرد تحية أو سؤال حال عادي (زي "كيفك"، "مرحبا"، "السلام عليكم"، "أهلين") بدون أي ذكر لملاحظة أو مشكلة أو استفسار فعلي: رد عليه بتحية طبيعية ودودة وقصيرة واسأله كيف تقدر تساعده اليوم. **ممنوع تماماً** الرد بـ"تفضل، شو ملاحظتك؟" على مجرد تحية — هاي الجملة تُستخدم فقط لما الزبون يذكر صراحة كلمة "ملاحظة" أو "سؤال" أو "استفسار" بدون تفاصيل (شوف البند التالي). اجعل intent="general" و suggest_state="idle".
   - إذا قال الزبون فقط "عندي ملاحظه" أو "عندي سؤال" أو "بدي استفسر" بدون ما يكتب ايش الملاحظة أو السؤال: اسأله "تفضل، شو ملاحظتك؟ 😊" واجعل intent="general" و suggest_state="idle". لا تحوّل لرمزي قبل ما تعرف ايش يريد.
   - إذا كتب الزبون ملاحظته أو سؤاله الفعلي: **حاول تجاوب عليه أولاً** بأسلوبك اللطيف والذكي.
   - إذا قدرت تجاوب (أوقات، أسعار، موقع، توضيح، اعتذار لطيف...): رد مباشرة واجعل intent="general" و suggest_state="idle".
   - إذا ما قدرت تجاوب (شكوى على شغل سابق، ملاحظة تحتاج متابعة شخصية من رمزي، سؤال خارج صلاحياتك): قل للزبون "رح أحوّل ملاحظتك لفريق الصيانة مباشرة" واجعل intent="inquiry" و suggest_state="waiting_owner_answer".
9. ⚠️ (للأسئلة العامة): الأسئلة عن (الأسعار، أوقات العمل، موقع المحل): جاوبها مباشرة باختصار ودقة من الـ Knowledge Base المذكورة أعلاه (أوقاتنا من 10 الصبح لـ 9 المساء)، واجعل الـ suggest_state هي idle وحدد intent="general".

10. 💬 (إذا قال الزبون "بفكر" أو "برجعلك" أو "بعدين" أو ما شابه): لا تسكت وتخلص الرد بجملة عادية. اطرح عليه سؤال قصير ولطيف يخليه يقرر الآن أو يوضح تردده، مثل: "تمام خذ وقتك 🙏، بس حابب أساعدك تقرر بسرعة — هل المشكلة معطلتك كتير هلأ؟" أو "ولا يهمك، بس خلي ببالك إنه أجر الكشف ثابت والفني بيوصلك بنفس اليوم إذا أكدت هلأ". الهدف إنك ترجعه للمحادثة بدل ما يسكت ويروح. اجعل intent="general" و suggest_state="idle".

11. 🔎 (فحص سريع قبل فتح الطلب): إذا كانت المشكلة من النوع اللي ممكن تكون بسيطة وغير حقيقية (مثلاً الجهاز "مش شغال خالص" أو "مش عم يوصل كهرباء")، اسأل سؤال تحقق سريع واحد بس قبل ما تفتح طلب صيانة، مثل: "قبل ما نكمل، تأكدت إنه مقبس الكهرباء شغال والسلك موصول منيح؟". 🚨 هذا السؤال دايماً intent="general" و suggest_state="idle" — ممنوع منعاً باتاً تحويله لـ waiting_owner_answer أو inquiry، لأنه سؤال توضيحي بسيط من عندك وما يحتاج رمزي أبداً. إذا أكد الزبون إنه تأكد أو إنه المشكلة مش من هيك، كمل إجراءات فتح الطلب عادي (اجمع الموقع إذا ناقص، ثم انتقل لخطوة رسوم الزيارة). لا تسأل هذا السؤال لأعطال واضحة تقنياً (زي صوت غريب، تسريب مي، ما بيبرد).

12. 🧾 (بدون تشخيص — وظيفتك تاخد الطلب وتحوّله للفني، مش تحل المشكلة): ممنوع تحاول تشخّص سبب العطل أو تقترح حل تقني أو سبب محتمل (زي "غالباً الفيوز محروق" أو "ممكن يكون الموتور"). لما الزبون يوصف عرَض واضح ومنطقي (مثلاً: "الثلاجة ما بتبرد"، "الفرن ما بيسخن")، رد بأسلوبك اعتراف بسيط بالمشكلة بدون أي تخمين تقني، وقله إنه بيحتاج طلب صيانة وفني يعاينه، واسأله إذا بيحب تفتحله الطلب. مثال أسلوب (نوّع الصياغة ولا تكررها حرفياً): "تمام، فهمت المشكلة، هاي بتحتاج فني يعاينها. بتحب نفتحلك طلب صيانة؟". اجعل intent="general" و suggest_state="idle" لحد ما يوافق صراحة (حسب قاعدة التأكيد الصارمة)، وبعدها كمل إجراءات فتح الطلب الاعتيادية (اجمع الموقع إذا ناقص، ثم رسوم الزيارة).

12.5. 🚫 (فحص منطقية المشكلة مع نوع الجهاز): قبل ما تقبل وصف العطل، تأكد إنه منطقي لنوع الجهاز يلي ذكره الزبون (مثلاً: "ثلاجة ما بتطبخ" أو "فرن ما ببرد" — هاي أوصاف مقلوبة/مش منطقية، لأنه الثلاجة وظيفتها تبريد مش طبخ، والفرن وظيفته تسخين مش تبريد). إذا كان وصف المشكلة مش منطقي إطلاقاً لوظيفة الجهاز المذكور، **ممنوع تكمل أو تفتح طلب**، رد بأدب ووضوح، مثل: "حضرتك المشكلة يلي وصفتها مش منطقية لهاد الجهاز 😅، ممكن تكتبلي المشكلة الصحيحة؟" (نوّع الصياغة حسب الجهاز والعطل المذكور). اجعل intent="general" و suggest_state="idle" وانتظر توضيح الزبون قبل أي متابعة. لا تطبّق هذه القاعدة على أوصاف عامة غامضة زي "خربانة" أو "مش شغالة" (هذي تخضع لقاعدة 11 لا لهاي القاعدة).

13. 📅 (طلبات الجدولة والتأجيل — احترافي دايماً): إذا سأل الزبون عن وقت محدد لزيارة الفني (مثلاً "الفني بقدر يجي الساعة 5 العصر؟")، أو طلب تأجيل/تقديم موعد طلب موجود (مثلاً "ممكن تأجلولي الطلب لبكرة؟")، هذا قرار جدولة فعلي لازم رمزي أو الفني يأكده مباشرة — ما تحاول تجاوب أو تخمّن بنفسك. رد بأسلوب مهذب واثق يطمّن الزبون، مثل: "تمام، هذا الطلب أنسقه مباشرة مع فريق الصيانة/الفني وأرجعلك بالتأكيد أول ما يردوا 🙏"، واجعل حتماً intent="inquiry" و suggest_state="waiting_owner_answer".

14. 💰 (سؤال مباشر عن قيمة الرسوم — ممنوع التكرار الحرفي): إذا سأل الزبون صراحة "كم الرسوم؟" أو "قديش السعر؟" أو "شو التكلفة؟" (سؤال مباشر عن الرقم، مش أول مرة تشرح فيها الشرط التجاري)، **ممنوع تكرر جملة "للعلم، هناك رسوم فحص وزيارة..." حرفياً كجواب**. بدلها جاوبه مباشرة بجملة طبيعية قصيرة فيها الرقم:
   - إذا كان عندك رسوم الزيارة المحسوبة لمنطقته (مذكورة أسفل بالسياق إذا متوفرة): اذكر الرقم المحدد صراحة، مثل: "رسوم الكشف والزيارة لمنطقتك {رقم} دينار، وإذا وافقت على التصليح فتدفع حسب اتفاقك مع الفني."
   - إذا ما عندك رقم محدد بعد (الموقع لسا ما انجمع): جاوب بالنطاق العام (بين {$priceText} حسب المنطقة).
   🚦 هون بالذات لازم تنتبه لحالة الطلب المذكورة بالسياق أسفل: لو 🟢 (في طلب فعلي قيد التأكيد الآن)، اختم ردك حرفياً بعبارة "هل تريد تثبيت الطلب؟" (يعتمد عليها الكود لتفعيل أزرار التأكيد). أما لو 🔴 (ما في طلب مفتوح — الزبون بس بيسأل عن السعر بشكل عام أو بعد ما طلب سابق خلص)، **ممنوع** تضيف "هل تريد تثبيت الطلب؟" ولا أي دعوة لفتح طلب — جاوب السؤال وبس، واترك الزبون يقرر لو حاب يفتح طلب هو بنفسه.
   اجعل intent="general" و suggest_state="idle" (هذا سؤال توضيحي بسيط، ما يحتاج رمزي).

15. 🧩 (سؤال عن سعر قطعة سبق وسأل عنها الزبون بصورة): إذا كان مذكور بالسياق أسفل "آخر قطعة سأل عنها الزبون بالصورة" وسأل الزبون بعدها عن سعرها ("كم سعرها؟"، "قديش"، "بكم")، ما تخمّن السعر أبداً — قل له بأدب: "لحظة، رح أسأل فريق الصيانة عن سعرها وأرجعلك 🙏" واجعل حتماً intent="inquiry" و suggest_state="waiting_owner_answer".

16. 🛒 (نية شراء قطعة): إذا قال الزبون أي صيغة دالة على نية شراء قطعة سبق وسأل عنها ("بدي ياها"، "بدي أطلبها"، "بدي أشتريها"، "بدي احجزها")، لا تصعّد لرمزي — جاوبه مباشرة: "ممتاز! 🎉 للطلب أو الشراء تواصل معنا مباشرة على رقم الدعم: {$supportPhone}" واجعل intent="general" و suggest_state="idle".

17. 💸 (اعتراض السعر): إذا قال الزبون كلمة أو جملة قصيرة بمعنى "غالي" أو "عالي" أو قارن السعر بمكان تاني أرخص (بدون أي كلام غضب أو حدة)، هاد **تفاوض عادي على السعر، مش شكوى ولا انزعاج**. 🚫🚫 ممنوع منعاً باتاً أي شكل من أشكال الاعتذار أو التهدئة بهذا الرد — تحديداً ممنوع تكتب أو تقارب معنى: "آسف على الإزعاج"، "فاهمك تماماً وآسف"، "معذرة"، أو أي اعتذار مشابه. رد مباشرة وبثقة بدون مقدمة اعتذارية: ابدأ الجملة مباشرة بتبرير القيمة، بمعنى قريب من: "الفني عنا خبير، وهاي رسوم رمزية بس إذا ما تم التصليح، أما إذا تم التصليح فبتصير حسب الاتفاق مع الفني" (نوّع الصياغة بس خلي هالمعنى ثابت). لا تكرر جملة الرسوم الكاملة حرفياً ولا تسكت. 🚦 اتبع نفس منطق حالة الطلب من قاعدة 14: لو 🟢 (في طلب فعلي قيد التأكيد الآن)، اختم بعبارة "هل تريد تثبيت الطلب؟" حرفياً. لو 🔴 (ما في طلب مفتوح)، رد على تعليقه بس بدون أي دعوة لتثبيت طلب. اجعل intent="general" و suggest_state="idle".

17.5. 🚫🚫🚫 [قاعدة صارمة ضد الهلوسة — التزم فيها حرفياً بكل رد]:
   أ) **ممنوع اختراع أي معلومة** (سعر، مدة ضمان، وقت دوام، اسم موظف، تفصيل تقني) غير مذكورة صراحة بقسم "معلومات المحل" فوق أو بالسياق المرفق أسفل هالبرومبت (رسوم الزيارة المحسوبة، معلومات الطلب المجمّعة، آخر قطعة). لو الزبون سأل عن شي ما عندك رقم أو معلومة مؤكدة له، **لا تخمّن ولا تقرّب رقم** — قله بثقة إنك رح تتأكد له من فريق الصيانة، واجعل intent="inquiry" و suggest_state="waiting_owner_answer".
   ب) **ممنوع تنسى معلومات جمعتها فعلاً** بنفس المحادثة (جهاز/ماركة/عطل/موقع مذكورين بقسم "معلومات الطلب اللي أصلاً جمعتها" بالسياق أسفل) وترجع تسألها من جديد — هاي معلومات مؤكدة وثابتة إلا إذا الزبون غيّرها بنفسه صراحة.
   ج) **ممنوع تدّعي تثبيت/تسجيل/إلغاء طلب فعلي بنفسك** (شوف قاعدة 4.5) — هاي فقط رسائل نظام آلية بعد تنفيذ حقيقي بالداتابيز.

18. 🔁 (upsell لطيف وغير مزعج): بعد ما يوافق الزبون صراحة على فتح الطلب (بعد كلمة التأكيد)، ممكن تضيف بجملة الرد الأخيرة سؤال قصير وودود واحد بس، مثل: "وإذا في جهاز ثاني عندك بحاجة صيانة، قلي وأفتحلك طلب له كمان 🙏". ممنوع تكرار هذا السؤال أكتر من مرة بنفس المحادثة، وممنوع تسأله قبل ما يوافق على الطلب الأساسي.

19. ⏱️ (تحفيز الزبون المتردد بلطف): إذا حسّيت إن الزبون متردد أو بيسوّف (زي "بشوف"، "بفكر فيها")، بعد إجابتك اذكر بجملة طبيعية وغير ملحّة إن الفني بيقدر يوصله بنفس اليوم لو أكد هلق، بدون ضغط أو إلحاح مزعج.

20. 🤝 (زبون قديم/راجع): إذا ذكر الزبون بشكل صريح إنه تعامل معكم قبل هيك أو زبون قديم، رحّب فيه بحرارة أكبر شوي ("أهلين فيك رجعتلنا 🙏") قبل ما تكمل معه إجراءات الطلب العادية.

21. 😠 (تهدئة زبون منزعج قبل أي شي): إذا كانت رسالة الزبون فيها فعلاً غضب أو شكوى حادة واضحة (مثل: تأخر فني، صراخ بالكتابة، كلام حاد أو استياء من خدمة سابقة، تكرار شكوى) — **وليس** مجرد تعليق قصير على السعر زي "غالي" أو "عالي" (هاد خاضع لقاعدة 17 فقط، بدون اعتذار)، ابدأ ردك بجملة تهدئة واعتراف بمشاعره ومبدئياً اعتذار بسيط ("فاهمك تماماً وآسف على الإزعاج 🙏")، قبل ما تقرر تجاوب بنفسك أو تصعّد لرمزي حسب باقي القواعد.
PROMPT;
    }

    /**
     * 🛡️ فحص نصّي بسيط يكشف لو رد الموديل فيه ادعاء تثبيت/تسجيل طلب — يُستخدم فقط
     * كشبكة أمان أخيرة بالفرع العام (general) اللي ما بيمر بمنطق التثبيت الحقيقي.
     * لو رجّع true، لازم نمنع الرد الأصلي من الوصول للزبون (شوف الاستخدام أعلاه).
     */
    private function looksLikeFakeConfirmation(string $reply): bool
    {
        $norm = $this->normalizeArabicText($reply);
        $patterns = [
            'تم تثبيت',
            'تم تسجيل',
            'تسجيل طلبك',
            'رقم الطلب',
            'تم فتح طلب',
            'تم حجز',
            'الفني رح يوصلك',
            'الفني بيوصلك',
            'رح نرسل لك فني',
            'رح يتواصل معك',
        ];
        foreach ($patterns as $p) {
            if (str_contains($norm, $this->normalizeArabicText($p))) return true;
        }
        return false;
    }

    /** هل الموقع مجرد اسم محافظة/مدينة كبيرة بدون حي أو منطقة؟ */
    private function isGovernorateOnly(string $loc): bool
    {
        $n = $this->normalizeArabicText($loc);
        $n = preg_replace('/[^\p{L}\s]/u', ' ', $n) ?? $n;
        $skip  = ['محافظه','مدينه','منطقه','في','من','ب','انا','بدي','عنا','عندي','ال'];
        $words = array_values(array_filter(
            preg_split('/\s+/u', trim($n)) ?: [],
            fn($w) => $w !== '' && !in_array($w, $skip, true)
        ));
        $n = implode(' ', $words);
        if (mb_strpos($n, 'ال') === 0) $n = mb_substr($n, 2);
        $govs = ['عمان','زرقاء','سلط','بلقاء','اربد','كرك','معان','عقبه','مفرق','جرش','عجلون','مادبا','طفيله',
                 'amman','zarqa','salt','irbid','aqaba'];
        if (in_array($n, $govs, true)) return true;
        // "بعمان" / "بالزرقاء" (حرف ب ملتصق)
        if (mb_strpos($n, 'ب') === 0) {
            $m = mb_substr($n, 1);
            if (mb_strpos($m, 'ال') === 0) $m = mb_substr($m, 2);
            return in_array($m, $govs, true);
        }
        return false;
    }

    private function resolveDeviceTypeId(string $deviceText): ?array
    {
        if (trim($deviceText) === '') return null;
        $devices = $this->getActiveDeviceTypes();
        $norm    = $this->normalizeArabicText($deviceText);

        foreach ($devices as $d) {
            if ($this->normalizeArabicText((string)$d['name_ar']) === $norm) return $d;
        }

        foreach ($devices as $d) {
            $dNorm = $this->normalizeArabicText((string)$d['name_ar']);
            if (str_contains($norm, $dNorm) || str_contains($dNorm, $norm)) return $d;
        }

        $aliases = [
            'براد'      => 'ثلاجة',
            'فريدج'     => 'ثلاجة',
            'غساله'     => 'غسالة',
            'مكيف هواء' => 'مكيف',
            'اي سي'     => 'مكيف',
            'فرن غاز'   => 'فرن',
            'نشافة'     => 'مجفف',
            'نشافه'     => 'مجفف',
            'جفاف'      => 'مجفف',
            'مجفف ملابس'=> 'مجفف',
            'مايكرويف'  => 'ميكرويف',
            'ميكروويف'  => 'ميكرويف',
            'مايكرووايف'=> 'ميكرويف',
        ];
        foreach ($aliases as $alias => $canonical) {
            if (str_contains($norm, $this->normalizeArabicText($alias))) {
                $cNorm = $this->normalizeArabicText($canonical);
                foreach ($devices as $d) {
                    if ($this->normalizeArabicText((string)$d['name_ar']) === $cNorm) return $d;
                }
            }
        }

        return null;
    }

    private function resolveIssueId(int $deviceTypeId, string $issueText): ?array
    {
        if (trim($issueText) === '' || $deviceTypeId === 0) return null;
        $issues = $this->getIssuesByDeviceType($deviceTypeId);
        $norm   = $this->normalizeArabicText($issueText);

        foreach ($issues as $issue) {
            if ($this->normalizeArabicText((string)$issue['issue_text_ar']) === $norm) return $issue;
        }

        $issuesFull = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT id, issue_text_ar, issue_keywords FROM common_issues WHERE device_type_id=? ORDER BY sort_order ASC"
            );
            $stmt->execute([$deviceTypeId]);
            $issuesFull = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        foreach ($issuesFull as $issue) {
            $kw = (string)($issue['issue_keywords'] ?? '');
            if ($kw === '') continue;
            foreach (array_map('trim', explode(',', $kw)) as $keyword) {
                if (str_contains($norm, $this->normalizeArabicText($keyword))) return $issue;
            }
        }

        foreach ($issues as $issue) {
            $iNorm = $this->normalizeArabicText((string)$issue['issue_text_ar']);
            if (str_contains($norm, $iNorm) || str_contains($iNorm, $norm)) return $issue;
        }

        return null;
    }

}
