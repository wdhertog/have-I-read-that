<?php

declare(strict_types=1);

namespace App;

/**
 * Wikidata, for what the library catalogues don't know: the series ("Harry Potter",
 * part 2, not the publisher's series), the original title and language, the
 * translator, the year of first publication, genres, awards, and a summary from the
 * Dutch (or else English) Wikipedia.
 *
 * Few editions are in Wikidata with their ISBN, so the book is found in two ways: by
 * ISBN on the edition (and from there the work it is an edition or translation of),
 * and otherwise by title and author on the work itself. The second only counts when
 * both the title and an author's surname match.
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

    /** @return array<string, mixed>|null */
    public function find(string $isbn, ?string $title = null, ?string $author = null): ?array
    {
        $json = ($this->get)(self::URL . rawurlencode(self::isbnQuery($isbn)));
        $items = $json !== null ? self::parseIsbn($json) : null;
        if ($items === null && $title !== null && self::words($title) !== '') {
            $json = ($this->get)(self::URL . rawurlencode(self::titleQuery($title)));
            $work = $json !== null ? self::parseTitleSearch($json, $title, (string) $author) : null;
            $items = $work !== null ? ['edition' => null, 'work' => $work] : null;
        }
        if ($items === null) {
            return null;
        }
        $json = ($this->get)(self::URL . rawurlencode(self::detailsQuery($items['work'], $items['edition'])));
        $found = $json !== null ? self::parseDetails($json) : null;
        if ($found === null) {
            return null;
        }
        if ($title !== null && $found['original_title'] !== null
            && in_array(self::words($found['original_title']), [self::words($title), self::words(self::mainTitle($title))], true)) {
            $found['original_title'] = null; // not a translation, or the same title anyway
        }
        if ($found['wikipedia'] !== null) {
            [$language, $page] = $found['wikipedia'];
            $summary = ($this->get)("https://{$language}.wikipedia.org/api/rest_v1/page/summary/" . rawurlencode($page));
            $extract = $summary !== null ? self::parseSummary($summary) : null;
            if ($extract !== null) {
                $found['description'] = $extract;
                $found['description_language'] = $language;
            }
        }
        unset($found['wikipedia']);
        return array_filter($found, static fn ($v) => $v !== null) ?: null;
    }

    /** SPARQL that finds the items carrying one of the ISBN's spellings, and their work. */
    public static function isbnQuery(string $isbn): string
    {
        $values = implode(' ', array_map(static fn (string $s) => '"' . $s . '"', self::spellings($isbn)));
        return <<<SPARQL
            SELECT ?edition ?work WHERE {
              VALUES ?isbn { {$values} }
              { ?edition wdt:P212 ?isbn } UNION { ?edition wdt:P957 ?isbn }
              OPTIONAL { ?edition wdt:P629 ?work }
            }
            LIMIT 5
            SPARQL;
    }

    /** SPARQL that searches written works by title, with their names and authors. */
    public static function titleQuery(string $title): string
    {
        $search = self::words(self::mainTitle($title)) . ' haswbstatement:P50';
        return <<<SPARQL
            SELECT ?item ?name ?authorName WHERE {
              SERVICE wikibase:mwapi {
                bd:serviceParam wikibase:endpoint "www.wikidata.org" ; wikibase:api "Search" ;
                  mwapi:srsearch "{$search}" ; mwapi:srlimit "10" .
                ?item wikibase:apiOutputItem mwapi:title .
              }
              { ?item rdfs:label ?name } UNION { ?item skos:altLabel ?name }
              FILTER(LANG(?name) IN ("nl", "en", "mul"))
              ?item wdt:P50 ?author .
              ?author rdfs:label ?authorName .
              FILTER(LANG(?authorName) IN ("nl", "en", "mul"))
            }
            LIMIT 500
            SPARQL;
    }

    /** SPARQL for everything we use about a work, and about the edition when we know it. */
    public static function detailsQuery(string $work, ?string $edition): string
    {
        $values = "(wd:{$work} 0)" . ($edition !== null && $edition !== $work ? " (wd:{$edition} 1)" : '');
        return <<<SPARQL
            SELECT ?direct ?prop ?value ?valueLabel ?ordinal WHERE {
              VALUES (?item ?direct) { {$values} }
              { ?item p:P179 ?statement . ?statement ps:P179 ?value . OPTIONAL { ?statement pq:P1545 ?ordinal } BIND("series" AS ?prop) }
              UNION { ?item wdt:P1476 ?value . BIND("title" AS ?prop) }
              UNION { ?item wdt:P407 ?language . ?language wdt:P218 ?value . BIND("language" AS ?prop) }
              UNION { ?item wdt:P577 ?value . BIND("date" AS ?prop) }
              UNION { ?item wdt:P136 ?value . BIND("genre" AS ?prop) }
              UNION { ?item wdt:P655 ?value . BIND("translator" AS ?prop) }
              UNION { ?item wdt:P166 ?value . BIND("award" AS ?prop) }
              UNION { ?value schema:about ?item ; schema:isPartOf <https://nl.wikipedia.org/> . BIND("nlwiki" AS ?prop) }
              UNION { ?value schema:about ?item ; schema:isPartOf <https://en.wikipedia.org/> . BIND("enwiki" AS ?prop) }
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

    /** @return array{edition: string, work: string}|null Q-ids */
    public static function parseIsbn(string $json): ?array
    {
        foreach (self::rows($json) ?? [] as $row) {
            $edition = self::qid($row['edition']['value'] ?? '');
            if ($edition !== null) {
                return ['edition' => $edition, 'work' => self::qid($row['work']['value'] ?? '') ?? $edition];
            }
        }
        return null;
    }

    /** The Q-id of the hit whose name is the book's title and whose author shares a surname. */
    public static function parseTitleSearch(string $json, string $title, string $author): ?string
    {
        $titles = array_unique([self::words($title), self::words(self::mainTitle($title))]);
        $surnames = self::surnames($author);
        $items = [];
        foreach (self::rows($json) ?? [] as $row) {
            $item = self::qid($row['item']['value'] ?? '');
            if ($item === null) {
                continue;
            }
            $items[$item]['names'][self::words((string) ($row['name']['value'] ?? ''))] = true;
            foreach (self::surnames((string) ($row['authorName']['value'] ?? '')) as $surname) {
                $items[$item]['surnames'][$surname] = true;
            }
        }
        foreach ($items as $item => $info) {
            $titleMatches = array_intersect($titles, array_keys($info['names'])) !== [];
            $authorMatches = $surnames === [] || array_intersect($surnames, array_keys($info['surnames'] ?? [])) !== [];
            if ($titleMatches && $authorMatches) {
                return (string) $item;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public static function parseDetails(string $json): ?array
    {
        $rows = self::rows($json);
        if ($rows === null) {
            return null;
        }
        $series = null;
        $seriesScore = -1;
        $byProp = [];
        foreach ($rows as $row) {
            $prop = (string) ($row['prop']['value'] ?? '');
            $direct = (int) ($row['direct']['value'] ?? 0);
            $value = trim((string) ($row['value']['value'] ?? ''));
            $label = trim((string) ($row['valueLabel']['value'] ?? $value));
            if ($prop === 'series') {
                if ($label === '' || self::qid($label) !== null) {
                    continue; // no label in Dutch or English
                }
                $ordinal = str_replace(',', '.', trim((string) ($row['ordinal']['value'] ?? '')));
                $number = is_numeric($ordinal) && (float) $ordinal >= 0 && (float) $ordinal < 100000 ? round((float) $ordinal, 1) : null;
                // Prefer a numbered place in a series, then the edition's own series over the work's.
                $score = ($number !== null ? 2 : 0) + $direct;
                if ($score > $seriesScore) {
                    $series = ['series' => mb_substr($label, 0, 255), 'series_number' => $number];
                    $seriesScore = $score;
                }
                continue;
            }
            $shown = in_array($prop, ['genre', 'translator', 'award'], true) ? $label : $value;
            if ($shown !== '' && self::qid($shown) === null) {
                $byProp[$prop][$direct][$shown] = true;
            }
        }
        // The work's value where it has one, else the edition's.
        $pick = static function (string $prop, ?int $only = null) use ($byProp): array {
            foreach ($only !== null ? [$only] : [0, 1] as $direct) {
                if (!empty($byProp[$prop][$direct])) {
                    // Keys like "1984" became ints in the array; values are always text.
                    return array_map('strval', array_keys($byProp[$prop][$direct]));
                }
            }
            return [];
        };
        $years = array_filter(array_map(
            static fn (string $date) => preg_match('/^([+-]?\d{1,4})-/', $date, $m) ? (int) $m[1] : null,
            $pick('date', 0),
        ), static fn ($y) => $y !== null && $y > -3000 && $y < 3000);
        $wikipedia = null;
        foreach (['nlwiki' => 'nl', 'enwiki' => 'en'] as $prop => $language) {
            $url = $pick($prop)[0] ?? null;
            if ($url !== null && preg_match('~^https://' . $language . '\.wikipedia\.org/wiki/([^/?#]+)$~', $url, $m)) {
                $wikipedia = [$language, rawurldecode($m[1])];
                break;
            }
        }
        $join = static fn (array $values, int $max, int $length) => $values
            ? mb_substr(implode(', ', array_slice($values, 0, $max)), 0, $length) : null;
        return [
            'series' => $series['series'] ?? null,
            'series_number' => $series['series_number'] ?? null,
            'original_title' => isset($pick('title', 0)[0]) ? mb_substr($pick('title', 0)[0], 0, 500) : null,
            'original_language' => Language::normalize($pick('language', 0)[0] ?? null),
            'first_published' => $years ? min($years) : null,
            'genre' => $join($pick('genre'), 3, 255),
            'translator' => $join($pick('translator', 1), 3, 255),
            'awards' => $join($pick('award', 0), 10, 1000),
            'wikipedia' => $wikipedia,
        ];
    }

    /** The first paragraph of a Wikipedia page summary, or null for a disambiguation page. */
    public static function parseSummary(string $json): ?string
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['type'] ?? '') === 'disambiguation') {
            return null;
        }
        $extract = trim((string) ($data['extract'] ?? ''));
        return $extract !== '' ? mb_substr($extract, 0, 5000) : null;
    }

    /** @return list<array<string, mixed>>|null */
    private static function rows(string $json): ?array
    {
        $rows = json_decode($json, true)['results']['bindings'] ?? null;
        return is_array($rows) ? $rows : null;
    }

    /** "http://www.wikidata.org/entity/Q42" or "Q42" → "Q42"; anything else → null. */
    private static function qid(string $value): ?string
    {
        return preg_match('#(?:^|/entity/)(Q\d{1,12})$#', $value, $m) ? $m[1] : null;
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
