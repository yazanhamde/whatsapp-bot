<?php

/**
 * AbdulrahmanBotTrait.php — منطق بوت عبدالرحمن (المحادثة الحرة بالذكاء الاصطناعي)
 *
 * كل الدوال هون خاصة بشخصية عبدالرحمن فقط: الـ system prompt الكامل،
 * استخراج بيانات الطلب من المحادثة الحرة، تأكيد الطلب، تتبعه،
 * ومتابعة العملاء اللي تركوا المحادثة بدون تأكيد (dropoff).
 *
 * هاد trait مو كلاس مستقل — بينضم لكلاس Agent (see includes/Agent.php)
 * لنفس سبب KhaledBotTrait: وصول مباشر للخدمات المشتركة بدون تعقيد.
 */
trait AbdulrahmanBotTrait
{
    private function handleAbdulrahman(
        string $phone,
        int    $convId,
        string $text,
        int    $custId,
        string $state,
        array  $conv,
        array  $draft
    ): void {
        // 🎯 حسم مباشر من الكود (بدون انتظار رأي الـ AI) لما الزبون يكون قدام
        // خطوة "هل تريد تثبيت الطلب؟" — هيك الزر يشتغل 100% من أول ضغطة دايماً،
        // بدل ما نعتمد على تفسير الموديل لنص الزر (اللي ممكن يتلخبط أحياناً).
        if (!empty($draft['awaiting_confirm_reply'])) {
            $handled = $this->resolvePendingConfirmation($phone, $convId, $custId, $text, $draft);
            if ($handled) return;
            // ما انطبق عليها تأكيد ولا رفض واضح → كمل عادي واخلي الـ AI يتعامل معها
        }

        // ✅ لو منتظرين رقم هاتف الزبون (قناة ماسنجر/انستقرام) — نحسمها مباشرة
        // بدون ما نضيّع الرسالة بمحادثة حرة مع الـ AI
        if ($state === self::STATE_MAINT_CONTACT_PHONE) {
            $this->handleContactPhoneState($phone, $convId, $custId, $text, $draft);
            return;
        }

        $history = $this->getRecentHistory($convId, 6);

        $priceText = self::VISIT_PRICE_MIN . " إلى " . self::VISIT_PRICE_MAX . " دينار";

        // ✅ قواعد وشخصية عبدالرحمن بالكامل تنقرا هلق من قسم "تعليمات" بالداشبورد
        // (setting_key = custom_bot_instructions) بدل ما تكون مثبتة بالكود.
        // لو الأدمن ما كتب/ما عدّل شي بعد، منستخدم نفس القواعد الافتراضية الأصلية
        // (defaultAbdulrahmanRules) عشان ما ينكسر سلوك البوت لحد ما الأدمن يراجعها.
        $dashboardRules = $this->getCustomInstructions();
        $rulesBlock     = $dashboardRules !== '' ? $dashboardRules : self::defaultAbdulrahmanRules($priceText);

        // 🔒 الجزء التقني الثابت: عقد الإخراج JSON اللي الكود بيعتمد عليه حرفياً
        // (extracted_data / suggest_state) — ما بينعدل من الداشبورد لأنه لو الأدمن
        // غيّره أو حذفه بالغلط، بينكسر تحليل رد الموديل بالكامل.
        $outputContract = <<<PROMPT

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📋 ملخص سريع لتصنيف نوع رسالة الزبون (ارجع له لما تتردد):
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- سؤال عادي تقدر تجاوبه أنت مباشرة (أسعار، أوقات، موقع، توضيح بسيط، تشخيص أولي، فحص سريع) → intent="general", suggest_state="idle"
- استفسار أو ملاحظة ما تقدر تجاوبها أنت (شكوى شغل سابق، تعديل موعد/جدولة، طلب يحتاج قرار معاذ، سعر قطعة سابقة) → intent="inquiry", suggest_state="waiting_owner_answer"
- زبون بس قال "عندي سؤال/ملاحظة" بدون تفاصيل → intent="general", suggest_state="idle" (اسأله شو الموضوع أولاً، لا تحوّل لمعاذ فاضي)
- زبون يسأل عن حالة طلب قديم → intent="track_order", suggest_state="idle"
- زبون يفتح طلب صيانة جديد كامل المعلومات → intent="maintenance"
- زبون يسأل عن قطعة غيار → intent="part_request"
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

مهم جداً:
- إذا الزبون ذكر الجهاز والمشكلة والموقع في رسالة وحدة، اجمعها كلها وحدد intent=maintenance
- لا تطلب معلومة موجودة بالرسالة مرة ثانية
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

        // ✅ لو الموقع محفوظ مسبقاً بالـ draft (بعت موقعه بمحادثة سابقة قريبة) — نذكّر
        // الموديل صراحة، حتى لو ما ظهر بالـ history المرسل (آخر 6 رسائل فقط)، عشان
        // ما يعيد سؤال الزبون عن موقعه من جديد وهو أصلاً محفوظ عندنا.
        if (!empty($draft['location'])) {
            $priceContext .= "\n\n📍 موقع الزبون محفوظ مسبقاً من رسالة سابقة: \"{$draft['location']}\". "
                . "لا تعيد سؤاله عن موقعه إطلاقاً إلا إذا طلب هو صراحة تغييره.";
        }

        $finalPrompt  = $systemPrompt . $priceContext;

        $messages = [
            ['role' => 'system', 'content' => $finalPrompt],
            ...$history,
            ['role' => 'user', 'content' => $text],
        ];

        $aiResponse = $this->callAIWithRetry($messages, true);

        if (!$aiResponse) {
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULRAHMAN;
            $this->updateConvState($convId, $state, $draft);
            $this->reply($phone, $convId,
                "لم أفهم سؤالك جيداً الآن 😅\n"
                . "اكتب سؤالك ثاني أو اتصل بنا مباشرة،\n"
                . "أو اختر من القائمة لخالد 👇"
            );
            $this->sendWelcome($phone, $convId);
            return;
        }

        $reply        = $aiResponse['reply']          ?? '';
        $extracted    = $aiResponse['extracted_data'] ?? [];
        $suggestState = $aiResponse['suggest_state']  ?? 'null';

        if ($reply === '') {
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULRAHMAN;
            $this->updateConvState($convId, $state, $draft);
            $this->reply($phone, $convId,
                "لم أفهم سؤالك جيداً 😅\nجرب تكتبه ثاني أو اختر من القائمة 👇"
            );
            $this->sendWelcome($phone, $convId);
            return;
        }

        $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULRAHMAN;

        // ✅ حماية: أحياناً الموديل بيرجع كلمة "null" كنص حرفي (مش JSON null الحقيقية)
        // — لازم نرفضها هون قبل ما تنخزن كأنها قيمة فعلية (سبب باغ "null — null")
        $isRealValue = function ($v): bool {
            if (empty($v)) return false;
            $n = mb_strtolower(trim((string)$v));
            return $n !== 'null' && $n !== 'none' && $n !== 'n/a';
        };

        if ($isRealValue($extracted['device'] ?? null))   $draft['device_type']  = $extracted['device'];
        if ($isRealValue($extracted['brand'] ?? null))    $draft['device_brand'] = $extracted['brand'];
        if ($isRealValue($extracted['location'] ?? null)) $draft['location']     = $extracted['location'];

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

        // ✅ سأل عن قطعة غيار (بالمحادثة الحرة، بغض النظر شو suggest_state رجّعه الموديل)
        // → نفس فلو خالد بالضبط: نطلب صورة القطعة، ولما توصل بنرد "متوفرة" + رقم الدعم
        // مباشرة (بدون انتظار تصنيف الصورة ولا تحويل لمعاذ).
        if ($intent === 'part_request') {
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_KHALED;
            $partNameGuess = trim((string)($extracted['device'] ?? ''));
            if ($partNameGuess !== '') $draft['part_name'] = $partNameGuess;
            $this->updateConvState($convId, self::STATE_WAITING_PART_IMAGE, $draft);
            $this->reply($phone, $convId, "🧩 تمام، ممكن تبعتلي صورة القطعة؟ 📸");
            return;
        }

        if (isset($aiResponse['suggest_state'])) {

            if ($aiResponse['suggest_state'] === 'idle') {

                if ($intent === 'track_order' || $intent === 'tracking') {
                    $this->reply($phone, $convId, $reply);
                    $this->trackLatestOrderByPhone($phone, $convId);
                    $this->notifyOwnerCustomerIsTracking($phone);
                    $this->updateConvState($convId, self::STATE_IDLE, $draft);
                    return;
                }

                if ($intent === 'inquiry') {
                    $this->reply($phone, $convId, $reply);
                    // ✅ فقط نحوّل لمعاذ إذا عبدالرحمن ما عرف يجاوب (suggest_state=waiting_owner_answer)
                    // إذا جاوب بنفسه (suggest_state=idle) → ما نزعج معاذ
                    $suggestIsEscalate = ($aiResponse['suggest_state'] ?? '') === 'waiting_owner_answer';
                    if ($suggestIsEscalate) {
                        $notifId = $this->saveNoteAsNotification($convId, $custId, $text, $phone, self::BOT_ABDULRAHMAN);
                        $replyLine = $notifId ? "\n\nللرد اكتب:\n*" . self::BOT_ABDULRAHMAN . ": رد#{$notifId}: جوابك*" : '';
                        $this->notifyOwnerWithCustomerNote($phone, $text);
                        if ($notifId) $this->notifyAdmins(self::BOT_ABDULRAHMAN . " 💬 *ملاحظة/استفسار — يحتاج رد معاذ*\n📱 {$phone}\n📝 {$text}{$replyLine}");
                        $this->escalateToOwner($phone, $convId, $text, $custId, null, self::BOT_ABDULRAHMAN);
                    }
                    $this->updateConvState($convId, self::STATE_IDLE, $draft);
                    return;
                }

                if ($intent === 'maintenance'
                    && !empty($draft['device_type'])
                    && !empty($draft['issue'])
                    && !empty($draft['location'])
                ) {
                    // ✅ تحقق منطقي: هل العطل المذكور فعلاً معقول لهذا الجهاز؟
                    // (نفس فحص خالد isIssueLogicalAI — لو الزبون ذكر عطل لجهاز تاني
                    // بالغلط، منوقف ومنسأله يوضح بدل ما نفتح طلب غلط).
                    if (!$this->isIssueLogicalAI((string)$draft['device_type'], (string)$draft['issue'])) {
                        $mentionedIssue = (string)$draft['issue'];
                        $mentionedDevice = (string)$draft['device_type'];
                        unset($draft['issue'], $draft['issue_id']);
                        // ✅ نفس فكرة getDeviceIssueExamples عند خالد: بدل سؤال عام، نوضح
                        // للزبون بالضبط شو أعطال هذا الجهاز الفعلية، عشان يفهم وين الالتباس
                        // (مثلاً: قال "ثلاجة" ووصف عطل غسالة — نوريه أمثلة أعطال الثلاجة الحقيقية)
                        $deviceExamples = $this->getDeviceIssueExamples($mentionedDevice);
                        $this->reply($phone, $convId,
                            "🤔 وصف \"{$mentionedIssue}\" مش شكله عطل مرتبط بـ *{$mentionedDevice}* عادةً.\n"
                            . "أعطال *{$mentionedDevice}* المعتادة مثلاً: {$deviceExamples}\n\n"
                            . "ممكن تقصد جهاز تاني (مثلاً غسالة أو جلاية)؟ ولا في تفصيل زيادة عن المشكلة تحب تضيفه؟"
                        );
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }

                    // ── فحص الموقع (داخل الأردن؟) وتحديد المنطقة — عبر OpenAI ─────
                    $areaType = $this->detectServiceArea((string)$draft['location']);
                    if (!$this->lastLocationIsJordan) {
                        $this->reply($phone, $convId,
                            "⚠️ خدمة الصيانة متوفرة داخل *الأردن* فقط 🇯🇴\nاكتب عنوانك داخل الأردن."
                        );
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }
                    if ($areaType === 'parts') {
                        $this->reply($phone, $convId,
                            "عذراً، خدمة الصيانة بالبيت متوفرة بعمان والسلط والزرقاء فقط حالياً.\n"
                            . "بس عندنا قطع غيار لكل محافظات المملكة!\n"
                            . "للطلب اتصل على: 📞 *" . self::SUPPORT_PHONE . "*\n(مكالمة فقط — لا رسائل واتساب)"
                        );
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }
                    // تحديد سعر الزيارة
                    $draft['visit_price'] = ($areaType === 'zarqa') ? 15 : 10;


                    $wasAwaitingConfirm = !empty($draft['awaiting_confirm_reply']);
                    $userApproved = $wasAwaitingConfirm
                        && (bool)preg_match('/(اه|نعم|نهم|تمام|^تم$|اوكي|أوكي|موافق|ثبت|توكل|ياريت|يا ريت|امشي)/ui', $text);
                    $isConfirmationAsk = str_contains($reply, 'تثبيت الطلب');
                    $draft['awaiting_confirm_reply'] = $isConfirmationAsk; // للرسالة الجاية

                    if (!$userApproved) {
                        if ($isConfirmationAsk) {
                            // ✅ زر تأكيد جاهز بدل ما الزبون يكتب يدوي — بيرفع نسبة إتمام الطلب
                            // (يظهر فقط لما عبدالرحمن فعلياً يسأل "هل تريد تثبيت الطلب؟"، مش على أي سؤال توضيحي)
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

                    $this->markDropoffRecovered($convId);

                    // ✅ نقبل المشكلة كما هي — معاذ والفني يحكموا
                    $hasRecentTicket = false;
                    try {
                        $checkStmt = $this->db->prepare(
                            "SELECT id FROM service_requests WHERE customer_phone=? AND created_at >= NOW() - INTERVAL 5 MINUTE LIMIT 1"
                        );
                        $checkStmt->execute([$phone]);
                        $hasRecentTicket = (bool)$checkStmt->fetchColumn();
                    } catch (Throwable $e) {}

                    if ($hasRecentTicket) {
                        $this->reply($phone, $convId,
                            "النظام يظهر أنه قد أكّد لك طلباً قبل قليل، وحتى لا يتكرر الطلب، فإن طلبك الأول الفني يعمل عليه الآن وسنستعجله لك 🙏"
                        );
                        $this->updateConvState($convId, self::STATE_IDLE, $draft);
                        return;
                    }

                    $this->reply($phone, $convId, $reply);
                    if ($this->channel !== 'whatsapp' && empty($draft['contact_phone'])) {
                        // ✅ نطلب رقم الزبون الحقيقي أولاً (ماسنجر/انستقرام) قبل ما نثبّت الطلب
                        $this->finalizeOrAskContactPhone($phone, $convId, $custId, $draft);
                        return;
                    }
                    $this->finalizeMaintenanceRequest($phone, $convId, $custId, $draft);
                    $supportMsg = "💡 للمتابعة أو الاستفسار لاحقاً، يمكنك التواصل مع الدعم الفني مباشرة على الرقم: " . self::SUPPORT_PHONE . " 📞";
                    $this->reply($phone, $convId, $supportMsg);
                    return;
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

                $this->reply($phone, $convId, $reply);
                $partImage = (string)($draft['last_part_image'] ?? '');
                $ownerQuestion = !empty($draft['last_part_label'])
                    ? "🧩 الزبون بسأل عن سعر قطعة\n🔧 " . $draft['last_part_label'] . "\n💬 نص رسالته: {$text}"
                    : $text;
                $notifId = $this->escalateToOwner($phone, $convId, $ownerQuestion, $custId, null, self::BOT_ABDULRAHMAN);
                if ($partImage && file_exists($partImage)) {
                    $forwardCaption = "🧩 استفسار سعر قطعة\n📱 {$phone}\n🔧 " . ($draft['last_part_label'] ?? '')
                        . ($notifId ? "\n\nللرد اكتب:\n*" . self::BOT_ABDULRAHMAN . ": رد#{$notifId}: جوابك*" : '');
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
    //  دوال الإشعارات الداخلية لمعاذ
    // ═══════════════════════════════════════════════════════════════
    private function trackLatestOrderByPhone(string $phone, int $convId): void
    {
        $latestReq = $this->findLastRequestByCustomerPhone($phone);
        if ($latestReq) {
            $statusMap = [
                'pending'     => 'جاري تعيين فني صيانة ⏳',
                'assigned'    => 'الفني بالطريق إليك 🚗',
                'in_progress' => 'قيد التصليح 🛠️',
                'completed'   => 'تم التصليح وإغلاق الطلب بنجاح ✅',
                'cancelled'   => 'ملغي ❌',
            ];
            $currentStatus = $statusMap[$latestReq['status']] ?? 'قيد المتابعة 🛠️';

            $msg = "🔍 تفاصيل طلبك الأخير المفتوح معنا:\n\n";
            $msg .= "🔢 رقم الطلب: " . ($latestReq['order_number'] ?? '---') . "\n";
            $msg .= "🖥️ الجهاز: " . ($latestReq['product_name'] ?: 'غير محدد') . "\n";
            $msg .= "📍 العنوان: " . ($latestReq['customer_location'] ?: 'غير محدد') . "\n";
            $msg .= "📊 حالة الطلب: " . $currentStatus . "\n\n";
            $msg .= "فريق مشغل جنين يتابع الموضوع باهتمام، لا تقلق! ❤️";

            $this->reply($phone, $convId, $msg);
        } else {
            $this->reply($phone, $convId,
                "بحثت برقم هاتفك ولا يوجد لديّ أي طلب صيانة مفتوح حالياً، تريد أن نفتح لك طلباً جديداً؟"
            );
        }
    }

    private function notifyOwnerCustomerIsTracking(string $phone): void
    {
        $msg = "⚠️ 🔔 [تنبيه صيانة]: الزبون صاحب الرقم ({$phone}) قام الآن بالاستفسار وتتبع حالة طلبه عبر البوت. يرجى المتابعة معه يا أستاذ معاذ 🙏";
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

    /** يعلّم أنه الزبون رجع وأكد الطلب بعد ما كان متردد (عشان تقرير معاذ يعرف الفرق بين اللي رجع واللي ما رجع) */
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
            $draft['awaiting_confirm_reply'] = false;
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            $this->reply($phone, $convId, "تمام، لو غيرت رأيك أو حاب تسأل أي سؤال احنا موجودين 🙏");
            return true;
        }

        $isConfirm = $trimmed === 'btn_confirm_order'
            || (bool)preg_match('/(اه|نعم|نهم|تمام|^تم$|اوكي|أوكي|موافق|ثبت|توكل|ياريت|يا ريت|امشي)/ui', $trimmed);

        if (!$isConfirm) return false; // رسالة مش واضحة (سؤال جديد مثلاً) → خلي الـ AI يتعامل معها

        $draft['awaiting_confirm_reply'] = false;
        $this->markDropoffRecovered($convId);

        // ✅ منع تكرار الطلب لو انضغط الزر مرتين بسرعة أو تكرر الإرسال
        $hasRecentTicket = false;
        try {
            $checkStmt = $this->db->prepare(
                "SELECT id FROM service_requests WHERE customer_phone=? AND created_at >= NOW() - INTERVAL 5 MINUTE LIMIT 1"
            );
            $checkStmt->execute([$phone]);
            $hasRecentTicket = (bool)$checkStmt->fetchColumn();
        } catch (Throwable $e) {}

        if ($hasRecentTicket) {
            $this->reply($phone, $convId,
                "النظام يظهر أنه قد أكّد لك طلباً قبل قليل، وحتى لا يتكرر الطلب، فإن طلبك الأول الفني يعمل عليه الآن وسنستعجله لك 🙏"
            );
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            return true;
        }

        if ($this->channel !== 'whatsapp' && empty($draft['contact_phone'])) {
            // ✅ نطلب رقم الزبون الحقيقي أولاً (ماسنجر/انستقرام) قبل ما نثبّت الطلب
            $this->finalizeOrAskContactPhone($phone, $convId, $custId, $draft);
            return true;
        }

        $this->reply($phone, $convId, "تمام، تم تثبيت طلبك ✅");
        $this->finalizeMaintenanceRequest($phone, $convId, $custId, $draft);
        $supportMsg = "💡 للمتابعة أو الاستفسار لاحقاً، يمكنك التواصل مع الدعم الفني مباشرة على الرقم: " . self::SUPPORT_PHONE . " 📞";
        $this->reply($phone, $convId, $supportMsg);
        return true;
    }

    /**
     * القيمة الافتراضية لقواعد وشخصية عبدالرحمن — نفس النص الأصلي اللي كان مثبّت بالكود
     * حرفياً. تُستخدم فقط إذا كان حقل "تعليمات" بالداشبورد فاضي (لسا الأدمن ما
     * حفظ شي). أول ما الأدمن يفتح قسم "تعليمات" وما يلاقي شي، هاد النص هو اللي
     * لازم يترزرع بالحقل تلقائياً (شوف bot_instructions.php) عشان يعدّل عليه
     * مباشرة بدل ما يبلش من صفر.
     */
    public static function defaultAbdulrahmanRules(string $priceText): string
    {
        $supportPhone = self::SUPPORT_PHONE;
        return <<<PROMPT
أنت "عبدالرحمن"، مساعد ذكي بمشغل جنين للصيانة وبيع قطع الغيار بالأردن.
صاحب المحل اسمه "معاذ"، وهو معلّم بالصنعة وإنسان محترم.

شخصيتك:
- تحكي بأسلوب عربي بسيط وواضح، مزيج بين الفصحى والعامية العربية المفهومة لكل الناطقين بالعربية.
- ذكي وتفهم المشاكل التقنية للأجهزة المنزلية.
- تقدر تفاصل بالأسعار بأسلوب لطيف.
- تجاوب على أسئلة المحل البسيطة (أوقات، أسعار، موقع) مباشرة بدون أن تطلب مساعدة إضافية.

🚫 قواعد سلبية على الأسلوب (ممنوعات ثابتة — الباقي بأسلوبك الحر بالكامل):
- ممنوع لهجة محلية مميزة أو تعبيرات زي "يا غالي"، "أبشر"، "ع راسي"، "حياك الله".
- ممنوع الرموز التعبيرية الزايدة عن واحد بنفس الرسالة.
- ممنوع الوعود القاطعة بحل أكيد قبل ما الفني يعاين الجهاز فعلياً.
- ممنوع تكرار نفس الجملة أو الأسلوب حرفياً أكتر من مرة بنفس المحادثة (نوّع بالصياغة حتى لو نفس المعنى).

معلومات المحل والاتصال (Knowledge Base):
- اسم المحل: مشغل جنين للصيانة وبيع قطع الغيار (فريق مشغل جنين).
- 📍 الموقع الصحيح: عمان - الهاشمي الشمالي - نزول سوق المخفر. رابط اللوكيشن: https://maps.google.com/?q=31.9646027,35.959144
- ⏰ أوقات الدوام والعمل الرسمية: من الساعة 10:00 صباحاً وحتى الساعة 9:00 مساءً طيلة أيام الأسبوع. (ممنوع تماماً ذكر أي وقت آخر).
- 📞 رقم الدعم الفني: {$supportPhone} (أعطه فوراً لأي زبون يطلب رقم الدعم أو التواصل).

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
1. إذا أرسل الزبون صورة (سواء لقطعة متوفرة أو غير متوفرة أو جهاز خربان)، رد فوراً: "تمام، سأحوّل الصورة إلى الأستاذ معاذ ليتحقق منها ويرد عليك فوراً!"، واجعل intent="general".
2. إذا طلب الزبون صراحة وبكلمات واضحة التحدث مع "معاذ" شخصياً أو تقديم شكوى عن شغل سابق لا تريد الإجابة عليها.

1. لو ذكر الزبون اسم "معاذ": رد بحرارة "تحت أمرك!"
2. 🚨 (تتبع الطلبات والمراجعات): إذا قال الزبون (وين صار طلبي، لحد الآن ما إجاني حد، تأخرتوا علي، بدي أشوف طلبي)، أخبره فوراً: "لا تقلق، سأتابع طلبك الآن من رقم هاتفك، لحظات فقط لأرى أين أصبح الفني". ويجب حتماً إرجاع intent="track_order" و suggest_state="idle". ممنوع فتح طلب جديد هنا.
3. 🛑 (تعدد الطلبات ومنع التكرار): إذا طلب صيانة لجهاز جديد في نفس المحادثة بعد تأكيد طلب سابق، ذكّره بالطلب الأول صراحة (نص حرفي: "لقد أكدنا لك طلباً قبل قليل للثلاجة، تريد أن نفتح لك طلباً جديداً ومنفصلاً للفرن أيضاً؟")، واجعل intent="general" و suggest_state="idle" حتى يوافق صراحة بكلمات التأكيد.
4. لو الزبون يريد صيانة جديدة: اجمع منه (نوع الجهاز + ماركته مثل LG أو Samsung أو غيرها + وصف المشكلة + الموقع) بأسلوب محادثة ودود وبسيط. الماركة مش إلزامية إذا ما ذكرها.
5. ⚠️ (شرط تجاري - رسوم الزيارة): عندما تتوفر معلومات الطلب الجديد، يجب إخبار الزبون صراحة بوجود رسوم فحص وزيارة تختلف حسب منطقته، وأن أجر الكشف يُحسب إذا لم يرغب بالتصليح، وإذا تم التصليح يدفع حسب الاتفاق مع الفني. 🚨 اكتب هذه الجملة حرفياً بدون أي تعديل إملائي أو دمج مع جمل أخرى بنفس الجملة، واجعلها جملة منفصلة قائمة بذاتها حتى لو سبقتها جملة ترحيبية: "للعلم، هناك رسوم فحص وزيارة تختلف حسب منطقتك، وأجر الكشف يُحسب إذا لم ترغب بالتصليح، أما إذا تم التصليح فتدفع حسب الاتفاق مع الفني. هل تريد تثبيت الطلب؟". في هذه الخطوة اجعل حتماً intent="general" و suggest_state="idle".
6. 🖥️ (المنتجات خارج التخصص): إذا سأل عن PC، كمبيوتر، شاشات، لابتوبات، اعتذر منه بلطف وأخبره أننا نصلح فقط الأجهزة المنزلية الكبيرة (غسالات، ثلاجات، مكيفات، أفران، جلايات، ميكرويف، مجففات ملابس 🌀)، واجعل حتماً intent="general" و suggest_state="idle".
6.5. 🔧 (قطع الغيار — مهم جداً): إذا سأل الزبون إذا كنتم تبيعون قطع أو قطعة أو قطع غيار، لا تسأله أي سؤال ولا تطلب منه اسم الجهاز — رد فوراً ومباشرة: "نعم، نبيع قطع الغيار لكل محافظات المملكة 📦 للطلب اتصل على {$supportPhone} (مكالمة فقط، لا رسائل واتساب)". واجعل intent="general" و suggest_state="idle".
7. ⚠️ (تنبيه الموقع والهلوسة): لا تقل للزبون "خدماتنا داخل الأردن فقط" إذا أعطاك اسم مدينة أو منطقة أو اسم جهاز فقط؛ تعامل معه بشكل طبيعي واترك النظام يتحقق من الموقع تلقائياً بعد ذلك.
8. 🛑 (الشكاوى والملاحظات والأسئلة):
   - إذا قال الزبون فقط "عندي ملاحظه" أو "عندي سؤال" أو "بدي استفسر" بدون ما يكتب ايش الملاحظة أو السؤال: اسأله "تفضل، شو ملاحظتك؟ 😊" واجعل intent="general" و suggest_state="idle". لا تحوّل لمعاذ قبل ما تعرف ايش يريد.
   - إذا كتب الزبون ملاحظته أو سؤاله الفعلي: **حاول تجاوب عليه أولاً** بأسلوبك اللطيف والذكي.
   - إذا قدرت تجاوب (أوقات، أسعار، موقع، توضيح، اعتذار لطيف...): رد مباشرة واجعل intent="general" و suggest_state="idle".
   - إذا ما قدرت تجاوب (شكوى على شغل سابق، ملاحظة تحتاج متابعة شخصية من معاذ، سؤال خارج صلاحياتك): قل للزبون "رح أحوّل ملاحظتك لمعاذ مباشرة" واجعل intent="inquiry" و suggest_state="waiting_owner_answer".
9. ⚠️ (للأسئلة العامة): الأسئلة عن (الأسعار، أوقات العمل، موقع المحل): جاوبها مباشرة باختصار ودقة من الـ Knowledge Base المذكورة أعلاه (أوقاتنا من 10 الصبح لـ 9 المساء)، واجعل الـ suggest_state هي idle وحدد intent="general".

10. 💬 (إذا قال الزبون "بفكر" أو "برجعلك" أو "بعدين" أو ما شابه): لا تسكت وتخلص الرد بجملة عادية. اطرح عليه سؤال قصير ولطيف يخليه يقرر الآن أو يوضح تردده، مثل: "تمام خذ وقتك 🙏، بس حابب أساعدك تقرر بسرعة — هل المشكلة معطلتك كتير هلأ؟" أو "ولا يهمك، بس خلي ببالك إنه أجر الكشف ثابت والفني بيوصلك بنفس اليوم إذا أكدت هلأ". الهدف إنك ترجعه للمحادثة بدل ما يسكت ويروح. اجعل intent="general" و suggest_state="idle".

11. 🔎 (فحص سريع قبل فتح الطلب): إذا كانت المشكلة من النوع اللي ممكن تكون بسيطة وغير حقيقية (مثلاً الجهاز "مش شغال خالص" أو "مش عم يوصل كهرباء")، اسأل سؤال تحقق سريع واحد بس قبل ما تفتح طلب صيانة، مثل: "قبل ما نكمل، تأكدت إنه مقبس الكهرباء شغال والسلك موصول منيح؟". 🚨 هذا السؤال دايماً intent="general" و suggest_state="idle" — ممنوع منعاً باتاً تحويله لـ waiting_owner_answer أو inquiry، لأنه سؤال توضيحي بسيط من عندك وما يحتاج معاذ أبداً. إذا أكد الزبون إنه تأكد أو إنه المشكلة مش من هيك، كمل إجراءات فتح الطلب عادي (اجمع الموقع إذا ناقص، ثم انتقل لخطوة رسوم الزيارة). لا تسأل هذا السؤال لأعطال واضحة تقنياً (زي صوت غريب، تسريب مي، ما بيبرد).

12. 🧾 (بدون تشخيص — وظيفتك تاخد الطلب وتحوّله للفني، مش تحل المشكلة): ممنوع تحاول تشخّص سبب العطل أو تقترح حل تقني أو سبب محتمل (زي "غالباً الفيوز محروق" أو "ممكن يكون الموتور"). لما الزبون يوصف عرَض واضح ومنطقي (مثلاً: "الثلاجة ما بتبرد"، "الفرن ما بيسخن")، رد بأسلوبك اعتراف بسيط بالمشكلة بدون أي تخمين تقني، وقله إنه بيحتاج طلب صيانة وفني يعاينه، واسأله إذا بيحب تفتحله الطلب. مثال أسلوب (نوّع الصياغة ولا تكررها حرفياً): "تمام، فهمت المشكلة، هاي بتحتاج فني يعاينها. بتحب نفتحلك طلب صيانة؟". اجعل intent="general" و suggest_state="idle" لحد ما يوافق صراحة (حسب قاعدة التأكيد الصارمة)، وبعدها كمل إجراءات فتح الطلب الاعتيادية (اجمع الموقع إذا ناقص، ثم رسوم الزيارة).

12.5. 🚫 (فحص منطقية المشكلة مع نوع الجهاز): قبل ما تقبل وصف العطل، تأكد إنه منطقي لنوع الجهاز يلي ذكره الزبون (مثلاً: "ثلاجة ما بتطبخ" أو "فرن ما ببرد" — هاي أوصاف مقلوبة/مش منطقية، لأنه الثلاجة وظيفتها تبريد مش طبخ، والفرن وظيفته تسخين مش تبريد). إذا كان وصف المشكلة مش منطقي إطلاقاً لوظيفة الجهاز المذكور، **ممنوع تكمل أو تفتح طلب**، رد بأدب ووضوح، مثل: "حضرتك المشكلة يلي وصفتها مش منطقية لهاد الجهاز 😅، ممكن تكتبلي المشكلة الصحيحة؟" (نوّع الصياغة حسب الجهاز والعطل المذكور). اجعل intent="general" و suggest_state="idle" وانتظر توضيح الزبون قبل أي متابعة. لا تطبّق هذه القاعدة على أوصاف عامة غامضة زي "خربانة" أو "مش شغالة" (هذي تخضع لقاعدة 11 لا لهاي القاعدة).

13. 📅 (طلبات الجدولة والتأجيل — احترافي دايماً): إذا سأل الزبون عن وقت محدد لزيارة الفني (مثلاً "الفني بقدر يجي الساعة 5 العصر؟")، أو طلب تأجيل/تقديم موعد طلب موجود (مثلاً "ممكن تأجلولي الطلب لبكرة؟")، هذا قرار جدولة فعلي لازم معاذ أو الفني يأكده مباشرة — ما تحاول تجاوب أو تخمّن بنفسك. رد بأسلوب مهذب واثق يطمّن الزبون، مثل: "تمام، هذا الطلب أنسقه مباشرة مع معاذ/الفني وأرجعلك بالتأكيد أول ما يرد 🙏"، واجعل حتماً intent="inquiry" و suggest_state="waiting_owner_answer".

14. 💰 (سؤال مباشر عن قيمة الرسوم — ممنوع التكرار الحرفي): إذا سأل الزبون صراحة "كم الرسوم؟" أو "قديش السعر؟" أو "شو التكلفة؟" (سؤال مباشر عن الرقم، مش أول مرة تشرح فيها الشرط التجاري)، **ممنوع تكرر جملة "للعلم، هناك رسوم فحص وزيارة..." حرفياً كجواب**. بدلها جاوبه مباشرة بجملة طبيعية قصيرة فيها الرقم:
   - إذا كان عندك رسوم الزيارة المحسوبة لمنطقته (مذكورة أسفل بالسياق إذا متوفرة): اذكر الرقم المحدد صراحة، مثل: "رسوم الكشف والزيارة لمنطقتك {رقم} دينار، وإذا وافقت على التصليح فتدفع حسب اتفاقك مع الفني. هل تريد تثبيت الطلب؟" 🚨 لازم تنتهي بالضبط بعبارة "هل تريد تثبيت الطلب؟" حرفياً (نفس نص قاعدة 5) — هاي العبارة يعتمد عليها الكود لتفعيل أزرار التأكيد، أي صياغة تانية بتكسر آلية التأكيد.
   - إذا ما عندك رقم محدد بعد (الموقع لسا ما انجمع): جاوب بالنطاق العام (بين {$priceText} حسب المنطقة) واطلب الموقع لتحديد الرقم بالضبط.
   اجعل intent="general" و suggest_state="idle" (هذا سؤال توضيحي بسيط، ما يحتاج معاذ).

15. 🧩 (سؤال عن سعر قطعة سبق وسأل عنها الزبون بصورة): إذا كان مذكور بالسياق أسفل "آخر قطعة سأل عنها الزبون بالصورة" وسأل الزبون بعدها عن سعرها ("كم سعرها؟"، "قديش"، "بكم")، ما تخمّن السعر أبداً — قل له بأدب: "لحظة، رح أسأل معاذ عن سعرها وأرجعلك 🙏" واجعل حتماً intent="inquiry" و suggest_state="waiting_owner_answer".

16. 🛒 (نية شراء قطعة): إذا قال الزبون أي صيغة دالة على نية شراء قطعة سبق وسأل عنها ("بدي ياها"، "بدي أطلبها"، "بدي أشتريها"، "بدي احجزها")، لا تصعّد لمعاذ — جاوبه مباشرة: "ممتاز! 🎉 للطلب أو الشراء تواصل معنا مباشرة على رقم الدعم: {$supportPhone}" واجعل intent="general" و suggest_state="idle".
PROMPT;
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
