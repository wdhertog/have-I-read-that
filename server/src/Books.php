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
            (SELECT r.progress_percent FROM `reads` r WHERE r.book_id = b.id AND r.finished IS NULL ORDER BY r.id DESC LIMIT 1) AS progress_percent,
            (SELECT l.borrower FROM loans l WHERE l.book_id = b.id AND l.returned_on IS NULL ORDER BY l.id DESC LIMIT 1) AS lent_to,
            (SELECT l.lent_on FROM loans l WHERE l.book_id = b.id AND l.returned_on IS NULL ORDER BY l.id DESC LIMIT 1) AS lent_since
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
            'series' => $str('series', 255),
            'series_number' => $str('series_number', 20),
            'publisher' => $str('publisher', 255),
            'published_year' => $str('published_year', 10),
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
        $data['tags'] = self::normalizeTags($data['tags']);
        if ($data['published_year'] !== null) {
            if (!ctype_digit($data['published_year']) || (int) $data['published_year'] < 1000 || (int) $data['published_year'] > 2999) {
                throw new InvalidArgumentException('Jaar moet een jaartal zijn, bijv. 1947.');
            }
            $data['published_year'] = (int) $data['published_year'];
        }
        $data['extra'] = [];
        foreach (self::DETAIL_FIELDS as $key => $max) {
            if (array_key_exists($key, $input)) {
                $data['extra'][$key] = $str($key, $max);
            }
        }
        if (isset($data['extra']['original_language'])) {
            $data['extra']['original_language'] = Language::normalize($data['extra']['original_language']);
        }
        if (isset($data['extra']['genre'])) {
            $data['extra']['genre'] = self::normalizeTags($data['extra']['genre']);
        }
        if (isset($data['extra']['first_published'])) {
            if (!preg_match('/^-?\d{1,4}$/', $data['extra']['first_published'])) {
                throw new InvalidArgumentException('Eerste verschijning moet een jaartal zijn, bijv. 1947.');
            }
            $data['extra']['first_published'] = (int) $data['extra']['first_published'];
        }
        if ($data['series_number'] !== null) {
            $number = str_replace(',', '.', $data['series_number']);
            if (!is_numeric($number) || (float) $number < 0 || (float) $number > 99999) {
                throw new InvalidArgumentException('Deel van de serie moet een getal zijn, bijv. 3 of 2,5.');
            }
            $data['series_number'] = round((float) $number, 1);
        }
        return $data;
    }

    /** "fantasy,  bieb, Fantasy" → "fantasy, bieb": trimmed, without duplicates (ignoring case). */
    public static function normalizeTags(?string $tags): ?string
    {
        $seen = [];
        foreach (explode(',', (string) $tags) as $tag) {
            $tag = trim((string) preg_replace('/\s+/u', ' ', $tag));
            if ($tag !== '' && !isset($seen[mb_strtolower($tag)])) {
                $seen[mb_strtolower($tag)] = $tag;
            }
        }
        return $seen ? implode(', ', $seen) : null;
    }

    /** @return list<string> */
    public static function tagList(?string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $tags)), static fn ($t) => $t !== ''));
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
    public function list(?string $status = null, string $q = '', ?int $limit = null, ?string $shelf = null): array
    {
        $sql = self::SELECT_BOOK . ' WHERE 1 = 1';
        $args = [];
        if ($status !== null && isset(self::STATUSES[$status])) {
            $sql .= ' AND b.status = ?';
            $args[] = $status;
        }
        if ($q !== '') {
            $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.tags LIKE ? OR b.series LIKE ? OR b.genre LIKE ?
                      OR b.translator LIKE ? OR b.original_title LIKE ? OR b.isbn = ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like, $like, $like, $like, $q);
        }
        if ($shelf !== null && $shelf !== '') {
            $sql .= ' AND b.tags LIKE ?';
            $args[] = '%' . addcslashes($shelf, '%_\\') . '%';
        }
        $sql .= " ORDER BY b.status = 'reading' DESC, COALESCE(last_finished, b.date_added) DESC, b.id DESC";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        $books = array_map([self::class, 'cast'], $this->query($sql, $args)->fetchAll());
        if ($shelf !== null && $shelf !== '') {
            // The LIKE above is a rough cut; keep only books that really have this tag.
            $books = array_values(array_filter($books, static fn (array $b) => in_array(
                mb_strtolower($shelf), array_map('mb_strtolower', self::tagList($b['tags'])), true,
            )));
        }
        return $books;
    }

    /**
     * Shelves are your tags (StoryGraph's tags come in as shelves), with how many books
     * are on each, alphabetically.
     *
     * @return list<array{name: string, count: int}>
     */
    public function shelves(): array
    {
        $counts = [];
        foreach ($this->query("SELECT tags FROM books WHERE tags IS NOT NULL AND tags != ''")->fetchAll(PDO::FETCH_COLUMN) as $tags) {
            foreach (self::tagList($tags) as $tag) {
                $key = mb_strtolower($tag);
                $counts[$key] ??= ['name' => $tag, 'count' => 0];
                $counts[$key]['count']++;
            }
        }
        uasort($counts, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
        return array_values($counts);
    }

    /** Rename a shelf on every book (or merge it into an existing one); '' removes it. Returns the number of books changed. */
    public function renameShelf(string $from, string $to): int
    {
        $to = trim($to);
        if (str_contains($to, ',')) {
            throw new InvalidArgumentException('Een plank mag geen komma bevatten.');
        }
        $changed = 0;
        foreach ($this->list(null, '', null, $from) as $book) {
            $tags = array_map(static fn (string $t) => mb_strtolower($t) === mb_strtolower($from) ? $to : $t, self::tagList($book['tags']));
            $this->query('UPDATE books SET tags = ? WHERE id = ?', [self::normalizeTags(implode(',', $tags)), $book['id']]);
            $changed++;
        }
        return $changed;
    }

    /**
     * Series with how many books you have of each and how many you've read.
     *
     * @return list<array{name: string, books: int, read: int}>
     */
    public function seriesList(): array
    {
        return array_map(static fn (array $r) => ['name' => $r['series'], 'books' => (int) $r['books'], 'read' => (int) $r['read']],
            $this->query(
                "SELECT series, COUNT(*) AS books, SUM(status = 'read') AS `read` FROM books
                 WHERE series IS NOT NULL AND series != '' GROUP BY series ORDER BY series",
            )->fetchAll());
    }

    /** @return list<array<string, mixed>> The books of a series, in reading order. */
    public function seriesBooks(string $series): array
    {
        return array_map([self::class, 'cast'], $this->query(
            self::SELECT_BOOK . ' WHERE b.series = ? ORDER BY b.series_number IS NULL, b.series_number, b.title',
            [$series],
        )->fetchAll());
    }

    // --- notes and quotes -------------------------------------------------------

    public const MAX_NOTE = 5000;

    /** @return list<array{id: int, kind: string, text: string, page: ?int, public: bool, created_at: string}> */
    public function notes(int $bookId, bool $publicOnly = false): array
    {
        $rows = $this->query(
            'SELECT id, kind, text, page, public, created_at FROM notes WHERE book_id = ?'
            . ($publicOnly ? ' AND public = 1' : '') . ' ORDER BY page IS NULL, page, id',
            [$bookId],
        )->fetchAll();
        return array_map(static fn (array $n) => [
            'id' => (int) $n['id'], 'kind' => $n['kind'], 'text' => $n['text'],
            'page' => $n['page'] !== null ? (int) $n['page'] : null, 'public' => (bool) $n['public'], 'created_at' => $n['created_at'],
        ], $rows);
    }

    /** @param array<string, mixed> $input kind, text, page, public */
    public function addNote(int $bookId, array $input): int
    {
        $text = self::text($input['text'] ?? null, self::MAX_NOTE);
        if ($text === '') {
            throw new InvalidArgumentException('Schrijf eerst iets op.');
        }
        $page = self::text($input['page'] ?? null, 10);
        if ($page !== '' && (!ctype_digit($page) || (int) $page > self::MAX_PAGES)) {
            throw new InvalidArgumentException('Pagina moet een getal zijn.');
        }
        $this->query(
            'INSERT INTO notes (book_id, kind, text, page, public) VALUES (?, ?, ?, ?, ?)',
            [$bookId, ($input['kind'] ?? '') === 'quote' ? 'quote' : 'note', $text, $page === '' ? null : (int) $page,
             in_array($input['public'] ?? null, ['on', '1', 1, true], true) ? 1 : 0],
        );
        return (int) $this->db->lastInsertId();
    }

    /** Delete a note and return its book's id (null if it doesn't exist). */
    public function deleteNote(int $noteId): ?int
    {
        $bookId = $this->query('SELECT book_id FROM notes WHERE id = ?', [$noteId])->fetchColumn();
        if ($bookId === false) {
            return null;
        }
        $this->query('DELETE FROM notes WHERE id = ?', [$noteId]);
        return (int) $bookId;
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
                'INSERT INTO books (title, author, isbn, format, pages, status, rating, review, tags, owned, series, series_number,
                                    publisher, published_year, date_added)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$data['title'], $data['author'], $data['isbn'], $data['format'], $data['pages'], $data['status'],
                 $data['rating'], $data['review'], $data['tags'], $data['owned'], $data['series'], $data['series_number'],
                 $data['publisher'], $data['published_year'], date('Y-m-d')],
            );
            $id = (int) $this->db->lastInsertId();
            $this->saveDetails($id, $data['extra'] ?? []);
            $today = date('Y-m-d');
            if ($data['status'] === 'reading') {
                $this->addRead($id, $started ?? $today, null);
            } elseif ($data['status'] === 'read' || ($data['status'] === 'dnf' && ($started || $finished))) {
                $this->addRead($id, $started, $finished ?? $today, $data['status'] === 'dnf');
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
        $old = $this->query('SELECT status FROM books WHERE id = ?', [$id])->fetchColumn();
        $this->query(
            'UPDATE books SET title = ?, author = ?, isbn = ?, format = ?, pages = ?, status = ?, rating = ?,
                    review = ?, tags = ?, owned = ?, series = ?, series_number = ?, publisher = ?, published_year = ?
             WHERE id = ?',
            [$data['title'], $data['author'], $data['isbn'], $data['format'], $data['pages'], $data['status'],
             $data['rating'], $data['review'], $data['tags'], $data['owned'], $data['series'], $data['series_number'],
             $data['publisher'], $data['published_year'], $id],
        );
        $this->saveDetails($id, $data['extra'] ?? []);
        if ($old !== false && $old !== $data['status']) {
            $this->followStatus($id, (string) $old, $data['status']);
        }
    }

    /**
     * A status changed by editing, not by "start" or "finish": keep the reads in step,
     * so the book counts (or stops counting) in the year's stats and goal.
     */
    private function followStatus(int $id, string $old, string $new): void
    {
        $today = date('Y-m-d');
        $open = $this->query('SELECT id FROM `reads` WHERE book_id = ? AND finished IS NULL ORDER BY id DESC LIMIT 1', [$id])->fetchColumn();
        $last = $this->query('SELECT id FROM `reads` WHERE book_id = ? AND finished IS NOT NULL ORDER BY finished DESC, id DESC LIMIT 1', [$id])->fetchColumn();
        if ($new === 'reading' && !$open) {
            $this->addRead($id, $today, null);
        } elseif ($new === 'read' || $new === 'dnf') {
            $dnf = (int) ($new === 'dnf');
            if ($open) {
                $this->query('UPDATE `reads` SET finished = ?, dnf = ? WHERE id = ?', [$today, $dnf, $open]);
            } elseif ($last && in_array($old, ['read', 'dnf'], true)) {
                $this->query('UPDATE `reads` SET dnf = ? WHERE id = ?', [$dnf, $last]); // read ↔ dnf
            } elseif ($new === 'read') {
                $this->addRead($id, null, $today);
            }
        }
    }

    /** @param array<string, mixed> $details Column => value, only DETAIL_FIELDS. */
    private function saveDetails(int $id, array $details): void
    {
        $details = array_intersect_key($details, self::DETAIL_FIELDS);
        if ($details) {
            $columns = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($details)));
            $this->query("UPDATE books SET {$columns} WHERE id = ?", [...array_values($details), $id]);
        }
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
            $this->query('UPDATE `reads` SET finished = ?, dnf = ? WHERE id = ?', [$date, (int) $dnf, $open]);
        } else {
            $this->addRead($id, null, $date, $dnf);
        }
        $review = $review !== null && trim($review) !== '' ? trim($review) : null;
        $this->query(
            'UPDATE books SET status = ?, rating = COALESCE(?, rating), review = COALESCE(?, review) WHERE id = ?',
            [$dnf ? 'dnf' : 'read', $rating, $review, $id],
        );
    }

    /** @param bool $dnf Stopped before the end: doesn't count as read in the stats. */
    public function addRead(int $bookId, ?string $started, ?string $finished, bool $dnf = false): void
    {
        $this->query('INSERT INTO `reads` (book_id, started, finished, dnf) VALUES (?, ?, ?, ?)', [$bookId, $started, $finished, (int) $dnf]);
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
             WHERE r.finished >= ? AND r.finished < ? AND r.dnf = 0",
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
            $book['notes'] = $this->notes($book['id']);
        }
        $goals = array_map('intval', $this->query('SELECT year, books FROM goals ORDER BY year')->fetchAll(PDO::FETCH_KEY_PAIR));
        return ['books' => $books, 'goals' => $goals];
    }

    /** Catalogue field => [column, Dutch name]; only empty columns get filled. */
    private const ENRICH_FIELDS = [
        'author' => ['author', 'auteur'], 'pages' => ['pages', "pagina's"], 'publisher' => ['publisher', 'uitgever'],
        'year' => ['published_year', 'jaar'], 'language' => ['language', 'taal'],
        'series' => ['series', 'serie'], 'series_number' => ['series_number', 'deel'],
        'description' => ['description', 'beschrijving'], 'genre' => ['genre', 'genre'],
        'original_title' => ['original_title', 'oorspronkelijke titel'], 'original_language' => ['original_language', 'oorspronkelijke taal'],
        'translator' => ['translator', 'vertaler'], 'first_published' => ['first_published', 'eerste verschijning'],
        'awards' => ['awards', 'prijzen'],
    ];

    /**
     * Details mostly filled in from catalogues, with their maximum length. Saved only
     * when the input has them, so a client that doesn't know them (an older app)
     * leaves them alone.
     */
    public const DETAIL_FIELDS = [
        'description' => self::MAX_REVIEW, 'genre' => 255, 'original_title' => 500, 'original_language' => 20,
        'translator' => 255, 'first_published' => 6, 'awards' => 1000,
    ];

    /**
     * Fill a book's empty fields from a catalogue lookup (Catalog::lookup). Never
     * overwrites what's there. Marks the book as looked up either way.
     *
     * @param array<string, mixed> $book
     * @param array<string, mixed>|null $info
     * @return list<string> What was filled in.
     */
    public function fillFromCatalog(array $book, ?array $info): array
    {
        $set = [];
        $changes = [];
        foreach (self::ENRICH_FIELDS as $field => [$column, $label]) {
            $value = self::catalogValue($column, $info[$field] ?? null);
            $empty = ($book[$column] ?? null) === null || $book[$column] === '';
            if ($field === 'series_number') {
                $empty = $empty && isset($set['series']); // a number only belongs with the series it came with
            }
            if ($empty && $value !== null && $value !== '') {
                $set[$column] = is_string($value) ? mb_substr($value, 0, self::DETAIL_FIELDS[$column] ?? ($column === 'language' ? 20 : 255)) : $value;
                $changes[] = $label;
            }
        }
        $columns = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($set)));
        $this->query(
            'UPDATE books SET ' . ($columns !== '' ? $columns . ', ' : '') . 'enriched_at = NOW() WHERE id = ?',
            [...array_values($set), $book['id']],
        );
        return $changes;
    }

    /** A catalogue value that fits its column, or null: odd numbers from a source are skipped. */
    private static function catalogValue(string $column, mixed $value): mixed
    {
        $ranges = ['pages' => [1, self::MAX_PAGES], 'published_year' => [1000, 2999], 'first_published' => [-3000, 2999], 'series_number' => [0, 9999]];
        if (isset($ranges[$column])) {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            [$min, $max] = $ranges[$column];
            return $value >= $min && $value <= $max ? ($column === 'series_number' ? round((float) $value, 1) : (int) $value) : null;
        }
        return is_string($value) || $value === null ? $value : (string) $value;
    }

    /** @return list<array<string, mixed>> Books with an ISBN that were never looked up. */
    public function toEnrich(int $limit): array
    {
        return array_map([self::class, 'cast'], $this->query(
            self::SELECT_BOOK . " WHERE b.isbn IS NOT NULL AND b.isbn != '' AND b.enriched_at IS NULL ORDER BY b.id LIMIT " . max(1, $limit),
        )->fetchAll());
    }

    public function countToEnrich(): int
    {
        return (int) $this->query("SELECT COUNT(*) FROM books WHERE isbn IS NOT NULL AND isbn != '' AND enriched_at IS NULL")->fetchColumn();
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
        'series' => 'serie', 'series_number' => 'deel in serie',
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
                // StoryGraph's own id first: it still matches after you changed the title.
                $existing = $this->findByStorygraphId($book['storygraph_id'] ?? null)
                    ?? $this->findDuplicate($book['title'], $book['author'], $book['isbn']);
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
            // A StoryGraph DNF counts as not read, like finishing with "niet uitgelezen".
            $this->query("UPDATE `reads` r JOIN books b ON b.id = r.book_id SET r.dnf = 1
                          WHERE b.status = 'dnf' AND r.finished IS NOT NULL AND r.dnf = 0");
            $dryRun ? $this->db->rollBack() : $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    private function findByStorygraphId(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $book = $this->query(self::SELECT_BOOK . ' WHERE b.storygraph_id = ? ORDER BY b.id LIMIT 1', [$id])->fetch();
        return $book ? self::cast($book) : null;
    }

    /** @param array<string, mixed> $book */
    private function insertImported(array $book): void
    {
        $this->query(
            "INSERT INTO books (title, author, isbn, format, pages, status, rating, review, tags, moods, owned, contributors,
                                content_warnings, content_warning_description, details, series, series_number, storygraph_id,
                                source, date_added)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'storygraph', ?)",
            [$book['title'], $book['author'], $book['isbn'], $book['format'], $book['pages'], $book['status'], $book['rating'],
             $book['review'], $book['tags'], $book['moods'], $book['owned'], $book['contributors'], $book['content_warnings'],
             $book['content_warning_description'], $book['details'] ? json_encode($book['details'], JSON_UNESCAPED_UNICODE) : null,
             $book['series'] ?? null, $book['series_number'] ?? null, $book['storygraph_id'] ?? null, $book['date_added'] ?? date('Y-m-d')],
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
        // The StoryGraph id is bookkeeping, not something to report.
        if (($existing['storygraph_id'] ?? null) === null && ($book['storygraph_id'] ?? null) !== null) {
            $set['storygraph_id'] = $book['storygraph_id'];
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
                $changes[] = mb_strtolower((string) (StoryGraph::DETAIL_LABELS[$name] ?? $name));
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
             WHERE r.finished IS NOT NULL AND r.dnf = 0
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
             WHERE r.finished >= ? AND r.finished < ? AND r.dnf = 0 ORDER BY r.finished DESC",
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
             WHERE r.finished IS NOT NULL AND r.dnf = 0
             ORDER BY r.finished DESC, r.id DESC LIMIT " . max(1, $limit),
        )->fetchAll();
        return array_map([self::class, 'cast'], $rows);
    }

    /** @return list<array{author: string, n: int}> */
    /**
     * Read books per original language (or the book's own when it isn't a translation),
     * and how many of them you read in translation.
     *
     * @return list<array{language: string, books: int, translated: int}>
     */
    public function readByLanguage(): array
    {
        return array_map(static fn (array $r) => ['language' => $r['lang'], 'books' => (int) $r['books'], 'translated' => (int) $r['translated']],
            $this->query(
                "SELECT COALESCE(original_language, language) AS lang, COUNT(*) AS books,
                        SUM(original_language IS NOT NULL AND language IS NOT NULL AND original_language != language) AS translated
                 FROM books WHERE status = 'read' AND COALESCE(original_language, language) IS NOT NULL
                 GROUP BY lang ORDER BY books DESC, lang",
            )->fetchAll());
    }

    /** @return array<string, int> Genre => number of read books, most read first. */
    public function topGenres(int $limit = 10): array
    {
        $counts = [];
        $names = [];
        foreach ($this->query("SELECT genre FROM books WHERE status = 'read' AND genre IS NOT NULL AND genre != ''")->fetchAll(PDO::FETCH_COLUMN) as $genres) {
            foreach (self::tagList($genres) as $genre) {
                $key = mb_strtolower($genre);
                $names[$key] ??= $genre;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);
        $top = [];
        foreach (array_slice($counts, 0, $limit, true) as $key => $n) {
            $top[$names[$key]] = $n;
        }
        return $top;
    }

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
        if (array_key_exists('published_year', $book)) {
            $book['published_year'] = $book['published_year'] !== null ? (int) $book['published_year'] : null;
        }
        if (array_key_exists('first_published', $book)) {
            $book['first_published'] = $book['first_published'] !== null ? (int) $book['first_published'] : null;
        }
        if (array_key_exists('series_number', $book)) {
            $book['series_number'] = $book['series_number'] !== null ? (float) $book['series_number'] : null;
        }
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
