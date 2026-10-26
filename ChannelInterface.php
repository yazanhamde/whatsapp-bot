<?php
/**
 * ChannelInterface
 * ─────────────────────────────────────────────────────────────────────────
 * عقد موحّد لأي قناة تواصل (واتساب / ماسنجر / انستقرام).
 * Agent.php بيتعامل مع أي كلاس يطبّق هالواجهة بدون ما يعرف تفاصيل القناة.
 *
 * ملاحظة مهمة: مو كل قناة بتدعم كل الميزات (مثلاً ماسنجر ما عنده sendTemplate
 * بمعنى واتساب - المفهوم مختلف). للميزات الغير مدعومة، الكلاس بيرجع أقرب
 * سلوك ممكن (مثلاً sendTemplate بيتحول لـ sendText عادي) بدل ما يرمي خطأ،
 * عشان Agent.php يضل شغال بدون ما نعمل فيه if/else لكل قناة.
 */
interface ChannelInterface
{
    /** اسم القناة: 'whatsapp' | 'messenger' | 'instagram' */
    public function name(): string;

    /** إرسال نص عادي (بينقسم تلقائياً إذا طويل) */
    public function sendText(string $to, string $text): array;

    /**
     * إرسال أزرار سريعة (Quick Replies بماسنجر/انستقرام، Interactive Buttons بواتساب)
     * @param array $buttons مصفوفة بسيطة: [['id' => '...', 'title' => '...'], ...]
     */
    public function sendButtons(string $to, string $bodyText, array $buttons, string $headerText = '', string $footerText = ''): array;

    /** إرسال صورة برابط عام (URL) */
    public function sendImage(string $to, string $imageUrl, string $caption = ''): array;

    /** إرسال موقع جغرافي (واتساب بس بيدعمها فعلياً كنوع رسالة location) */
    public function sendLocation(string $to, float $lat, float $lng, string $name = '', string $address = ''): array;

    /**
     * إرسال قائمة/منيو. واتساب عندها List Message أصلي.
     * ماسنجر/انستقرام ما عندهم مكافئ حقيقي → بترجع كنص مرقّم + أزرار سريعة (أول 3 خيارات).
     */
    public function sendList(string $to, string $bodyText, string $buttonLabel, array $sections, string $headerText = '', string $footerText = ''): array;

    /**
     * إرسال قالب معتمد (خاص بواتساب لكسر نافذة الـ24 ساعة).
     * ماسنجر/انستقرام: نافذة الرد عندهم 24 ساعة أيضاً بس بدون مفهوم "template مسجل"
     * رسمي بنفس الطريقة → الكلاس بيرسل كنص عادي (أفضل جهد ممكن).
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode = 'ar', array $bodyParams = []): array;

    /** تنزيل ميديا (صورة/صوت) محلياً، بيرجع المسار أو null */
    public function downloadMedia(string $mediaId): ?string;

    /** تعليم رسالة كمقروءة (إذا القناة ما بتدعمها، الكلاس بيتجاهلها بصمت) */
    public function markRead(string $msgId): void;
}
