<?php
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/ChannelInterface.php';

/**
 * Messenger — طبقة الاتصال مع Facebook Messenger Platform (Graph API v19+)
 * نفس بنية WhatsApp.php بالضبط عشان يكون سهل الصيانة على نفس النمط.
 *
 * التوثيق الرسمي: https://developers.facebook.com/docs/messenger-platform
 *
 * المتطلبات بالـ .env:
 *   FB_PAGE_ID          = آي دي صفحة الفيسبوك
 *   FB_PAGE_TOKEN        = Page Access Token
 *   FB_APP_SECRET        = App Secret (للتحقق من توقيع الويبهوك)
 *   FB_VERIFY_TOKEN      = توكن التحقق من الويبهوك (اختاره انت بنفسك)
 */
class Messenger implements ChannelInterface {

    private const GRAPH_VERSION  = 'v19.0';
    private const GRAPH_BASE     = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/';

    private const MAX_RETRIES    = 3;
    private const RETRY_DELAY_MS = 300;
    private const MAX_TEXT_LEN   = 2000; // حد ماسنجر للرسالة الواحدة
    private const UPLOAD_DIR     = __DIR__ . '/../uploads/';

    private string $pageToken;

    public function __construct() {
        $this->pageToken = defined('FB_PAGE_TOKEN') ? FB_PAGE_TOKEN : (getenv('FB_PAGE_TOKEN') ?: '');
    }

    public function name(): string { return 'messenger'; }

    // ── HTTP layer (نفس نمط WhatsApp.php تماماً) ───────────────────────────

    private function call(string $method, string $url, array $data = []): array {
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // Graph API بياخد الـ token كـ query param مش Authorization header
        $sep = str_contains($url, '?') ? '&' : '?';
        $url .= $sep . 'access_token=' . urlencode($this->pageToken);

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Messenger cURL error [$method]: $curlErr");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Messenger invalid JSON [$method]: " . substr($raw, 0, 200));
        }

        if ($httpCode >= 400) {
            $errMsg  = $decoded['error']['message'] ?? 'Unknown error';
            $errCode = $decoded['error']['code'] ?? $httpCode;
            throw new RuntimeException("Messenger API error $errCode [$method]: $errMsg");
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

                preg_match('/API error (\d+)/', $msg, $m);
                $code        = isset($m[1]) ? (int)$m[1] : 0;
                $isRetryable = $code === 429 || $code >= 500 || str_contains($msg, 'cURL error');

                if (!$isRetryable || $attempt >= self::MAX_RETRIES) throw $e;

                error_log("[Messenger] Attempt $attempt failed (retrying in {$delay}ms): $msg");
                usleep($delay * 1000);
                $delay *= 2;
            }
        }
    }

    private function sendPayload(string $psid, array $message, string $tag = ''): array {
        $body = [
            'recipient'         => ['id' => $psid],
            'messaging_type'    => $tag !== '' ? 'MESSAGE_TAG' : 'RESPONSE',
            'message'           => $message,
        ];
        if ($tag !== '') $body['tag'] = $tag;

        return $this->callWithRetry('POST', self::GRAPH_BASE . 'me/messages', $body);
    }

    // ── Public API (تطبيق ChannelInterface) ─────────────────────────────────

    public function sendText(string $to, string $text): array {
        $to   = $this->sanitizeId($to);
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('sendText(): message body cannot be empty.');
        }

        $chunks = $this->splitText($text, self::MAX_TEXT_LEN);
        $last   = [];
        foreach ($chunks as $chunk) {
            $last = $this->sendPayload($to, ['text' => $chunk]);
        }
        return $last;
    }

    public function sendButtons(string $to, string $bodyText, array $buttons, string $headerText = '', string $footerText = ''): array {
        $to = $this->sanitizeId($to);

        // ماسنجر بيدعم لغاية 3 quick replies بالمعنى المتماثل مع واتساب interactive buttons
        $quickReplies = [];
        foreach (array_slice($buttons, 0, 13) as $btn) { // ماسنجر فعلياً يسمح 13 quick reply
            $title = mb_substr(trim($btn['title'] ?? ''), 0, 20);
            $id    = trim($btn['id'] ?? $title);
            if ($title === '') continue;
            $quickReplies[] = [
                'content_type' => 'text',
                'title'        => $title,
                'payload'      => $id,
            ];
        }

        $text = trim(($headerText !== '' ? $headerText . "\n" : '') . $bodyText . ($footerText !== '' ? "\n" . $footerText : ''));

        $message = ['text' => mb_substr($text, 0, self::MAX_TEXT_LEN)];
        if (!empty($quickReplies)) $message['quick_replies'] = $quickReplies;

        return $this->sendPayload($to, $message);
    }

    public function sendImage(string $to, string $imageUrl, string $caption = ''): array {
        $to = $this->sanitizeId($to);

        $result = $this->sendPayload($to, [
            'attachment' => [
                'type'    => 'image',
                'payload' => ['url' => $imageUrl, 'is_reusable' => true],
            ],
        ]);

        // ماسنجر ما بيدعم caption مع الصورة بنفس الرسالة → نبعتها كنص منفصل
        if (trim($caption) !== '') {
            $this->sendText($to, $caption);
        }

        return $result;
    }

    public function sendLocation(string $to, float $lat, float $lng, string $name = '', string $address = ''): array {
        // ماسنجر ما عنده نوع رسالة "location" بنفس مفهوم واتساب (منتشر فقط للاستلام لا الإرسال)
        // → أفضل جهد: نبعت رابط خرائط جوجل كنص
        $to   = $this->sanitizeId($to);
        $link = "https://maps.google.com/?q={$lat},{$lng}";
        $text = trim(($name !== '' ? "📍 $name\n" : '') . ($address !== '' ? "$address\n" : '') . $link);
        return $this->sendText($to, $text);
    }

    public function sendList(string $to, string $bodyText, string $buttonLabel, array $sections, string $headerText = '', string $footerText = ''): array {
        // ماسنجر ما عنده List Message. أفضل جهد: نحول لنص مرقّم + أول 3 خيارات كـ quick replies
        $to    = $this->sanitizeId($to);
        $lines = [];
        $flatButtons = [];

        if ($headerText !== '') $lines[] = "*{$headerText}*";
        $lines[] = trim($bodyText);

        $i = 1;
        foreach ($sections as $section) {
            if (!empty($section['title'])) $lines[] = "\n— {$section['title']} —";
            foreach ($section['rows'] ?? [] as $row) {
                $title = trim($row['title'] ?? '');
                $desc  = trim($row['description'] ?? '');
                if ($title === '') continue;
                $lines[] = "{$i}. {$title}" . ($desc !== '' ? " — {$desc}" : '');
                if (count($flatButtons) < 13) {
                    $flatButtons[] = ['id' => $row['id'] ?? $title, 'title' => mb_substr($title, 0, 20)];
                }
                $i++;
            }
        }
        if ($footerText !== '') $lines[] = "\n{$footerText}";

        return $this->sendButtons($to, implode("\n", $lines), $flatButtons);
    }

    public function sendTemplate(string $to, string $templateName, string $languageCode = 'ar', array $bodyParams = []): array {
        // ماسنجر ما عنده "template" مسجل بمفهوم واتساب. أفضل جهد: نبعت القيم كنص عادي.
        $to   = $this->sanitizeId($to);
        $text = !empty($bodyParams) ? implode(' ', array_map('strval', $bodyParams)) : $templateName;
        return $this->sendText($to, $text);
    }

    public function downloadMedia(string $mediaId): ?string {
        // بماسنجر الـ "مديا آي دي" مش موجود بنفس مفهوم واتساب — الميديا بتوصل
        // كـ URL مباشر بالـ webhook payload (attachments[].payload.url).
        // هالدالة موجودة بس عشان تحقق الـ interface؛ التنزيل الفعلي لازم
        // يصير من الرابط مباشرة بالـ webhook (استخدم downloadFromUrl بدلها).
        return $this->downloadFromUrl($mediaId);
    }

    /** تنزيل ميديا من رابط مباشر (مثل يلي بيوصل بـ webhook ماسنجر) */
    public function downloadFromUrl(string $url): ?string {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return null;

        if (!is_dir(self::UPLOAD_DIR)) @mkdir(self::UPLOAD_DIR, 0755, true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mimeType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            error_log("[Messenger::downloadMedia] fetch failed httpCode=$httpCode url=$url");
            return null;
        }

        $ext      = $this->mimeToExt($mimeType);
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $path     = self::UPLOAD_DIR . 'images/' . $filename;
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);

        if (file_put_contents($path, $raw) === false) {
            error_log("[Messenger::downloadMedia] write failed: $path");
            return null;
        }

        return $path;
    }

    public function markRead(string $msgId): void {
        // ماسنجر بيدعم sender_action=mark_seen بس بياخد PSID مش message id
        // هون منسكت بصمت لأنه Agent.php بينادي markRead($waId) مش بالـ psid
        // (استخدم markSeen($psid) إذا بدك تفعّلها فعلياً بالـ webhook)
    }

    public function markSeen(string $psid): void {
        try {
            $this->call('POST', self::GRAPH_BASE . 'me/messages', [
                'recipient'     => ['id' => $this->sanitizeId($psid)],
                'sender_action' => 'mark_seen',
            ]);
        } catch (RuntimeException $e) {
            error_log("[Messenger::markSeen] Failed for psid=$psid: " . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function sanitizeId(string $id): string {
        $clean = trim($id);
        if ($clean === '') {
            throw new InvalidArgumentException("Invalid Messenger PSID: '$id'");
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

    private function mimeToExt(string $mime): string {
        return match (strtolower(trim(explode(';', $mime)[0] ?? ''))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png'               => 'png',
            'image/webp'              => 'webp',
            'image/gif'               => 'gif',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4'               => 'm4a',
            'video/mp4'               => 'mp4',
            default                   => 'bin',
        };
    }
}
