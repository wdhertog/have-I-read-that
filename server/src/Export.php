<?php

declare(strict_types=1);

namespace App;

/**
 * Exports the library as a StoryGraph-compatible CSV (which this app can import again)
 * or as JSON with everything.
 */
final class Export
{
    /** StoryGraph's export columns, in their order. */
    public const COLUMNS = [
        'Title', 'Authors', 'Contributors', 'ISBN/UID', 'Format', 'Read Status', 'Date Added', 'Last Date Read',
        'Dates Read', 'Read Count', 'Moods', 'Pace', 'Character- or Plot-Driven?', 'Strong Character Development?',
        'Loveable Characters?', 'Diverse Characters?', 'Flawed Characters?', 'Star Rating', 'Review',
        'Content Warnings', 'Content Warning Description', 'Tags', 'Owned?',
    ];

    private const STATUS = ['read' => 'read', 'to-read' => 'to-read', 'reading' => 'currently-reading', 'dnf' => 'did-not-finish'];
    private const FORMAT = ['paperback' => 'paperback', 'hardcover' => 'hardcover', 'ebook' => 'digital', 'audio' => 'audio'];

    /** @param array{books: list<array<string, mixed>>, goals: array<int, int>} $data */
    public static function csv(array $data): string
    {
        $out = fopen('php://memory', 'r+');
        fputcsv($out, self::COLUMNS, escape: '');
        foreach ($data['books'] as $book) {
            $dates = [];
            $finished = [];
            foreach ($book['reads'] as $read) {
                $started = self::slashDate($read['started']);
                $end = self::slashDate($read['finished']);
                if ($started !== '' || $end !== '') {
                    $dates[] = $started . '-' . $end;
                }
                if ($read['finished']) {
                    $finished[] = $read['finished'];
                }
            }
            $row = array_fill_keys(self::COLUMNS, '');
            $row['Title'] = $book['title'];
            $row['Authors'] = $book['author'];
            $row['ISBN/UID'] = (string) $book['isbn'];
            $row['Format'] = self::FORMAT[$book['format'] ?? ''] ?? '';
            $row['Read Status'] = self::STATUS[$book['status']];
            $row['Date Added'] = self::slashDate($book['date_added']);
            $row['Last Date Read'] = $finished ? self::slashDate(max($finished)) : '';
            $row['Dates Read'] = implode(', ', $dates);
            $row['Read Count'] = (string) count($finished);
            $row['Moods'] = (string) $book['moods'];
            $row['Contributors'] = (string) ($book['contributors'] ?? '');
            $row['Content Warnings'] = (string) ($book['content_warnings'] ?? '');
            $row['Content Warning Description'] = (string) ($book['content_warning_description'] ?? '');
            foreach ($book['details'] ?? [] as $name => $value) {
                if (array_key_exists($name, $row) && $row[$name] === '') {
                    $row[$name] = (string) $value;
                }
            }
            $row['Star Rating'] = $book['rating'] !== null ? Mastodon::formatRating($book['rating']) : '';
            $row['Review'] = (string) $book['review'];
            $row['Tags'] = (string) $book['tags'];
            $row['Owned?'] = $book['owned'] ? 'Yes' : 'No';
            fputcsv($out, array_map([self::class, 'cell'], array_values($row)), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /** @param array{books: list<array<string, mixed>>, goals: array<int, int>} $data */
    public static function json(array $data): string
    {
        return json_encode(
            ['exported_at' => date('c'), 'app' => 'have-i-read-that'] + $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private static function slashDate(?string $date): string
    {
        return $date ? str_replace('-', '/', $date) : '';
    }

    /**
     * Spreadsheet programs run cells that start with = + - @ as formulas; a leading
     * apostrophe makes them plain text (and StoryGraph::parse strips it again).
     */
    private static function cell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }
}
