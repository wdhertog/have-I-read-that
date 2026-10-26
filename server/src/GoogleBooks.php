<?php

declare(strict_types=1);

namespace App;

/**
 * Google Books: many Dutch books, with covers. Works without a key; set
 * GOOGLE_BOOKS_API_KEY when you hit its daily limit.
 */
final class GoogleBooks
{
    /** @param \Closure(string): ?string $get */
    public function __construct(private \Closure $get, private string $key = '')
    {
    }

    /** @return array<string, mixed>|null */
    public function byIsbn(string $isbn): ?array
    {
        $items = $this->query('isbn:' . $isbn, 1);
        return $items ? ['isbn' => $isbn] + $items[0] : null;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 10): array
    {
        return $this->query($query, $limit);
    }

    /** @return array{bytes: string, mime: string}|null */
    public function cover(string $isbn): ?array
    {
        $book = $this->byIsbn($isbn);
        $url = $book['cover'] ?? null;
        // Only fetch pictures from Google's own hosts.
        if ($url === null || !preg_match('#^https://([a-z0-9-]+\.)*(google\.com|googleusercontent\.com|googleapis\.com)/#i', $url)) {
            return null;
        }
        $bytes = ($this->get)($url);
        $info = $bytes !== null ? @getimagesizefromstring($bytes) : false;
        // Google's "no picture" stand-in is tiny; a real cover is bigger than this.
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true) || $info[0] < 60 || $info[1] < 80) {
            return null;
        }
        return ['bytes' => $bytes, 'mime' => $info['mime']];
    }

    /** @return list<array<string, mixed>> */
    private function query(string $q, int $limit): array
    {
        $url = 'https://www.googleapis.com/books/v1/volumes?' . http_build_query(array_filter([
            'q' => $q, 'maxResults' => $limit, 'printType' => 'books', 'key' => $this->key,
        ]));
        $json = json_decode((string) ($this->get)($url), true);
        $results = [];
        foreach ($json['items'] ?? [] as $item) {
            $info = $item['volumeInfo'] ?? [];
            if (($info['title'] ?? '') === '') {
                continue;
            }
            $isbn = '';
            foreach ($info['industryIdentifiers'] ?? [] as $id) {
                if (($id['type'] ?? '') === 'ISBN_13' || ($isbn === '' && ($id['type'] ?? '') === 'ISBN_10')) {
                    $isbn = (string) $id['identifier'];
                }
            }
            $cover = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
            $results[] = [
                'title' => trim($info['title'] . (isset($info['subtitle']) ? ': ' . $info['subtitle'] : '')),
                'author' => implode(', ', array_slice($info['authors'] ?? [], 0, 3)),
                'isbn' => $isbn,
                'pages' => isset($info['pageCount']) && $info['pageCount'] > 0 ? (int) $info['pageCount'] : null,
                'year' => preg_match('/^\d{4}/', (string) ($info['publishedDate'] ?? ''), $m) ? (int) $m[0] : null,
                'publisher' => $info['publisher'] ?? null,
                'language' => $info['language'] ?? null,
                // A bigger version than the 128px thumbnail, over HTTPS.
                'cover' => $cover !== null ? preg_replace('#^http://#', 'https://', str_replace('&edge=curl', '', $cover)) . '&fife=w600' : null,
            ];
        }
        return $results;
    }
}
