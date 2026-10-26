<?php

declare(strict_types=1);

namespace App;

/**
 * Wikidata, for the one thing the library catalogues don't know: which series a book
 * belongs to ("Harry Potter", part 2), not the publisher's series.
 *
 * Few editions are in Wikidata with their ISBN, so it looks in two ways: by ISBN on
 * the edition (or the work it translates), and otherwise by title and author on the
 * work itself. The second only counts when both the title and the author's surname
 * match.
 */
final class Wikidata
{
    private const URL = 'https://query.wikidata.org/sparql?format=json&query=';

    /** @param \Closure(string): ?string $get */
    public function __construct(private \Closure $get)
    {
    }

    /** Whether the query service answers at all (for bin/lookup.php). */
    public function reachable(): bool
    {
        return ($this->get)(self::URL . rawurlencode('ASK {}')) !== null;
    }

    /** @return array{series: string, series_number: ?float}|null */
    public function find(string $isbn, ?string $title = null, ?string $author = null): ?array
    {
        $json = ($this->get)(self::URL . rawurlencode(self::query($isbn)));
        $found = $json !== null ? self::parse($json) : null;
        if ($found === null && $title !== null && self::words($title) !== '') {
            $json = ($this->get)(self::URL . rawurlencode(self::titleQuery($title)));
            $found = $json !== null ? self::parseTitleSearch($json, $title, (string) $author) : null;
        }
        return $found;
    }

    /** SPARQL that finds the series of every item carrying one of the ISBN's spellings. */
    public static function query(string $isbn): string
    {
        $values = implode(' ', array_map(static fn (string $s) => '"' . $s . '"', self::spellings($isbn)));
        return <<<SPARQL
            SELECT ?seriesLabel ?ordinal ?direct WHERE {
              VALUES ?isbn { {$values} }
              { ?edition wdt:P212 ?isbn } UNION { ?edition wdt:P957 ?isbn }
              { ?edition p:P179 ?statement . BIND(1 AS ?direct) }
              UNION { ?edition wdt:P629 ?work . ?work p:P179 ?statement . BIND(0 AS ?direct) }
              ?statement ps:P179 ?series .
              OPTIONAL { ?statement pq:P1545 ?ordinal }
              SERVICE wikibase:label { bd:serviceParam wikibase:language "nl,en,mul". }
            }
            LIMIT 20
            SPARQL;
    }

    /** SPARQL that searches items in a series by title, with their names and authors. */
    public static function titleQuery(string $title): string
    {
        $search = self::words(self::mainTitle($title)) . ' haswbstatement:P179';
        return <<<SPARQL
            SELECT ?item ?seriesLabel ?ordinal ?name ?authorName WHERE {
              SERVICE wikibase:mwapi {
                bd:serviceParam wikibase:endpoint "www.wikidata.org" ; wikibase:api "Search" ;
                  mwapi:srsearch "{$search}" ; mwapi:srlimit "10" .
                ?item wikibase:apiOutputItem mwapi:title .
              }
              ?item p:P179 ?statement .
              ?statement ps:P179 ?series .
              OPTIONAL { ?statement pq:P1545 ?ordinal }
              { ?item rdfs:label ?name } UNION { ?item skos:altLabel ?name }
              FILTER(LANG(?name) IN ("nl", "en", "mul"))
              OPTIONAL { ?item wdt:P50 ?author . ?author rdfs:label ?authorName . FILTER(LANG(?authorName) IN ("nl", "en", "mul")) }
              SERVICE wikibase:label { bd:serviceParam wikibase:language "nl,en,mul". }
            }
            LIMIT 500
            SPARQL;
    }

    /**
     * Wikidata stores ISBNs with hyphens, placed by registration group and publisher.
     * Rather than carry the ISBN range table, try every way of placing them.
     *
     * @return list<string>
     */
    public static function spellings(string $isbn): array
    {
        $codes = [$isbn];
        if (strlen($isbn) === 13 && str_starts_with($isbn, '978')) {
            $codes[] = Kb::toIsbn10($isbn);
        }
        $spellings = [];
        foreach ($codes as $code) {
            $spellings[] = $code;
            $prefix = strlen($code) === 13 ? substr($code, 0, 3) . '-' : '';
            $middle = substr($code, strlen($code) === 13 ? 3 : 0, 9);
            for ($i = 1; $i <= 7; $i++) {
                for ($j = $i + 1; $j <= 8; $j++) {
                    $spellings[] = $prefix . substr($middle, 0, $i) . '-' . substr($middle, $i, $j - $i) . '-'
                        . substr($middle, $j) . '-' . substr($code, -1);
                }
            }
        }
        return $spellings;
    }

    /** @return array{series: string, series_number: ?float}|null */
    public static function parse(string $json): ?array
    {
        $rows = json_decode($json, true)['results']['bindings'] ?? null;
        if (!is_array($rows)) {
            return null;
        }
        $best = null;
        $bestScore = -1;
        foreach ($rows as $row) {
            $name = trim((string) ($row['seriesLabel']['value'] ?? ''));
            if ($name === '' || preg_match('/^Q\d+$/', $name)) {
                continue; // no label in Dutch or English
            }
            $ordinal = str_replace(',', '.', trim((string) ($row['ordinal']['value'] ?? '')));
            $number = is_numeric($ordinal) && (float) $ordinal >= 0 && (float) $ordinal < 100000 ? round((float) $ordinal, 1) : null;
            // Prefer a numbered place in a series, then the edition's own series over the work's.
            $score = ($number !== null ? 2 : 0) + (int) ($row['direct']['value'] ?? 0);
            if ($score > $bestScore) {
                $best = ['series' => mb_substr($name, 0, 255), 'series_number' => $number];
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * The series of the search hit whose name is the book's title and whose author
     * shares a surname with the book's.
     *
     * @return array{series: string, series_number: ?float}|null
     */
    public static function parseTitleSearch(string $json, string $title, string $author): ?array
    {
        $rows = json_decode($json, true)['results']['bindings'] ?? null;
        if (!is_array($rows)) {
            return null;
        }
        $titles = array_unique([self::words($title), self::words(self::mainTitle($title))]);
        $surnames = self::surnames($author);
        $items = [];
        foreach ($rows as $row) {
            $item = (string) ($row['item']['value'] ?? '');
            $items[$item]['names'][self::words((string) ($row['name']['value'] ?? ''))] = true;
            foreach (self::surnames((string) ($row['authorName']['value'] ?? '')) as $surname) {
                $items[$item]['surnames'][$surname] = true;
            }
            $items[$item]['rows'][] = $row + ['direct' => ['value' => '1']];
        }
        foreach ($items as $item) {
            $titleMatches = array_intersect($titles, array_keys($item['names'])) !== [];
            $authorMatches = $surnames === [] || array_intersect($surnames, array_keys($item['surnames'] ?? [])) !== [];
            if ($titleMatches && $authorMatches) {
                $found = self::parse((string) json_encode(['results' => ['bindings' => $item['rows']]]));
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** "De avonden: een winterverhaal (Reve #1)" → "De avonden" */
    private static function mainTitle(string $title): string
    {
        return trim(preg_split('/\s*[:(\[]/u', $title)[0] ?? $title);
    }

    /** Lowercase letters and digits only, single spaces: for comparing and searching. */
    private static function words(string $text): string
    {
        $text = mb_strtolower($text);
        if (function_exists('transliterator_transliterate')) {
            $text = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        }
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    /** @return list<string> Last word of each name: "J.K. Rowling, Jim Kay" → ["rowling", "kay"] */
    private static function surnames(string $authors): array
    {
        $surnames = [];
        foreach (preg_split('/\s*[,;&]\s*|\s+(?:en|and)\s+/u', $authors) ?: [] as $name) {
            $words = explode(' ', self::words($name));
            $last = end($words);
            if ($last !== false && mb_strlen($last) > 1) {
                $surnames[] = $last;
            }
        }
        return array_values(array_unique($surnames));
    }
}
