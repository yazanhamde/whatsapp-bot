<?php

/**
 * ConversationTrait — الدوال المساعدة الأساسية
 *
 * تحتوي على:
 *  - DB helpers: getOrCreateCustomer, getOrCreateConversation, updateConvState
 *  - Message helpers: saveMessage, reply, safeMarkRead
 *  - AI helpers: callAIWithRetry, cleanJsonOutput, answerGeneralQuestion
 *  - Business helpers: findLastRequest*, getActiveDeviceTypes, getIssuesByDeviceType
 *  - Utility: normalizeDigits, normalizeArabicText, detectMimeType, buildOrderNumber, mapServiceStatus
 *  - Send helpers: sendShopLocation, sendTrackingInfo, sendProductResult, sendProductImageResult
 *  - Public API: sendDirectMessage, sendOwnerAnswerByQuote, resumeConversation, pauseBot, etc.
 *
 * يجب أن يكون أول trait يُدرج في Agent عشان باقي الـ Traits تعتمد عليه.
 */
trait ConversationTrait
{
    // ═══════════════════════════════════════════════════════════════
    //  Session Timeout
    // ═══════════════════════════════════════════════════════════════
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

    // ═══════════════════════════════════════════════════════════════
    //  AI Helpers
    // ═══════════════════════════════════════════════════════════════
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
    //  Send Helpers
    // ═══════════════════════════════════════════════════════════════
    private function sendShopLocation(string $phone): void
    {
        try {
            $this->wa->sendLocation(
                $phone,
                31.9646027,
                35.959144,
                'مؤسسة فلسطين لصيانة الأجهزة المنزلية',
                'الهاشمي الشمالي، نزول سوق المخفر، عمان'
            );
        } catch (Throwable $e) {
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
        $createdAt   = new DateTime($req['created_at']);
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
            . "📌 " . $this->mapServiceStatus((string)$req['status'])
        );
    }

    private function sendProductResult(string $phone, int $convId, array $product, string $query, string $prefix = ''): void
    {
        $replyMsg = $prefix . "🔍 *نتيجة البحث عن:* {$query}\n\n" . $this->ps->formatProduct($product);
        $imgUrl   = $this->ps->getImageUrl($product);
        if ($imgUrl && !str_contains($imgUrl, 'no_image')) {
            try { $this->wa->sendImage($phone, $imgUrl, $this->ps->formatCaption($product)); return; }
            catch (Throwable) {}
        }
        $this->reply($phone, $convId, $replyMsg);
    }

    private function sendProductImageResult(string $phone, int $convId, array $product): void
    {
        $name  = $product['name']           ?? 'قطعة';
        $qty   = (int)($product['quantity'] ?? 0);
        $code  = $product['code']           ?? '';
        $avail = $qty > 0 ? '✅ متوفرة' : '❌ غير متوفرة حالياً';

        $caption  = "*{$name}*\n";
        $caption .= "📦 {$avail}";
        if ($code) $caption .= "\n🔢 {$code}";

        $imgUrl = $this->ps->getImageUrl($product);
        if ($imgUrl) {
            try {
                $this->wa->sendImage($phone, $imgUrl, $caption);
                return;
            } catch (Throwable) {}
        }
        $this->reply($phone, $convId, $caption);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Conversation Helpers
    // ═══════════════════════════════════════════════════════════════
    private function isButtonPayload(string $text): bool
    {
        $buttons = [
            'btn_maintenance','btn_part_inquiry','btn_track','btn_inquiry',
            'btn_inquiry_q','btn_inquiry_note','btn_inquiry_comp',
            '🔧 طلب صيانة','🧩 استفسار عن قطعة','📦 تتبع طلب','📝 استفسار',
            '📋 استفسار','💬 ملاحظة أو اقتراح','⚠️ شكوى',
            'طلب صيانة','استفسار عن قطعة','تتبع طلب','استفسار',
            'حجز صيانة للأجهزة المنزلية','صورة أو اسم قطعة الغيار',
            'متابعة برقم الطلب','استفسار أو ملاحظة',
            'سؤال أو استفسار عام','ملاحظة أو اقتراح أو مقترح',
            'تقديم شكوى رسمية',
        ];
        return in_array(trim($text), $buttons, true);
    }

    private function tryKBReply(string $phone, int $convId, string $text): bool
    {
        try {
            $kb = $this->kb->search($text);
            if ($kb) { $this->reply($phone, $convId, $kb['answer']); return true; }
        } catch (Throwable $e) {}
        return false;
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
                    $adminResp = $this->wa->sendText($adminPhone, $message);
                    $this->rememberOwnerMsg($adminResp, $message);
                } catch (Throwable $e) {
                    error_log("[notifyAdmins] Failed to send to admin $adminPhone: " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log("[notifyAdmins] " . $e->getMessage());
        }
    }

    private function botRecentlySaid(int $convId, string $needle, int $seconds = 60): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM messages
                 WHERE conversation_id=? AND sender='bot'
                   AND body LIKE ? AND created_at >= (NOW() - INTERVAL ? SECOND)"
            );
            $stmt->execute([$convId, '%' . $needle . '%', $seconds]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }

    // ═══════════════════════════════════════════════════════════════
    //  DB Finders
    // ═══════════════════════════════════════════════════════════════
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

    private function findLastRequestByCustomerPhone(string $phone, ?int $custId = null, bool $onlyOpen = false): ?array
    {
        try {
            $digits = preg_replace('/\D/', '', $this->normalizeDigits($phone));
            $tail   = substr($digits, -9);
            $where  = "(RIGHT(REGEXP_REPLACE(customer_phone, '[^0-9]', ''), 9) = ?";
            $params = [$tail];
            if ($custId) { $where .= " OR customer_id = ?"; $params[] = $custId; }
            $where .= ")";
            if ($onlyOpen) $where .= " AND status IN ('pending','assigned','in_progress')";
            $stmt = $this->db->prepare(
                "SELECT id, order_number, product_name, issue_description, customer_location, status, created_at
                 FROM service_requests WHERE {$where} ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log("[findLastRequestByCustomerPhone] " . $e->getMessage());
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

    // ═══════════════════════════════════════════════════════════════
    //  Core DB Operations
    // ═══════════════════════════════════════════════════════════════
    private function updateConvState(int $convId, string $state, array $draft): void
    {
        try {
            $this->db->prepare("UPDATE conversations SET state=?, order_draft=?, last_msg_at=NOW() WHERE id=?")
                ->execute([$state, json_encode($draft, JSON_UNESCAPED_UNICODE), $convId]);
        } catch (Throwable $e) { error_log("[updateConvState] " . $e->getMessage()); }
    }

    private function getOrCreateCustomer(string $phone): array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE phone=? LIMIT 1');
        $stmt->execute([$phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $this->db->prepare('INSERT IGNORE INTO customers (phone) VALUES (?)')->execute([$phone]);
        $stmt->execute([$phone]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$this->db->lastInsertId(), 'phone' => $phone];
    }

    private function getOrCreateConversation(int $custId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM conversations WHERE customer_id=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$custId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $this->db->prepare(
            "INSERT INTO conversations (customer_id, status, bot_paused, state, last_msg_at) VALUES (?, 'bot', 0, 'idle', NOW())"
        )->execute([$custId]);
        $stmt->execute([$custId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$this->db->lastInsertId(), 'customer_id' => $custId, 'status' => 'bot', 'state' => 'idle'];
    }

    private function saveMessage(int $convId, string $sender, string $type, string $body, ?string $mediaPath = null, ?string $transcription = null, ?string $waMessageId = null): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO messages (conversation_id, sender, msg_type, body, media_path, transcription, wa_message_id) VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$convId, $sender, $type, $body, $mediaPath, $transcription, $waMessageId]);
            $this->db->prepare("UPDATE conversations SET last_msg_at = NOW() WHERE id = ?")->execute([$convId]);
        } catch (Throwable $e) {
            error_log("[saveMessage] " . $e->getMessage());
            if (!in_array($type, ['text', 'audio', 'image'], true)) {
                try {
                    $this->db->prepare(
                        "INSERT INTO messages (conversation_id, sender, msg_type, body, media_path, transcription, wa_message_id) VALUES (?, ?, 'text', ?, ?, ?, ?)"
                    )->execute([$convId, $sender, "[{$type}] " . $body, $mediaPath, $transcription, $waMessageId]);
                    $this->db->prepare("UPDATE conversations SET last_msg_at = NOW() WHERE id = ?")->execute([$convId]);
                } catch (Throwable $e2) { error_log("[saveMessage/fallback] " . $e2->getMessage()); }
            }
        }
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

    // ═══════════════════════════════════════════════════════════════
    //  Utility
    // ═══════════════════════════════════════════════════════════════
    private function normalizeDigits(string $text): string
    {
        return str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], ['0','1','2','3','4','5','6','7','8','9'], $text);
    }

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
    //  Public API (تُستخدم من الداشبورد وملفات api/)
    // ═══════════════════════════════════════════════════════════════
    public function sendDirectMessage(string $phone, string $text): array
    {
        try { $this->wa->sendText($phone, $text); return ['ok' => true]; }
        catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    public function sendOwnerAnswerByQuote(string $quotedWaId, string $ownerAnswer): array
    {
        try {
            $nStmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $nStmt->execute(['ownmsg:' . $quotedWaId]);
            $mappedNotifId = $nStmt->fetchColumn();
            if ($mappedNotifId !== false && (int)$mappedNotifId > 0) {
                return $this->sendOwnerAnswer((int)$mappedNotifId, $ownerAnswer, true);
            }

            $stmt = $this->db->prepare("
                SELECT m.conversation_id, m.sender, cu.phone, n.id AS notif_id
                FROM messages m
                JOIN conversations c  ON c.id  = m.conversation_id
                JOIN customers    cu ON cu.id  = c.customer_id
                LEFT JOIN notifications n ON n.conversation_id = c.id AND n.status = 'pending'
                WHERE m.wa_message_id = ?
                LIMIT 1
            ");
            $stmt->execute([$quotedWaId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ['ok' => false, 'error' => 'ما لقيت الرسالة المسحوبة في قاعدة البيانات'];
            }

            if (!empty($row['notif_id'])) {
                return $this->sendOwnerAnswer((int)$row['notif_id'], $ownerAnswer, true);
            }

            $this->wa->sendText($row['phone'], $ownerAnswer);
            $this->saveMessage((int)$row['conversation_id'], 'owner', 'text', $ownerAnswer);

            $this->db->prepare("
                UPDATE notifications
                SET owner_answer = ?, status = 'resolved', resolved_at = NOW()
                WHERE conversation_id = ? AND status = 'pending'
            ")->execute([$ownerAnswer, $row['conversation_id']]);

            $this->db->prepare("UPDATE conversations SET last_msg_at = NOW() WHERE id = ?")
                ->execute([$row['conversation_id']]);

            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('[sendOwnerAnswerByQuote] ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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

    public function cleanupOldMedia(int $days = 7): array
    {
        $deletedFiles  = 0;
        $missingFiles  = 0;
        $deletedRows   = 0;
        $failedDeletes = 0;

        try {
            $stmt = $this->db->prepare("
                SELECT id, media_path FROM messages
                WHERE msg_type IN ('image', 'audio', 'video')
                  AND media_path IS NOT NULL AND media_path != ''
                  AND created_at < (NOW() - INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $path = (string)$row['media_path'];
                if ($path !== '' && is_file($path)) {
                    if (@unlink($path)) { $deletedFiles++; }
                    else { $failedDeletes++; error_log("[cleanupOldMedia] فشل حذف الملف: {$path}"); continue; }
                } else { $missingFiles++; }
                $this->db->prepare("DELETE FROM messages WHERE id = ?")->execute([$row['id']]);
                $deletedRows++;
            }
        } catch (Throwable $e) {
            error_log("[cleanupOldMedia] " . $e->getMessage());
        }

        return compact('days', 'deletedFiles', 'missingFiles', 'deletedRows', 'failedDeletes');
    }
}
// end trait

