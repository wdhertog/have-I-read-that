<?php

declare(strict_types=1);

namespace App;

/**
 * Parse a StoryGraph CSV export (Manage Account -> Export StoryGraph Library):
 *
 *     Title,Authors,Contributors,ISBN/UID,Format,Read Status,Date Added,
 *     Last Date Read,Dates Read,Read Count,Moods,Pace,...,Star Rating,Review,
 *     Content Warnings,Content Warning Description,Tags,Owned?
 */
final class StoryGraph
{
    private const STATUS_MAP = [
        'read' => 'read',
        'to-read' => 'to-read',
        'currently-reading' => 'reading',
        'paused' => 'reading',
        'did-not-finish' => 'dnf',
    ];

    private const DATE_RE = '#\d{4}[/-]\d{1,2}[/-]\d{1,2}#';

    /** Return an ISO date (YYYY-MM-DD) or null. Accepts Y/m/d, Y-m-d and d/m/Y. */
    public static function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$#', $value, $m)) {
            [, $y, $mo, $d] = $m;
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            [, $d, $mo, $y] = $m;
        } else {
            return null;
        }
        return checkdate((int) $mo, (int) $d, (int) $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Parse "2023/01/05-2023/01/20, 2024/03/01-2024/03/09" into [start, end] pairs.
     *
     * @return list<array{0: ?string, 1: ?string}>
     */
    public static function parseDatesRead(?string $value, string $status): array
    {
        $reads = [];
        foreach (explode(',', (string) $value) as $segment) {
            $segment = trim($segment);
            preg_match_all(self::DATE_RE, $segment, $m);
            $dates = array_map([self::class, 'parseDate'], $m[0]);
            if (count($dates) >= 2) {
                $reads[] = [$dates[0], $dates[1]];
            } elseif (count($dates) === 1) {
                // "2023/01/05-" is an open start, "-2023/01/20" only an end. Without a
                // dash, a single date is a finish date for finished books.
                if (str_ends_with($segment, '-')) {
                    $reads[] = [$dates[0], null];
                } elseif (str_starts_with($segment, '-') || $status === 'read') {
                    $reads[] = [null, $dates[0]];
                } else {
                    $reads[] = [$dates[0], null];
                }
            }
        }
        return $reads;
    }

    /** Shorten to what the database column holds, so one odd row can't abort the import. */
    private static function clip(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    public static function cleanIsbn(?string $value): ?string
    {
        $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', (string) $value));
        return in_array(strlen($isbn), [10, 13], true) ? $isbn : null;
    }

    public static function parseRating(?string $value): ?float
    {
        $value = trim((string) $value);
        if (!is_numeric($value)) {
            return null;
        }
        $rating = (float) $value;
        return $rating > 0 && $rating <= 5 ? $rating : null;
    }

    /**
     * @return list<array<string, mixed>> Normalized books.
     */
    public static function parse(string $csv): array
    {
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw new \InvalidArgumentException('Het bestand is geen UTF-8.');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, (string) $csv);
        rewind($handle);
        $header = fgetcsv($handle, escape: '');
        if (!$header || !in_array('Title', $header, true)) {
            throw new \InvalidArgumentException("Dit lijkt geen StoryGraph-export te zijn (kolom 'Title' ontbreekt).");
        }
        $books = [];
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = trim((string) ($cells[$i] ?? ''));
            }
            if ($row['Title'] === '') {
                continue;
            }
            $status = self::STATUS_MAP[strtolower($row['Read Status'] ?? '')] ?? 'to-read';
            $reads = self::parseDatesRead($row['Dates Read'] ?? '', $status);
            $lastRead = self::parseDate($row['Last Date Read'] ?? '');
            if (!$reads && $lastRead) {
                $reads = [[null, $lastRead]];
            }
            $books[] = [
                'title' => self::clip($row['Title'], 500),
                'author' => self::clip($row['Authors'] ?? '', 500),
                'isbn' => self::cleanIsbn($row['ISBN/UID'] ?? ''),
                'format' => Books::normalizeFormat($row['Format'] ?? ''),
                'status' => $status,
                'rating' => self::parseRating($row['Star Rating'] ?? ''),
                'review' => self::clip($row['Review'] ?? '', Books::MAX_REVIEW) ?: null,
                'tags' => self::clip($row['Tags'] ?? '', 1000) ?: null,
                'moods' => self::clip($row['Moods'] ?? '', 1000) ?: null,
                'owned' => strtolower($row['Owned?'] ?? '') === 'yes' ? 1 : 0,
                'date_added' => self::parseDate($row['Date Added'] ?? ''),
                'reads' => $reads,
            ];
        }
        fclose($handle);
        return $books;
    }
}
