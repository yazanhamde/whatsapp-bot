<?php

/**
 * MediaHandlerTrait — معالجة الصور والصوت وصور القطع
 *
 * تحتوي على: handleOwnerPartImage, sendTopPartsInquiries,
 *  handlePartImage, processPartImageWithDevice, routeClassifiedImage,
 *  handleDeviceImage, handleDeviceImageIntent, handleAdsImage,
 *  handleBankImage, handleImageMessage
 *
 * تعتمد على: ConversationTrait + OwnerNotifyTrait
 */
trait MediaHandlerTrait
{
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

            $products = [];
            if ($modelCode)  $products = $this->ps->searchByCode($modelCode);
            if (empty($products) && $partNameAr) $products = $this->ps->searchExact($partNameAr);
            if (empty($products) && $partNameEn) $products = $this->ps->searchExact($partNameEn);
            if (empty($products) && $partNameAr) $products = $this->ps->search($partNameAr);
            if (empty($products) && $partNameEn) $products = $this->ps->search($partNameEn);

            if (!empty($products)) {
                $msg2 = $identified . "━━━━━━━━━━━━━━\n📦 *النتائج بالمخزن:*\n\n";
                foreach ($products as $p) {
                    $qty  = (int)($p['quantity'] ?? 0);
                    $icon = $qty > 0 ? '✅' : '❌';
                    $msg2 .= "{$icon} " . ($p['name'] ?? '') . " — *{$qty}* حبة\n";
                    if (!empty($p['code'])) $msg2 .= "    كود: {$p['code']}\n";
                }
                $this->wa->sendText($phone, $msg2);
            } else {
                $partLabel = $partNameAr ?: ($partNameEn ?: $modelCode);
                $this->wa->sendText($phone, $identified . "━━━━━━━━━━━━━━\n❌ هذه القطعة (*{$partLabel}*) غير موجودة بالمخزن.");
            }
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

    private function handlePartImage(string $phone, int $convId, string $mediaId, array $draft, ?string $preDownloadedPath = null): void
    {
        $botName = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_MOHAMMAD;

        try {
            $imagePath = $preDownloadedPath ?: $this->wa->downloadMedia($mediaId);
            if (!$imagePath) {
                $this->reply($phone, $convId, "❌ ما قدرت أحمّل الصورة، ابعتها مرة ثانية 🙏");
                return;
            }

            // احفظ الصورة في الـ draft واسأل عن الجهاز
            $draft['part_image_path']      = $imagePath;
            $draft['part_image_id']        = $mediaId;
            $draft[self::DRAFT_ACTIVE_BOT] = self::BOT_ABDULLAH; // ✅ نفس إصلاح STATE_WAITING_IMAGE_CONFIRM — لازم لتوصيل ردّ اسم الجهاز للمعالج الصحيح
            $this->updateConvState($convId, self::STATE_WAITING_PART_DEVICE, $draft);
            $this->reply($phone, $convId,
                "✅ وصلتني الصورة!\n\n" .
                "🔧 *شو نوع الجهاز؟*\n" .
                "مثال: غسالة، ثلاجة، مكيف، فرن، كولر، جلاية، ميكرويف"
            );

        } catch (Throwable $e) {
            error_log("[handlePartImage] " . $e->getMessage());
            $this->reply($phone, $convId, "عذراً، صار خطأ أثناء تحميل الصورة 🙏");
            $this->updateConvState($convId, self::STATE_IDLE, []);
        }
    }

    private function processPartImageWithDevice(string $phone, int $convId, array $draft): void
    {
        $botName   = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_MOHAMMAD;
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

            $products = $this->ps->searchByImageWithDevice($imageData, $mimeType, $device);

            if (!empty($products)) {
                foreach ($products as $product) {
                    $this->sendProductImageResult($phone, $convId, $product);
                }
                // ✅ نبعت صورة القطعة لرمزي كمان (سواء متوفرة أو لأ) — عشان يعرف شو
                // هي القطعة يلي الزبون بيسأل عنها، مو بس نص إشعار بدون صورة.
                $namesLabel = implode('، ', array_map(fn($p) => $p['name'] ?? 'قطعة', $products));
                $availLabel = implode('، ', array_map(fn($p) => ((int)($p['quantity'] ?? 0) > 0) ? '✅ متوفرة' : '❌ غير متوفرة', $products));
                $this->forwardImageToOwners($imagePath, "{$botName} 🖼️ *استفسار صورة قطعة*\n📱 {$phone}\n🔧 {$device}\n🧩 {$namesLabel}\n📦 {$availLabel}");
                $this->notifyAdmins("{$botName} 🖼️ *استفسار صورة + جهاز*\n📱 {$phone}\n🔧 {$device}\n✅ " . count($products) . " نتيجة");

                // ✅ نحفظ آخر قطعة سأل عنها الزبون — عشان لو سأل بعدها عن السعر أو بدو يشتريها نعرف نربطها
                $draft['last_part_label']     = trim("$namesLabel — $device");
                $draft['last_part_image']     = $imagePath;
                $draft['last_part_available'] = str_contains($availLabel, '✅');
            } else {
                $label = trim("$partName $device");
                $conv    = $this->getConversation($convId);
                $custId  = (int)($conv['customer_id'] ?? 0);
                $this->reply($phone, $convId, "❌ ما لقينا هالقطعة بمخزوننا حالياً.\nرح أوصّل سؤالك لفريق الصيانة 🙏");
                $notifId = $this->escalateToOwner($phone, $convId, $this->buildPartEscalationMsg($phone, $label, $device), $custId, null, $botName);
                $forwardCaption = "🧩 صورة قطعة (غير موجودة بالمخزن)\n📱 {$phone}\n🔧 {$label}"
                    . ($notifId ? "\n\nللرد اكتب:\n*{$botName}: رد#{$notifId}: جوابك*" : '');
                $this->forwardImageToOwners($imagePath, $forwardCaption);
                $this->ps->logUnresolvedImage($notifId, $convId, $custId, $imagePath, [], 'part');
                $draft['last_part_label']     = $label;
                $draft['last_part_image']     = $imagePath;
                $draft['last_part_available'] = false;
            }
            // ✅ خلّص الفلو — رجّع idle بس خلي الزبون مرتبط بعبدالله (بوت القطع)، واحتفظ بسياق آخر قطعة
            $this->updateConvState($convId, self::STATE_IDLE, [
                self::DRAFT_ACTIVE_BOT => self::BOT_ABDULLAH,
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
     * bank → إشعار فوري لرمزي + رد للزبون، بدون أي تحليل إضافي للصورة
     */
    private function routeClassifiedImage(
        string $phone, int $convId, string $mediaId, string $caption,
        int $custId, array $draft, string $imagePath
    ): void {
        $botName = $draft[self::DRAFT_ACTIVE_BOT] ?? self::BOT_MOHAMMAD;

        try {
            $imageData = base64_encode((string)file_get_contents($imagePath));
            $mimeType  = $this->detectMimeType($imagePath);
            $category  = $this->ps->classifyImageCategory($imageData, $mimeType, $caption);
        } catch (Throwable $e) {
            error_log('[routeClassifiedImage] ' . $e->getMessage());
            $category = 'part'; // fallback آمن — نفس السلوك القديم
        }

        switch ($category) {
            case 'bank':
                $this->handleBankImage($phone, $convId, $custId, $imagePath, $botName);
                return;

            case 'ads':
                $this->handleAdsImage($phone, $convId, $custId, $imagePath, $botName);
                return;

            case 'device':
                $this->handleDeviceImage($phone, $convId, $custId, $draft, $imagePath, $botName);
                return;

            case 'part':
            default:
                $this->handleImageMessage($phone, $convId, $mediaId, $caption, $custId, $draft, $imagePath);
                return;
        }
    }

    /** جهاز كامل بالصورة (مش قطعة مفردة) → نسأل الزبون شو بالضبط بدو */
}
