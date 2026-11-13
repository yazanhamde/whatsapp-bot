<?php

/**
 * OwnerNotifyTrait — إشعارات المالك + حل نافذة 24 ساعة
 */
trait OwnerNotifyTrait
{
    private const OWNER_TEMPLATE_NAME = 'owner_notify_generic';
    private const OWNER_TEMPLATE_LANG = 'ar';

    private function isOwnerWithinWindow(string $ownerPhone): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1"
            );
            $stmt->execute(["owner_last_msg_at:$ownerPhone"]);
            $lastSeen = $stmt->fetchColumn();
            if (!$lastSeen) return false;
            $diffHours = (time() - strtotime($lastSeen)) / 3600;
            return $diffHours < 24;
        } catch (Throwable $e) {
            error_log("[isOwnerWithinWindow] $ownerPhone: " . $e->getMessage());
            return false;
        }
    }

    private function logOwnerAlert(string $message, string $kind = 'text', ?string $phone = null): void
    {
        try {
            if ($phone === null || $phone === '') {
                $phone = $this->_alertPhone;
                if (!$phone && preg_match('/(?<!\d)(\d{10,15})(?!\d)/', $message, $m)) {
                    $phone = $m[1];
                }
            }
            $custId = null;
            $convId = null;
            if ($phone) {
                $c = $this->db->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
                $c->execute([$phone]);
                $custId = $c->fetchColumn() ?: null;
                if ($custId) {
                    $v = $this->db->prepare("SELECT id FROM conversations WHERE customer_id=? ORDER BY id DESC LIMIT 1");
                    $v->execute([$custId]);
                    $convId = $v->fetchColumn() ?: null;
                }
            }
            $this->db->prepare(
                "INSERT INTO owner_alerts (conversation_id, customer_id, customer_phone, kind, message)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$convId, $custId, $phone, $kind, mb_substr($message, 0, 4000)]);
        } catch (Throwable $e) {
            error_log('[logOwnerAlert] ' . $e->getMessage());
        }
    }

    private function sendToOwners(string $message): void
    {
        $this->logOwnerAlert($message, 'text');
        $owners = explode(',', OWNER_PHONE);
        foreach ($owners as $owner) {
            $owner = trim($owner);
            if ($owner === '') continue;
            try {
                if (self::OWNER_TEMPLATE_NAME !== '' && !$this->isOwnerWithinWindow($owner)) {
                    $resp = $this->wa->sendTemplate(
                        $owner,
                        self::OWNER_TEMPLATE_NAME,
                        self::OWNER_TEMPLATE_LANG,
                        [$message]
                    );
                } else {
                    $resp = $this->wa->sendText($owner, $message);
                }
                $this->rememberOwnerMsg($resp, $message);
            } catch (Throwable $e) {
                error_log("[sendToOwners] Failed to send to $owner: " . $e->getMessage());
                if (self::OWNER_TEMPLATE_NAME !== '' && str_contains($e->getMessage(), '131047')) {
                    try {
                        $resp = $this->wa->sendTemplate(
                            $owner,
                            self::OWNER_TEMPLATE_NAME,
                            self::OWNER_TEMPLATE_LANG,
                            [$message]
                        );
                        $this->rememberOwnerMsg($resp, $message);
                    } catch (Throwable $e2) {
                        error_log("[sendToOwners] Template fallback failed for $owner: " . $e2->getMessage());
                    }
                }
            }
        }
    }

    private function rememberOwnerMsg($waResponse, string $message): void
    {
        try {
            if (!preg_match('/رد#(\d+)/u', $message, $m)) return;
            $wamid = is_array($waResponse) ? ($waResponse['messages'][0]['id'] ?? '') : '';
            if ($wamid === '') return;
            $this->db->prepare("
                INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute(['ownmsg:' . $wamid, $m[1]]);
        } catch (Throwable $e) {
            error_log('[rememberOwnerMsg] ' . $e->getMessage());
        }
    }

    private function sendLocationToOwners(float $lat, float $lng, string $device, string $phone): void
    {
        $this->logOwnerAlert(
            "📍 لوكيشن GPS — {$device}\nhttps://maps.google.com/?q={$lat},{$lng}",
            'location',
            $phone
        );
        $owners = explode(',', OWNER_PHONE);
        foreach ($owners as $owner) {
            $owner = trim($owner);
            if ($owner === '') continue;
            try {
                if (self::OWNER_TEMPLATE_NAME !== '' && !$this->isOwnerWithinWindow($owner)) {
                    $this->wa->sendTemplate(
                        $owner,
                        self::OWNER_TEMPLATE_NAME,
                        self::OWNER_TEMPLATE_LANG,
                        ["طلب صيانة جديد فيه موقع GPS — الجهاز: $device، الزبون: $phone. افتح لوحة التحكم لمشاهدة الموقع."]
                    );
                }
                $this->wa->sendLocation($owner, $lat, $lng, $device, $phone);
            } catch (Throwable $e) {
                error_log("[sendLocationToOwners] Failed to send location to $owner: " . $e->getMessage());
            }
        }
    }

    private function forwardImageToOwners(string $imagePath, string $caption): void
    {
        try {
            $imageUrl = $this->localPathToPublicUrl($imagePath);
            if (!$imageUrl) {
                $this->notifyAdmins($caption . "\n⚠️ (تعذّر إرفاق الصورة تلقائياً، راجع لوحة التحكم)");
                return;
            }

            $this->logOwnerAlert($caption, 'image');
            $owners = explode(',', OWNER_PHONE);
            foreach ($owners as $owner) {
                $owner = trim($owner);
                if ($owner === '') continue;
                try {
                    if (self::OWNER_TEMPLATE_NAME !== '' && !$this->isOwnerWithinWindow($owner)) {
                        $this->wa->sendTemplate(
                            $owner,
                            self::OWNER_TEMPLATE_NAME,
                            self::OWNER_TEMPLATE_LANG,
                            [$caption !== '' ? $caption : 'وصلت صورة جديدة من زبون، افتح لوحة التحكم.']
                        );
                    }
                    $imgResp = $this->wa->sendImage($owner, $imageUrl, $caption);
                    $this->rememberOwnerMsg($imgResp, $caption);
                } catch (Throwable $e) {
                    error_log("[forwardImageToOwners] Failed to send to $owner: " . $e->getMessage());
                }
            }

            $ownersList = array_map('trim', explode(',', OWNER_PHONE));
            $stmt = $this->db->query(
                "SELECT phone FROM users WHERE role='admin' AND phone IS NOT NULL AND phone!=''"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminPhone) {
                $adminPhone = trim($adminPhone);
                if (in_array($adminPhone, $ownersList, true)) continue;
                try {
                    $this->wa->sendImage($adminPhone, $imageUrl, $caption);
                } catch (Throwable $e) {
                    error_log("[forwardImageToOwners] Failed to send to admin $adminPhone: " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[forwardImageToOwners] ' . $e->getMessage());
            $this->notifyAdmins($caption);
        }
    }

    private function localPathToPublicUrl(string $imagePath): ?string
    {
        if ($imagePath === '' || !file_exists($imagePath)) return null;

        $docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $realPath = realpath($imagePath);

        if ($docRoot && $realPath && str_starts_with($realPath, $docRoot)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? (defined('APP_HOST') ? APP_HOST : '');
            if ($host === '') return null;
            $rel = substr($realPath, strlen($docRoot));
            return $scheme . '://' . $host . '/' . ltrim(str_replace('\\', '/', $rel), '/');
        }

        if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
            $webhookDir = realpath(__DIR__ . '/..');
            if ($webhookDir && $realPath && str_starts_with($realPath, $webhookDir)) {
                $rel = substr($realPath, strlen($webhookDir));
                return rtrim(APP_BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            }
        }
        return null;
    }
}
