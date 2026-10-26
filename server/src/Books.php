<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

/**
 * All reading-log data access, shared by the web UI and the API.
 */
final class Books
{
    public const STATUSES = [
        'reading' => 'Aan het lezen',
        'to-read' => 'Wil ik lezen',
        'read' => 'Gelezen',
        'dnf' => 'Niet uitgelezen',
    ];
    public const FORMATS = ['paperback', 'hardcover', 'ebook', 'audio'];
    /** Statuses the public page shows; the to-read list and DNFs stay private. */
    public const PUBLIC_STATUSES = ['reading', 'read'];
    public const MAX_REVIEW = 10000;
    public const MAX_PAGES = 100000;
    public const MAX_GOAL = 10000;

    /** Book columns plus the progress of the current (unfinished) read. */
    private const SELECT_BOOK = "SELECT b.*,
            (SELECT MAX(r.finished) FROM `reads` r WHERE r.book_id = b.id) AS last_finished,
            (SELECT r.progress_page FROM `reads` r WHERE r.book_id = b.id AND r.finished IS NULL ORDER BY r.id DESC LIMIT 1) AS progress_page,
            (SELECT r.progress_percent FROM `reads` r WHERE r.book_id = b.id AND r.finished IS NULL ORDER BY r.id DESC LIMIT 1) AS progress_percent
        FROM books b";

    public function __construct(private PDO $db)
    {
    }

    public function db(): PDO
    {
        return $this->db;
    }

    /**
     * Validate user input (form or JSON) into a book row.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate(array $input): array
    {
        $str = static function (string $key, int $max = 500) use ($input): ?string {
            $value = self::text($input[$key] ?? null, $max);
            return $value === '' ? null : $value;
        };
        $data = [
            'title' => $str('title'),
            'author' => $str('author') ?? '',
            'isbn' => $str('isbn', 50),
            'format' => $str('format', 50),
            'status' => $str('status', 20) ?? 'to-read',
            'review' => $str('review', self::MAX_REVIEW),
            'tags' => $str('tags', 1000),
            'pages' => $str('pages', 20),
            'rating' => $str('rating', 20),
            'owned' => in_array($input['owned'] ?? null, ['on', '1', 1, true], true) ? 1 : 0,
        ];
        if ($data['title'] === null) {
            throw new InvalidArgumentException('Titel is verplicht.');
        }
        if (!isset(self::STATUSES[$data['status']])) {
            $data['status'] = 'to-read';
        }
        $data['format'] = self::normalizeFormat($data['format']);
        if ($data['isbn'] !== null) {
            $data['isbn'] = StoryGraph::cleanIsbn($data['isbn']);
        }
        if ($data['pages'] !== null) {
            if (!ctype_digit($data['pages']) || (int) $data['pages'] > self::MAX_PAGES) {
                throw new InvalidArgumentException("Pagina's moet een getal zijn.");
            }
            $data['pages'] = (int) $data['pages'];
        }
        $data['rating'] = self::validateRating($data['rating']);
        return $data;
    }

    /** Map a format (e.g. StoryGraph's "digital") onto FORMATS, or null. */
    public static function normalizeFormat(?string $format): ?string
    {
        $format = strtolower(trim((string) $format));
        $format = ['digital' => 'ebook', 'e-book' => 'ebook', 'audiobook' => 'audio'][$format] ?? $format;
        return in_array($format, self::FORMATS, true) ? $format : null;
    }

    /**
     * A trimmed string from form or JSON input. Arrays/objects count as empty, so odd
     * input can't cause warnings or database errors.
     */
    public static function text(mixed $value, int $max): string
    {
        $value = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Tekst bevat ongeldige tekens.');
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Tekst is te lang (maximaal {$max} tekens).");
        }
        return $value;
    }

    public static function validateRating(mixed $rating): ?float
    {
        $rating = str_replace(',', '.', self::text($rating, 20));
        if ($rating === '') {
            return null;
        }
        if (!is_numeric($rating) || (float) $rating <= 0 || (float) $rating > 5) {
            throw new InvalidArgumentException('Beoordeling moet tussen 0 en 5 liggen.');
        }
        return (float) $rating;
    }

    public static function validDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status = null, string $q = '', ?int $limit = null): array
    {
        $sql = self::SELECT_BOOK . ' WHERE 1 = 1';
        $args = [];
        if ($status !== null && isset(self::STATUSES[$status])) {
            $sql .= ' AND b.status = ?';
            $args[] = $status;
        }
        if ($q !== '') {
            $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.tags LIKE ? OR b.isbn = ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like, $q);
        }
        $sql .= " ORDER BY b.status = 'reading' DESC, COALESCE(last_finished, b.date_added) DESC, b.id DESC";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        return array_map([self::class, 'cast'], $this->query($sql, $args)->fetchAll());
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->query('SELECT status, COUNT(*) FROM books GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $book = $this->query(self::SELECT_BOOK . ' WHERE b.id = ?', [$id])->fetch();
        return $book ? self::cast($book) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByIsbn(string $isbn): ?array
    {
        $book = $this->query('SELECT * FROM books WHERE isbn = ? ORDER BY id LIMIT 1', [$isbn])->fetch();
        return $book ? self::cast($book) : null;
    }

    /**
     * An existing book that looks like this one: same ISBN, or the same title and author
     * (the column collation ignores case, accents and trailing spaces).
     *
     * @return array<string, mixed>|null
     */
    public function findDuplicate(string $title, string $author, ?string $isbn, ?int $exceptId = null): ?array
    {
        $collapse = static fn (string $s) => trim((string) preg_replace('/\s+/u', ' ', $s));
        $book = $this->query(
            self::SELECT_BOOK . ' WHERE b.id != ? AND ((? IS NOT NULL AND b.isbn = ?) OR (b.title = ? AND b.author = ?))
             ORDER BY b.id LIMIT 1',
            [$exceptId ?? 0, $isbn, $isbn, $collapse($title), $collapse($author)],
        )->fetch();
        return $book ? self::cast($book) : null;
    }

    /** @return list<array<string, mixed>> */
    public function reads(int $bookId): array
    {
        return $this->query(
            "SELECT id, started, finished, progress_page, progress_percent FROM `reads` WHERE book_id = ?
             ORDER BY COALESCE(finished, started, '9999-12-31') DESC, id DESC",
            [$bookId],
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function posts(int $bookId): array
    {
        return $this->query('SELECT id, kind, url, created_at FROM posts WHERE book_id = ? ORDER BY id DESC', [$bookId])->fetchAll();
    }

    /**
     * Add a book. A reading/read/dnf book gets a matching read entry.
     *
     * @param array<string, mixed> $data Output of validate().
     */
    public function create(array $data, ?string $started = null, ?string $finished = null): int
    {
        $this->db->beginTransaction();
        try {
            $this->query(
                'INSERT INTO books (title, author, isbn, format, pages, status, rating, review, tags, owned, date_added)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())',
                [$data['title'], $data['author'], $data['isbn'], $data['format'], $data['pages'], $data['status'],
                 $data['rating'], $data['review'], $data['tags'], $data['owned']],
            );
            $id = (int) $this->db->lastInsertId();
            $today = date('Y-m-d');
            if ($data['status'] === 'reading') {
                $this->addRead($id, $started ?? $today, null);
            } elseif ($data['status'] === 'read' || ($data['status'] === 'dnf' && ($started || $finished))) {
                $this->addRead($id, $started, $finished ?? $today);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    /** @param array<string, mixed> $data Output of validate(). */
    public function update(int $id, array $data): void
    {
        $this->query(
            'UPDATE books SET title = ?, author = ?, isbn = ?, format = ?, pages = ?, status = ?, rating = ?,
                    review = ?, tags = ?, owned = ?
             WHERE id = ?',
            [$data['title'], $data['author'], $data['isbn'], $data['format'], $data['pages'], $data['status'],
             $data['rating'], $data['review'], $data['tags'], $data['owned'], $id],
        );
    }

    public function delete(int $id): void
    {
        $this->query('DELETE FROM books WHERE id = ?', [$id]);
    }

    public function start(int $id, ?string $date = null): void
    {
        $this->addRead($id, $date ?? date('Y-m-d'), null);
        $this->query("UPDATE books SET status = 'reading' WHERE id = ?", [$id]);
    }

    /** Close the open read (or log one) and store the verdict. Null rating/review keep the current value. */
    public function finish(int $id, ?string $date = null, ?float $rating = null, ?string $review = null, bool $dnf = false): void
    {
        $date ??= date('Y-m-d');
        $open = $this->query(
            'SELECT id FROM `reads` WHERE book_id = ? AND finished IS NULL ORDER BY id DESC LIMIT 1',
            [$id],
        )->fetchColumn();
        if ($open) {
            $this->query('UPDATE `reads` SET finished = ? WHERE id = ?', [$date, $open]);
        } else {
            $this->addRead($id, null, $date);
        }
        $review = $review !== null && trim($review) !== '' ? trim($review) : null;
        $this->query(
            'UPDATE books SET status = ?, rating = COALESCE(?, rating), review = COALESCE(?, review) WHERE id = ?',
            [$dnf ? 'dnf' : 'read', $rating, $review, $id],
        );
    }

    public function addRead(int $bookId, ?string $started, ?string $finished): void
    {
        $this->query('INSERT INTO `reads` (book_id, started, finished) VALUES (?, ?, ?)', [$bookId, $started, $finished]);
    }

    /** Change a read's dates and return the book it belongs to (null if it doesn't exist). */
    public function updateRead(int $readId, ?string $started, ?string $finished): ?int
    {
        $bookId = $this->query('SELECT book_id FROM `reads` WHERE id = ?', [$readId])->fetchColumn();
        if ($bookId === false) {
            return null;
        }
        $this->query('UPDATE `reads` SET started = ?, finished = ? WHERE id = ?', [$started, $finished, $readId]);
        return (int) $bookId;
    }

    /**
     * Record how far into the current read you are, as a page or a percentage. With the
     * book's page count known, one is worked out from the other.
     *
     * @return array{page: ?int, percent: int}
     */
    public function setProgress(int $bookId, ?int $page, ?int $percent): array
    {
        $book = $this->find($bookId) ?? throw new InvalidArgumentException('Boek niet gevonden.');
        if ($book['status'] !== 'reading') {
            throw new InvalidArgumentException('Begin eerst met lezen.');
        }
        $pages = $book['pages'];
        if ($page !== null) {
            if ($page < 0 || $page > self::MAX_PAGES || ($pages !== null && $page > $pages)) {
                throw new InvalidArgumentException($pages !== null
                    ? "Kies een pagina tussen 0 en {$pages}."
                    : 'Ongeldige pagina.');
            }
            if ($pages === null) {
                throw new InvalidArgumentException("Vul eerst het aantal pagina's van het boek in, of geef een percentage.");
            }
            $percent = (int) round(100 * $page / max(1, $pages));
        } elseif ($percent !== null) {
            if ($percent < 0 || $percent > 100) {
                throw new InvalidArgumentException('Kies een percentage tussen 0 en 100.');
            }
            $page = $pages !== null ? (int) round($pages * $percent / 100) : null;
        } else {
            throw new InvalidArgumentException('Geef een pagina of een percentage.');
        }
        $open = $this->query(
            'SELECT id FROM `reads` WHERE book_id = ? AND finished IS NULL ORDER BY id DESC LIMIT 1',
            [$bookId],
        )->fetchColumn();
        if (!$open) {
            $this->addRead($bookId, null, null);
            $open = $this->db->lastInsertId();
        }
        $this->query('UPDATE `reads` SET progress_page = ?, progress_percent = ? WHERE id = ?', [$page, $percent, $open]);
        return ['page' => $page, 'percent' => $percent];
    }

    public function goal(int $year): ?int
    {
        $books = $this->query('SELECT books FROM goals WHERE year = ?', [$year])->fetchColumn();
        return $books === false ? null : (int) $books;
    }

    /** Set the reading goal for a year; null or 0 removes it. */
    public function setGoal(int $year, ?int $books): void
    {
        if ($year < 1900 || $year > 2999) {
            throw new InvalidArgumentException('Ongeldig jaar.');
        }
        if (!$books) {
            $this->query('DELETE FROM goals WHERE year = ?', [$year]);
            return;
        }
        if ($books < 0 || $books > self::MAX_GOAL) {
            throw new InvalidArgumentException('Kies een doel tussen 1 en ' . self::MAX_GOAL . ' boeken.');
        }
        $this->query('INSERT INTO goals (year, books) VALUES (?, ?) ON DUPLICATE KEY UPDATE books = VALUES(books)', [$year, $books]);
    }

    /** @return array{year: int, goal: ?int, read: int} */
    public function goalProgress(int $year): array
    {
        $read = $this->query(
            "SELECT COUNT(*) FROM `reads` r JOIN books b ON b.id = r.book_id
             WHERE r.finished >= ? AND r.finished < ? AND b.status = 'read'",
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        )->fetchColumn();
        return ['year' => $year, 'goal' => $this->goal($year), 'read' => (int) $read];
    }

    /**
     * Everything, for the export.
     *
     * @return array{books: list<array<string, mixed>>, goals: array<int, int>}
     */
    public function export(): array
    {
        $books = array_map([self::class, 'cast'], $this->query('SELECT * FROM books ORDER BY id')->fetchAll());
        $reads = [];
        foreach ($this->query('SELECT * FROM `reads` ORDER BY started, finished, id')->fetchAll() as $read) {
            $reads[(int) $read['book_id']][] = [
                'started' => $read['started'],
                'finished' => $read['finished'],
                'progress_page' => $read['progress_page'] !== null ? (int) $read['progress_page'] : null,
                'progress_percent' => $read['progress_percent'] !== null ? (int) $read['progress_percent'] : null,
            ];
        }
        foreach ($books as &$book) {
            $book['reads'] = $reads[$book['id']] ?? [];
        }
        $goals = array_map('intval', $this->query('SELECT year, books FROM goals ORDER BY year')->fetchAll(PDO::FETCH_KEY_PAIR));
        return ['books' => $books, 'goals' => $goals];
    }

    /** Delete a read and return the book it belonged to. */
    public function deleteRead(int $readId): ?int
    {
        $bookId = $this->query('SELECT book_id FROM `reads` WHERE id = ?', [$readId])->fetchColumn();
        if ($bookId === false) {
            return null;
        }
        $this->query('DELETE FROM `reads` WHERE id = ?', [$readId]);
        return (int) $bookId;
    }

    public function logPost(int $bookId, string $kind, ?string $url): void
    {
        $this->query('INSERT INTO posts (book_id, kind, url) VALUES (?, ?, ?)', [$bookId, $kind, $url]);
    }

    /** Fields an import may fill in, with the Dutch name shown in the preview. */
    private const IMPORT_FIELDS = [
        'author' => 'auteur', 'isbn' => 'ISBN', 'format' => 'formaat', 'pages' => "pagina's", 'rating' => 'beoordeling',
        'review' => 'recensie', 'tags' => 'tags', 'moods' => 'moods', 'contributors' => 'medewerkers',
        'content_warnings' => 'inhoudswaarschuwingen', 'content_warning_description' => 'toelichting inhoudswaarschuwingen',
    ];

    /**
     * Import parsed StoryGraph books. New books are added; for books that are already
     * here, only empty fields are filled in and missing reads added, so whatever you
     * changed yourself stays. With $dryRun nothing is saved: you get the preview.
     *
     * @param list<array<string, mixed>> $books Output of StoryGraph::parse().
     * @return array{imported: list<string>, updated: array<string, list<string>>, unchanged: list<string>}
     */
    public function import(array $books, bool $dryRun = false): array
    {
        $result = ['imported' => [], 'updated' => [], 'unchanged' => []];
        $this->db->beginTransaction();
        try {
            foreach ($books as $book) {
                $existing = $this->findDuplicate($book['title'], $book['author'], $book['isbn']);
                if ($existing === null) {
                    $this->insertImported($book);
                    $result['imported'][] = $book['title'];
                    continue;
                }
                $changes = $this->fillMissing($existing, $book);
                $added = $this->addMissingReads($existing['id'], $book['reads']);
                if ($added) {
                    $changes[] = $added === 1 ? '1 leesmoment' : "{$added} leesmomenten";
                }
                if ($changes) {
                    // Same title twice in one file: keep both lists of changes apart.
                    $key = isset($result['updated'][$existing['title']]) ? $existing['title'] . " (#{$existing['id']})" : $existing['title'];
                    $result['updated'][$key] = $changes;
                } else {
                    $result['unchanged'][] = $existing['title'];
                }
            }
            $dryRun ? $this->db->rollBack() : $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $result;
    }

    /** @param array<string, mixed> $book */
    private function insertImported(array $book): void
    {
        $this->query(
            "INSERT INTO books (title, author, isbn, format, pages, status, rating, review, tags, moods, owned, contributors,
                                content_warnings, content_warning_description, details, source, date_added)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'storygraph', COALESCE(?, CURDATE()))",
            [$book['title'], $book['author'], $book['isbn'], $book['format'], $book['pages'], $book['status'], $book['rating'],
             $book['review'], $book['tags'], $book['moods'], $book['owned'], $book['contributors'], $book['content_warnings'],
             $book['content_warning_description'], $book['details'] ? json_encode($book['details'], JSON_UNESCAPED_UNICODE) : null,
             $book['date_added']],
        );
        $id = (int) $this->db->lastInsertId();
        foreach ($book['reads'] as [$started, $finished]) {
            $this->addRead($id, $started, $finished);
        }
    }

    /**
     * Fill the existing book's empty fields from the import.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $book
     * @return list<string> What was filled in.
     */
    private function fillMissing(array $existing, array $book): array
    {
        $set = [];
        $changes = [];
        foreach (self::IMPORT_FIELDS as $field => $label) {
            $empty = $existing[$field] === null || $existing[$field] === '';
            if ($empty && ($book[$field] ?? null) !== null && $book[$field] !== '') {
                $set[$field] = $book[$field];
                $changes[] = $label;
            }
        }
        if (!$existing['owned'] && $book['owned']) {
            $set['owned'] = 1;
            $changes[] = 'in bezit';
        }
        $details = $existing['details'];
        $newDetails = array_diff_key($book['details'], $details);
        if ($newDetails) {
            $set['details'] = json_encode($details + $newDetails, JSON_UNESCAPED_UNICODE);
            foreach (array_keys($newDetails) as $name) {
                $changes[] = mb_strtolower(StoryGraph::DETAIL_LABELS[$name] ?? $name);
            }
        }
        if ($set) {
            $columns = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($set)));
            $this->query("UPDATE books SET {$columns} WHERE id = ?", [...array_values($set), $existing['id']]);
        }
        return $changes;
    }

    /**
     * Add reads from the import that aren't here yet. A read counts as present when one
     * has the same finish date, or (for an unfinished one) the same start date.
     *
     * @param list<array{0: ?string, 1: ?string}> $reads
     */
    private function addMissingReads(int $bookId, array $reads): int
    {
        $have = $this->reads($bookId);
        $finished = array_filter(array_column($have, 'finished'));
        $started = array_filter(array_column($have, 'started'));
        $added = 0;
        foreach ($reads as [$start, $finish]) {
            $present = $finish !== null ? in_array($finish, $finished, true) : ($start !== null && in_array($start, $started, true));
            if (!$present && ($start !== null || $finish !== null)) {
                $this->addRead($bookId, $start, $finish);
                $finished[] = $finish;
                $started[] = $start;
                $added++;
            }
        }
        return $added;
    }

    /** @return list<array{year: string, books: int, pages: ?int, avg_rating: ?float}> */
    public function years(): array
    {
        $rows = $this->query(
            "SELECT YEAR(r.finished) AS year, COUNT(*) AS books, SUM(b.pages) AS pages, ROUND(AVG(b.rating), 2) AS avg_rating
             FROM `reads` r JOIN books b ON b.id = r.book_id
             WHERE r.finished IS NOT NULL AND b.status = 'read'
             GROUP BY YEAR(r.finished) ORDER BY year DESC",
        )->fetchAll();
        return array_map(static fn (array $r) => [
            'year' => (string) $r['year'],
            'books' => (int) $r['books'],
            'pages' => $r['pages'] !== null ? (int) $r['pages'] : null,
            'avg_rating' => $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null,
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function readInYear(int $year): array
    {
        $rows = $this->query(
            "SELECT b.*, r.finished FROM `reads` r JOIN books b ON b.id = r.book_id
             WHERE r.finished >= ? AND r.finished < ? AND b.status = 'read' ORDER BY r.finished DESC",
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)],
        )->fetchAll();
        return array_map([self::class, 'cast'], $rows);
    }

    /**
     * The most recently finished books, for the feed.
     *
     * @return list<array<string, mixed>>
     */
    public function recentlyFinished(int $limit = 50): array
    {
        $rows = $this->query(
            "SELECT b.*, r.finished FROM `reads` r JOIN books b ON b.id = r.book_id
             WHERE r.finished IS NOT NULL AND b.status = 'read'
             ORDER BY r.finished DESC, r.id DESC LIMIT " . max(1, $limit),
        )->fetchAll();
        return array_map([self::class, 'cast'], $rows);
    }

    /** @return list<array{author: string, n: int}> */
    public function topAuthors(int $limit = 10): array
    {
        return $this->query(
            "SELECT author, COUNT(*) AS n FROM books WHERE status = 'read' AND author != ''
             GROUP BY author ORDER BY n DESC, author LIMIT " . max(1, $limit),
        )->fetchAll();
    }

    /**
     * @param array<string, mixed> $book
     * @return array<string, mixed>
     */
    private static function cast(array $book): array
    {
        $book['id'] = (int) $book['id'];
        $book['rating'] = $book['rating'] !== null ? (float) $book['rating'] : null;
        $book['pages'] = $book['pages'] !== null ? (int) $book['pages'] : null;
        $book['owned'] = (bool) $book['owned'];
        if (array_key_exists('details', $book)) {
            $book['details'] = is_string($book['details']) ? (json_decode($book['details'], true) ?: []) : [];
        }
        foreach (['progress_page', 'progress_percent'] as $key) {
            if (array_key_exists($key, $book)) {
                $book[$key] = $book[$key] !== null ? (int) $book[$key] : null;
            }
        }
        return $book;
    }

    /** @param list<mixed> $args */
    private function query(string $sql, array $args = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }
}
