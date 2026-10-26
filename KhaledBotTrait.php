<?php

/**
 * KhaledBotTrait.php — منطق بوت خالد (الأزرار والفلو المنظم)
 *
 * كل الدوال هون خاصة بشخصية خالد فقط: استقبال أزرار القائمة، فلو
 * الصيانة المرحلي، استفسار القطعة، تتبع الطلب، الشكاوى/الملاحظات.
 *
 * هاد trait مو كلاس مستقل — بينضم لكلاس Agent (see includes/Agent.php)
 * عشان يضل عنده وصول مباشر لكل الخدمات المشتركة (DB, WhatsApp, AI,
 * RAG, ProductSearch...) من غير ما نعقّد الكود بطبقة تمرير context.
 */
trait KhaledBotTrait
{
    private function handleKhaled(
        string $phone,
        int    $convId,
        string $text,
        int    $custId,
        string $state,
        array  $conv,
        array  $draft
    ): void {
        $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_KHALED;

        // ✅ لو في قطعة سابقة سأل عنها الزبون بالصورة (last_part_label)، نتحقق أول
        // شي إذا هاي الرسالة سؤال عن سعرها أو نية شراء، قبل أي معالجة تانية
        if (!empty($draft['last_part_label']) && $state === self::STATE_IDLE) {
            $n = $this->normalizeArabicText(mb_strtolower(trim($text)));
            $isPriceQuestion = (bool)preg_match('/(سعر|كم|قديش|بكم|تكلفة|حق ال)/u', $n);
            $isPurchaseIntent = (bool)preg_match('/(بدي ياه|بدي ياها|بدي اطلب|بدي أطلب|بدي اشتري|بدي أشتري|بشتريها|بطلبها|احجزها|احجز)/u', $n);

            if ($isPurchaseIntent) {
                $this->reply($phone, $convId,
                    "ممتاز! 🎉 للطلب أو الشراء تواصل معنا مباشرة على رقم الدعم:\n📞 *" . self::SUPPORT_PHONE . "*"
                );
                return;
            }

            if ($isPriceQuestion) {
                $label = (string)$draft['last_part_label'];
                $imagePath = (string)($draft['last_part_image'] ?? '');
                $this->reply($phone, $convId, "لحظة، رح أسأل معاذ عن سعرها وأرجعلك 🙏");
                $notifId = $this->escalateToOwner($phone, $convId,
                    "🧩 *الزبون بسأل عن سعر قطعة*\n📱 {$phone}\n🔧 {$label}\n\n⚠️ يرجى الرد بالسعر ليتم إبلاغ الزبون.",
                    $custId, null, self::BOT_KHALED
                );
                if ($imagePath && file_exists($imagePath)) {
                    $forwardCaption = "🧩 استفسار سعر قطعة\n📱 {$phone}\n🔧 {$label}"
                        . ($notifId ? "\n\nللرد اكتب:\n*" . self::BOT_KHALED . ": رد#{$notifId}: جوابك*" : '');
                    $this->forwardImageToOwners($imagePath, $forwardCaption);
                }
                return;
            }
        }

        if (in_array($state, [
            self::STATE_MAINT_CONFIRM,
            self::STATE_MAINT_NAME,
            self::STATE_MAINT_PHONE_EARLY,
            self::STATE_MAINT_DEVICE,
            self::STATE_MAINT_BRAND,
            self::STATE_MAINT_ISSUE_CUSTOM,
            self::STATE_MAINT_LOCATION,
            self::STATE_WAITING_TRACK_NO,
            self::STATE_WAITING_INQUIRY,
            self::STATE_WAITING_INQUIRY_TEXT,
            self::STATE_WAITING_NOTE_TEXT,
            self::STATE_WAITING_COMPLAINT_TEXT,
            self::STATE_WAITING_PART_TEXT,
            self::STATE_WAITING_PART_IMAGE,
            self::STATE_WAITING_PART_DEVICE,
            self::STATE_WAITING_IMAGE_CONFIRM,
            self::STATE_WAITING_PART_NAME_FALLBACK,
        ], true)) {
            $this->khaledContinueFlow($phone, $convId, $text, $custId, $state, $conv, $draft);
            return;
        }

        $buttonMap = [
            // IDs
            'btn_maintenance'   => 'maintenance',
            'btn_part_inquiry'  => 'part_inquiry',
            'btn_track'         => 'track',
            'btn_inquiry'       => 'inquiry',
            'btn_inquiry_q'     => 'inquiry_question',
            'btn_inquiry_note'  => 'inquiry_note',
            'btn_inquiry_comp'  => 'inquiry_complaint',
            // titles مع إيموجي
            '🔧 طلب صيانة'              => 'maintenance',
            '🧩 استفسار عن قطعة'        => 'part_inquiry',
            '📦 تتبع طلب'               => 'track',
            '📝 استفسار'                => 'inquiry',
            '📋 استفسار'                => 'inquiry_question',
            '💬 ملاحظة أو اقتراح'       => 'inquiry_note',
            '⚠️ شكوى'                   => 'inquiry_complaint',
            // titles بدون إيموجي
            'طلب صيانة'                 => 'maintenance',
            'استفسار عن قطعة'           => 'part_inquiry',
            'تتبع طلب'                  => 'track',
            'استفسار'                   => 'inquiry',
            // descriptions القائمة
            'حجز صيانة للأجهزة المنزلية'   => 'maintenance',
            'صورة أو اسم قطعة الغيار'       => 'part_inquiry',
            'متابعة برقم الطلب'             => 'track',
            'استفسار أو ملاحظة'             => 'inquiry',
            'سؤال أو استفسار عام'           => 'inquiry_question',
            'ملاحظة أو اقتراح أو مقترح'    => 'inquiry_note',
            'تقديم شكوى رسمية'              => 'inquiry_complaint',
        ];

        $action = $buttonMap[$text] ?? null;
        if (!$action) {
            foreach ($buttonMap as $key => $val) {
                if (str_contains($text, $key)) { $action = $val; break; }
            }
        }

        // 🛒 نية شراء قطعة (عادة بعد ما معاذ يأكد توفرها) → رقم المحل مباشرة، بدون قائمة
        if (!$action && (bool)preg_match('/(بدي اطلب|بدي اشتري|بدي اخذ|بدي احجز|كيف اطلب|كيف اشتري|ابغى اطلب|أطلبها|أشتريها|اطلبها|اشتريها)/ui', $this->normalizeArabicText($text))) {
            $this->reply($phone, $convId,
                "تمام 👍 للطلب أو الاستفسار أكتر، تواصل معنا مباشرة على رقم الدعم:\n📞 *" . self::SUPPORT_PHONE . "*"
            );
            return;
        }

        $priceText = self::VISIT_PRICE_MIN . " و" . self::VISIT_PRICE_MAX . " دينار";

        switch ($action) {
            case 'maintenance':
                // ✅ طلب الصيانة بالكامل يضل عند خالد بالفلو المنظم (أزرار/أرقام)،
                // ما في تسليم لعبدالرحمن إطلاقاً — الزبون يضغط زر فبيضل يحكي مع نفس البوت.
                $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_KHALED;
                $this->updateConvState($convId, self::STATE_MAINT_CONFIRM, $draft);
                $this->reply($phone, $convId,
                    "🔧 *طلب صيانة — خالد معك*\n\n"
                    . "للعلم، في رسوم فحص وزيارة تختلف حسب منطقتك ({$priceText})، "
                    . "وأجر الكشف يُحسب إذا ما رغبت بالتصليح، وإذا تم التصليح بتدفع حسب الاتفاق مع الفني.\n\n"
                    . "بدك نكمل الطلب؟\nاكتب *1* للمتابعة أو *2* للإلغاء."
                );
                break;

            case 'part_inquiry':
                $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_KHALED;
                $this->updateConvState($convId, self::STATE_WAITING_PART_TEXT, $draft);
                $this->reply($phone, $convId,
                    "🧩 *استفسار عن قطعة — خالد معك*\n\n"
                    . "اكتب اسم القطعة أو كودها، أو ابعت صورتها."
                );
                break;

            case 'track':
                $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_KHALED;
                $this->updateConvState($convId, self::STATE_WAITING_TRACK_NO, $draft);
                $this->reply($phone, $convId,
                    "📦 *تتبع طلب — خالد معك*\n\n"
                    . "أرسل رقم الطلب.\nمثال: SR-2026-000123\n\n"
                    . "_(أو اكتب اسمك، أو اسم الجهاز، وأنا أدور عليه برقم هاتفك)_"
                );
                break;

            case 'inquiry':
            case 'inquiry_question':
            case 'inquiry_note':
            case 'inquiry_complaint':
                // ✅ مباشرة بدون قائمة فرعية — لا AI هون إطلاقاً
                $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_KHALED;
                $this->updateConvState($convId, self::STATE_WAITING_INQUIRY_TEXT, $draft);
                $this->reply($phone, $convId, "📋 *خالد معك*\n\nاكتب استفسارك أو ملاحظتك وأنا أسجّلها لمعاذ مباشرة 📨");
                break;

            default:
                $generalAnswer = $this->answerGeneralQuestion($text);
                if ($generalAnswer !== '') {
                    $this->reply($phone, $convId, $generalAnswer . "\n\n📌 اختر من القائمة أو اكتب لعبدالرحمن:");
                }
                $this->sendWelcome($phone, $convId);
        }
    }

    private function khaledContinueFlow(
        string $phone,
        int    $convId,
        string $text,
        int    $custId,
        string $state,
        array  $conv,
        array  $draft
    ): void {
        $text = $this->normalizeDigits($text);

        switch ($state) {
            case self::STATE_MAINT_CONFIRM:
                $n = $this->normalizeArabicText($text);
                if ($text === '1' || str_contains($n, 'نعم') || str_contains($n, 'نهم') || str_contains($n, 'اه') || str_contains($n, 'أه') || str_contains($n, 'تمام') || str_contains($n, 'اكيد') || str_contains($n, 'أكيد') || str_contains($n, 'يلا') || str_contains($n, 'موافق')) {
                    $this->updateConvState($convId, self::STATE_MAINT_NAME, $draft);
                    $this->reply($phone, $convId, "👌 ممتاز! نبدأ طلبك.\n\n👤 *شو اسمك؟*");
                } elseif ($text === '2' || str_contains($n, 'لا') || str_contains($n, 'الغ') || str_contains($n, 'الغاء') || str_contains($n, 'لاء')) {
                    $this->updateConvState($convId, self::STATE_IDLE, []);
                    $this->reply($phone, $convId, "تمام 😊 إذا احتجتنا نحن هون، لا تتردد!");
                } else {
                    if ($this->handleKhaledInterruption($phone, $convId, $text, $state, $draft)) return;
                    $this->reply($phone, $convId, "ما فهمت 😅\nاكتب *1* للمتابعة أو *2* للإلغاء.");
                }
                return;

            case self::STATE_MAINT_NAME:
                $name = trim($text);
                if ($this->handleKhaledInterruption($phone, $convId, $text, $state, $draft)) return;
                if (mb_strlen($name) < 2 || ctype_digit($name)) {
                    $this->reply($phone, $convId, "⚠️ اكتب اسمك من فضلك 🙏");
                    return;
                }
                $draft['customer_name'] = $name;

                // ✅ لو القناة مش واتساب وما عنا رقم حقيقي بعد → نسأل عنه هلق (بداية الفلو)
                // بدل ما ننتظر لآخر خطوة (بعد الموقع)، عشان لو صار dropoff بأي خطوة بعدها
                // يضل عنا أقل شي رقم تواصل حقيقي مع الزبون.
                if ($this->channel !== 'whatsapp' && empty($draft['contact_phone'])) {
                    $this->updateConvState($convId, self::STATE_MAINT_PHONE_EARLY, $draft);
                    $this->reply($phone, $convId,
                        "📱 تمام يا {$name}! ممكن رقم هاتفك (واتساب) عشان الفني يقدر يتواصل معك؟\n" .
                        "مثال: 07xxxxxxxx"
                    );
                    return;
                }

                $this->showDeviceSelection($phone, $convId, $draft);
                return;

            case self::STATE_MAINT_PHONE_EARLY:
                $normalizedEarly = $this->normalizeContactPhone($text);
                if ($normalizedEarly === null) {
                    if ($this->handleKhaledInterruption($phone, $convId, $text, $state, $draft)) return;
                    $this->reply($phone, $convId, "⚠️ الرقم غير واضح، اكتبه هيك مثلاً: 07xxxxxxxx 🙏");
                    return;
                }
                $draft['contact_phone'] = $normalizedEarly;
                $this->showDeviceSelection($phone, $convId, $draft);
                return;

            case self::STATE_MAINT_DEVICE:
                $devices  = $this->getActiveDeviceTypes();
                $selected = $this->matchDeviceFromText($text, $devices);
                if (!$selected) {
                    if ($this->handleKhaledInterruption($phone, $convId, $text, $state, $draft)) return;
                    $lines = ["⚠️ اختار نوع الجهاز من القائمة:\n"];
                    foreach ($devices as $i => $d) {
                        $lines[] = ($i + 1) . ") " . trim((string)($d['icon'] ?? '🔹')) . " {$d['name_ar']}";
                    }
                    $this->reply($phone, $convId, implode("\n", $lines));
                    return;
                }
                $draft['device_type_id'] = (int)$selected['id'];
                $draft['device_type']    = $selected['name_ar'];
                $this->updateConvState($convId, self::STATE_MAINT_BRAND, $draft);
                $this->reply($phone, $convId,
                    "✅ *{$selected['name_ar']}* — تمام!\n\n" .
                    "🏷️ *شو ماركة الجهاز؟*\n" .
                    "مثال: Samsung, LG, Bosch, Zanussi, Unionaire\n\n" .
                    "_(مش عارف الماركة؟ اكتب \"ما بعرف\" وبنكمل 😊)_"
                );
                return;

            case self::STATE_MAINT_BRAND:
                $brand = trim($text);
                // ✅ لو الزبون سأل سؤال جانبي (لوكيشن، رقم، أو أي سؤال عام) بدل ما يجاوب
                // عن الماركة → نجاوبه ونرجعله نفس السؤال، من غير ما نحفظ السؤال كماركة غلط
                if (!$this->isUnknownBrand($brand) && $this->handleKhaledInterruption($phone, $convId, $text, $state, $draft)) {
                    return;
                }
                // نقطة 2: فهم "ما بعرف" بكل أشكالها — قبل أي شيء آخر
                $draft['device_brand'] = $this->isUnknownBrand($brand) ? '' : $brand;
                $this->updateConvState($convId, self::STATE_MAINT_ISSUE_CUSTOM, $draft);
                $deviceLabel = $draft['device_type'] . (!empty($draft['device_brand']) ? " — {$draft['device_brand']}" : '');
                $brandLine   = empty($draft['device_brand']) ? '' : "✅ الماركة: *{$draft['device_brand']}*\n\n";
                $issueExamples = $this->getDeviceIssueExamples((string)($draft['device_type'] ?? ''));
                $this->reply($phone, $convId,
                    "🔧 *{$deviceLabel}*\n{$brandLine}" .
                    "✍️ *اكتب المشكلة بكلماتك:*\n" .
                    "مثال: {$issueExamples}\n\n" .
                    "_(مش لازم تكون عارف المشكلة بالضبط، قلنا بس شو اللي صاير)_ 😊"
                );
                return;

            case self::STATE_MAINT_ISSUE_CUSTOM:
                $customIssue = trim($text);
                // نقطة 2: فهم "ما بعرف" المشكلة
                if ($this->isUnknownIssue($customIssue)) {
                    $draft['issue_id'] = null;
                    $draft['issue']    = 'مشكلة غير محددة — الزبون لا يعرف وصفها';
                    $this->updateConvState($convId, self::STATE_MAINT_LOCATION, $draft);
                    $this->reply($phone, $convId,
                        "لا بأس! الفني رح يكشف ويحدد المشكلة 👨‍🔧\n\n" .
                        "📍 *ابعتلي موقعك:*\n" .
                        "• موقع GPS 🗺️\n" .
                        "• أو اكتب منطقتك وأقرب معلم ✍️"
                    );
                    return;
                }
                if (mb_strlen($customIssue) < 3 || ctype_digit($customIssue)) {
                    $this->reply($phone, $convId, "⚠️ وصف المشكلة قصير شوي، قلنا أكثر قليلاً 🙏");
                    return;
                }
                $device = (string)($draft['device_type'] ?? 'الجهاز');
                // ✅ فقط نتحقق منطقياً — بدون isOfftopicReply ولا answerWithAI (هما سبب الهلوسة)
                if (!$this->isIssueLogicalAI($device, $customIssue)) {
                    // ✅ قبل ما نرفضها كوصف مشكلة، نتأكد إنها مش سؤال جانبي (لوكيشن، قطع، إلخ)
                    // — إذا كانت سؤال، نجاوب عليه ونرجعله لنفس سؤال المشكلة
                    if ($this->handleKhaledInterruption($phone, $convId, $text, $state, $draft)) return;
                    $rejectExamples = $this->getDeviceIssueExamples($device);
                    $this->reply($phone, $convId,
                        "⚠️ هالوصف ما يبدو منطقي لـ *{$device}* 😅\n" .
                        "مثال: {$rejectExamples}\n\n" .
                        "اكتب المشكلة الفعلية 🙏"
                    );
                    return;
                }
                $draft['issue_id'] = null;
                $draft['issue']    = $customIssue;
                $this->updateConvState($convId, self::STATE_MAINT_LOCATION, $draft);
                $this->reply($phone, $convId,
                    "✅ فاهمين المشكلة!\n\n" .
                    "📍 *ابعتلي موقعك:*\n" .
                    "• موقع GPS 🗺️\n" .
                    "• أو اكتب منطقتك وأقرب معلم ✍️"
                );
                return;

            case self::STATE_MAINT_LOCATION:
                $location = trim($text);
                if (mb_strlen($location) < 3) {
                    $this->reply($phone, $convId, "⚠️ اكتب منطقتك وأقرب معلم، أو ابعت موقع GPS 🗺️");
                    return;
                }
                // ✅ لو الزبون سأل سؤال جانبي بدل ما يبعت الموقع → نجاوبه ونرجعله لنفس السؤال
                if ($this->handleKhaledInterruption($phone, $convId, $text, $state, $draft)) return;
                // ✅ أي موقع معقول يُقبل مباشرة — النظام يفحص إذا هو في الأردن
                $areaType = $this->detectServiceArea($location);

                if (!$this->lastLocationIsJordan) {
                    $this->reply($phone, $convId,
                        "⚠️ خدمة الصيانة متوفرة داخل *الأردن* فقط 🇯🇴\n" .
                        "اكتب عنوانك داخل الأردن."
                    );
                    return;
                }

                if ($areaType === 'parts') {
                    $this->updateConvState($convId, self::STATE_IDLE, []);
                    $this->reply($phone, $convId,
                        "⚠️ عذراً، خدمة الصيانة المنزلية متوفرة في *عمان والسلط والزرقاء* فقط حالياً.\n\n" .
                        "بس عندنا *قطع غيار* لكل محافظات المملكة! 📦\n" .
                        "للطلب اتصل على: 📞 *" . self::SUPPORT_PHONE . "*\n(مكالمة فقط — لا رسائل واتساب)"
                    );
                    return;
                }

                $draft['visit_price']        = ($areaType === 'zarqa') ? 15 : 10;
                $draft['location']           = $location;
                $draft['location_is_jordan'] = true;
                $this->finalizeOrAskContactPhone($phone, $convId, $custId, $draft);
                return;

            case self::STATE_MAINT_CONTACT_PHONE:
                $this->handleContactPhoneState($phone, $convId, $custId, $text, $draft);
                return;

            case self::STATE_WAITING_TRACK_NO:
                // ✅ تتبع الطلب بـ 5 حالات بترتيب واضح:
                // 1) رقم طلب SR-...          → الطلب المطابق لهاد الرقم بالضبط
                // 2) "ما بعرف"                → أحدث طلب لصاحب المحادثة نفسه
                // 3) رقم هاتف (7-15 رقم)      → أحدث طلب مسجل على هاد الرقم (ممكن يكون شخص تاني)
                // 4) اسم الزبون               → أحدث طلب مطابق للاسم ضمن طلبات صاحب المحادثة
                // 5) نص فيه اسم جهاز/مشكلة    → دور بطلبات صاحب المحادثة نفسه على تطابق اسم الجهاز
                // ✅ بكل حالة نجاح، نبعت تنبيه لمعاذ إنه الزبون رجع يسأل عن طلبه —
                // ممكن يكون ما تابعه لهلق، فبيصير تذكير مفيد مش بس معلومة.
                $this->updateConvState($convId, self::STATE_IDLE, []);
                $input = strtoupper(trim($text));

                // ١. رقم طلب SR-
                if (preg_match('/SR-\d{4}-\d+/i', $input, $m)) {
                    $req = $this->findRequestByNumber($m[0]);
                    if ($req) {
                        $this->sendTrackingInfo($phone, $convId, $req);
                        $this->notifyAdmins($this->buildTrackingFoundMsg($phone, $req, 'برقم الطلب'));
                        return;
                    }
                    $this->reply($phone, $convId, "❌ ما لقيت طلب بالرقم *{$m[0]}*، تأكد منه وحاول مرة ثانية.");
                    return;
                }

                // ٢. "ما بعرف" → أحدث طلب لصاحب المحادثة
                if ($this->isUnknownBrand($text)) {
                    $latest = $this->findLastRequestByCustomer($custId);
                    if ($latest) {
                        $this->sendTrackingInfo($phone, $convId, $latest);
                        $this->notifyAdmins($this->buildTrackingFoundMsg($phone, $latest, 'أحدث طلب له'));
                        return;
                    }
                    $this->reply($phone, $convId, "ما لقيت طلبات مسجلة عندك 🧐");
                    return;
                }

                // ٣. رقم هاتف (7-15 رقم متتالي) → أحدث طلب مسجل على هاد الرقم
                $digitsOnly = preg_replace('/\D/', '', $text);
                if (preg_match('/^\d{7,15}$/', $digitsOnly)) {
                    $byPhone = $this->findLastRequestByCustomerPhone($digitsOnly);
                    if ($byPhone) {
                        $this->sendTrackingInfo($phone, $convId, $byPhone);
                        $this->notifyAdmins($this->buildTrackingFoundMsg($phone, $byPhone, "برقم هاتف ({$digitsOnly})"));
                        return;
                    }
                    $this->reply($phone, $convId, "❌ ما لقيت طلب مسجل على الرقم *{$digitsOnly}*، رح أحوّل استفسارك لمعاذ 🙏");
                    $this->escalateToOwner($phone, $convId, $this->buildTrackingEscalationMsg($phone, $text), $custId, null, self::BOT_KHALED);
                    return;
                }

                // ٤. اسم الزبون (زي ما كتبه هو وقت تسجيل الطلب) → أحدث طلب مطابق للاسم
                // ✅ بس لو النص فعلاً يشبه اسم (مو رقم ومو جملة طويلة كأنها وصف عطل)
                $possibleName = trim($text);
                if (!ctype_digit(preg_replace('/\s/', '', $possibleName)) && mb_strlen($possibleName) >= 2 && mb_strlen($possibleName) <= 60) {
                    $byName = $this->findRequestByCustomerName($custId, $possibleName);
                    if ($byName) {
                        $this->sendTrackingInfo($phone, $convId, $byName);
                        $this->notifyAdmins($this->buildTrackingFoundMsg($phone, $byName, "بالاسم ({$possibleName})"));
                        return;
                    }
                }

                // ٥. بحث باسم الجهاز/المشكلة ضمن طلبات صاحب المحادثة نفسه
                $deviceMatch = $this->findRequestByDeviceName($custId, $text);
                if ($deviceMatch) {
                    $this->sendTrackingInfo($phone, $convId, $deviceMatch);
                    $this->notifyAdmins($this->buildTrackingFoundMsg($phone, $deviceMatch, "باسم الجهاز ({$text})"));
                    return;
                }

                // ٦. ما انطبقت ولا حالة → اعرض كل طلبات صاحب المحادثة كملاذ أخير
                $allRequests = $this->findAllRequestsByCustomer($custId);
                if (!empty($allRequests)) {
                    if (count($allRequests) === 1) {
                        $this->sendTrackingInfo($phone, $convId, $allRequests[0]);
                    } else {
                        $msg = "📦 *طلباتك عندنا:*\n\n";
                        foreach ($allRequests as $i => $r) {
                            $status  = $this->mapServiceStatus($r['status']);
                            $device  = $r['device_type'] ?? '';
                            $brand   = $r['device_brand'] ?? '';
                            $label   = trim("$device $brand");
                            $msg    .= ($i + 1) . ". *{$r['order_number']}*";
                            if ($label) $msg .= " — {$label}";
                            $msg    .= "\n    📌 {$status}\n";
                        }
                        $msg .= "\nاكتب رقم الطلب (SR-...) لأشوفلك التفاصيل 📋";
                        $this->reply($phone, $convId, $msg);
                    }
                    $this->notifyAdmins(self::BOT_KHALED . " 📦 *تتبع طلب — الزبون رجع يسأل عن كل طلباته*\n📱 {$phone}\n⚠️ تأكد إنه كل طلباته المفتوحة متابعة.");
                    return;
                }

                // ٥. ما في طلبات إطلاقاً
                $this->reply($phone, $convId, "ما لقيت طلبات مسجلة عندك 🧐\nرح أحوّل استفسارك لمعاذ 🙏");
                $this->escalateToOwner($phone, $convId, $this->buildTrackingEscalationMsg($phone, $text), $custId, null, self::BOT_KHALED);
                return;

            // نقطة 5: عرض أزرار الاختيار
            case self::STATE_WAITING_INQUIRY:
                // ✅ لو كتب نص مباشرة بدل ما يضغط زر → احفظه مباشرة بدون AI بدون قائمة
                $inquiryDirect = trim($text);
                if ($inquiryDirect === '') { $this->reply($phone, $convId, "اكتب استفسارك أو ملاحظتك من فضلك 😊"); return; }
                $this->saveComplaintRecord($convId, $custId, $inquiryDirect, 'inquiry');
                $this->notifyAdmins(self::BOT_KHALED . " 📋 *استفسار/ملاحظة جديدة*\n📱 {$phone}\n📝 {$inquiryDirect}");
                $this->updateConvState($convId, self::STATE_IDLE, []);
                $this->reply($phone, $convId, "✅ وصلنا استفسارك!\nمعاذ رح يرد عليك بأقرب وقت 🙏");
                return;

            // نقطة 5: استفسار أو ملاحظة — بدون AI إطلاقاً
            case self::STATE_WAITING_INQUIRY_TEXT:
                $inquiryText = trim($text);
                if ($inquiryText === '') { $this->reply($phone, $convId, "اكتب استفسارك أو ملاحظتك من فضلك 😊"); return; }
                $this->saveComplaintRecord($convId, $custId, $inquiryText, 'inquiry');
                $this->notifyAdmins(self::BOT_KHALED . " 📋 *استفسار/ملاحظة جديدة*\n📱 {$phone}\n📝 {$inquiryText}");
                $this->updateConvState($convId, self::STATE_IDLE, []);
                $this->reply($phone, $convId, "✅ وصلنا استفسارك!\nمعاذ رح يرد عليك بأقرب وقت 🙏");
                return;

            // ملاحظة أو شكوى — نفس منطق الاستفسار، بدون AI
            case self::STATE_WAITING_NOTE_TEXT:
            case self::STATE_WAITING_COMPLAINT_TEXT:
                $noteText = trim($text);
                if ($noteText === '') { $this->reply($phone, $convId, "اكتب استفسارك أو ملاحظتك من فضلك 😊"); return; }
                $type = ($state === self::STATE_WAITING_COMPLAINT_TEXT) ? 'complaint' : 'note';
                $this->saveComplaintRecord($convId, $custId, $noteText, $type);
                $notifId = $this->saveNoteAsNotification($convId, $custId, $noteText, $phone, self::BOT_KHALED);
                $this->notifyAdmins(self::BOT_KHALED . " 📋 *استفسار/ملاحظة جديدة*\n📱 {$phone}\n📝 {$noteText}");
                $this->notifyOwnerWithCustomerNote($phone, $noteText);
                $this->updateConvState($convId, self::STATE_IDLE, []);
                $this->reply($phone, $convId, "✅ وصلنا استفسارك!\nمعاذ رح يرد عليك بأقرب وقت 🙏");
                return;

            case self::STATE_WAITING_PART_TEXT:
                $partQuery = trim($text);
                // نقطة 2: فهم "ما بعرف اسمها"
                if ($this->isUnknownBrand($partQuery)) {
                    $draft['part_name'] = 'قطعة غير معروفة الاسم';
                    $this->updateConvState($convId, self::STATE_WAITING_PART_IMAGE, $draft);
                    $this->reply($phone, $convId, "مشكلة مش! ابعتلي صورة القطعة وأنا أعرف شو هي 📸");
                    return;
                }
                $draft['part_name'] = $partQuery;
                $this->updateConvState($convId, self::STATE_WAITING_PART_IMAGE, $draft);
                $this->reply($phone, $convId,
                    "📸 ابعتلي صورة القطعة أو الرقم المكتوب عليها وأنا أدور عليها"
                );
                return;

            case self::STATE_WAITING_PART_NAME_FALLBACK:
                $this->handlePartNameFallback($phone, $convId, $text, $custId, $draft);
                return;

            case self::STATE_WAITING_PART_IMAGE:
                $this->reply($phone, $convId, "📸 ابعتلي صورة القطعة من فضلك.");
                return;

            case self::STATE_WAITING_IMAGE_CONFIRM:
                $n = $this->normalizeArabicText(mb_strtolower($text));
                $isYes = preg_match('/(نعم|نهم|اه|أه|تمام|موافق|اوكي|أوكي|يلا|اكيد|أكيد|صح|صحيح)/u', $n);
                $isNo  = preg_match('/(لا|لأ|لاء|غلط|مو هي|مش هي|مو هاي|غير|خطأ)/u', $n);

                if ($isYes) {
                    // ✅ نحافظ على last_part_label/last_part_image/last_part_available بدل ما نمسحهم —
                    // عشان لو الزبون سأل بعدها "كم سعرها؟" أو "بدي اشتريها" يضل السياق موجود
                    $this->updateConvState($convId, self::STATE_IDLE, [
                        self::DRAFT_ACTIVE_BOT => self::BOT_KHALED,
                        'last_part_label'      => $draft['last_part_label']     ?? null,
                        'last_part_image'      => $draft['last_part_image']     ?? ($draft['last_image_path'] ?? null),
                        'last_part_available'  => $draft['last_part_available'] ?? true,
                    ]);
                    $this->reply($phone, $convId, "ممتاز! 🎉 هل تريد الطلب؟ تواصل معنا على *" . self::SUPPORT_PHONE . "* 📞");
                } elseif ($isNo) {
                    $partLabel = $draft['last_part_label'] ?? 'قطعة غيار';
                    $imagePath = $draft['last_part_image'] ?? ($draft['last_image_path'] ?? '');
                    $this->reply($phone, $convId, "عذراً على الخطأ! 🙏 رح أوصّل سؤالك لمعاذ مباشرة.");
                    $notifId = $this->escalateToOwner($phone, $convId, $this->buildPartEscalationMsg($phone, $partLabel, '', false), $custId, null, $botName ?? self::BOT_ABDULRAHMAN);
                    if ($imagePath && file_exists($imagePath)) {
                        $forwardCaption = "🧩 صورة قطعة (اقتراح البوت غلط)\n📱 {$phone}\n🔧 {$partLabel}"
                            . ($notifId ? "\n\nللرد اكتب:\n*{$botName}: رد#{$notifId}: جوابك*" : '');
                        $this->forwardImageToOwners($imagePath, $forwardCaption);
                    }
                    $this->updateConvState($convId, self::STATE_IDLE, [self::DRAFT_ACTIVE_BOT => self::BOT_KHALED]);
                } else {
                    $this->reply($phone, $convId, "رد بـ *نعم* إذا هي القطعة الصح، أو *لا* إذا غلط 😊");
                }
                return;

            case self::STATE_WAITING_PART_DEVICE:
                // الزبون كتب اسم الجهاز بعد ما بعت الصورة — ما نستدعي interruption هون
                $deviceInput = trim($text);
                if (mb_strlen($deviceInput) < 2) {
                    $this->reply($phone, $convId, "اكتب اسم الجهاز من فضلك (مثل: غسالة، ثلاجة، فرن، مكيف، كولر)");
                    return;
                }
                // تأكد إنها اسم جهاز وليست جملة طويلة
                $knownDevices = ['غسالة','ثلاجة','مكيف','فرن','كولر','جلاية','ميكرويف','مكنسة','نشافة','حماصة','صوبة'];
                $norm = $this->normalizeArabicText(mb_strtolower($deviceInput));
                $matchedDevice = '';
                foreach ($knownDevices as $dev) {
                    if (str_contains($norm, $this->normalizeArabicText($dev))) {
                        $matchedDevice = $dev;
                        break;
                    }
                }
                $draft['part_device'] = $matchedDevice ?: $deviceInput;
                $this->updateConvState($convId, self::STATE_IDLE, []);
                $this->processPartImageWithDevice($phone, $convId, $draft);
                return;
        }
    }

    /** يعرض قائمة الأجهزة وينقل الحالة لـ STATE_MAINT_DEVICE — تُستدعى بعد جمع الاسم/الرقم */
    private function showDeviceSelection(string $phone, int $convId, array $draft): void
    {
        $devices = $this->getActiveDeviceTypes();
        if (empty($devices)) {
            $this->reply($phone, $convId, "لا توجد أجهزة متاحة الآن، حاول لاحقاً 🙏");
            $this->updateConvState($convId, self::STATE_IDLE, []);
            return;
        }
        $this->updateConvState($convId, self::STATE_MAINT_DEVICE, $draft);
        $lines = ["🔹 *شو نوع الجهاز؟*\nاكتب الرقم:\n"];
        foreach ($devices as $i => $d) {
            $lines[] = ($i + 1) . ") " . trim((string)($d['icon'] ?? '🔹')) . " {$d['name_ar']}";
        }
        $this->reply($phone, $convId, implode("\n", $lines));
    }

    private function handleKhaledInterruption(
        string $phone,
        int    $convId,
        string $text,
        string $state,
        array  $draft
    ): bool {
        // لو طلب لوكيشن → ابعت الموقع مباشرة وارجعه للخطوة
        $n = $this->normalizeArabicText(mb_strtolower($text));
        $isLocationRequest = str_contains($n, 'وين') || str_contains($n, 'موقع') ||
                             str_contains($n, 'عنوان') || str_contains($text, 'لوكيشن') ||
                             str_contains($n, 'خريطه') || str_contains($n, 'كيف اجي');
        if ($isLocationRequest) {
            $this->sendShopLocation($phone);
            $this->reply($phone, $convId, $this->getFlowReminder($state));
            return true;
        }

        // لو طلب رقم المحل → أعطه الرقم وارجعه للخطوة
        $isPhoneRequest = str_contains($n, 'رقم') || str_contains($n, 'تليفون') ||
                          str_contains($n, 'هاتف') || str_contains($n, 'اتصل') ||
                          str_contains($n, 'تواصل');
        if ($isPhoneRequest) {
            $this->reply($phone, $convId,
                "📞 رقم التواصل: *" . self::SUPPORT_PHONE . "*\n\n" . $this->getFlowReminder($state)
            );
            return true;
        }

        // نقطة 1: GPT يجاوب أي سؤال خارجي ذكي وبعدين يرجع للخطوة
        // ✅ بس لو النص فعلاً شكله سؤال — لتجنب إنه ياخذ إجابة عادية (موقع/ماركة/وصف عطل)
        // ويحاول "يجاوب" عليها بالغلط بدل ما يخليها تكمل بالفلو الطبيعي
        $looksLikeQuestion = str_contains($text, '؟') || (bool)preg_match(
            '/^\s*(وين|ليش|كيف|هل|متى|كم|شو|بكم|فيكم|عندكم|بتبيعوا?|بدي اعرف)\b/u',
            $n
        );
        if (!$looksLikeQuestion) return false;

        $answer = $this->answerGeneralQuestion($text);
        if ($answer === '') {
            $answer = $this->answerWithAI($text);
        }
        if ($answer === '') return false;

        $this->reply($phone, $convId, $answer . "\n\n" . $this->getFlowReminder($state));
        return true;
    }

    private function getFlowReminder(string $state): string
    {
        return match ($state) {
            self::STATE_MAINT_CONFIRM          => "↩️ نرجع لطلبنا — هل تريد المتابعة؟ اكتب *1* للموافقة أو *2* للإلغاء.",
            self::STATE_MAINT_NAME             => "↩️ نرجع — شو اسمك؟",
            self::STATE_MAINT_PHONE_EARLY      => "↩️ نرجع — ابعتلي رقم هاتفك (مثال: 07xxxxxxxx).",
            self::STATE_MAINT_DEVICE           => "↩️ نرجع — ما هو نوع الجهاز الذي يحتاج صيانة؟",
            self::STATE_MAINT_BRAND            => "↩️ نرجع — ما هي ماركة الجهاز؟ (أو اكتب \"ما بعرف\")",
            self::STATE_MAINT_ISSUE_CUSTOM     => "↩️ نرجع — اكتب وصف المشكلة بكلماتك.",
            self::STATE_MAINT_LOCATION         => "↩️ نرجع — أرسل موقعك أو اكتب منطقتك.",
            self::STATE_WAITING_TRACK_NO       => "↩️ نرجع — أرسل رقم الطلب (SR-...) أو اسم الجهاز.",
            self::STATE_WAITING_INQUIRY        => "↩️ نرجع — اكتب استفسارك أو ملاحظتك.",
            self::STATE_WAITING_INQUIRY_TEXT   => "↩️ نرجع — اكتب استفسارك أو ملاحظتك.",
            self::STATE_WAITING_NOTE_TEXT      => "↩️ نرجع — اكتب استفسارك أو ملاحظتك.",
            self::STATE_WAITING_COMPLAINT_TEXT => "↩️ نرجع — اكتب استفسارك أو ملاحظتك.",
            self::STATE_WAITING_PART_TEXT      => "↩️ نرجع — اكتب اسم القطعة أو كودها.",
            self::STATE_WAITING_PART_NAME_FALLBACK => "↩️ نرجع — تعرف اسم القطعة يلي بعتلي صورتها؟ أو اكتب *ما بعرف*.",
            default                            => "كيف يمكنني مساعدتك؟",
        };
    }

    // نقطة 1: جواب ذكي عبر GPT للأسئلة الخارجية
    private function answerWithAI(string $question): string
    {
        if (mb_strlen(trim($question)) < 3) return '';
        try {
            $prompt =
                "أنت خالد، مساعد بوت واتساب لمشغل جنين للصيانة وبيع قطع الغيار في عمان.\n" .
                "الزبون سأل سؤالاً عاماً وسط محادثة خدمة. أجب بشكل قصير ومفيد، بأسلوب عربي بسيط، "
                . "مزيج بين الفصحى والعامية العربية المفهومة لكل الناطقين بالعربية، بدون لهجة محلية مميزة.\n" .
                "لو السؤال ما عندك جواب عليه (مثل: أسعار أجهزة، مواضيع خارج خدمتنا) قل ذلك بلطف.\n" .
                "الجواب يكون جملة أو جملتين فقط.\n\n" .
                "السؤال: {$question}";

            $response = $this->ai->chat([['role' => 'user', 'content' => $prompt]], 200);
            $answer   = trim($response ?? '');
            return ($answer !== '' && mb_strlen($answer) > 5) ? $answer : '';
        } catch (Throwable $e) {
            error_log('[answerWithAI] ' . $e->getMessage());
            return '';
        }
    }

    // نقطة 2: فهم "ما بعرف" بكل أشكالها
    private function isUnknownBrand(string $text): bool
    {
        $n = $this->normalizeArabicText(mb_strtolower(trim($text)));
        $unknownPhrases = [
            'لا اعرف', 'ما اعرف', 'مش عارف', 'مش عارفه', 'ما بعرف', 'ما بحكم',
            'مو عارف', 'مو عارفه', 'بدون', 'بدون ماركه', 'بدون اسم',
            'لا', 'no', 'no idea', 'idk', 'unknown', 'مجهول', 'مجهوله',
            'ما ادري', 'ما ادره', 'مادري', 'والله ما بعرف', 'مش فاكر',
            'نسيت', 'ما شايف', 'ما في اسم',
        ];
        foreach ($unknownPhrases as $phrase) {
            if (str_contains($n, $this->normalizeArabicText($phrase))) return true;
        }
        return mb_strlen($n) < 2;
    }

    // نقطة 2: فهم "ما بعرف المشكلة"
    private function isUnknownIssue(string $text): bool
    {
        $n = $this->normalizeArabicText(mb_strtolower(trim($text)));
        $unknownPhrases = [
            'لا اعرف', 'ما اعرف', 'مش عارف', 'ما بعرف', 'بدون', 'مجهول',
            'ما ادري', 'مادري', 'والله ما بعرف', 'مش فاكر', 'ما بحكم',
            'خربان بس ما بعرف', 'شي فيه', 'في مشكله',
        ];
        foreach ($unknownPhrases as $phrase) {
            if (str_contains($n, $this->normalizeArabicText($phrase))) return true;
        }
        return false;
    }

    // نقطة 5: إرسال قائمة نوع الاستفسار (list مثل خالد)
    private function sendInquiryTypeButtons(string $phone, int $convId, array $draft): void
    {
        $sent = false;
        $msg  = "كيف يمكننا مساعدتك؟ اختر نوع تواصلك:";
        try {
            $this->wa->sendList(
                $phone,
                $msg,
                "☰ نوع التواصل",
                [[
                    'title' => 'تواصل معنا',
                    'rows'  => [
                        ['id' => 'btn_inquiry_q',    'title' => '📋 استفسار',          'description' => 'سؤال أو استفسار عام'],
                        ['id' => 'btn_inquiry_note', 'title' => '💬 ملاحظة أو اقتراح', 'description' => 'ملاحظة أو اقتراح أو مقترح'],
                    ],
                ]],
                STORE_NAME
            );
            $sent = true;
            $this->saveMessage($convId, 'bot', 'text', $msg . ' [قائمة استفسار/ملاحظة]');
        } catch (Throwable $e) {
            error_log('[sendInquiryTypeButtons] ' . $e->getMessage());
        }

        if (!$sent) {
            $fallback = $msg . "\n\n1) 📋 استفسار\n2) 💬 ملاحظة أو اقتراح";
            try { $this->wa->sendText($phone, $fallback); } catch (Throwable) {}
            $this->saveMessage($convId, 'bot', 'text', $fallback);
        }
    }

    // نقطة 5: حفظ السجل بنوعه
    private function saveComplaintRecord(int $convId, int $custId, string $text, string $type): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO complaints (conversation_id, customer_id, complaint_text, status, complaint_type) VALUES (?, ?, ?, 'new', ?)"
            )->execute([$convId, $custId, $text, $type]);
        } catch (Throwable $e) {
            // لو ما في عمود complaint_type → نحفظ بدونه
            try {
                $this->db->prepare(
                    "INSERT INTO complaints (conversation_id, customer_id, complaint_text, status) VALUES (?, ?, ?, 'new')"
                )->execute([$convId, $custId, $text]);
            } catch (Throwable $e2) {
                error_log('[saveComplaintRecord] ' . $e2->getMessage());
            }
        }
    }

    // نقطة 4: كل طلبات الزبون
    private function findAllRequestsByCustomer(int $custId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT sr.*, dt.name_ar as device_type
                FROM service_requests sr
                LEFT JOIN device_types dt ON sr.device_type_id = dt.id
                WHERE sr.customer_id = ?
                ORDER BY sr.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$custId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[findAllRequestsByCustomer] ' . $e->getMessage());
            return [];
        }
    }

    // نقطة 4: بحث باسم الجهاز
    /**
     * يبني رسالة تصعيد واضحة لمعاذ لما زبون يسأل عن تتبع طلب وما نلقى إشي.
     * لازم توصله: شو استفسر عنه الزبون بالضبط، رقمه، وقت الاستفسار، وتنويه
     * صريح إنه ما رح تصير متابعة تلقائية من طرف النظام — لازم هو يتواصل يدوياً.
     */
    private function buildTrackingEscalationMsg(string $phone, string $customerInput): string
    {
        return "🔍 *استفسار تتبع طلب — لم يتم إيجاد أي طلب*\n"
            . "📱 رقم الزبون: {$phone}\n"
            . "🕐 وقت الاستفسار: " . date('Y-m-d H:i') . "\n"
            . "📝 الزبون كتب: \"{$customerInput}\"\n\n"
            . "⚠️ ما عنا طلب صيانة مسجل باسم هاد الزبون أو رقمه — "
            . "يرجى التواصل معه مباشرة، لأنه ما رح تصير أي متابعة تلقائية من طرف النظام لهاد الاستفسار.";
    }

    /**
     * يبني رسالة تنبيه لمعاذ لما زبون يرجع يسأل/يتبع طلب *موجود فعلاً*.
     * الهدف تذكير معاذ إنه هاد الطلب لسا حي بذهن الزبون — يمكن ما انتبهله أو ما تابعه.
     */
    private function buildTrackingFoundMsg(string $phone, array $req, string $matchedBy): string
    {
        $status = $this->mapServiceStatus((string)($req['status'] ?? ''));
        $device = trim((string)($req['product_name'] ?? '') ?: trim(
            (string)($req['device_type'] ?? '') . ' ' . (string)($req['device_brand'] ?? '')
        ));
        $brand = trim((string)($req['device_brand'] ?? ''));
        return "📦 *الزبون رجع يتبع طلبه*\n"
            . "📱 رقم الزبون: {$phone}\n"
            . "🔢 رقم الطلب: {$req['order_number']}\n"
            . ($device !== '' ? "🖥️ {$device}" . ($brand !== '' ? " — {$brand}" : '') . "\n" : '')
            . "📌 الحالة الحالية: {$status}\n"
            . "🔍 دور عليه: {$matchedBy}\n\n"
            . "⚠️ الزبون يهتم بمتابعة هاد الطلب — تأكد إنه ما وقف بدون متابعة.";
    }

    /**
     * بحث بالاسم — يدور بين طلبات صاحب المحادثة نفسه (custId) على تطابق جزئي
     * مع customer_name (زي ما كتبه الزبون وقت تسجيل الطلب). بيرجع أحدث طلب مطابق.
     */
    private function findRequestByCustomerName(int $custId, string $text): ?array
    {
        $n = $this->normalizeArabicText(mb_strtolower(trim($text)));
        if (mb_strlen($n) < 2) return null;
        try {
            $stmt = $this->db->prepare("
                SELECT sr.*, dt.name_ar as device_type
                FROM service_requests sr
                LEFT JOIN device_types dt ON sr.device_type_id = dt.id
                WHERE sr.customer_id = ? AND sr.customer_name IS NOT NULL AND sr.customer_name <> ''
                ORDER BY sr.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$custId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $nameNorm = $this->normalizeArabicText(mb_strtolower((string)$row['customer_name']));
                if ($nameNorm !== '' && (str_contains($nameNorm, $n) || str_contains($n, $nameNorm))) {
                    return $row;
                }
            }
        } catch (Throwable $e) {
            error_log('[findRequestByCustomerName] ' . $e->getMessage());
        }
        return null;
    }

    private function findRequestByDeviceName(int $custId, string $text): ?array
    {
        $n = $this->normalizeArabicText(mb_strtolower(trim($text)));
        if (mb_strlen($n) < 3) return null;
        try {
            // ✅ sr.device_brand موجود فعلياً كعمود بجدول service_requests (يُملأ من الآن فصاعداً
            // من finalizeMaintenanceRequest) — قبل هيك كان الكود بيحاول يقرأه من الـ SELECT
            // بدون ما يجيبه أصلاً، فمطابقة الماركة كانت دايماً فاضية.
            $stmt = $this->db->prepare("
                SELECT sr.*, dt.name_ar as device_type
                FROM service_requests sr
                LEFT JOIN device_types dt ON sr.device_type_id = dt.id
                WHERE sr.customer_id = ?
                ORDER BY sr.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$custId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $deviceNorm = $this->normalizeArabicText(mb_strtolower((string)($row['device_type'] ?? '')));
                $brandNorm  = $this->normalizeArabicText(mb_strtolower((string)($row['device_brand'] ?? '')));
                if (str_contains($deviceNorm, $n) || str_contains($n, $deviceNorm) ||
                    ($brandNorm && str_contains($brandNorm, $n))) {
                    return $row;
                }
            }
        } catch (Throwable $e) {
            error_log('[findRequestByDeviceName] ' . $e->getMessage());
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  عبدالرحمن — بوت المحادثة العامية الحرة
    // ═══════════════════════════════════════════════════════════════
    private function matchDeviceFromText(string $text, array $devices): ?array
    {
        $text = trim($text);

        // رقم مباشر
        if (ctype_digit($text)) {
            $idx = (int)$text - 1;
            return $devices[$idx] ?? null;
        }

        $norm = $this->normalizeArabicText(mb_strtolower($text));

        // مطابقة تامة بعد التطبيع
        foreach ($devices as $d) {
            if ($this->normalizeArabicText(mb_strtolower((string)$d['name_ar'])) === $norm) return $d;
        }

        // مطابقة جزئية — الاسم موجود في النص أو العكس
        foreach ($devices as $d) {
            $dNorm = $this->normalizeArabicText(mb_strtolower((string)$d['name_ar']));
            if (str_contains($norm, $dNorm) || str_contains($dNorm, $norm)) return $d;
        }

        // مطابقة بأول 3 أحرف (للأسماء القصيرة مثل: فرن، فون، ثلج...)
        foreach ($devices as $d) {
            $dNorm = $this->normalizeArabicText(mb_strtolower((string)$d['name_ar']));
            $prefix = mb_substr($dNorm, 0, 3);
            if (mb_strlen($prefix) >= 3 && str_contains($norm, $prefix)) return $d;
        }

        return null;
    }

    /**
     * قائمة أعطال شائعة لكل نوع جهاز — تُستخدم لعرض أمثلة للزبون (getDeviceIssueExamples)
     * وأيضاً كـ "طبقة أمان" سريعة قبل استدعاء الـ AI (isIssueLogicalAI):
     * لو وصف الزبون قريب لفظياً من أحد الأعطال المعروفة لجهازه، نقبله فوراً
     * بدون المخاطرة بهلوسة الموديل على وصف طبيعي جداً وشائع.
     */
    private function deviceIssueKeywordMap(): array
    {
        return [
            'فرن'       => ['سخن', 'يحرق', 'شواي', 'شعل', 'ولع'],
            'غسالة'     => ['غسل', 'سرب', 'دور', 'عبي', 'نشف', 'صوت', 'شغال'],
            'ثلاجة'     => ['برد', 'صوت', 'جمد', 'تجمد', 'تسرب', 'شغال'],
            'مكيف'      => ['برد', 'طفي', 'سرب', 'صوت', 'دفي', 'شغال'],
            'كولر'      => ['برد', 'شغال', 'صوت'],
            'جلاية'     => ['غسل', 'سرب', 'وسخ', 'شغال'],
            'ميكرويف'   => ['سخن', 'دوار', 'شغال'],
            'مجفف'      => ['نشف', 'طفي', 'صوت', 'شغال'],
            'نشافة'     => ['نشف', 'صوت', 'شغال'],
            'صوبة'      => ['حرار', 'شعل', 'ولع', 'شغال'],
            'حماصة'     => ['حمص', 'حرق', 'شغال'],
            'مكنسة'     => ['شفط', 'صوت', 'شغال'],
        ];
    }

    /**
     * فحص سريع محلي (بدون AI) — لو وصف العطل يحتوي كلمة مفتاحية مرتبطة عادةً
     * بجهاز الزبون (مثلاً "غسل" لغسالة، أو "برد" لثلاجة)، نعتبره منطقي فوراً.
     * هذا يحمي من حالات هلوسة الـ AI اللي بترفض أعطال طبيعية جداً وشائعة
     * (زي "ما بتغسل" لغسالة) لمجرد إنها "بديهية أو تكرر اسم الجهاز".
     */
    private function isIssueObviouslyValid(string $device, string $issue): bool
    {
        $deviceNorm = $this->normalizeArabicText(mb_strtolower(trim($device)));
        $issueNorm  = $this->normalizeArabicText(mb_strtolower(trim($issue)));
        foreach ($this->deviceIssueKeywordMap() as $key => $keywords) {
            if (!str_contains($deviceNorm, $this->normalizeArabicText($key))) continue;
            foreach ($keywords as $kw) {
                if (str_contains($issueNorm, $this->normalizeArabicText($kw))) return true;
            }
        }
        return false;
    }

    /**
     * فحص منطقية العطل للجهاز — أولاً فحص سريع محلي (isIssueObviouslyValid) للأعطال
     * الشائعة الواضحة، وإذا لم يُحسم، نلجأ إلى OpenAI كحكم أخير للحالات الغامضة فقط.
     */
    private function getDeviceIssueExamples(string $device): string
    {
        $norm = $this->normalizeArabicText(mb_strtolower(trim($device)));
        $map = [
            'فرن'       => 'الفرن ما يسخّن، الفرن بيحرق الأكل من تحت، الشواية ما بتشتغل',
            'غسالة'     => 'الغسالة بتسرب مي، الغسالة ما بتدور، الغسالة بتعبي في النشيف',
            'ثلاجة'     => 'الثلاجة ما بتبرد، الثلاجة بتعمل صوت، المجمد ما بيجمّد',
            'مكيف'      => 'المكيف ما يبرد، المكيف يطفي لحاله، المكيف بيسرب مي',
            'كولر'      => 'الكولر ما يبرد، الكولر ما بيشتغل، الكولر بيعمل صوت',
            'جلاية'     => 'الجلاية ما بتغسل، الجلاية بتسرب مي، الصحون بتطلع وسخه',
            'ميكرويف'   => 'الميكرويف ما بيسخّن، الميكرويف بيشتغل بدون ما يسخّن، الدوارة ما بتتحرك',
            'مجفف'      => 'المجفف ما بينشف، المجفف يطفي لحاله، المجفف بيعمل صوت',
            'نشافة'     => 'النشافة ما بتنشف، النشافة بتعمل صوت، النشافة ما بتشتغل',
            'صوبة'      => 'الصوبة ما بتشتغل، الصوبة ما بتعطي حرارة، الشعلة ما بتولع',
            'حماصة'     => 'الحماصة ما بتحمص، الحماصة بتحرق الخبز، الحماصة ما بتشتغل',
            'مكنسة'     => 'المكنسة ما بتشفط، المكنسة بتعمل صوت، المكنسة ما بتشتغل',
        ];
        $norm2 = $norm;
        foreach ($map as $key => $examples) {
            if (str_contains($norm2, $this->normalizeArabicText($key))) {
                return $examples;
            }
        }
        // fallback عام
        return "الجهاز ما بيشتغل، بيعمل صوت غريب، ما بيكمل دورته";
    }

    private function isIssueLogicalAI(string $device, string $issue): bool
    {
        // ✅ طبقة أمان أولى: لو الوصف واضح ومرتبط بكلمة مفتاحية معروفة لهذا الجهاز
        // (زي "غسل" لغسالة، "برد" لثلاجة) نقبله فوراً بدون استدعاء الـ AI أصلاً —
        // هاد بيمنع رفض أعطال بديهية جداً وشائعة بالغلط (مثال حقيقي واجهناه:
        // "غسالة" + "ما بتغسل" أو "بتعبي بتنشيف" انرفضوا غلط من الـ AI رغم إنهم
        // نفس الأمثلة اللي إحنا أنفسنا منقترحها للزبون بـ getDeviceIssueExamples).
        if ($this->isIssueObviouslyValid($device, $issue)) {
            return true;
        }
        try {
            $answer = trim((string)$this->ai->chat([
                ['role' => 'system', 'content' =>
                    "أنت فني أجهزة منزلية خبير. مهمتك فقط: الحكم هل يمكن منطقياً أن "
                    . "يحدث هذا العطل المذكور لهذا الجهاز بالتحديد، بناءً على معرفتك "
                    . "الفنية بكيف يعمل هذا النوع من الأجهزة (مثلاً: الثلاجة للتبريد، "
                    . "الفرن للتسخين، الغسالة والجلاية للماء، المكيف للتبريد/التدفئة). "
                    . "مهم جداً: إعادة صياغة وظيفة الجهاز الأساسية بصيغة النفي "
                    . "(مثل \"الغسالة ما بتغسل\" أو \"الثلاجة ما بتبرد\") هي دائماً "
                    . "وصف منطقي وصحيح — هذا أشيع نوع شكوى عن أي جهاز، وليس غموضاً. "
                    . "لا تعتبره غير منطقي لمجرد أنه بسيط أو بديهي أو يكرر اسم وظيفة الجهاز. "
                    . "ارفض (INVALID) فقط إذا كان الوصف يذكر بوضوح وظيفة جهاز مختلف "
                    . "تماماً (مثل: ذكر \"تنظيف صحون\" لثلاجة، أو \"تبريد\" لفرن). "
                    . "عند أي شك، رجّح VALID. "
                    . "رد بكلمة واحدة فقط: VALID أو INVALID."
                ],
                ['role' => 'user', 'content' => "الجهاز: {$device}\nالعطل المذكور: {$issue}"],
            ], temp: 0.0, maxTokens: 10));
            return !str_contains(strtoupper($answer), 'INVALID');
        } catch (Throwable $e) {
            // في حال فشل الاتصال بالـ AI، لا نرفض الزبون — نمرر الطلب لمراجعة معاذ بدلاً من حظره

            return true;
        }
    }

}
