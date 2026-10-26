<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

/**
 * Books you lent out. Private: never on the public page or in the feed.
 */
final class Loans
{
    public function __construct(private PDO $db)
    {
    }

    /** Lend a book to someone. A book can only be lent to one person at a time. */
    public function lend(int $bookId, mixed $borrower, ?string $date = null, mixed $note = null): int
    {
        $borrower = trim((string) preg_replace('/\s+/u', ' ', Books::text($borrower, 100)));
        if ($borrower === '') {
            throw new InvalidArgumentException('Aan wie heb je het uitgeleend?');
        }
        $current = $this->current($bookId);
        if ($current !== null) {
            throw new InvalidArgumentException("Dit boek is al uitgeleend aan {$current['borrower']}.");
        }
        // Same person as before ("anna" vs "Anna"): keep the spelling used the first time.
        $known = $this->db->prepare('SELECT borrower FROM loans WHERE borrower = ? ORDER BY id LIMIT 1');
        $known->execute([$borrower]);
        $borrower = $known->fetchColumn() ?: $borrower;
        $note = Books::text($note, 500);
        $this->db->prepare('INSERT INTO loans (book_id, borrower, lent_on, note) VALUES (?, ?, ?, ?)')
            ->execute([$bookId, $borrower, $date ?? date('Y-m-d'), $note === '' ? null : $note]);
        return (int) $this->db->lastInsertId();
    }

    /** Mark the book as returned; false when it wasn't lent out. */
    public function returnBook(int $bookId, ?string $date = null): bool
    {
        $current = $this->current($bookId);
        if ($current === null) {
            return false;
        }
        $date ??= date('Y-m-d');
        $this->db->prepare('UPDATE loans SET returned_on = ? WHERE id = ?')
            ->execute([max($date, $current['lent_on']), $current['id']]);
        return true;
    }

    /** Remove a loan from the history (a mistake); returns its book id. */
    public function delete(int $loanId): ?int
    {
        $stmt = $this->db->prepare('SELECT book_id FROM loans WHERE id = ?');
        $stmt->execute([$loanId]);
        $bookId = $stmt->fetchColumn();
        if ($bookId === false) {
            return null;
        }
        $this->db->prepare('DELETE FROM loans WHERE id = ?')->execute([$loanId]);
        return (int) $bookId;
    }

    /** @return array<string, mixed>|null */
    public function current(int $bookId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM loans WHERE book_id = ? AND returned_on IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$bookId]);
        $loan = $stmt->fetch();
        return $loan ? self::cast($loan) : null;
    }

    /** @return list<array<string, mixed>> A book's loans, newest first. */
    public function forBook(int $bookId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM loans WHERE book_id = ? ORDER BY lent_on DESC, id DESC');
        $stmt->execute([$bookId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    /**
     * Everyone you ever lent to, with what they have now and what they had before.
     * People with books still out come first, then alphabetically.
     *
     * @return list<array{name: string, current: list<array<string, mixed>>, history: list<array<string, mixed>>}>
     */
    public function byPerson(): array
    {
        $rows = $this->db->query(
            'SELECT l.*, b.title, b.author FROM loans l JOIN books b ON b.id = l.book_id ORDER BY l.lent_on DESC, l.id DESC',
        )->fetchAll();
        $people = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['borrower']);
            $people[$key] ??= ['name' => $row['borrower'], 'current' => [], 'history' => []];
            $people[$key][$row['returned_on'] === null ? 'current' : 'history'][] = self::cast($row) + [
                'title' => $row['title'], 'author' => $row['author'],
            ];
        }
        uasort($people, static fn (array $a, array $b) => [!$a['current'], mb_strtolower($a['name'])] <=> [!$b['current'], mb_strtolower($b['name'])]);
        return array_values($people);
    }

    /** @return list<string> Names used before, for suggestions. */
    public function borrowers(): array
    {
        return $this->db->query('SELECT borrower FROM loans GROUP BY borrower ORDER BY MAX(lent_on) DESC')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string, mixed> $loan */
    private static function cast(array $loan): array
    {
        return [
            'id' => (int) $loan['id'],
            'book_id' => (int) $loan['book_id'],
            'borrower' => $loan['borrower'],
            'lent_on' => $loan['lent_on'],
            'returned_on' => $loan['returned_on'],
            'note' => $loan['note'],
            // Calendar days: dividing seconds by 86400 is a day short across summer time.
            'days' => (new \DateTimeImmutable((string) $loan['lent_on']))->diff(new \DateTimeImmutable((string) ($loan['returned_on'] ?? date('Y-m-d'))))->days,
        ];
    }
}
