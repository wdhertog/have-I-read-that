<?php

declare(strict_types=1);

namespace App;

/**
 * Book data and covers from several sources, each filling in what the previous one
 * missed. The order comes from METADATA_SOURCES and COVER_SOURCES.
 */
final class Catalog
{
    public const FIELDS = [
        'title', 'author', 'pages', 'publisher', 'year', 'language', 'series', 'series_number', 'description', 'genre',
        'original_title', 'original_language', 'translator', 'first_published', 'awards',
    ];

    public const DEFAULT_METADATA_SOURCES = 'kb,openlibrary,google,hardcover,wikidata';

    private Kb $kb;
    private GoogleBooks $google;
    private Wikidata $wikidata;
    private Hardcover $hardcover;

    /**
     * @param (callable(string): ?string)|null $get HTTPS GET; injectable for tests.
     * @param (callable(string, string, list<string>): ?string)|null $post HTTPS POST of JSON; likewise.
     */
    public function __construct(private Config $config, ?callable $get = null, ?callable $post = null)
    {
        $get = \Closure::fromCallable($get ?? [Http::class, 'get']);
        $this->hardcover = new Hardcover(\Closure::fromCallable($post ?? [Http::class, 'postJson']), $config->get('HARDCOVER_TOKEN'));
        $this->kb = new Kb($get);
        $this->google = new GoogleBooks($get, $config->get('GOOGLE_BOOKS_API_KEY'));
        $this->wikidata = new Wikidata($get);
    }

    public function wikidata(): Wikidata
    {
        return $this->wikidata;
    }

    public function hardcover(): Hardcover
    {
        return $this->hardcover;
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
        $seriesFrom = null;
        $descriptionLanguage = null;
        foreach ($this->metadataSources() as $source) {
            try {
                $found = match ($source) {
                    'kb' => $this->kb->byIsbn($isbn),
                    'openlibrary' => OpenLibrary::byIsbn($isbn),
                    'google' => $this->google->byIsbn($isbn),
                    'hardcover' => $this->hardcover->byIsbn($isbn),
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
                $value = ($found[$field] ?? null) === '' ? null : ($found[$field] ?? null);
                $empty = ($merged[$field] ?? null) === null;
                if ($field === 'series_number') {
                    // A number only belongs with the series it came with.
                    $empty = $empty && $seriesFrom === $source;
                } elseif ($field === 'description' && !$empty && $value !== null) {
                    // A Dutch description wins over one in another language.
                    $empty = ($found['description_language'] ?? null) === 'nl' && $descriptionLanguage !== 'nl';
                }
                if ($empty && $value !== null) {
                    $merged[$field] = $value;
                    if ($field === 'series') {
                        $seriesFrom = $source;
                    } elseif ($field === 'description') {
                        $descriptionLanguage = $found['description_language'] ?? null;
                    }
                } else {
                    $merged[$field] ??= null;
                }
            }
        }
        $merged['language'] = Language::normalize($merged['language'] ?? null);
        $merged['original_language'] = Language::normalize($merged['original_language'] ?? null);
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
                    unset($result['cover'], $result['work']);
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
