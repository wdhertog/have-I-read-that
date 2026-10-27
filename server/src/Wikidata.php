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
                    $series = ['series' => mb_substr($label, 0, 255), 'series_number' => $number, 'qid' => self::qid($value)];
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
            'wikidata_series' => $series['qid'] ?? null,
            'original_title' => isset($pick('title', 0)[0]) ? mb_substr($pick('title', 0)[0], 0, 500) : null,
            'original_language' => Language::normalize($pick('language', 0)[0] ?? null),
            'first_published' => $years ? min($years) : null,
            'genre' => $join($pick('genre'), 3, 255),
            'translator' => $join($pick('translator', 1), 3, 255),
            'awards' => $join($pick('award', 0), 10, 1000),
            'wikipedia' => $wikipedia,
        ];
    }

    // --- authors ----------------------------------------------------------------

    /**
     * The Q-id of a writer by name: a person whose name (or another name) matches and
     * who wrote books in Wikidata; of several, the one who wrote one of the given
     * titles, else the one with the most books. Null when Wikidata doesn't know them.
     *
     * @param list<string> $titles Titles of your books by them.
     */
    public function findAuthor(string $name, array $titles = []): ?string
    {
        $name = self::firstLast($name);
        if (self::words($name) === '') {
            return null;
        }
        $json = ($this->get)(self::URL . rawurlencode(self::authorSearchQuery($name)));
        return $json !== null ? self::parseAuthorSearch($json, $name, $titles) : null;
    }

    /**
     * What Wikidata and Wikipedia say about a writer.
     *
     * @return array<string, mixed>|null
     */
    public function author(string $qid): ?array
    {
        if (self::qid($qid) === null) {
            return null;
        }
        $json = ($this->get)(self::URL . rawurlencode(self::authorQuery($qid)));
        $author = $json !== null ? self::parseAuthor($json) : null;
        if ($author === null) {
            return null;
        }
        if ($author['wikipedia'] !== null) {
            [$language, $page] = $author['wikipedia'];
            $summary = ($this->get)("https://{$language}.wikipedia.org/api/rest_v1/page/summary/" . rawurlencode($page));
            $author['bio'] = $summary !== null ? self::parseSummary($summary) : null;
            $author['bio_language'] = $language;
            $author['wikipedia_url'] = "https://{$language}.wikipedia.org/wiki/" . rawurlencode(str_replace(' ', '_', $page));
        }
        unset($author['wikipedia']);
        $author['works'] = [];
        $json = ($this->get)(self::URL . rawurlencode(self::authorWorksQuery($qid)));
        if ($json !== null) {
            $author['works'] = self::parseSeriesParts($json) ?? [];
            usort($author['works'], static fn (array $a, array $b) => [$a['year'] ?? 9999, $a['title']] <=> [$b['year'] ?? 9999, $b['title']]);
        }
        return $author;
    }

    /** SPARQL that searches people who wrote books, by name, with all their names and how many books. */
    public static function authorSearchQuery(string $name): string
    {
        $search = self::words($name) . ' haswbstatement:P31=Q5';
        return <<<SPARQL
            SELECT ?item ?name ?titleNl ?titleEn WHERE {
              SERVICE wikibase:mwapi {
                bd:serviceParam wikibase:endpoint "www.wikidata.org" ; wikibase:api "Search" ;
                  mwapi:srsearch "{$search}" ; mwapi:srlimit "10" .
                ?item wikibase:apiOutputItem mwapi:title .
              }
              { ?item rdfs:label ?name } UNION { ?item skos:altLabel ?name }
              FILTER(LANG(?name) IN ("nl", "en", "mul"))
              ?work wdt:P50 ?item .
              OPTIONAL { ?work rdfs:label ?titleNl . FILTER(LANG(?titleNl) = "nl") }
              OPTIONAL { ?work rdfs:label ?titleEn . FILTER(LANG(?titleEn) = "en") }
            }
            LIMIT 3000
            SPARQL;
    }

    /** SPARQL for a writer's description, life, picture, awards and Wikipedia pages. */
    public static function authorQuery(string $qid): string
    {
        return <<<SPARQL
            SELECT ?prop ?value ?valueLabel ?lang ?precision ?rank WHERE {
              VALUES ?item { wd:{$qid} }
              { ?item rdfs:label ?value . FILTER(LANG(?value) IN ("nl", "en", "mul")) BIND("label" AS ?prop) BIND(LANG(?value) AS ?lang) }
              UNION { ?item schema:description ?value . FILTER(LANG(?value) IN ("nl", "en")) BIND("description" AS ?prop) BIND(LANG(?value) AS ?lang) }
              UNION { ?item p:P569 ?statement . ?statement wikibase:rank ?rank . FILTER(?rank != wikibase:DeprecatedRank)
                      ?statement psv:P569 [ wikibase:timeValue ?value ; wikibase:timePrecision ?precision ] . BIND("born" AS ?prop) }
              UNION { ?item p:P570 ?statement . ?statement wikibase:rank ?rank . FILTER(?rank != wikibase:DeprecatedRank)
                      ?statement psv:P570 [ wikibase:timeValue ?value ; wikibase:timePrecision ?precision ] . BIND("died" AS ?prop) }
              UNION { ?item wdt:P19 ?value . BIND("birthplace" AS ?prop) }
              UNION { ?item wdt:P20 ?value . BIND("deathplace" AS ?prop) }
              UNION { ?item wdt:P27 ?value . BIND("country" AS ?prop) }
              UNION { ?item wdt:P18 ?value . BIND("image" AS ?prop) }
              UNION { ?item wdt:P166 ?value . BIND("award" AS ?prop) }
              UNION { ?value schema:about ?item ; schema:isPartOf <https://nl.wikipedia.org/> . BIND("nlwiki" AS ?prop) }
              UNION { ?value schema:about ?item ; schema:isPartOf <https://en.wikipedia.org/> . BIND("enwiki" AS ?prop) }
              SERVICE wikibase:label { bd:serviceParam wikibase:language "nl,en,mul". }
            }
            LIMIT 500
            SPARQL;
    }

    /** SPARQL for a writer's literary works (not editions), with titles and year. */
    public static function authorWorksQuery(string $qid): string
    {
        return <<<SPARQL
            SELECT ?work ?titleNl ?titleEn ?date WHERE {
              ?work wdt:P50 wd:{$qid} .
              ?work wdt:P31/wdt:P279* wd:Q7725634 .
              FILTER NOT EXISTS { ?work wdt:P31 wd:Q3331189 }
              OPTIONAL { ?work rdfs:label ?titleNl . FILTER(LANG(?titleNl) = "nl") }
              OPTIONAL { ?work rdfs:label ?titleEn . FILTER(LANG(?titleEn) = "en") }
              OPTIONAL { ?work wdt:P577 ?date }
            }
            LIMIT 1000
            SPARQL;
    }

    /** @param list<string> $titles */
    public static function parseAuthorSearch(string $json, string $name, array $titles = []): ?string
    {
        $wanted = self::words(self::firstLast($name));
        $mine = [];
        foreach ($titles as $title) {
            $mine[self::words($title)] = true;
        }
        $items = [];
        foreach (self::rows($json) ?? [] as $row) {
            $item = self::qid($row['item']['value'] ?? '');
            if ($item === null) {
                continue;
            }
            $items[$item]['names'][self::words((string) ($row['name']['value'] ?? ''))] = true;
            foreach (['titleNl', 'titleEn'] as $key) {
                $title = self::words((string) ($row[$key]['value'] ?? ''));
                if ($title !== '') {
                    $items[$item]['titles'][$title] = true;
                }
            }
        }
        $best = null;
        $bestScore = -1;
        foreach ($items as $item => $info) {
            if (!in_array($wanted, array_map('strval', array_keys($info['names'])), true)) {
                continue;
            }
            $known = array_map('strval', array_keys($info['titles'] ?? []));
            // One of your books by them weighs more than any number of other books.
            $score = count(array_intersect_key(array_flip($known), $mine)) * 10000 + count($known);
            if ($score > $bestScore) {
                $best = (string) $item;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** @return array<string, mixed>|null */
    public static function parseAuthor(string $json): ?array
    {
        $rows = self::rows($json);
        if ($rows === null) {
            return null;
        }
        $values = [];
        $dates = [];
        foreach ($rows as $row) {
            $prop = (string) ($row['prop']['value'] ?? '');
            $value = trim((string) ($row['value']['value'] ?? ''));
            if ($prop === 'born' || $prop === 'died') {
                // A date can be known to the day, the month or only the year (then
                // Wikidata still writes "-01-01"); there may be several, one preferred.
                $dates[$prop][] = [
                    'value' => $value,
                    'precision' => (int) ($row['precision']['value'] ?? 11),
                    'preferred' => str_ends_with((string) ($row['rank']['value'] ?? ''), 'PreferredRank'),
                ];
                continue;
            }
            $label = trim((string) ($row['valueLabel']['value'] ?? $value));
            $lang = (string) ($row['lang']['value'] ?? '');
            $shown = in_array($prop, ['birthplace', 'deathplace', 'country', 'award'], true) ? $label : $value;
            if ($shown !== '' && self::qid($shown) === null) {
                $values[$prop][$lang][$shown] = true;
            }
        }
        // Dutch where there is, else English, else whatever there is.
        $first = static function (string $prop) use ($values): ?string {
            foreach (['nl', 'en', 'mul', ''] as $lang) {
                if (!empty($values[$prop][$lang])) {
                    return (string) array_key_first($values[$prop][$lang]);
                }
            }
            return null;
        };
        $all = static fn (string $prop): array => array_values(array_unique(array_map('strval', array_merge(
            ...array_map('array_keys', array_values($values[$prop] ?? [])),
        ))));
        // Wikidata may hold several dates, some wrong. Prefer the preferred one, then
        // the most precise; never a death before the birth. When the description gives
        // the years ("Australisch schrijver (1944-2026)"), a date more than a year off
        // is a mistake, and without a fitting date the description's year is shown.
        $expected = [];
        foreach (array_keys($values['description'] ?? []) as $lang) {
            foreach (array_keys($values['description'][$lang]) as $description) {
                if (!$expected && preg_match('/\b(1\d{3}|20\d{2})\s*[-–—]\s*(1\d{3}|20\d{2})\b/u', (string) $description, $m)
                    && (int) $m[2] >= (int) $m[1] && (int) $m[2] - (int) $m[1] <= 120) {
                    $expected = ['born' => (int) $m[1], 'died' => (int) $m[2]];
                }
            }
        }
        $date = static function (string $prop, ?array $after = null) use ($dates, $expected): ?array {
            $options = [];
            foreach ($dates[$prop] ?? [] as $candidate) {
                if (!preg_match('/^([+-]?\d{1,4})-(\d{2})-(\d{2})/', $candidate['value'], $m) || $candidate['precision'] < 9) {
                    continue; // decades and centuries say too little
                }
                $year = (int) $m[1];
                $month = $candidate['precision'] >= 10 && $m[2] !== '00' ? (int) $m[2] : null;
                $day = $candidate['precision'] >= 11 && $month !== null && $m[3] !== '00' ? (int) $m[3] : null;
                $sort = $year * 10000 + ($month ?? 1) * 100 + ($day ?? 1);
                if ($year > (int) date('Y') || ($after !== null && $sort < $after['sort'])
                    || (isset($expected[$prop]) && abs($expected[$prop] - $year) > 1)) {
                    continue;
                }
                $options[] = [
                    'score' => [$candidate['preferred'], isset($expected[$prop]) && $expected[$prop] === $year, $candidate['precision']],
                    'date' => ['year' => $year, 'month' => $month, 'date' => $day !== null ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null],
                    'sort' => $sort,
                ];
            }
            usort($options, static fn (array $a, array $b) => $b['score'] <=> $a['score']);
            if ($options === [] && isset($expected[$prop])) {
                return ['date' => ['year' => $expected[$prop], 'month' => null, 'date' => null], 'sort' => $expected[$prop] * 10000 + 101];
            }
            return $options[0] ?? null;
        };
        $born = $date('born');
        $died = $date('died', $born);
        $image = $first('image');
        $wikipedia = null;
        foreach (['nlwiki' => 'nl', 'enwiki' => 'en'] as $prop => $language) {
            $url = $first($prop);
            if ($url !== null && preg_match('~^https://' . $language . '\.wikipedia\.org/wiki/([^/?#]+)$~', $url, $m)) {
                $wikipedia = [$language, rawurldecode($m[1])];
                break;
            }
        }
        $name = $first('label');
        if ($name === null) {
            return null;
        }
        return [
            'name' => mb_substr($name, 0, 255),
            'description' => ($d = $first('description')) !== null ? mb_substr($d, 0, 500) : null,
            'born' => $born['date'] ?? null,
            'died' => $died['date'] ?? null,
            'birthplace' => $first('birthplace'),
            'deathplace' => $first('deathplace'),
            'countries' => array_slice($all('country'), 0, 3),
            'awards' => array_slice($all('award'), 0, 15),
            // Commons "Special:FilePath" link to the picture, over HTTPS.
            'image' => $image !== null && preg_match('~^https?://commons\.wikimedia\.org/wiki/Special:FilePath/[^/?#]+$~', $image)
                ? (string) preg_replace('~^http://~', 'https://', $image) : null,
            'wikipedia' => $wikipedia,
            'bio' => null,
            'bio_language' => null,
            'wikipedia_url' => null,
        ];
    }

    /** "Reve, Gerard" → "Gerard Reve"; other names stay as they are. */
    public static function firstLast(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^([^,]+),\s*([^,]+)$/u', $name, $m)) {
            return trim($m[2]) . ' ' . trim($m[1]);
        }
        return $name;
    }

    // --- series -----------------------------------------------------------------

    /**
     * The Q-id of a series by its name, checked against the authors of your books in
     * it: the name (or another name of it) must match and one of its books must share
     * an author's surname. Null when Wikidata doesn't know it.
     *
     * @param list<string> $authors
     */
    public function findSeries(string $name, array $authors): ?string
    {
        if (self::words($name) === '') {
            return null;
        }
        $json = ($this->get)(self::URL . rawurlencode(self::seriesSearchQuery($name)));
        return $json !== null ? self::parseSeriesSearch($json, $name, $authors) : null;
    }

    /** @return list<array{qid: string, number: ?float, title: string, title_en: ?string, author: string, year: ?int}>|null */
    public function seriesParts(string $qid): ?array
    {
        if (self::qid($qid) === null) {
            return null;
        }
        $json = ($this->get)(self::URL . rawurlencode(self::seriesPartsQuery($qid)));
        return $json !== null ? self::parseSeriesParts($json) : null;
    }

    /** SPARQL that searches series (items that books are part of) by name. */
    public static function seriesSearchQuery(string $name): string
    {
        $search = self::words($name);
        return <<<SPARQL
            SELECT ?item ?name ?authorName WHERE {
              SERVICE wikibase:mwapi {
                bd:serviceParam wikibase:endpoint "www.wikidata.org" ; wikibase:api "Search" ;
                  mwapi:srsearch "{$search}" ; mwapi:srlimit "10" .
                ?item wikibase:apiOutputItem mwapi:title .
              }
              FILTER EXISTS { ?part wdt:P179 ?item }
              { ?item rdfs:label ?name } UNION { ?item skos:altLabel ?name }
              FILTER(LANG(?name) IN ("nl", "en", "mul"))
              OPTIONAL {
                { ?item wdt:P50 ?author } UNION { ?book wdt:P179 ?item ; wdt:P50 ?author }
                ?author rdfs:label ?authorName .
                FILTER(LANG(?authorName) IN ("nl", "en", "mul"))
              }
            }
            LIMIT 1000
            SPARQL;
    }

    /** SPARQL for the works in a series (not editions, not sub-series), with titles and authors. */
    public static function seriesPartsQuery(string $qid): string
    {
        return <<<SPARQL
            SELECT ?work ?ordinal ?titleNl ?titleEn ?authorName ?date WHERE {
              ?work p:P179 ?statement .
              ?statement ps:P179 wd:{$qid} .
              OPTIONAL { ?statement pq:P1545 ?ordinal }
              FILTER NOT EXISTS { ?work wdt:P31 wd:Q3331189 }
              FILTER NOT EXISTS { ?subpart wdt:P179 ?work }
              OPTIONAL { ?work rdfs:label ?titleNl . FILTER(LANG(?titleNl) = "nl") }
              OPTIONAL { ?work rdfs:label ?titleEn . FILTER(LANG(?titleEn) = "en") }
              OPTIONAL { ?work wdt:P50 ?author . ?author rdfs:label ?authorName . FILTER(LANG(?authorName) IN ("nl", "en")) }
              OPTIONAL { ?work wdt:P577 ?date }
            }
            LIMIT 1000
            SPARQL;
    }

    /** @param list<string> $authors */
    public static function parseSeriesSearch(string $json, string $name, array $authors): ?string
    {
        $wanted = self::words($name);
        $surnames = self::surnames(implode(', ', $authors));
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
            $names = array_map('strval', array_keys($info['names']));
            // "De Kronieken van Narnia" also matches the series "Kronieken van Narnia".
            $nameMatches = in_array($wanted, $names, true) || in_array(preg_replace('/^(de|het|een|the|a) /', '', $wanted), array_map(
                static fn (string $n) => (string) preg_replace('/^(de|het|een|the|a) /', '', $n), $names), true);
            $authorMatches = $surnames === [] || array_intersect($surnames, array_map('strval', array_keys($info['surnames'] ?? []))) !== [];
            if ($nameMatches && $authorMatches) {
                return (string) $item;
            }
        }
        return null;
    }

    /** @return list<array{qid: string, number: ?float, title: string, title_en: ?string, author: string, year: ?int}>|null */
    public static function parseSeriesParts(string $json): ?array
    {
        $rows = self::rows($json);
        if ($rows === null) {
            return null;
        }
        $parts = [];
        foreach ($rows as $row) {
            $qid = self::qid($row['work']['value'] ?? '');
            if ($qid === null) {
                continue;
            }
            $part = $parts[$qid] ?? ['qid' => $qid, 'number' => null, 'title' => '', 'title_en' => null, 'authors' => [], 'year' => null];
            $ordinal = str_replace(',', '.', trim((string) ($row['ordinal']['value'] ?? '')));
            if ($part['number'] === null && is_numeric($ordinal) && (float) $ordinal >= 0 && (float) $ordinal < 10000) {
                $part['number'] = round((float) $ordinal, 1);
            }
            $nl = trim((string) ($row['titleNl']['value'] ?? ''));
            $en = trim((string) ($row['titleEn']['value'] ?? ''));
            if ($part['title'] === '' && ($nl !== '' || $en !== '')) {
                $part['title'] = mb_substr($nl !== '' ? $nl : $en, 0, 500);
            }
            if ($en !== '' && $en !== $part['title']) {
                $part['title_en'] = mb_substr($en, 0, 500);
            }
            $author = trim((string) ($row['authorName']['value'] ?? ''));
            if ($author !== '' && self::qid($author) === null) {
                $part['authors'][$author] = true;
            }
            if ($part['year'] === null && preg_match('/^([+-]?\d{1,4})-/', (string) ($row['date']['value'] ?? ''), $m)) {
                $part['year'] = (int) $m[1];
            }
            $parts[$qid] = $part;
        }
        $parts = array_values(array_filter($parts, static fn (array $p) => $p['title'] !== '' && self::qid($p['title']) === null));
        usort($parts, static fn (array $a, array $b) => [$a['number'] === null, $a['number'], $a['year'] ?? 9999, $a['title']]
            <=> [$b['number'] === null, $b['number'], $b['year'] ?? 9999, $b['title']]);
        return array_map(static function (array $p): array {
            $p['author'] = mb_substr(implode(', ', array_slice(array_map('strval', array_keys($p['authors'])), 0, 3)), 0, 500);
            unset($p['authors']);
            return $p;
        }, $parts);
    }

    /** Lowercase letters and digits only: for matching titles from different places. */
    public static function matchKey(string $title): string
    {
        return self::words($title);
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
