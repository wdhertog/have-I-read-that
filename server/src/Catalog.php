<?php

declare(strict_types=1);

namespace App;

/**
 * Book data and covers from several sources, each filling in what the previous one
 * missed. The order comes from METADATA_SOURCES and COVER_SOURCES.
 */
final class Catalog
{
    public const FIELDS = ['title', 'author', 'pages', 'publisher', 'year', 'language', 'series', 'series_number'];

    public const DEFAULT_METADATA_SOURCES = 'kb,openlibrary,google,wikidata';

    private Kb $kb;
    private GoogleBooks $google;
    private Wikidata $wikidata;

    /** @param (callable(string): ?string)|null $get HTTPS GET; injectable for tests. */
    public function __construct(private Config $config, ?callable $get = null)
    {
        $get = \Closure::fromCallable($get ?? [Http::class, 'get']);
        $this->kb = new Kb($get);
        $this->google = new GoogleBooks($get, $config->get('GOOGLE_BOOKS_API_KEY'));
        $this->wikidata = new Wikidata($get);
    }

    public function wikidata(): Wikidata
    {
        return $this->wikidata;
    }

    /** @return list<string> */
    public function metadataSources(): array
    {
        return self::list($this->config->get('METADATA_SOURCES', self::DEFAULT_METADATA_SOURCES));
    }

    /** @return list<string> */
    public function coverSources(): array
    {
        return self::list($this->config->get('COVER_SOURCES', 'openlibrary,google'));
    }

    /**
     * Everything known about an ISBN, merged over the sources; "sources" says which
     * ones answered. Null when none knows the book.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $isbn): ?array
    {
        $merged = ['isbn' => $isbn, 'sources' => []];
        foreach ($this->metadataSources() as $source) {
            try {
                $found = match ($source) {
                    'kb' => $this->kb->byIsbn($isbn),
                    'openlibrary' => OpenLibrary::byIsbn($isbn),
                    'google' => $this->google->byIsbn($isbn),
                    // Last, as it searches by the title and author found so far.
                    'wikidata' => $this->wikidata->find($isbn, $merged['title'] ?? null, $merged['author'] ?? null),
                    default => null,
                };
            } catch (\RuntimeException) {
                $found = null; // a source being down shouldn't stop the others
            }
            if (!$found) {
                continue;
            }
            $merged['sources'][] = $source;
            foreach (self::FIELDS as $field) {
                if (($merged[$field] ?? null) === null || $merged[$field] === '') {
                    $value = $found[$field] ?? null;
                    $merged[$field] = $value === '' ? null : $value;
                }
            }
        }
        $merged['language'] = Language::normalize($merged['language'] ?? null);
        return ($merged['title'] ?? null) ? $merged : null;
    }

    /**
     * Search by title, author or ISBN in the sources that can search (Open Library and
     * Google Books), without duplicates.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $results = [];
        $seen = [];
        foreach ($this->metadataSources() as $source) {
            try {
                $found = match ($source) {
                    'openlibrary' => OpenLibrary::search($query, $limit),
                    'google' => $this->google->search($query, $limit),
                    default => [],
                };
            } catch (\RuntimeException) {
                $found = [];
            }
            foreach ($found as $result) {
                $key = $result['isbn'] !== '' ? $result['isbn'] : mb_strtolower($result['title'] . '|' . $result['author']);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    unset($result['cover']);
                    $results[] = $result;
                }
            }
        }
        return array_slice($results, 0, $limit);
    }

    /** @return array{bytes: string, mime: string}|null */
    public function cover(?string $isbn): ?array
    {
        if ($isbn === null || $isbn === '') {
            return null;
        }
        foreach ($this->coverSources() as $source) {
            $cover = match ($source) {
                'openlibrary' => OpenLibrary::fetchCover($isbn),
                'google' => $this->google->cover($isbn),
                default => null,
            };
            if ($cover !== null) {
                return $cover;
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map(static fn ($s) => strtolower(trim($s)), explode(',', $value))));
    }
}
