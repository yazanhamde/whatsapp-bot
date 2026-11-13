<?php

/**
 * MaintenanceFlowTrait — فلو الصيانة وإدارة القطع والتصعيد
 *
 * تحتوي على: finalizeMaintenanceRequest, escalateToOwner,
 *  buildPartEscalationMsg, handlePartNameFallback, escalatePartWithLabel,
 *  processAudio, isSimpleGreeting, isAcknowledgment,
 *  isUnsupportedMediaText, sendWelcome,
 *  notifyRamziWithMissingPart, saveNoteAsNotification,
 *  notifyRamziWithCustomerNote, handleOwnerMessage,
 *  refineOwnerReply, markOwnerAlertsHandled, sendOwnerAnswer,
 *  learnFromImageCorrectionIfApplicable
 *
 * تعتمد على: ConversationTrait + OwnerNotifyTrait
 */
trait MaintenanceFlowTrait
{
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
        $botName      = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_MOHAMMAD;
        $visitPrice   = (int)($draft['visit_price'] ?? self::VISIT_PRICE_MIN);
        $custName     = trim((string)($draft['customer_name']  ?? '')) ?: null;
        $contactPhone = trim((string)($draft['customer_phone'] ?? '')) ?: $phone;

        try {
            $this->db->prepare("
                INSERT INTO service_requests
                (conversation_id, customer_id, customer_name, customer_phone, customer_location,
                 product_name, product_id, device_type_id, issue_id, issue_description, status)
                VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'pending')
            ")->execute([$convId, $custId, $custName, $contactPhone, $location, $deviceFull, $deviceTypeId, $issueId, $issue]);

            $requestId   = (int)$this->db->lastInsertId();
            $orderNumber = $this->buildOrderNumber($requestId);
            $this->db->prepare("UPDATE service_requests SET order_number=? WHERE id=?")->execute([$orderNumber, $requestId]);

            // لو اسم الزبون فاضي بجدول customers، عبّيه من الاسم يلي عطانا ياه هلق
            if ($custName !== null) {
                try {
                    $this->db->prepare(
                        "UPDATE customers SET name=? WHERE id=? AND (name IS NULL OR name='')"
                    )->execute([$custName, $custId]);
                } catch (Throwable $e) {
                    error_log('[finalizeMaintenanceRequest][update customer name] ' . $e->getMessage());
                }
            }

            $this->updateConvState($convId, self::STATE_IDLE, []);

            $mapsUrl = ($lat && $lng) ? "https://maps.google.com/?q={$lat},{$lng}" : null;

            $this->reply($phone, $convId,
                "✅ *تم تسجيل طلبك يا " . ($custName ?? 'صديقنا') . "!*\n\n"
                . "🔢 رقم الطلب: *{$orderNumber}*\n"
                . "👤 الاسم: " . ($custName ?? 'غير محدد') . "\n"
                . "🖥️ الجهاز: {$deviceFull}\n"
                . "⚠️ العطل: {$issue}\n"
                . "📍 العنوان: " . ($mapsUrl ? "GPS 🗺️" : $location) . "\n"
                . "📱 رقم التواصل: {$contactPhone}\n"
                . "💰 رسوم الزيارة: *{$visitPrice} دينار*\n\n"
                . "سيتواصل معك فريق مؤسسة فلسطين قريباً 📞\n"
                . "للاستفسار المباشر: *" . self::SUPPORT_PHONE . "*"
            );

            $ownerMsg = "{$botName} 🔧 *طلب صيانة جديد*\n\n"
                . "🔢 رقم الطلب: *{$orderNumber}*\n"
                . "👤 الاسم: " . ($custName ?? 'غير محدد') . "\n"
                . "📱 رقم التواصل: {$contactPhone}"
                . ($contactPhone !== $phone ? " (واتساب: {$phone})" : '') . "\n"
                . "🖥️ الجهاز: {$deviceFull}\n"
                . "⚠️ العطل: {$issue}\n"
                . "📍 العنوان: {$location}"
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

    private function handleDeviceImage(
        string $phone, int $convId, int $custId, array $draft, string $imagePath, string $botName
    ): void {
        $this->ps->logUnresolvedImage(null, $convId, $custId, $imagePath, [], 'device');
        $draft['device_image_path']    = $imagePath;
        $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
        $this->updateConvState($convId, self::STATE_WAITING_DEVICE_IMG_INTENT, $draft);
        $this->reply($phone, $convId,
            "شكراً على الصورة 📷\nبدك *قطعة غيار* لهالجهاز، ولا عندك *مشكلة/عطل* فيه وحاب صيانة؟"
        );
        $this->forwardImageToOwners($imagePath, "{$botName} 📷 صورة جهاز كامل\n📱 {$phone}\nبانتظار توضيح الزبون (قطعة / عطل)");
    }

    /** رد الزبون بعد سؤال "بدك قطعة ولا عندك مشكلة؟" */
    private function handleDeviceImageIntent(string $phone, int $convId, string $text, int $custId, array $draft): void
    {
        $normalized = $this->normalizeArabicText($text);
        $wantsPart  = (bool)preg_match('/(قطعه|قطعة|غيار)/ui', $normalized);
        $wantsFix   = (bool)preg_match('/(عطل|مشكله|مشكلة|صيانه|صيانة|خربان|خراب)/ui', $normalized);

        if ($wantsPart && !$wantsFix) {
            // نفس مسار "استفسار عن قطعة بالصورة" — الصورة موجودة، بس ناقصنا اسم الجهاز
            $draft['part_image_path']      = $draft['device_image_path'] ?? '';
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULLAH;
            $this->updateConvState($convId, self::STATE_WAITING_PART_DEVICE, $draft);
            $this->reply($phone, $convId, "🔧 *شو نوع الجهاز؟*\nمثال: غسالة، ثلاجة، مكيف، فرن، كولر، جلاية، ميكرويف");
            return;
        }

        if ($wantsFix && !$wantsPart) {
            // نحوّلها لمسار الصيانة العادي عند محمد — نخليه يكمل الأسئلة الاعتيادية (عطل + موقع)
            unset($draft['device_image_path']);
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_MOHAMMAD;
            $this->updateConvState($convId, self::STATE_IDLE, $draft);
            $this->handleMohammad($phone, $convId, $text, $custId, self::STATE_IDLE,
                $this->getConversation($convId) ?? [], $draft);
            return;
        }

        // رد مش واضح → نعيد السؤال بشكل أبسط
        $this->reply($phone, $convId, "تمام، بس وضحلي أكتر 🙏: اكتب *قطعة* إذا بدك تشتري قطعة غيار، أو *عطل* إذا عندك مشكلة بالجهاز.");
    }

    /** سكرين شوت / إعلان / صورة غير متعلقة بأجهزة منزلية */
    private function handleAdsImage(string $phone, int $convId, int $custId, string $imagePath, string $botName): void
    {
        $this->ps->logUnresolvedImage(null, $convId, $custId, $imagePath, [], 'ads');
        $this->reply($phone, $convId,
            "أهلين 👋 إحنا هون مختصين بصيانة الأجهزة المنزلية وقطع الغيار بس.\n" .
            "إذا في جهاز أو قطعة حاب تسأل عنها، ابعتلي صورتها وبساعدك 🙏"
        );
        // إشعار خفيف مع الصورة نفسها (بدون انتظار رد) — لو التصنيف غلط، رمزي بيلاحظها بنفسه
        $this->forwardImageToOwners($imagePath, "{$botName} 📢 صورة غير متعلقة بأجهزة/قطع (سكرين شوت أو إعلان)\n📱 {$phone}");
    }

    /** معلومات مالية/حساسة (حساب بنكي، تحويل، هوية، بطاقة) → تصعيد فوري بدون أي تحليل إضافي */
    private function handleBankImage(string $phone, int $convId, int $custId, string $imagePath, string $botName): void
    {
        $this->ps->logUnresolvedImage(null, $convId, $custId, $imagePath, [], 'bank');
        $this->reply($phone, $convId,
            "هاي الصورة تبدو متعلقة بمعاملات مالية — رح أوصّلها لفريق الصيانة مباشرة، وهم الأنسب يتعاملوا معها 🙏"
        );
        $this->forwardImageToOwners($imagePath, "{$botName} 💳 صورة تبدو متعلقة بمعاملة مالية — يرجى المتابعة\n📱 {$phone}");
    }

    private function handleImageMessage(string $phone, int $convId, string $mediaId, string $caption, int $custId, array $draft, ?string $preDownloadedPath = null): void
    {
        $botName = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_MOHAMMAD;
        $this->reply($phone, $convId, "🔍 جاري البحث عن القطعة في المخزن...");

        try {
            $imagePath = $preDownloadedPath ?: $this->wa->downloadMedia($mediaId);
            if (!$imagePath) throw new RuntimeException("downloadMedia null");

            $imageData = base64_encode((string)file_get_contents($imagePath));
            $mimeType  = $this->detectMimeType($imagePath);

            $keywords  = $this->ps->getImageKeywords($imageData, $mimeType);
            $products  = $this->ps->searchByImage($imageData, $mimeType);
            $analysis  = $this->ps->getLastAnalysis(); // ✅ جيب بيانات التحليل فوراً بعد searchByImage
            $partLabel = !empty($keywords) ? $keywords[0] : ($analysis['part_name'] ?: ($caption ?: 'غير معروف'));

            // ✅ فقط اقترح لو النتيجة جاءت من المقارنة البصرية (visual_verified=true)
            $isVisuallyVerified = !empty($products) && !empty($products[0]['visual_verified']);

            if ($isVisuallyVerified) {
                // نتيجة موثوقة بصرياً → اقترح للزبون
                $this->sendProductImageResult($phone, $convId, $products[0]);
                $this->reply($phone, $convId, "هل هذه القطعة اللي تبحث عنها؟ 🤔\nرد بـ *نعم* إذا صح، أو *لا* إذا غلط وأنا أوصّل سؤالك لفريق الصيانة 🙏");
                $this->forwardImageToOwners($imagePath, "{$botName} 🖼️ *استفسار صورة*\n📱 {$phone}\nالقطعة: {$partLabel}\nاقترح: {$products[0]['name']}");
                // ✅ نحفظ اسم القطعة المقترحة الفعلية (يلي شافها الزبون) مش تصنيف الـ AI العام —
                // عشان لو سأل عن السعر بعدها، رمزي يعرف بالظبط عن أي قطعة يحكي
                $draft['last_part_label']      = $products[0]['name'];
                $draft['last_part_image']      = $imagePath;
                $draft['last_part_available']  = ((int)($products[0]['quantity'] ?? 0)) > 0;
                $draft['last_image_path']      = $imagePath;
                $draft['last_analysis']        = $analysis; // ✅ احفظ للـ state
                $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULLAH;
                $this->updateConvState($convId, self::STATE_WAITING_IMAGE_CONFIRM, $draft);
            } else {
                // ما في تطابق بصري موثوق → قبل ما نصعّد لرمزي، نجرب نسأل الزبون عن اسم القطعة
                // (كتير قطع بتكون مصدية/مكسورة فبيصعب نتعرف عليها بصرياً، بس الزبون بيعرف اسمها)
                $draft['fallback_image_path'] = $imagePath;
                $draft['fallback_part_label'] = $partLabel;
                $draft['fallback_analysis']   = $analysis;
                $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULLAH;
                $this->updateConvState($convId, self::STATE_WAITING_PART_NAME_FALLBACK, $draft);
                $this->reply($phone, $convId,
                    "🔍 ما تأكدت من القطعة بالصورة (ممكن تكون الصورة مش واضحة أو القطعة مستعملة/متأثرة).\n" .
                    "تعرف اسمها أو نوعها؟ (مثلاً: حساس باب، مضخة تصريف، ترموستات...)\n" .
                    "لو ما بتعرف، اكتب *ما بعرف* وأنا أوصّل صورتها لفريق الصيانة مباشرة 🙏"
                );
            }

        } catch (Throwable $e) {
            error_log("[handleImageMessage] " . $e->getMessage());
            $this->reply($phone, $convId, "عذراً، صار خطأ أثناء تحليل الصورة.");
        }
    }

    /** رد الزبون بعد سؤال "تعرف اسم القطعة؟" — يجرب بحث نصي أول، وإلا يصعّد لرمزي مع الصورة + الاسم */
    /**
     * يبني رسالة تصعيد موحّدة لرمزي لما يوصل استفسار عن قطعة (بالنص أو بالصورة)
     * وما نلقاها بالمخزون — نفس أسلوب رسائل تصعيد التتبع، لتناسق أفضل عند رمزي.
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
        // رمزي يتعرف عليها من الصورة. ممنوع نفترض "غير متوفرة" هون، لأنه هاد بيلخبط
        // الذكاء الاصطناعي لما يصيغ رد رمزي لاحقاً (قد يقول "متوفر" ويطلع بالعكس).
        return "🧩 *استفسار عن قطعة — نحتاج تحديد هويتها*\n"
            . "📱 رقم الزبون: {$phone}\n"
            . "🕐 وقت الاستفسار: " . date('Y-m-d H:i') . "\n"
            . $deviceLine
            . "🔍 وصف/تخمين: {$label}\n\n"
            . "⚠️ النظام ما قدر يتأكد من هوية هاي القطعة ولا من توفرها — يرجى معاينة الصورة وإخبارنا شو هي وهل هي متوفرة، للرد على الزبون.";
    }

    private function handlePartNameFallback(string $phone, int $convId, string $text, int $custId, array $draft): void
    {
        $botName   = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_ABDULLAH;
        $imagePath = $draft['fallback_image_path'] ?? '';
        $analysis  = $draft['fallback_analysis']   ?? [];
        $oldLabel  = $draft['fallback_part_label'] ?? 'قطعة غيار';
        $partQuery = trim($text);

        // الزبون ما بيعرف اسمها → صعّد فوراً بدون بحث نصي
        if ($this->isUnknownBrand($partQuery) || mb_strlen($partQuery) < 2) {
            // ✅ الزبون صراحة قال "ما بعرف" — لازم نوضح لرمزي إنه هاد تخمين النظام غير
            // مؤكد ومش اسم أكيد، عشان ما ينضلل ويعتمد عليه وكأنه حقيقة مؤكدة
            $honestLabel = "قطعة غير معروفة (الزبون نفسه ما بيعرف اسمها). تخمين مبدئي غير مؤكد من صورة النظام: {$oldLabel}";
            $this->escalatePartWithLabel($phone, $convId, $custId, $imagePath, $honestLabel, $analysis, $botName);
            return;
        }

        // ✅ نستخدم الاسم كـ"تلميح" مع الصورة نفسها (مو بحث نصي عام) — عشان نرجع نتيجة
        // وحدة موثوقة بصرياً بدل قائمة عامة بكل القطع اللي فيها نفس الكلمة (زي "باب غسالة")
        try {
            $imageData = base64_encode((string)file_get_contents($imagePath));
            $mimeType  = $this->detectMimeType($imagePath);
            $hinted    = $this->ps->searchByImageWithHint($imageData, $mimeType, $partQuery);
        } catch (Throwable $e) {
            error_log('[handlePartNameFallback] ' . $e->getMessage());
            $hinted = [];
        }

        $isVisuallyVerified = !empty($hinted) && !empty($hinted[0]['visual_verified']);

        if ($isVisuallyVerified) {
            $this->sendProductImageResult($phone, $convId, $hinted[0]);
            $this->reply($phone, $convId, "هل هذه القطعة اللي تبحث عنها؟ 🤔\nرد بـ *نعم* إذا صح، أو *لا* إذا غلط وأنا أوصّل سؤالك لفريق الصيانة 🙏");
            $draft['last_part_label']      = $partQuery;
            $draft['last_image_path']      = $imagePath;
            $draft['last_analysis']        = $analysis;
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULLAH;
            $this->updateConvState($convId, self::STATE_WAITING_IMAGE_CONFIRM, $draft);
            return;
        }

        // ما لقينا تطابق واثق حتى مع الاسم → نصعّد لرمزي مع الصورة + الاسم يلي عطاه الزبون
        $this->escalatePartWithLabel($phone, $convId, $custId, $imagePath, $partQuery, $analysis, $botName);
    }

    /** يصعّد صورة قطعة لرمزي مع تسمية واضحة (اسم الزبون أو تحليل البوت) */
    private function escalatePartWithLabel(
        string $phone, int $convId, int $custId, string $imagePath, string $label, array $analysis, string $botName
    ): void {
        $this->reply($phone, $convId, "تمام، رح أوصّل سؤالك لفريق الصيانة مباشرة معاها الصورة 🙏");
        $notifId = $this->escalateToOwner($phone, $convId, $this->buildPartEscalationMsg($phone, $label, '', false), $custId, null, $botName);
        $forwardCaption = "🧩 صورة قطعة (بدون تطابق مؤكد)\n📱 {$phone}\n🔍 وصف الزبون: {$label}"
            . ($notifId ? "\n\nللرد اكتب:\n*{$botName}: رد#{$notifId}: جوابك*" : '');
        $this->forwardImageToOwners($imagePath, $forwardCaption);
        $this->ps->logUnresolvedImage($notifId, $convId, $custId, $imagePath, $analysis);
        // ✅ خلي الزبون مرتبط بعبدالله (بوت القطع) لحد ما رمزي يرد — مش نصفّر البوت النشط،
        // لأنه لو الزبون كتب أي شي قبل رد رمزي، لازم يضل بمسار القطع مش يوقع بمسار الصيانة الافتراضي
        // ✅ ونحفظ سياق آخر قطعة هون كمان — عشان لو الزبون سأل بعدها "كم سعرها؟" (سواء
        // قبل أو بعد رد رمزي) نقدر نكتشفها ونصعّدها بنفس الصورة، بدل ما نفقد السياق.
        $this->updateConvState($convId, self::STATE_IDLE, [
            self::DRAFT_ACTIVE_BOT => self::BOT_ABDULLAH,
            'last_part_label'      => $label,
            'last_part_image'      => $imagePath,
            'last_part_available'  => null, // لسا ما عرفنا، رمزي هو يلي رح يحدد
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
            // عشان رمزي يقدر يسمعه لاحقاً من لوحة التحكم — media_path بيتخزن وبيظل صالح.
            $this->saveMessage($convId, 'customer', 'audio', $transcribed ?: '[صوت]', $path, $transcribed, $waId);
            return $transcribed;
        } catch (Throwable $e) {
            error_log("[processAudio] " . $e->getMessage());
            $this->saveMessage($convId, 'customer', 'audio', '[صوت - فشل النسخ]', null, null, $waId);
            return '';
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
        return false;
    }

    /** ✅ كلمات شكر/إقرار قصيرة ("اوك"، "تمام"، "إن شاء الله") — ما بدها قائمة ترحيب */
    private function isAcknowledgment(string $text): bool
    {
        $norm = $this->normalizeArabicText($text);
        $norm = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $norm));
        if ($norm === '') return false;
        $acks = ['اوك','اوكي','اوكيه','ok','okay','تمام','تمام شكرا','تمام تسلم','ان شاء الله','انشاء الله','ان شاءالله',
                 'شكرا','شكرا لك','شكرا الك','مشكور','يعطيك العافيه','الله يعطيك العافيه','تسلم','تسلمو','يسلمو','ممتاز',
                 'حاضر','ماشي','طيب','زي الفل','بارك الله فيك','الله يبارك فيك','اه','ايوه','تمام ماشي'];
        return in_array($norm, $acks, true);
    }

    /** 📎 رسالة غير نصية (فيديو/ستيكر/ملف...) جاية من الـ webhook بصيغة "[رسالة من نوع X]" */
    private function isUnsupportedMediaText(string $text): bool
    {
        return (bool)preg_match('/^\[رسالة من نوع [^\]]+\]$/u', trim($text));
    }

    // ═══════════════════════════════════════════════════════════════
    //  رسالة الترحيب الموحدة
    // ═══════════════════════════════════════════════════════════════
    private function sendWelcome(string $phone, int $convId): void
    {
        $this->updateConvState($convId, self::STATE_IDLE, []);

        $msg = "أهلاً بكم في *مؤسسة فلسطين لصيانة الأجهزة المنزلية وقطع الغيار* 👋\n"
             . "_(هذا مساعد آلي بالذكاء الاصطناعي 🤖)_\n\n"
             . "اختر من القائمة للتحدث مع *عبدالله* 👇\n"
             . "أو اكتب مباشرة للتحدث مع *محمد* ✍️";

        $sent = false;
        try {
            $this->wa->sendList(
                $phone,
                $msg,
                "☰ خدمات عبدالله",
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
            $this->saveMessage($convId, 'bot', 'text', $msg . ' [قائمة عبدالله]');
        } catch (Throwable $e) {
            error_log("[sendWelcome] sendList failed: " . $e->getMessage());
        }

        if (!$sent) {
            $fallback = $msg . "\n\n1) 🔧 طلب صيانة\n2) 🧩 استفسار عن قطعة\n3) 📦 تتبع طلب\n4) 📝 استفسار";
            try { $this->wa->sendText($phone, $fallback); } catch (Throwable) {}
            $this->saveMessage($convId, 'bot', 'text', $fallback . ' [قائمة عبدالله]');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  عبدالله — بوت الأزرار والفلو المنظم
    // ═══════════════════════════════════════════════════════════════
    public function notifyRamziWithMissingPart(string $phone, string $partName): void
    {
        $msg = "📸 [تنبيه قطعة]: رقم {$phone} طلب \"{$partName}\" وهي غير متوفرة بالمخزن. يرجى المتابعة يا أستاذ رمزي!";
        $this->sendToOwners($msg);
    }

    // ═══════════════════════════════════════════════════════════════
    //  ✅ حفظ الملاحظة كـ notification رسمي عشان رمزي يقدر يرد بـ رد#
    // ═══════════════════════════════════════════════════════════════
    /** يسجّل أنه في زبون سأل عن صيانة ووصل لخطوة التأكيد بس ما أكد بعد (لتقرير رمزي + التذكير التلقائي) */
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

    /**
     * يحفظ ملاحظة الزبون بجدول customer_notes ويبعت إشعار *واحد* بس لرمزي والأدمنز.
     * $adminMessage: نص جاهز (فيه سطر "رد#" إذا في إشعار pending). لو null بنبني النص الافتراضي.
     */
    public function notifyRamziWithCustomerNote(string $phone, string $note, ?string $adminMessage = null): void
    {
        try {
            $this->db->prepare("INSERT INTO customer_notes (phone, message) VALUES (?, ?)")->execute([$phone, $note]);
        } catch (Throwable $e) {
            error_log("[notifyRamziWithCustomerNote] DB error: " . $e->getMessage());
        }
        $this->notifyAdmins($adminMessage ?? "📝 [ملاحظة زبون]: {$phone} قال: \"{$note}\"");
    }

    // ═══════════════════════════════════════════════════════════════
    //  رسالة المالك رمزي
    // ═══════════════════════════════════════════════════════════════
    public function handleOwnerMessage(string $ownerText): void
    {
        $ownerText = trim($ownerText);

        // ✅ الصيغة الكاملة (مفضّلة): "عبدالله: رد#31: جوابك"
        if (preg_match('/^(عبدالله|محمد)\s*:\s*رد#(\d+)\s*:\s*(.+)$/su', $ownerText, $m)) {
            $result = $this->sendOwnerAnswer((int)$m[2], trim($m[3]), botName: trim($m[1]));
            if (!($result['ok'] ?? false)) {
                $this->notifyAdmins("⚠️ ما قدرت أرسل ردك على الإشعار #{$m[2]}: " . ($result['error'] ?? 'خطأ غير معروف'));
            }
            return;
        }

        // ✅ الصيغة المختصرة (بدون اسم البوت — رمزي ناسي يحطه): "رد#31: جوابك"
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
    //  إرسال جواب رمزي للزبون
    // ═══════════════════════════════════════════════════════════════
    /**
     * يعيد صياغة رد رمزي بأسلوب مهذب ومحترم قبل ما يوصل للزبون (للرد المباشر من الداشبورد).
     * المعنى والأرقام والأسعار ثابتة؛ لو فشل الـ AI بيرجع النص الأصلي بدون تغيير.
     */
    public function refineOwnerReply(string $ownerAnswer, string $customerMessage = ''): string
    {
        $system = "أعد صياغة رسالة صاحب المحل (رمزي) كرسالة واتساب مهذبة ومحترمة وودودة للزبون، بأسلوب عربي بسيط "
            . "(مزيج بين الفصحى والعامية العامة)، قصيرة (1-3 جمل). "
            . "🚨 قواعد صارمة: حافظ على المعنى والقرار كما هو تماماً (مثلاً إذا قال تم الإلغاء أو تم التأجيل أو غير متوفر أو موافق، لا تغيّره ولا تعكسه). "
            . "لا تضف أي معلومة أو وعد أو موعد أو سعر من عندك. حافظ على أي رقم أو سعر أو تاريخ أو رقم طلب بالحرف. "
            . "لا تعتذر بدون سبب ولا تضف مقدمات طويلة. اكتب نص الرسالة فقط بدون شرح وبدون علامات اقتباس.";
        try {
            $ctx = $customerMessage !== '' ? "آخر رسالة من الزبون: {$customerMessage}\n" : '';
            $out = trim((string)$this->ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $ctx . "رسالة رمزي: {$ownerAnswer}"],
            ]));
            return $out !== '' ? $out : $ownerAnswer;
        } catch (Throwable $e) {
            error_log('[refineOwnerReply] ' . $e->getMessage());
            return $ownerAnswer;
        }
    }

    /** يعلّم كل إشعارات رمزي المفتوحة لهالزبون إنها "تم الرد عليها" (بيُستدعى بعد أي رد من رمزي) */
    public function markOwnerAlertsHandled(int $convId): void
    {
        try {
            $this->db->prepare(
                "UPDATE owner_alerts SET handled_at = NOW()
                 WHERE handled_at IS NULL
                   AND (conversation_id = ?
                        OR customer_id = (SELECT customer_id FROM conversations WHERE id = ? LIMIT 1))"
            )->execute([$convId, $convId]);
        } catch (Throwable $e) {
            error_log('[markOwnerAlertsHandled] ' . $e->getMessage());
        }
    }

    public function sendOwnerAnswer(int $notificationId, string $ownerAnswer, bool $saveToKb = true, string $botName = ''): array
    {
        $stmt = $this->db->prepare("
            SELECT n.*, c.customer_id, cu.phone
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
        $activeBotName    = $botName ?: ($row['bot_name'] ?? self::BOT_MOHAMMAD);

        $availGuard = " 🚨 مهم جداً: اعتمد فقط على *جواب رمزي* لتحديد هل القطعة متوفرة أو لا — تجاهل تماماً أي كلمة \"غير متوفرة\" أو \"لم نجد\" وردت بنص سؤال الزبون (هاي كانت افتراضات نظام قديمة قبل ما رمزي يرد، مش الحقيقة). إذا رمزي قال \"متوفر\"، ردك لازم يقول متوفر بدون أي لبس أو عكس للمعنى. إذا قال \"غير متوفر\"، ردك يقول غير متوفر. ممنوع تقلب المعنى.";
        $systemMsg = $activeBotName === self::BOT_ABDULLAH
            ? "أعد صياغة جواب رمزي كرسالة واتساب قصيرة ومحترمة. 2-3 جمل. إذا كان سؤال الزبون يذكر اسم قطعة أو منتج معين، اذكر اسمها صراحة بردك (مثل: \"قطعة [الاسم] سعرها كذا دينار\"). حافظ على أي رقم أو سعر ذكره رمزي بالحرف بدون تغيير أو تقريب.{$availGuard}"
            : "أعد صياغة جواب رمزي كرسالة واتساب بأسلوب عربي بسيط، مزيج بين الفصحى والعامية العربية العامة، ودود ولطيف. 2-3 جمل. إذا كان سؤال الزبون يذكر اسم قطعة أو منتج معين، اذكر اسمها صراحة بردك (مثل: \"قطعة [الاسم] سعرها كذا دينار\"). حافظ على أي رقم أو سعر ذكره رمزي بالحرف بدون تغيير أو تقريب.{$availGuard}";

        try {
            $finalReply = trim((string)$this->ai->chat([
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user', 'content' => "سؤال الزبون: $customerQuestion\nجواب رمزي: $ownerAnswer"],
            ]));
        } catch (Throwable $e) { $finalReply = ''; }

        if ($finalReply === '') $finalReply = $ownerAnswer;

        try {
            $this->db->beginTransaction();
            $this->wa->sendText($row['phone'], $finalReply);
            $this->saveMessage((int)$row['conversation_id'], 'bot', 'text', $finalReply);

            // ✅ عبدالله ما إله علاقة بـ KnowledgeBase إطلاقاً (لا قراءة ولا كتابة) —
            // هو بس بيتدرب على صور القطع عبر ProductSearch (نفس آلية محمد). فبس لو
            // السؤال أصلاً كان من محمد منحفظه بقاعدة المعرفة.
            if ($saveToKb && $activeBotName === self::BOT_MOHAMMAD && $customerQuestion && $ownerAnswer) {
                $this->kb->save($customerQuestion, $ownerAnswer, 'owner');
            }

            $this->db->prepare("UPDATE notifications SET owner_answer=?, status='resolved', resolved_at=NOW() WHERE id=?")
                ->execute([$ownerAnswer, $notificationId]);
            $this->db->prepare("UPDATE conversations SET status='bot', last_msg_at=NOW() WHERE id=?")
                ->execute([(int)$row['conversation_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'خطأ في قاعدة البيانات'];
        }

        $this->markOwnerAlertsHandled((int)$row['conversation_id']);

        return ['ok' => true, 'reply' => $finalReply, 'learn' => $this->learnFromImageCorrectionIfApplicable($notificationId, $ownerAnswer)];
    }

    // ═══════════════════════════════════════════════════════════════
    //  تعلّم تلقائي: لو هذا الإشعار كان عن صورة لم يتعرف عليها البوت،
    //  نحاول نطابق جواب رمزي بمنتج حقيقي بالمخزن ونغذّي القاموس/الفهرس
    //  البصري — عشان نفس القطعة تتعرف عليها تلقائياً بالمرة الجاية.
    // ═══════════════════════════════════════════════════════════════
    private function learnFromImageCorrectionIfApplicable(int $notificationId, string $ownerAnswer): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM unresolved_images WHERE notification_id = ? AND status = 'pending' LIMIT 1"
            );
            $stmt->execute([$notificationId]);
            $unresolvedId = $stmt->fetchColumn();
            if (!$unresolvedId) return ['attempted' => false];

            // حاول نطابق جواب رمزي بمنتج حقيقي وواحد بالضبط (تجنّب ربط خاطئ)
            $matches = $this->ps->search($ownerAnswer);

            if (count($matches) === 1) {
                $ok = $this->ps->learnFromCorrection((int)$unresolvedId, (int)$matches[0]['id']);
                if ($ok) {
                    $this->notifyAdmins("🧠 *تعلّم تلقائي*\nتم ربط القطعة بـ \"{$matches[0]['name']}\" — البوت رح يتعرف عليها تلقائياً بالمرة الجاية ✅");
                }
                return ['attempted' => true, 'learned' => $ok, 'product_id' => $matches[0]['id'] ?? null];
            }

            // ما قدرنا نحدد منتج واحد بثقة — اتركها pending لمراجعة يدوية من اللوحة
            return ['attempted' => true, 'learned' => false, 'reason' => count($matches) === 0 ? 'no_match' : 'ambiguous'];

        } catch (Throwable $e) {
            error_log('[learnFromImageCorrectionIfApplicable] ' . $e->getMessage());
            return ['attempted' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  تصعيد للمالك رمزي
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
                    $botName === self::BOT_ABDULLAH
                        ? '📨 سؤالك عند فريق الصيانة وسيرد عليك قريباً، يرجى الانتظار قليلاً 🙏'
                        : 'سؤالك وصل لفريق الصيانة من قبل وسيرد عليك بأسرع وقت 🙏'
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

            $replyText = $botName === self::BOT_ABDULLAH
                ? 'لحظة، سأسأل فريق الصيانة وأرجع إليك ⏳'
                : 'لحظة من فضلك، سأسأل فريق الصيانة وأرجع إليك بأسرع وقت ⏳';

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
}
