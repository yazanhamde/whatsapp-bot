<?php
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/ChannelInterface.php';

class WhatsApp implements ChannelInterface {

    public function name(): string { return 'whatsapp'; }


    private const MESSAGES_BASE  = WA_API . WA_PHONE_ID . '/';
    private const MEDIA_BASE     = WA_API;

    private const MAX_RETRIES    = 3;
    private const RETRY_DELAY_MS = 300;
    private const MAX_TEXT_LEN   = 4096;
    private const MAX_MEDIA_MB   = 16;
    private const UPLOAD_DIR     = __DIR__ . '/../uploads/';  // مجلد موحد

    // ── HTTP layer ────────────────────────────────────────────────────────

    private function call(string $method, string $url, array $data = [], bool $isMedia = false): array {
        $headers = [
            'Authorization: Bearer ' . WA_TOKEN,
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }

        $ch       = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("WhatsApp cURL error [$method]: $curlErr");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("WhatsApp invalid JSON [$method]: " . substr($raw, 0, 200));
        }

        if ($httpCode >= 400) {
            $errMsg  = $decoded['error']['message']
                    ?? $decoded['error']['error_data']['details']
                    ?? 'Unknown error';
            $errCode = $decoded['error']['code'] ?? $httpCode;
            throw new RuntimeException("WhatsApp API error $errCode [$method]: $errMsg");
        }

        return $decoded;
    }

    private function callWithRetry(string $method, string $url, array $data = []): array {
        $attempt = 0;
        $delay   = self::RETRY_DELAY_MS;

        while (true) {
            try {
                return $this->call($method, $url, $data);
            } catch (RuntimeException $e) {
                $attempt++;
                $msg = $e->getMessage();

                preg_match('/API error (\d{3})/', $msg, $m);
                $code        = isset($m[1]) ? (int)$m[1] : 0;
                $isRetryable = $code === 429
                            || $code >= 500
                            || str_contains($msg, 'cURL error');

                if (!$isRetryable || $attempt >= self::MAX_RETRIES) throw $e;

                error_log("[WhatsApp] Attempt $attempt failed (retrying in {$delay}ms): $msg");
                usleep($delay * 1000);
                $delay *= 2;
            }
        }
    }

    // ── Public API ────────────────────────────────────────────────────────

    public function sendText(string $to, string $text): array {
        $to   = $this->sanitizePhone($to);
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('sendText(): message body cannot be empty.');
        }

        $chunks = $this->splitText($text, self::MAX_TEXT_LEN);
        $last   = [];

        foreach ($chunks as $chunk) {
            $last = $this->callWithRetry('POST', self::MESSAGES_BASE . 'messages', [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'text',
                'text' => ['body' => $chunk],
            ]);
        }

        return $last;
    }

    public function sendButtons(string $to, string $bodyText, array $buttons, string $headerText = '', string $footerText = ''): array {
        $to = $this->sanitizePhone($to);

        // واتساب يدعم 3 أزرار كحد أقصى — نقطع الزائد بدل ما نرمي exception
        $buttons = array_slice($buttons, 0, 3);

        if (empty($buttons)) {
            throw new InvalidArgumentException('sendButtons(): لا يوجد أزرار.');
        }

        $apiButtons = [];
        foreach ($buttons as $btn) {
            $title = mb_substr(trim($btn['title'] ?? ''), 0, 20);
            $id    = mb_substr(trim($btn['id']    ?? $title), 0, 256);
            if ($title === '') continue;
            $apiButtons[] = [
                'type'  => 'reply',
                'reply' => ['id' => $id, 'title' => $title],
            ];
        }

        if (empty($apiButtons)) {
            throw new InvalidArgumentException('sendButtons(): لا يوجد أزرار صالحة.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => mb_substr(trim($bodyText), 0, 1024)],
                'action' => ['buttons' => $apiButtons],
            ],
        ];

        if ($headerText !== '') {
            $payload['interactive']['header'] = [
                'type' => 'text',
                'text' => mb_substr($headerText, 0, 60),
            ];
        }

        if ($footerText !== '') {
            $payload['interactive']['footer'] = [
                'text' => mb_substr($footerText, 0, 60),
            ];
        }

        return $this->callWithRetry('POST', self::MESSAGES_BASE . 'messages', $payload);
    }

    public function sendImage(string $to, string $imageUrl, string $caption = ''): array {
        $to = $this->sanitizePhone($to);

        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("sendImage(): invalid URL '$imageUrl'");
        }

        return $this->callWithRetry('POST', self::MESSAGES_BASE . 'messages', [
            'messaging_product' => 'whatsapp',
            'to'    => $to,
            'type'  => 'image',
            'image' => [
                'link'    => $imageUrl,
                'caption' => mb_substr($caption, 0, 1024),
            ],
        ]);
    }

    public function getMediaInfo(string $mediaId): array {
        return $this->call('GET', self::MEDIA_BASE . $mediaId);
    }

    // ✅ الإصلاح الرئيسي: downloadMedia يحدد المجلد الصحيح حسب نوع الميديا
    public function downloadMedia(string $mediaId): ?string {
        try {
            $info = $this->call('GET', self::MEDIA_BASE . $mediaId);
        } catch (RuntimeException $e) {
            error_log("[WhatsApp::downloadMedia] Failed to get media info for $mediaId: " . $e->getMessage());
            return null;
        }

        $mediaUrl = $info['url']       ?? null;
        $mimeType = $info['mime_type'] ?? 'application/octet-stream';
        $fileSize = (int)($info['file_size'] ?? 0);

        if (!$mediaUrl) {
            error_log("[WhatsApp::downloadMedia] No URL returned for mediaId=$mediaId");
            return null;
        }

        $maxBytes = self::MAX_MEDIA_MB * 1024 * 1024;

        if ($fileSize > 0 && $fileSize > $maxBytes) {
            error_log("[WhatsApp::downloadMedia] File too large: {$fileSize}B");
            return null;
        }

        // ✅ مجلد منفصل حسب نوع الميديا
        $isImage = str_starts_with($mimeType, 'image/');
        $subDir  = $isImage ? 'images/' : 'audio/';
        $dir     = self::UPLOAD_DIR . $subDir;
        $ext     = $this->mimeToExt($mimeType);
        $path    = $dir . preg_replace('/[^a-zA-Z0-9_-]/', '_', $mediaId) . '.' . $ext;

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log("[WhatsApp::downloadMedia] Cannot create dir: $dir");
            return null;
        }

        $tmpPath = $path . '.tmp';
        $fp      = fopen($tmpPath, 'wb');

        if (!$fp) {
            error_log("[WhatsApp::downloadMedia] Cannot open tmp file: $tmpPath");
            return null;
        }

        $exceeded = false;

        $ch = curl_init($mediaUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . WA_TOKEN],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxBytes, &$exceeded) {
                if ($dlNow > $maxBytes) {
                    $exceeded = true;
                    return 1;
                }
                return 0;
            },
        ]);

        curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($exceeded || $curlErr || $httpCode >= 400) {
            @unlink($tmpPath);
            error_log("[WhatsApp::downloadMedia] Download failed: exceeded=$exceeded curlErr=$curlErr http=$httpCode mediaId=$mediaId mimeType=$mimeType");
            return null;
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            error_log("[WhatsApp::downloadMedia] rename failed: $tmpPath → $path");
            return null;
        }

        error_log("[WhatsApp::downloadMedia] OK: $path (mime=$mimeType ext=$ext)");
        return $path;
    }

    public function sendLocation(string $to, float $lat, float $lng, string $name = '', string $address = ''): array {
        $to = $this->sanitizePhone($to);
        return $this->callWithRetry('POST', self::MESSAGES_BASE . 'messages', [
            'messaging_product' => 'whatsapp',
            'to'       => $to,
            'type'     => 'location',
            'location' => [
                'latitude'  => $lat,
                'longitude' => $lng,
                'name'      => mb_substr($name, 0, 100),
                'address'   => mb_substr($address, 0, 150),
            ],
        ]);
    }

    /**
     * يبعت Template Message معتمد من Meta — النوع الوحيد المسموح إرساله
     * خارج نافذة الـ 24 ساعة (Customer Service Window).
     *
     * @param string $to           رقم المستلم
     * @param string $templateName اسم القالب المسجل والمعتمد على Meta Business Manager
     * @param string $languageCode كود اللغة (لازم يطابق بالضبط ما هو مسجل بالقالب)
     * @param array  $bodyParams   قيم المتغيرات {{1}}, {{2}}... بالترتيب داخل الـ Body
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode = 'ar', array $bodyParams = []): array {
        $to = $this->sanitizePhone($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'       => $to,
            'type'     => 'template',
            'template' => [
                'name'     => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if (!empty($bodyParams)) {
            $payload['template']['components'] = [
                [
                    'type'       => 'body',
                    'parameters' => array_map(
                        fn($p) => ['type' => 'text', 'text' => (string)$p],
                        $bodyParams
                    ),
                ],
            ];
        }

        return $this->callWithRetry('POST', self::MESSAGES_BASE . 'messages', $payload);
    }

    public function deleteMedia(string $path): void {
        if ($path !== '' && file_exists($path)) {
            @unlink($path);
        }
    }

    public function markRead(string $msgId): void {
        if (trim($msgId) === '') return;
        try {
            $this->call('POST', self::MESSAGES_BASE . 'messages', [
                'messaging_product' => 'whatsapp',
                'status'     => 'read',
                'message_id' => $msgId,
            ]);
        } catch (RuntimeException $e) {
            error_log("[WhatsApp::markRead] Failed for msgId=$msgId: " . $e->getMessage());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function sanitizePhone(string $phone): string {
        $clean = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^\d{7,15}$/', $clean)) {
            throw new InvalidArgumentException("Invalid phone number: '$phone'");
        }
        return $clean;
    }

    private function splitText(string $text, int $maxLen): array {
        if (mb_strlen($text) <= $maxLen) return [$text];

        $chunks = [];
        while (mb_strlen($text) > $maxLen) {
            $slice = mb_substr($text, 0, $maxLen);
            $pos   = mb_strrpos($slice, "\n");
            $pos   = ($pos !== false && $pos > 0) ? $pos : $maxLen;
            $chunks[] = mb_substr($text, 0, $pos);
            $text     = ltrim(mb_substr($text, $pos));
        }
        if (trim($text) !== '') $chunks[] = $text;

        return $chunks;
    }

    // ✅ الإصلاح: إضافة امتدادات الصور
    private function mimeToExt(string $mime): string {
        return match (strtolower(trim($mime))) {
            // صور
            'image/jpeg', 'image/jpg'             => 'jpg',
            'image/png'                           => 'png',
            'image/webp'                          => 'webp',
            'image/gif'                           => 'gif',
            'image/heic', 'image/heif'            => 'jpg', // تحويل لـ jpg
            // صوت
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/mpeg', 'audio/mp3'             => 'mp3',
            'audio/mp4', 'audio/x-m4a'           => 'm4a',
            'audio/wav'                           => 'wav',
            'audio/aac'                           => 'aac',
            'audio/amr'                           => 'amr',
            // فيديو
            'video/mp4'                           => 'mp4',
            'video/3gpp'                          => '3gp',
            // مستندات
            'application/pdf'                     => 'pdf',
            default                               => 'bin',
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // List Message
    // ─────────────────────────────────────────────────────────────────────
    public function sendList(
        string $to,
        string $bodyText,
        string $buttonLabel,
        array  $sections,
        string $headerText = '',
        string $footerText = ''
    ): array {
        $to = $this->sanitizePhone($to);

        if (empty($sections)) {
            throw new InvalidArgumentException('sendList(): يجب أن يكون هناك قسم واحد على الأقل.');
        }

        $apiSections = [];
        foreach ($sections as $section) {
            $rows = [];
            foreach ($section['rows'] ?? [] as $row) {
                $id    = mb_substr(trim($row['id']          ?? ''), 0, 200);
                $title = mb_substr(trim($row['title']       ?? ''), 0, 24);
                $desc  = mb_substr(trim($row['description'] ?? ''), 0, 72);
                if ($title === '') continue;
                if ($id   === '') $id = $title;
                $entry = ['id' => $id, 'title' => $title];
                if ($desc !== '') $entry['description'] = $desc;
                $rows[] = $entry;
            }
            if (empty($rows)) continue;
            $sectionEntry = ['rows' => $rows];
            $sTitle = mb_substr(trim($section['title'] ?? ''), 0, 24);
            if ($sTitle !== '') $sectionEntry['title'] = $sTitle;
            $apiSections[] = $sectionEntry;
        }

        if (empty($apiSections)) {
            throw new InvalidArgumentException('sendList(): لا توجد صفوف صالحة.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'body'   => ['text' => mb_substr(trim($bodyText), 0, 4096)],
                'action' => [
                    'button'   => mb_substr(trim($buttonLabel), 0, 20),
                    'sections' => $apiSections,
                ],
            ],
        ];

        if ($headerText !== '') {
            $payload['interactive']['header'] = [
                'type' => 'text',
                'text' => mb_substr(trim($headerText), 0, 60),
            ];
        }

        if ($footerText !== '') {
            $payload['interactive']['footer'] = [
                'text' => mb_substr(trim($footerText), 0, 60),
            ];
        }

        return $this->callWithRetry('POST', self::MESSAGES_BASE . 'messages', $payload);
    }
}