<?php

declare(strict_types=1);

namespace App;

/**
 * Book lookup via the Open Library search API (no API key needed).
 */
final class OpenLibrary
{
    /** @return list<array{title: string, author: string, isbn: string, pages: ?int, year: ?int}> */
    public static function search(string $query, int $limit = 10): array
    {
        return self::fetch(['q' => $query, 'limit' => $limit]);
    }

    /** @return array{title: string, author: string, isbn: string, pages: ?int, year: ?int}|null */
    public static function byIsbn(string $isbn): ?array
    {
        $results = self::fetch(['isbn' => $isbn, 'limit' => 1]);
        if (!$results) {
            return null;
        }
        // Keep the scanned ISBN rather than whichever edition Open Library lists first.
        return ['isbn' => $isbn] + $results[0];
    }

    public static function coverUrl(?string $isbn, string $size = 'M'): ?string
    {
        return $isbn ? "https://covers.openlibrary.org/b/isbn/{$isbn}-{$size}.jpg?default=false" : null;
    }

    /**
     * @param array<string, string|int> $params
     * @return list<array{title: string, author: string, isbn: string, pages: ?int, year: ?int}>
     */
    private static function fetch(array $params): array
    {
        $params['fields'] = 'title,author_name,isbn,number_of_pages_median,first_publish_year';
        $ch = curl_init('https://openlibrary.org/search.json?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'have-i-read-that',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new \RuntimeException('Open Library niet bereikbaar: ' . ($error ?: "HTTP {$code}"));
        }
        $docs = json_decode((string) $body, true)['docs'] ?? [];
        $results = [];
        foreach ($docs as $doc) {
            $isbns = $doc['isbn'] ?? [];
            $isbn13 = array_values(array_filter($isbns, static fn ($i) => strlen($i) === 13));
            $results[] = [
                'title' => (string) ($doc['title'] ?? ''),
                'author' => implode(', ', array_slice($doc['author_name'] ?? [], 0, 3)),
                'isbn' => (string) ($isbn13[0] ?? $isbns[0] ?? ''),
                'pages' => isset($doc['number_of_pages_median']) ? (int) $doc['number_of_pages_median'] : null,
                'year' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
            ];
        }
        return $results;
    }
}
