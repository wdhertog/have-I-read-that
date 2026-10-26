<?php

declare(strict_types=1);

namespace App;

/**
 * Wikidata, for the one thing the library catalogues don't know: which series a book
 * belongs to ("Harry Potter", part 3), not the publisher's series. Looked up by ISBN
 * on the edition, or on the work it is an edition or translation of.
 */
final class Wikidata
{
    private const URL = 'https://query.wikidata.org/sparql?format=json&query=';

    /** @param \Closure(string): ?string $get */
    public function __construct(private \Closure $get)
    {
    }

    /** @return array{series: string, series_number: ?float}|null */
    public function byIsbn(string $isbn): ?array
    {
        $json = ($this->get)(self::URL . rawurlencode(self::query($isbn)));
        return $json !== null ? self::parse($json) : null;
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
}
