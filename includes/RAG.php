<?php
/**
 * RAG.php — الذاكرة الذكية لمحمد
 *
 * كيف يشتغل:
 *  1. لما زبون يسأل → نحول سؤاله لـ embedding (أرقام تمثل معنى السؤال)
 *  2. نقارنه مع كل الذكريات المحفوظة (cosine similarity)
 *  3. إذا في تشابه > 80% → نعطي محمد الجواب القديم كسياق
 *  4. إذا رمزي صحح جواب أو زبون وافق → نحفظ المحادثة
 *
 * يتعلم من:
 *  - كل موافقة زبون (auto)
 *  - كل تصحيح يعمله رمزي (approved) — أعلى أولوية
 */


class RAG
{
    private PDO    $db;
    private string $openaiKey;

    // حد التشابه — فوقه يُعتبر "إجابة مشابهة"
    private const SIMILARITY_THRESHOLD = 0.80;

    // كم ذكرى نجيب كسياق لمحمد
    private const MAX_CONTEXT_MEMORIES = 3;

    public function __construct(PDO $db)
    {
        $this->db        = $db;
        $this->openaiKey = defined('OPENAI_KEY') ? OPENAI_KEY : (getenv('OPENAI_KEY') ?: '');
    }

    // ═══════════════════════════════════════════════════════
    //  البحث — أهم دالة
    //  تُستدعى قبل ما محمد يجاوب
    // ═══════════════════════════════════════════════════════

    /**
     * ابحث عن ذكريات مشابهة لسؤال الزبون
     * @return array قائمة بالذكريات المشابهة مرتبة بالأقرب أولاً
     */
    public function search(string $question): array
    {
        if (trim($question) === '') return [];

        // 1. حوّل السؤال لـ embedding
        $queryEmbedding = $this->embed($question);
        if (empty($queryEmbedding)) return [];

        // 2. جيب كل الذكريات من الـ DB
        $stmt = $this->db->query(
            "SELECT id, question, answer, embedding, source, use_count, score, tags
             FROM rag_memories
             WHERE score > 0.3
             ORDER BY score DESC, use_count DESC
             LIMIT 200"
        );
        $memories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($memories)) return [];

        // 3. احسب التشابه لكل ذكرى
        $results = [];
        foreach ($memories as $mem) {
            $memEmbedding = json_decode($mem['embedding'], true);
            if (!is_array($memEmbedding)) continue;

            $similarity = $this->cosineSimilarity($queryEmbedding, $memEmbedding);
            if ($similarity >= self::SIMILARITY_THRESHOLD) {
                $results[] = [
                    'id'         => $mem['id'],
                    'question'   => $mem['question'],
                    'answer'     => $mem['answer'],
                    'similarity' => $similarity,
                    'source'     => $mem['source'],
                    'use_count'  => $mem['use_count'],
                    'score'      => $mem['score'],
                    'tags'       => $mem['tags'],
                ];
            }
        }

        // 4. رتّب بالأكثر تشابهاً + الأعلى تقييماً
        usort($results, fn($a, $b) =>
            ($b['similarity'] * $b['score']) <=> ($a['similarity'] * $a['score'])
        );

        return array_slice($results, 0, self::MAX_CONTEXT_MEMORIES);
    }

    /**
     * بناء نص السياق اللي يُضاف لـ prompt محمد
     */
    public function buildContext(string $question): string
    {
        $memories = $this->search($question);
        if (empty($memories)) return '';

        $lines = ["📚 **تجارب سابقة مشابهة** (استخدمها كمرجع وكيّف جوابك):\n"];
        foreach ($memories as $i => $m) {
            $sourceLabel = $m['source'] === 'approved' ? '✅ رمزي صحّح' : '💬 زبون وافق';
            $lines[] = sprintf(
                "[%d] %s (تشابه %.0f%%)\nسؤال: %s\nجواب: %s\n",
                $i + 1,
                $sourceLabel,
                $m['similarity'] * 100,
                $m['question'],
                $m['answer']
            );
            // زوّد عداد الاستخدام
            $this->incrementUseCount((int)$m['id']);
        }

        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════
    //  الحفظ — تتعلم من كل تجربة ناجحة
    // ═══════════════════════════════════════════════════════

    /**
     * احفظ محادثة ناجحة (زبون وافق)
     */
    public function saveAuto(string $question, string $answer, ?string $tags = null): bool
    {
        return $this->save($question, $answer, 'auto', 0.7, $tags);
    }

    /**
     * احفظ تصحيح رمزي — أعلى أولوية
     */
    public function saveApproved(string $question, string $answer, ?string $tags = null): bool
    {
        return $this->save($question, $answer, 'approved', 1.0, $tags);
    }

    /**
     * خفّض تقييم ذكرى (زبون رفض أو شكا)
     */
    public function penalize(int $memoryId, float $penalty = 0.2): void
    {
        $this->db->prepare(
            "UPDATE rag_memories SET score = GREATEST(0, score - ?) WHERE id = ?"
        )->execute([$penalty, $memoryId]);
    }

    // ═══════════════════════════════════════════════════════
    //  الإدارة
    // ═══════════════════════════════════════════════════════

    /**
     * إحصائيات الذاكرة
     */
    public function stats(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(source='approved') as approved,
                SUM(source='auto') as auto_saved,
                AVG(score) as avg_score,
                SUM(use_count) as total_uses
             FROM rag_memories"
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    /**
     * احذف الذكريات الضعيفة (score < 0.2)
     */
    public function cleanup(): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM rag_memories WHERE score < 0.2 AND source != 'approved'"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ═══════════════════════════════════════════════════════
    //  قاموس الأسماء العامية + الأعطال
    // ═══════════════════════════════════════════════════════

    /**
     * حوّل الاسم العامي للاسم الرسمي بالمخزن
     * مثال: "كربون" → "مروحة"، "سير" → "قشاط"
     */
    public function resolveAlias(string $text): string
    {
        if (trim($text) === '') return $text;

        try {
            $stmt = $this->db->prepare(
                "SELECT official_name, device FROM product_aliases
                 WHERE ? LIKE CONCAT('%', alias, '%')
                 ORDER BY LENGTH(alias) DESC LIMIT 1"
            );
            $stmt->execute([mb_strtolower(trim($text))]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row['official_name'] . ($row['device'] ? ' ' . $row['device'] : '');
            }
        } catch (Throwable $e) {
            error_log('[RAG::resolveAlias] ' . $e->getMessage());
        }
        return $text;
    }

    /**
     * ابحث عن معلومات العطل من قاعدة المعرفة
     * مثال: "ثلاجة + ما بتبرد" → السبب والقطعة المطلوبة
     */
    public function getFaultKnowledge(string $device, string $symptom): array
    {
        if (trim($symptom) === '') return [];

        try {
            // بحث FULLTEXT أولاً
            $stmt = $this->db->prepare(
                "SELECT cause, part_needed, priority
                 FROM fault_knowledge
                 WHERE (device = ? OR device = '')
                 AND MATCH(symptom) AGAINST(? IN BOOLEAN MODE)
                 ORDER BY priority ASC, MATCH(symptom) AGAINST(? IN BOOLEAN MODE) DESC
                 LIMIT 3"
            );
            $stmt->execute([$device, $symptom, $symptom]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // إذا ما لقى بالـ FULLTEXT → LIKE
            if (empty($rows)) {
                $words = explode(' ', $symptom);
                $conditions = [];
                $params = [$device];
                foreach ($words as $w) {
                    if (mb_strlen($w) > 2) {
                        $conditions[] = "symptom LIKE ?";
                        $params[] = "%$w%";
                    }
                }
                if (!empty($conditions)) {
                    $sql = "SELECT cause, part_needed, priority FROM fault_knowledge
                            WHERE (device = ? OR device = '') AND (" . implode(' OR ', $conditions) . ")
                            ORDER BY priority ASC LIMIT 3";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            return $rows;
        } catch (Throwable $e) {
            error_log('[RAG::getFaultKnowledge] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * بناء سياق كامل شامل: ذكريات + أعطال + أسماء عامية
     */
    public function buildFullContext(string $question, string $device = ''): string
    {
        $parts = [];

        // 1. ذكريات من محادثات سابقة
        $memories = $this->search($question);
        if (!empty($memories)) {
            $lines = ["📚 تجارب سابقة مشابهة:"];
            foreach ($memories as $i => $m) {
                $label = $m['source'] === 'approved' ? '✅ مصحح' : '💬 ناجح';
                $lines[] = sprintf("[%d] %s — سؤال: %s → جواب: %s",
                    $i + 1, $label, $m['question'], $m['answer']);
                $this->incrementUseCount((int)$m['id']);
            }
            $parts[] = implode("\n", $lines);
        }

        // 2. معلومات العطل إذا ذُكر جهاز
        if ($device) {
            $faults = $this->getFaultKnowledge($device, $question);
            if (!empty($faults)) {
                $lines = ["🔧 أعطال شائعة مشابهة:"];
                foreach ($faults as $f) {
                    $lines[] = "- السبب: {$f['cause']}" . ($f['part_needed'] ? " | القطعة: {$f['part_needed']}" : '');
                }
                $parts[] = implode("\n", $lines);
            }
        }

        return implode("\n\n", $parts);
    }

    // ═══════════════════════════════════════════════════════
    //  الداخل — دوال مساعدة
    // ═══════════════════════════════════════════════════════

    private function save(
        string $question,
        string $answer,
        string $source,
        float  $score,
        ?string $tags
    ): bool {
        if (trim($question) === '' || trim($answer) === '') return false;

        // تحقق إذا في سؤال مشابه جداً (> 95%) — تحديث بدل إضافة
        $existing = $this->search($question);
        foreach ($existing as $mem) {
            if ($mem['similarity'] > 0.95) {
                // حدّث الجواب إذا المصدر الجديد أفضل
                if ($source === 'approved' || $mem['source'] === 'auto') {
                    $this->db->prepare(
                        "UPDATE rag_memories
                         SET answer=?, source=?, score=GREATEST(score,?), use_count=use_count+1, updated_at=NOW()
                         WHERE id=?"
                    )->execute([$answer, $source, $score, $mem['id']]);
                }
                return true;
            }
        }

        // سؤال جديد — احفظه
        $embedding = $this->embed($question);
        if (empty($embedding)) return false;

        $this->db->prepare(
            "INSERT INTO rag_memories (question, answer, embedding, source, score, tags)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $question,
            $answer,
            json_encode($embedding),
            $source,
            $score,
            $tags,
        ]);

        return true;
    }

    private function incrementUseCount(int $id): void
    {
        $this->db->prepare(
            "UPDATE rag_memories SET use_count = use_count + 1 WHERE id = ?"
        )->execute([$id]);
    }

    /**
     * حوّل نص لـ embedding عبر OpenAI
     * text-embedding-3-small: رخيص جداً، 1536 بُعد
     */
    private function embed(string $text): array
    {
        if ($this->openaiKey === '') return [];

        try {
            $ch = curl_init('https://api.openai.com/v1/embeddings');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'model' => 'text-embedding-3-small',
                    'input' => mb_substr(trim($text), 0, 2000), // حد أمان
                ]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->openaiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 15,
            ]);

            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $code >= 400) return [];

            $data = json_decode($raw, true);
            return (array)($data['data'][0]['embedding'] ?? []);

        } catch (Throwable $e) {
            error_log('[RAG::embed] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cosine Similarity — يقيس التشابه بين سؤالين (0 = مختلف تماماً، 1 = نفس المعنى)
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) return 0.0;

        $dot  = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $val) {
            $dot   += $val * $b[$i];
            $normA += $val * $val;
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? (float)($dot / $denom) : 0.0;
    }
}
