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
    public const MAX_REVIEW = 10000;
    public const MAX_PAGES = 100000;

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
        $sql = 'SELECT b.*, (SELECT MAX(r.finished) FROM `reads` r WHERE r.book_id = b.id) AS last_finished
                FROM books b WHERE 1 = 1';
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
        $book = $this->query('SELECT * FROM books WHERE id = ?', [$id])->fetch();
        return $book ? self::cast($book) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByIsbn(string $isbn): ?array
    {
        $book = $this->query('SELECT * FROM books WHERE isbn = ? ORDER BY id LIMIT 1', [$isbn])->fetch();
        return $book ? self::cast($book) : null;
    }

    /** @return list<array<string, mixed>> */
    public function reads(int $bookId): array
    {
        return $this->query(
            "SELECT id, started, finished FROM `reads` WHERE book_id = ?
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

    /**
     * Import parsed StoryGraph books, skipping ones that already exist.
     *
     * @param list<array<string, mixed>> $books Output of StoryGraph::parse().
     * @return array{imported: list<string>, skipped: list<string>}
     */
    public function import(array $books): array
    {
        $result = ['imported' => [], 'skipped' => []];
        $this->db->beginTransaction();
        try {
            foreach ($books as $book) {
                if ($this->exists($book)) {
                    $result['skipped'][] = $book['title'];
                    continue;
                }
                $this->query(
                    "INSERT INTO books (title, author, isbn, format, status, rating, review, tags, moods, owned, source, date_added)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'storygraph', COALESCE(?, CURDATE()))",
                    [$book['title'], $book['author'], $book['isbn'], $book['format'], $book['status'], $book['rating'],
                     $book['review'], $book['tags'], $book['moods'], $book['owned'], $book['date_added']],
                );
                $id = (int) $this->db->lastInsertId();
                foreach ($book['reads'] as [$started, $finished]) {
                    $this->addRead($id, $started, $finished);
                }
                $result['imported'][] = $book['title'];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $result;
    }

    /** @param array<string, mixed> $book */
    private function exists(array $book): bool
    {
        if ($book['isbn'] && $this->findByIsbn($book['isbn'])) {
            return true;
        }
        return (bool) $this->query(
            'SELECT 1 FROM books WHERE LOWER(title) = LOWER(?) AND LOWER(author) = LOWER(?)',
            [$book['title'], $book['author']],
        )->fetchColumn();
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
