<?php
require_once __DIR__ . '/../config/db.php';

class KnowledgeBase {

    private PDO $db;

    private const MAX_QUESTION_LEN = 500;
    private const MAX_ANSWER_LEN   = 3000;
    private const MIN_WORD_LEN     = 3;
    private const SEARCH_LIMIT     = 1;
    private const MIN_MATCH_RATIO  = 0.6; // نسبة كلمات السؤال الجديد لازم تتطابق مع سؤال محفوظ

    private const STOP_WORDS = [
        'في', 'من', 'إلى', 'على', 'عن', 'مع', 'هل', 'ما', 'هو', 'هي',
        'انا', 'انت', 'هذا', 'هذه', 'الي', 'اللي', 'يا', 'لو', 'اذا',
        // كلمات دردشة عامة بتسبب تطابق خاطئ لو انحفظت جوابات رمزية عليها
        'تمام', 'طيب', 'شو', 'بس', 'الو', 'اه', 'لا', 'ok', 'اوك',
        'ماشي', 'خلص', 'مشكلة', 'مشكله', 'ممكن', 'كيف', 'وين', 'متى',
        'حابب', 'بدي', 'عندي', 'عندك', 'نزال', 'ابدا', 'والله',
    ];

    public function __construct() {
        $this->db = getDB();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // بحث عن إجابة في قاعدة المعرفة
    // ─────────────────────────────────────────────────────────────────────────

    public function search(string $query): ?array {
        $query = trim($query);
        if ($query === '') return null;

        $row = $this->fulltextSearch($query);
        if (!$row) $row = $this->likeSearch($query);
        // ملاحظة: تم إلغاء likeSearchBestWord (تطابق كلمة وحدة عامة) لأنها
        // كانت تُرجع نفس الجواب المحفوظ لكل زبون تقريباً — سبب المشكلة المُبلَّغ عنها.

        if ($row) {
            $this->db->prepare('UPDATE knowledge_base SET use_count = use_count + 1 WHERE id = ?')
                ->execute([$row['id']]);
        }

        return $row ?: null;
    }

    private function fulltextSearch(string $query): ?array {
        $words = $this->extractWords($query);
        if (empty($words)) return null;

        $ftQuery = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));

        try {
            $stmt = $this->db->prepare("
                SELECT *, MATCH(question, answer) AGAINST(:q1 IN BOOLEAN MODE) AS score
                FROM knowledge_base
                WHERE is_active = 1
                  AND MATCH(question, answer) AGAINST(:q2 IN BOOLEAN MODE)
                ORDER BY score DESC, use_count DESC
                LIMIT " . self::SEARCH_LIMIT
            );
            $stmt->execute([':q1' => $ftQuery, ':q2' => $ftQuery]);
            $row = $stmt->fetch();
            return $row ?: null;

        } catch (Throwable $e) {
            error_log('[KnowledgeBase::fulltextSearch] ' . $e->getMessage());
            return null;
        }
    }

    private function likeSearch(string $query): ?array {
        $words = $this->extractWords($query);
        if (empty($words)) return null;

        // نطلب تطابق مع سؤال محفوظ فقط (مو الجواب، لتفادي تطابقات عشوائية)
        // وبنسبة لا تقل عن MIN_MATCH_RATIO من كلمات رسالة الزبون الجديدة
        $conditions = [];
        $params     = [];

        foreach ($words as $w) {
            $conditions[] = 'question LIKE ?';
            $params[]     = '%' . $w . '%';
        }

        $minMatches = max(2, (int)ceil(count($words) * self::MIN_MATCH_RATIO));

        // نستخدم SUM(CASE...) لعد كم كلمة انطابقت فعلياً، ونطلب الحد الأدنى
        $caseSql = implode(' + ', array_map(fn($w) => 'CASE WHEN question LIKE ? THEN 1 ELSE 0 END', $words));
        $caseParams = array_map(fn($w) => '%' . $w . '%', $words);

        $sql = "
            SELECT *, ($caseSql) AS match_count
            FROM knowledge_base
            WHERE is_active = 1
            HAVING match_count >= ?
            ORDER BY match_count DESC, use_count DESC
            LIMIT " . self::SEARCH_LIMIT;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$caseParams, $minMatches]);
            $row = $stmt->fetch();
            return $row ?: null;

        } catch (Throwable $e) {
            error_log('[KnowledgeBase::likeSearch] ' . $e->getMessage());
            return null;
        }
    }

    private function extractWords(string $text): array {
        return array_values(array_filter(
            explode(' ', $text),
            fn($w) => mb_strlen($w) >= self::MIN_WORD_LEN
                   && !in_array($w, self::STOP_WORDS, true)
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // إدارة الأسئلة والأجوبة
    // ─────────────────────────────────────────────────────────────────────────

    public function save(string $question, string $answer, string $source = 'owner'): int {
        $question = mb_substr(trim($question), 0, self::MAX_QUESTION_LEN);
        $answer   = mb_substr(trim($answer),   0, self::MAX_ANSWER_LEN);

        if ($question === '' || $answer === '') {
            throw new InvalidArgumentException('Question and answer cannot be empty.');
        }

        try {
            $existing = $this->db->prepare('SELECT id FROM knowledge_base WHERE question = ? AND is_active = 1 LIMIT 1');
            $existing->execute([$question]);
            $existingId = $existing->fetchColumn();

            if ($existingId) {
                $this->db->prepare('UPDATE knowledge_base SET answer = ?, source = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$answer, $source, $existingId]);
                return (int)$existingId;
            }

            $this->db->prepare('INSERT INTO knowledge_base (question, answer, source) VALUES (?, ?, ?)')
                ->execute([$question, $answer, $source]);

            return (int)$this->db->lastInsertId();

        } catch (Throwable $e) {
            error_log('[KnowledgeBase::save] ' . $e->getMessage());
            throw new RuntimeException('Failed to save knowledge base entry.');
        }
    }

    public function getAll(int $page = 1, int $perPage = 50): array {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        try {
            $stmt = $this->db->prepare('SELECT * FROM knowledge_base ORDER BY id DESC LIMIT ? OFFSET ?');
            $stmt->execute([$perPage, $offset]);
            $items = $stmt->fetchAll();
            $total = (int)$this->db->query('SELECT COUNT(*) FROM knowledge_base')->fetchColumn();

            return [
                'items'    => $items,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int)ceil($total / $perPage),
            ];

        } catch (Throwable $e) {
            error_log('[KnowledgeBase::getAll] ' . $e->getMessage());
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
    }

    public function update(int $id, string $question, string $answer): void {
        $question = mb_substr(trim($question), 0, self::MAX_QUESTION_LEN);
        $answer   = mb_substr(trim($answer),   0, self::MAX_ANSWER_LEN);

        if ($question === '' || $answer === '') {
            throw new InvalidArgumentException('Question and answer cannot be empty.');
        }

        try {
            $this->db->prepare('UPDATE knowledge_base SET question = ?, answer = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$question, $answer, $id]);
        } catch (Throwable $e) {
            error_log("[KnowledgeBase::update] id=$id : " . $e->getMessage());
            throw new RuntimeException('Failed to update knowledge base entry.');
        }
    }

    public function delete(int $id): void {
        try {
            $this->db->prepare('UPDATE knowledge_base SET is_active = 0, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
        } catch (Throwable $e) {
            error_log("[KnowledgeBase::delete] id=$id : " . $e->getMessage());
            throw new RuntimeException('Failed to delete knowledge base entry.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // دالة جديدة لجلب معلومات المؤسسة للذكاء الاصطناعي
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * جلب كل إعدادات المؤسسة من جدول business_settings
     * وتنسيقها كنص يمكن إضافته إلى سياق الذكاء الاصطناعي.
     */
    public function getBusinessContext(): string {
        try {
            $stmt = $this->db->query("SELECT key_name, value_text FROM business_settings");
            $data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if (empty($data)) {
                return "لا توجد معلومات إضافية للمؤسسة.";
            }

            $context = "معلومات هامة عن المؤسسة:\n";
            foreach ($data as $key => $value) {
                // تحسين شكل المفتاح (opening_hours → Opening hours)
                $readableKey = ucfirst(str_replace('_', ' ', $key));
                $context .= "- {$readableKey}: {$value}\n";
            }
            return $context;

        } catch (Throwable $e) {
            error_log('[KnowledgeBase::getBusinessContext] ' . $e->getMessage());
            return "معلومات المؤسسة غير متوفرة حالياً.";
        }
    }
}