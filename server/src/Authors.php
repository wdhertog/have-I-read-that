<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

/**
 * Author pages: who a writer is (from Wikidata and Wikipedia, kept for 30 days, with
 * the photo stored on this server), your books by them, and their other work.
 */
final class Authors
{
    private const MAX_AGE_DAYS = 30;
    private const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

    /** @param \Closure(string): ?string $get HTTPS GET, for the photo. */
    public function __construct(
        private PDO $db,
        private Books $books,
        private Wikidata $wikidata,
        private string $photoDir,
        private \Closure $get,
    ) {
    }

    /**
     * What's known about a writer; looked up when it never was, or 30 days ago, or
     * when asked to.
     *
     * @return array{name: string, qid: ?string, data: ?array<string, mixed>, fetched_at: ?string}
     */
    public function profile(string $name, bool $refresh = false): array
    {
        $row = $this->query('SELECT qid, data, fetched_at FROM authors WHERE name = ?', [$name])->fetch();
        $stale = !$row || strtotime((string) $row['fetched_at']) < time() - self::MAX_AGE_DAYS * 86400;
        if ($refresh || $stale) {
            $titles = array_values(array_filter(array_merge(
                array_column($this->books($name), 'title'),
                array_column($this->books($name), 'original_title'),
            )));
            $qid = $this->wikidata->findAuthor($name, array_map('strval', $titles));
            $data = $qid !== null ? $this->wikidata->author($qid) : null;
            if ($qid !== null && $data !== null) {
                $data['photo'] = $this->savePhoto($qid, $data['image'] ?? null);
            }
            $this->query(
                'INSERT INTO authors (name, qid, data, fetched_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE qid = VALUES(qid), data = VALUES(data), fetched_at = VALUES(fetched_at)',
                [$name, $qid, $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null],
            );
            $row = ['qid' => $qid, 'data' => $data !== null ? json_encode($data) : null, 'fetched_at' => date('Y-m-d H:i:s')];
        }
        $data = json_decode((string) $row['data'], true);
        return [
            'name' => $name,
            'qid' => $row['qid'] !== null ? (string) $row['qid'] : null,
            'data' => is_array($data) ? $data : null,
            'fetched_at' => (string) $row['fetched_at'],
        ];
    }

    /**
     * Your books with this writer among the authors ("A, B" counts for both).
     *
     * @return list<array<string, mixed>>
     */
    public function books(string $name): array
    {
        $key = mb_strtolower(Wikidata::firstLast($name));
        return array_values(array_filter(
            $this->books->list(),
            static fn (array $book) => in_array($key, array_map(
                static fn (string $a) => mb_strtolower(Wikidata::firstLast($a)),
                self::names((string) $book['author']),
            ), true),
        ));
    }

    /**
     * Their other work, each with the book you have of it (or null), minus what you
     * said no to.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public function works(array $data): array
    {
        $index = new TitleIndex($this->db);
        $dismissed = array_flip(array_map('strval', $this->query('SELECT qid FROM dismissed_suggestions')->fetchAll(PDO::FETCH_COLUMN)));
        $works = [];
        foreach ($data['works'] ?? [] as $work) {
            $work['book'] = $index->byTitle([$work['title'] ?? null, $work['title_en'] ?? null]);
            if ($work['book'] !== null || !isset($dismissed[(string) $work['qid']])) {
                $works[] = $work;
            }
        }
        return $works;
    }

    /** Put one of their works on your to-read list; returns the new book's id. */
    public function addToRead(string $name, string $qid): int
    {
        $data = $this->profile($name)['data'] ?? [];
        foreach ($data['works'] ?? [] as $work) {
            if (($work['qid'] ?? null) === $qid) {
                return $this->books->create(Books::validate([
                    'title' => $work['title'],
                    'author' => $name,
                    'status' => 'to-read',
                    'first_published' => isset($work['year']) ? (string) $work['year'] : null,
                ]));
            }
        }
        throw new InvalidArgumentException('Dat boek is niet (meer) bekend.');
    }

    /** The stored photo of a writer (by the number of their Q-id). */
    public function photo(int $number): ?string
    {
        $path = "{$this->photoDir}/Q{$number}.jpg";
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Writers in your library: how many books, how many read, and the average rating.
     *
     * @return list<array{name: string, books: int, read: int, rating: ?float}>
     */
    public function all(): array
    {
        $authors = [];
        foreach ($this->query('SELECT author, status, rating FROM books WHERE author != \'\'')->fetchAll() as $book) {
            foreach (self::names((string) $book['author']) as $name) {
                $key = mb_strtolower(Wikidata::firstLast($name));
                $authors[$key] ??= ['spellings' => [], 'books' => 0, 'read' => 0, 'ratings' => []];
                $authors[$key]['spellings'][$name] = ($authors[$key]['spellings'][$name] ?? 0) + 1;
                $authors[$key]['books']++;
                $authors[$key]['read'] += (int) ($book['status'] === 'read');
                if ($book['rating'] !== null) {
                    $authors[$key]['ratings'][] = (float) $book['rating'];
                }
            }
        }
        $result = [];
        foreach ($authors as $a) {
            $result[] = [
                'name' => Books::spelling($a['spellings']),
                'books' => $a['books'],
                'read' => $a['read'],
                'rating' => $a['ratings'] ? round(array_sum($a['ratings']) / count($a['ratings']), 2) : null,
            ];
        }
        usort($result, static fn (array $a, array $b) => [$b['books'], $a['name']] <=> [$a['books'], $b['name']]);
        return $result;
    }

    /**
     * "Gerard Reve, Jan Wolkers" → two names; "Reve, Gerard" stays one.
     *
     * @return list<string>
     */
    public static function names(string $authors): array
    {
        $authors = trim($authors);
        if ($authors === '') {
            return [];
        }
        if (preg_match('/^[^,\s]+,\s*[^,]+$/u', $authors)) {
            return [$authors]; // "Reve, Gerard": a surname, then first names
        }
        return array_values(array_filter(array_map('trim', preg_split('/\s*[,;&]\s*|\s+(?:en|and)\s+/u', $authors) ?: [])));
    }

    /** Download the Commons picture once and keep it as a plain JPEG on this server. */
    private function savePhoto(string $qid, ?string $url): bool
    {
        if ($url === null || !preg_match('/^Q\d{1,12}$/', $qid)) {
            return false;
        }
        $path = "{$this->photoDir}/{$qid}.jpg";
        if (is_file($path)) {
            return true;
        }
        $bytes = ($this->get)($url . '?width=400');
        $jpeg = $bytes !== null && strlen($bytes) <= self::MAX_PHOTO_BYTES ? Covers::toJpeg($bytes) : null;
        if ($jpeg === null) {
            return false;
        }
        if (!is_dir($this->photoDir)) {
            @mkdir($this->photoDir, 0700, true);
        }
        return @file_put_contents($path, $jpeg, LOCK_EX) !== false;
    }

    /** @param list<mixed> $args */
    private function query(string $sql, array $args = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }
}
