<?php
/**
 * @filesource Gcms/Chat/QuickAnswerRepository.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms\Chat;

/**
 * DB-backed quick answer / FAQ definitions.
 *
 * @since 1.0
 */
class QuickAnswerRepository extends \Kotchasan\KBase
{
    /**
     * @var string
     */
    private const TABLE = 'ai_chat_quick_answers';

    /**
     * @var bool|null
     */
    private static $tableAvailable;

    /**
     * Return configured quick answers.
     *
     * @param bool $publishedOnly
     *
     * @return array
     */
    public function all(bool $publishedOnly = false): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        try {
            $query = \Kotchasan\Model::createQuery()
                ->select('*')
                ->from(self::TABLE)
                ->orderBy('sort_order', 'ASC')
                ->orderBy('id', 'ASC');

            if ($publishedOnly) {
                $query->where(['published', 1]);
            }

            $result = $query->execute();
        } catch (\Exception $e) {
            self::$tableAvailable = false;

            return [];
        }

        $items = [];
        foreach ($result->fetchAll() as $row) {
            $items[] = $this->normalizeStored($row);
        }

        return $items;
    }

    /**
     * Replace all quick answers from admin UI input.
     *
     * @param array $items
     *
     * @return bool
     */
    public function saveMany(array $items): bool
    {
        if (!$this->tableAvailable()) {
            return false;
        }

        $rows = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = $this->normalizeInput($item, $index + 1);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        $connection = \Kotchasan\Database::getConnection();
        $pdo = $connection ? $connection->getConnection() : null;
        $transaction = is_object($pdo) && method_exists($pdo, 'beginTransaction');

        try {
            if ($transaction) {
                $pdo->beginTransaction();
            }

            \Kotchasan\DB::create()->delete(self::TABLE, [['id', '>', 0]], 0);
            $db = \Kotchasan\DB::create();
            $timestamp = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $db->insert(self::TABLE, [
                    'title' => $row['title'],
                    'keywords' => $row['keywords'],
                    'match_mode' => $row['match_mode'],
                    'answer_text' => $row['answer_text'],
                    'sort_order' => $row['sort_order'],
                    'published' => $row['published'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp
                ]);
            }

            if ($transaction) {
                $pdo->commit();
            }
        } catch (\Exception $e) {
            if ($transaction && method_exists($pdo, 'rollBack')) {
                $pdo->rollBack();
            }

            return false;
        }

        return true;
    }

    /**
     * Match one message text to the first configured FAQ.
     *
     * @param string $text
     *
     * @return array|null
     */
    public function matchText(string $text): ?array
    {
        $normalizedText = mb_strtolower(trim($text));
        if ($normalizedText === '') {
            return null;
        }

        $bestItem = null;
        $bestKeywordLen = -1;
        $bestSort = PHP_INT_MAX;
        foreach ($this->all(true) as $item) {
            $keywords = $this->keywords($item['keywords']);
            $sort = isset($item['sort_order']) ? (int) $item['sort_order'] : 0;
            foreach ($keywords as $keyword) {
                if ($item['match_mode'] === 'exact' && $normalizedText === $keyword) {
                    return $item;
                }
                if ($item['match_mode'] !== 'exact' && mb_strlen($keyword) >= 2
                    && mb_strpos($normalizedText, $keyword) !== false) {
                    $len = mb_strlen($keyword);
                    if ($len > $bestKeywordLen || ($len === $bestKeywordLen && $sort < $bestSort)) {
                        $bestKeywordLen = $len;
                        $bestSort = $sort;
                        $bestItem = $item;
                    }
                }
            }
        }

        return $bestItem;
    }

    /**
     * Prompt actions for widget/admin quick replies.
     *
     * @param int $limit
     *
     * @return array
     */
    public function promptActions(int $limit = 3): array
    {
        $actions = [];
        foreach (array_slice($this->all(true), 0, max(0, $limit)) as $item) {
            $keywords = $this->keywords($item['keywords']);
            $value = !empty($keywords) ? $keywords[0] : $item['title'];
            if ($value === '') {
                continue;
            }
            $actions[] = [
                'type' => 'prompt',
                'label' => $item['title'] !== '' ? $item['title'] : $value,
                'value' => $value
            ];
        }

        return $actions;
    }

    /**
     * Compact quick-answer context for AI fallback.
     *
     * @param int $limit
     *
     * @return array
     */
    public function aiContext(int $limit = 8): array
    {
        $items = [];
        foreach (array_slice($this->all(true), 0, max(0, $limit)) as $item) {
            $items[] = [
                'title' => $item['title'],
                'keywords' => $this->keywords($item['keywords']),
                'answer_text' => mb_substr($item['answer_text'], 0, 280)
            ];
        }

        return $items;
    }

    /**
     * @param object $row
     *
     * @return array
     */
    private function normalizeStored($row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'title' => trim((string) ($row->title ?? '')),
            'keywords' => trim((string) ($row->keywords ?? '')),
            'match_mode' => $this->normalizeMode($row->match_mode ?? 'contains'),
            'answer_text' => trim(str_replace(["\r\n", "\r"], "\n", (string) ($row->answer_text ?? ''))),
            'sort_order' => (int) ($row->sort_order ?? 0),
            'published' => !empty($row->published) ? 1 : 0
        ];
    }

    /**
     * @param array $row
     * @param int   $index
     *
     * @return array|null
     */
    private function normalizeInput(array $row, int $index): ?array
    {
        $title = trim((string) ($row['title'] ?? ''));
        $keywords = trim((string) ($row['keywords'] ?? ''));
        $answerText = trim(str_replace(["\r\n", "\r"], "\n", (string) ($row['answer_text'] ?? '')));

        if ($title === '' && $keywords === '' && $answerText === '') {
            return null;
        }

        return [
            'title' => $title,
            'keywords' => implode("\n", $this->keywords($keywords)),
            'match_mode' => $this->normalizeMode($row['match_mode'] ?? 'contains'),
            'answer_text' => $answerText,
            'sort_order' => isset($row['sort_order']) && $row['sort_order'] !== '' ? (int) $row['sort_order'] : $index,
            'published' => !empty($row['published']) ? 1 : 0
        ];
    }

    /**
     * @param string $keywords
     *
     * @return array
     */
    private function keywords(string $keywords): array
    {
        $parts = preg_split('/[\r\n,]+/u', mb_strtolower($keywords));
        if (!is_array($parts)) {
            return [];
        }

        $items = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $items[$part] = $part;
            }
        }

        return array_values($items);
    }

    /**
     * @param mixed $mode
     *
     * @return string
     */
    private function normalizeMode($mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return $mode === 'exact' ? 'exact' : 'contains';
    }

    /**
     * @return bool
     */
    private function tableAvailable(): bool
    {
        if (self::$tableAvailable !== null) {
            return self::$tableAvailable;
        }

        try {
            \Kotchasan\Model::createQuery()
                ->select('id')
                ->from(self::TABLE)
                ->limit(1)
                ->execute();
            self::$tableAvailable = true;
        } catch (\Exception $e) {
            self::$tableAvailable = false;
        }

        return self::$tableAvailable;
    }
}