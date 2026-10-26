<?php

declare(strict_types=1);

namespace App;

/**
 * Book lookup via the Open Library search API (no API key needed).
 */
final class OpenLibrary
{
    private const MAX_COVER_BYTES = 5 * 1024 * 1024;

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
        $result = $results[0];
        $result['first_published'] = $result['year'];
        $result['description'] = self::workDescription($result['work'] ?? null);
        unset($result['work']);
        // Keep the scanned ISBN rather than whichever edition Open Library lists first.
        return ['isbn' => $isbn] + $result;
    }

    /** The description of a work ("/works/OL45804W"), often the publisher's blurb in English. */
    private static function workDescription(?string $work): ?string
    {
        if ($work === null || !preg_match('#^/works/OL\d+W$#', $work)) {
            return null;
        }
        $json = Http::get("https://openlibrary.org{$work}.json");
        $description = $json !== null ? (json_decode($json, true)['description'] ?? null) : null;
        if (is_array($description)) {
            $description = $description['value'] ?? null;
        }
        if (!is_string($description)) {
            return null;
        }
        // Drop the markdown source notes some descriptions end with.
        $description = trim((string) preg_replace(['/\R-{3,}.*$/s', '/\(\[source\]\[\d+\]\)/', '/\R\s*\[\d+\]:\s*\S+/'], '', $description));
        return $description !== '' ? mb_substr($description, 0, 5000) : null;
    }

    /**
     * Download a cover to attach to a toot or put in a collage. Null when there is none
     * or it can't be fetched; never throws.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public static function fetchCover(?string $isbn, string $size = 'L'): ?array
    {
        $url = self::coverUrl($isbn, $size);
        if ($url === null) {
            return null;
        }
        $bytes = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, // covers.openlibrary.org redirects to archive.org
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'have-i-read-that',
            // Stop reading once it gets bigger than any cover should be.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$bytes): int {
                $bytes .= $chunk;
                return strlen($bytes) > self::MAX_COVER_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false || $code !== 200) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png'], true) || $info[0] < 10 || $info[1] < 10) {
            return null; // not an image, or Open Library's 1x1 placeholder
        }
        return ['bytes' => $bytes, 'mime' => $mime];
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
        $params['fields'] = 'key,title,author_name,isbn,number_of_pages_median,first_publish_year';
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
                'work' => isset($doc['key']) ? (string) $doc['key'] : null,
            ];
        }
        return $results;
    }
}
