<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

/**
 * The other parts of the series in your library, from Wikidata: which ones you have
 * (matched by title, original title or part number) and which are missing, offered as
 * books you might like to read. Looked up per series and kept for 30 days.
 */
final class SeriesParts
{
    private const MAX_AGE_DAYS = 30;

    public function __construct(private PDO $db, private Books $books, private Wikidata $wikidata)
    {
    }

    /** @return list<string> Series in the library not looked up yet, or too long ago. */
    public function toRefresh(int $limit): array
    {
        return array_map('strval', $this->query(
            'SELECT DISTINCT b.series FROM books b LEFT JOIN series_parts p ON p.series = b.series
             WHERE b.series IS NOT NULL AND b.series != \'\'
               AND (p.series IS NULL OR p.fetched_at < NOW() - INTERVAL ' . self::MAX_AGE_DAYS . ' DAY)
             ORDER BY b.series LIMIT ' . max(1, $limit),
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function countToRefresh(): int
    {
        return (int) $this->query(
            'SELECT COUNT(DISTINCT b.series) FROM books b LEFT JOIN series_parts p ON p.series = b.series
             WHERE b.series IS NOT NULL AND b.series != \'\'
               AND (p.series IS NULL OR p.fetched_at < NOW() - INTERVAL ' . self::MAX_AGE_DAYS . ' DAY)',
        )->fetchColumn();
    }

    /**
     * Look a series up in Wikidata and remember its parts. Returns how many parts it
     * has, or null when Wikidata doesn't know the series.
     */
    public function refresh(string $series): ?int
    {
        $books = $this->books->seriesBooks($series);
        $qids = array_count_values(array_filter(array_map(static fn (array $b) => (string) ($b['wikidata_series'] ?? ''), $books)));
        arsort($qids);
        $qid = $qids ? (string) array_key_first($qids) : null;
        $qid ??= $this->wikidata->findSeries($series, array_values(array_unique(array_column($books, 'author'))));
        $parts = $qid !== null ? $this->wikidata->seriesParts($qid) : null;
        $this->query(
            'INSERT INTO series_parts (series, qid, parts, fetched_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE qid = VALUES(qid), parts = VALUES(parts), fetched_at = VALUES(fetched_at)',
            [$series, $qid, $parts !== null ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null],
        );
        return $parts !== null ? count($parts) : null;
    }

    /**
     * Every looked-up series with its parts, each with the book you have of it (or
     * null), and the parts you're missing (minus the ones you said no to).
     *
     * @return list<array{series: string, qid: ?string, fetched_at: string, parts: list<array<string, mixed>>, have: int, missing: list<array<string, mixed>>}>
     */
    public function overview(?string $only = null): array
    {
        $rows = $this->query(
            'SELECT p.series, p.qid, p.parts, p.fetched_at FROM series_parts p
             WHERE EXISTS (SELECT 1 FROM books b WHERE b.series = p.series)' . ($only !== null ? ' AND p.series = ?' : '') . '
             ORDER BY p.series',
            $only !== null ? [$only] : [],
        )->fetchAll();
        if (!$rows) {
            return [];
        }
        [$byTitle, $bySeries] = $this->libraryIndex();
        $dismissed = array_flip(array_map('strval', $this->query('SELECT qid FROM dismissed_suggestions')->fetchAll(PDO::FETCH_COLUMN)));
        $result = [];
        foreach ($rows as $row) {
            $parts = json_decode((string) $row['parts'], true);
            $parts = is_array($parts) ? $parts : [];
            $have = 0;
            $missing = [];
            // First by title, then by part number, and each of your books only once.
            $used = [];
            foreach ($parts as &$part) {
                $part['number'] = isset($part['number']) ? (float) $part['number'] : null; // JSON made 2.0 into 2
                $part['book'] = $this->matchTitle($part, $byTitle);
                if ($part['book'] !== null) {
                    $used[$part['book']['id']] = true;
                }
            }
            unset($part);
            foreach ($parts as &$part) {
                if ($part['book'] === null && $part['number'] !== null) {
                    $book = $bySeries[Wikidata::matchKey((string) $row['series']) . '#' . $part['number']] ?? null;
                    if ($book !== null && !isset($used[$book['id']])) {
                        $part['book'] = $book;
                        $used[$book['id']] = true;
                    }
                }
                if ($part['book'] !== null) {
                    $have++;
                } elseif (!isset($dismissed[(string) $part['qid']])) {
                    $missing[] = $part;
                }
            }
            unset($part);
            $result[] = [
                'series' => (string) $row['series'],
                'qid' => $row['qid'] !== null ? (string) $row['qid'] : null,
                'fetched_at' => (string) $row['fetched_at'],
                'parts' => $parts,
                'have' => $have,
                'missing' => $missing,
            ];
        }
        return $result;
    }

    /** Put a missing part on your to-read list; returns the new book's id. */
    public function addToRead(string $series, string $qid): int
    {
        $part = $this->part($series, $qid) ?? throw new InvalidArgumentException('Dat deel is niet (meer) bekend.');
        return $this->books->create(Books::validate([
            'title' => $part['title'],
            'author' => $part['author'] ?? '',
            'status' => 'to-read',
            'series' => $series,
            'series_number' => isset($part['number']) ? (string) $part['number'] : null,
            'first_published' => isset($part['year']) ? (string) $part['year'] : null,
        ]));
    }

    public function dismiss(string $qid): void
    {
        if (!preg_match('/^Q\d{1,12}$/', $qid)) {
            throw new InvalidArgumentException('Onbekend boek.');
        }
        $this->query('INSERT IGNORE INTO dismissed_suggestions (qid) VALUES (?)', [$qid]);
    }

    /** @return array<string, mixed>|null */
    private function part(string $series, string $qid): ?array
    {
        $json = $this->query('SELECT parts FROM series_parts WHERE series = ?', [$series])->fetchColumn();
        foreach (json_decode((string) $json, true) ?: [] as $part) {
            if (($part['qid'] ?? null) === $qid) {
                return $part;
            }
        }
        return null;
    }

    /**
     * Your books by title (also the original title, and without a subtitle), and by
     * series and part number.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function libraryIndex(): array
    {
        $byTitle = [];
        $bySeries = [];
        foreach ($this->query('SELECT id, title, original_title, series, series_number, status FROM books ORDER BY id')->fetchAll() as $book) {
            $book = ['id' => (int) $book['id']] + $book;
            foreach ([$book['title'], $book['original_title']] as $title) {
                foreach (self::keys((string) $title) as $key) {
                    $byTitle[$key] ??= $book;
                }
            }
            if ($book['series'] !== null && $book['series_number'] !== null) {
                $bySeries[Wikidata::matchKey((string) $book['series']) . '#' . (float) $book['series_number']] ??= $book;
            }
        }
        return [$byTitle, $bySeries];
    }

    /**
     * @param array<string, mixed> $part
     * @param array<string, array<string, mixed>> $byTitle
     * @return array<string, mixed>|null
     */
    private function matchTitle(array $part, array $byTitle): ?array
    {
        foreach ([$part['title'] ?? '', $part['title_en'] ?? ''] as $title) {
            foreach (self::keys((string) $title) as $key) {
                if (isset($byTitle[$key])) {
                    return $byTitle[$key];
                }
            }
        }
        return null;
    }

    /** @return list<string> "De avonden: een winterverhaal" → ["de avonden een winterverhaal", "de avonden"] */
    private static function keys(string $title): array
    {
        $main = trim((string) preg_split('/\s*[:(\[]/u', $title)[0]);
        // A main title of one or two letters says too little to match on.
        return array_values(array_unique(array_filter([Wikidata::matchKey($title), Wikidata::matchKey($main)], static fn (string $k) => mb_strlen($k) >= 3)));
    }

    /** @param list<mixed> $args */
    private function query(string $sql, array $args = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }
}
