<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Your books by title (also the original title, and without a subtitle), and by
 * series and part number: to see which books from a catalogue you already have.
 */
final class TitleIndex
{
    /** @var array<string, array<string, mixed>> */
    private array $byTitle = [];

    /** @var array<string, array<string, mixed>> */
    private array $bySeries = [];

    public function __construct(PDO $db)
    {
        foreach ($db->query('SELECT id, title, original_title, author, series, series_number, status FROM books ORDER BY id')->fetchAll() as $book) {
            $book = ['id' => (int) $book['id']] + $book;
            foreach ([$book['title'], $book['original_title']] as $title) {
                foreach (self::keys((string) $title) as $key) {
                    $this->byTitle[$key] ??= $book;
                }
            }
            if ($book['series'] !== null && $book['series_number'] !== null) {
                $this->bySeries[Wikidata::matchKey((string) $book['series']) . '#' . (float) $book['series_number']] ??= $book;
            }
        }
    }

    /**
     * The first of your books with one of these titles.
     *
     * @param list<?string> $titles
     * @return array<string, mixed>|null
     */
    public function byTitle(array $titles): ?array
    {
        foreach ($titles as $title) {
            foreach (self::keys((string) $title) as $key) {
                if (isset($this->byTitle[$key])) {
                    return $this->byTitle[$key];
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public function bySeries(string $series, float $number): ?array
    {
        return $this->bySeries[Wikidata::matchKey($series) . '#' . $number] ?? null;
    }

    /** @return list<string> "De avonden: een winterverhaal" → ["de avonden een winterverhaal", "de avonden"] */
    public static function keys(string $title): array
    {
        $main = trim((string) preg_split('/\s*[:(\[]/u', $title)[0]);
        // A main title of one or two letters says too little to match on.
        return array_values(array_unique(array_filter([Wikidata::matchKey($title), Wikidata::matchKey($main)], static fn (string $k) => mb_strlen($k) >= 3)));
    }
}
