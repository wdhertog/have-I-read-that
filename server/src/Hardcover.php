<?php

declare(strict_types=1);

namespace App;

/**
 * Hardcover (hardcover.app), the closest thing to Goodreads with an open API: series,
 * genres and descriptions, strongest for English books. Needs a personal API token
 * (HARDCOVER_TOKEN, from hardcover.app → Settings → API); without one it is skipped.
 */
final class Hardcover
{
    private const URL = 'https://api.hardcover.app/v1/graphql';

    private const QUERY = <<<'GRAPHQL'
        query ($isbns: [String!]) {
          editions(where: {_or: [{isbn_13: {_in: $isbns}}, {isbn_10: {_in: $isbns}}]}, limit: 1) {
            title
            pages
            release_date
            book {
              title
              description
              release_year
              cached_tags
              book_series { position series { name } }
              contributions { contribution author { name } }
            }
          }
        }
        GRAPHQL;

    /** @param \Closure(string, string, list<string>): ?string $post URL, JSON body, headers */
    public function __construct(private \Closure $post, private string $token)
    {
    }

    public function enabled(): bool
    {
        return trim($this->token) !== '';
    }

    /** @return array<string, mixed>|null */
    public function byIsbn(string $isbn): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $isbns = [$isbn];
        if (strlen($isbn) === 13 && str_starts_with($isbn, '978')) {
            $isbns[] = Kb::toIsbn10($isbn);
        }
        $token = trim($this->token);
        $token = str_starts_with(strtolower($token), 'bearer ') ? $token : "Bearer {$token}";
        $json = ($this->post)(
            self::URL,
            (string) json_encode(['query' => self::QUERY, 'variables' => ['isbns' => $isbns]]),
            ["Authorization: {$token}"],
        );
        return $json !== null ? self::parse($json) : null;
    }

    /** @return array<string, mixed>|null */
    public static function parse(string $json): ?array
    {
        $edition = json_decode($json, true)['data']['editions'][0] ?? null;
        if (!is_array($edition)) {
            return null;
        }
        $book = is_array($edition['book'] ?? null) ? $edition['book'] : [];
        $title = trim((string) ($edition['title'] ?? $book['title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $authors = [];
        $translators = [];
        foreach ($book['contributions'] ?? [] as $contribution) {
            $name = trim((string) ($contribution['author']['name'] ?? ''));
            $role = strtolower((string) ($contribution['contribution'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (str_contains($role, 'translat')) {
                $translators[] = $name;
            } elseif ($role === '' || $role === 'author') {
                $authors[] = $name;
            }
        }
        $series = $book['book_series'][0] ?? null;
        $position = $series['position'] ?? null;
        $tags = $book['cached_tags'] ?? null;
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }
        $genres = array_values(array_filter(array_map(
            static fn ($tag) => is_array($tag) ? trim((string) ($tag['tag'] ?? '')) : '',
            is_array($tags['Genre'] ?? null) ? array_slice($tags['Genre'], 0, 3) : [],
        )));
        $description = trim((string) ($book['description'] ?? ''));
        $year = preg_match('/^(\d{4})/', (string) ($edition['release_date'] ?? ''), $m) ? (int) $m[1] : null;
        return array_filter([
            'title' => $title,
            'author' => implode(', ', array_slice($authors, 0, 3)),
            'pages' => isset($edition['pages']) && (int) $edition['pages'] > 0 ? (int) $edition['pages'] : null,
            'year' => $year,
            'series' => isset($series['series']['name']) ? mb_substr(trim((string) $series['series']['name']), 0, 255) : null,
            'series_number' => is_numeric($position) && (float) $position >= 0 && (float) $position < 100000 ? round((float) $position, 1) : null,
            'description' => $description !== '' ? mb_substr($description, 0, 5000) : null,
            'genre' => $genres ? mb_substr(implode(', ', $genres), 0, 255) : null,
            'translator' => $translators ? mb_substr(implode(', ', $translators), 0, 255) : null,
            'first_published' => isset($book['release_year']) && is_numeric($book['release_year']) ? (int) $book['release_year'] : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
