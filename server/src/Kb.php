<?php

declare(strict_types=1);

namespace App;

/**
 * The KB (Koninklijke Bibliotheek, national library of the Netherlands): its catalogue
 * via the public SRU service, looked up by ISBN. Has title, author, publisher, year,
 * language and number of pages; no covers.
 */
final class Kb
{
    private const URL = 'https://jsru.kb.nl/sru/sru?version=1.2&operation=searchRetrieve&maximumRecords=1'
        . '&recordSchema=dcx&x-collection=GGC&query=';

    /** @param \Closure(string): ?string $get */
    public function __construct(private \Closure $get)
    {
    }

    /** @return array<string, mixed>|null */
    public function byIsbn(string $isbn): ?array
    {
        $candidates = [$isbn];
        if (strlen($isbn) === 13 && str_starts_with($isbn, '978')) {
            $candidates[] = self::toIsbn10($isbn); // older records only carry the 10-digit form
        }
        foreach ($candidates as $code) {
            $xml = ($this->get)(self::URL . rawurlencode("identifier.ISBN exact \"{$code}\""));
            $record = $xml !== null ? self::parse($xml) : null;
            if ($record !== null) {
                return ['isbn' => $isbn] + $record;
            }
        }
        return null;
    }

    /**
     * Read the first record of an SRU response. Elements are found by local name, so
     * it works for the "dc" and "dcx" schemas alike.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $xml): ?array
    {
        $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($doc === false) {
            return null;
        }
        $record = $doc->xpath('//*[local-name()="recordData"]')[0] ?? null;
        if ($record === null) {
            return null;
        }
        $values = static function (string $name) use ($record): array {
            return array_values(array_filter(array_map(
                static fn ($n) => trim((string) $n),
                $record->xpath('.//*[local-name()="' . $name . '"]') ?: [],
            )));
        };
        $title = $values('title')[0] ?? '';
        if ($title === '') {
            return null;
        }
        // "De avonden : een winterverhaal / Gerard Reve" → "De avonden: een winterverhaal"
        $title = trim(preg_replace('/\s+:\s+/', ': ', explode(' / ', $title)[0]));
        // Translators and illustrators are listed with a role; they aren't authors.
        $authors = [];
        $translators = [];
        foreach ([...$values('creator'), ...$values('contributor')] as $creator) {
            if (preg_match('/\([^)]*\b(vert\.|vertal\w*|translat\w*)[^)]*\)/iu', $creator)) {
                $translators[] = self::name($creator);
            } elseif (!preg_match('/\((illustr|redact|samenst|inleid|nawoord|voorwoord|ontwerp|fotogr|bewerk|editor)\w*\)/iu', $creator)
                && in_array($creator, $values('creator'), true)) {
                $authors[] = self::name($creator);
            }
        }
        $authors = array_slice($authors, 0, 3);
        // Notes like "Vert. uit het Engels. - Vert. van: The road. - New York : Knopf, 2006"
        $originalTitle = null;
        $originalLanguage = null;
        foreach ($values('description') as $note) {
            if ($originalTitle === null && preg_match('/(?:Vert\.|Vertaling) van:?\s*(.+?)(?:\s+-\s|\s*\/|$)/u', $note, $m)) {
                $originalTitle = trim(preg_replace('/\s+:\s+/', ': ', $m[1]), ' .');
            }
            if ($originalTitle === null && preg_match('/Oorspr(?:\.|onkelijke) titel:?\s*(.+?)(?:\s+-\s|\s*\/|$)/u', $note, $m)) {
                $originalTitle = trim(preg_replace('/\s+:\s+/', ': ', $m[1]), ' .');
            }
            if ($originalLanguage === null && preg_match('/(?:Vert\.|Vertaald) uit het (\w+)/u', $note, $m)) {
                $code = Language::normalize($m[1]);
                $originalLanguage = isset(Language::NAMES[$code]) ? $code : null;
            }
        }
        $year = null;
        foreach ($values('date') as $date) {
            if (preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', $date, $m)) {
                $year = (int) $m[1];
                break;
            }
        }
        $pages = null;
        foreach ($values('extent') as $extent) {
            if (preg_match('/(\d+)\s*(p\b|pag|blz|bladz|pages)/i', $extent, $m)) {
                $pages = (int) $m[1];
                break;
            }
        }
        // The KB gives both "nederlands" and an ISO code; prefer one we recognise.
        $languages = array_map([Language::class, 'normalize'], $values('language'));
        $known = array_values(array_filter($languages, static fn ($l) => isset(Language::NAMES[$l])));
        $language = $known[0] ?? $languages[0] ?? null;
        return [
            'title' => $title,
            'author' => implode(', ', array_unique(array_filter($authors))),
            'publisher' => isset($values('publisher')[0]) ? trim(preg_replace('/^.*?:\s*/', '', $values('publisher')[0])) : null,
            'year' => $year,
            'pages' => $pages,
            'language' => $language,
            'translator' => $translators ? mb_substr(implode(', ', array_unique($translators)), 0, 255) : null,
            'original_title' => $originalTitle !== '' ? $originalTitle : null,
            'original_language' => $originalLanguage,
        ];
    }

    /** "Reve, Gerard, 1923-2006 (auteur)" → "Gerard Reve" */
    private static function name(string $creator): string
    {
        $creator = trim(preg_replace(['/\(.*?\)/', '/,?\s*\d{4}-(\d{4})?/', '/\s*\[.*?\]/'], '', $creator), " ,.");
        $parts = array_map('trim', explode(',', $creator));
        return count($parts) >= 2 ? trim($parts[1] . ' ' . $parts[0]) : $creator;
    }

    public static function toIsbn10(string $isbn13): string
    {
        $core = substr($isbn13, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (10 - $i) * (int) $core[$i];
        }
        $check = (11 - $sum % 11) % 11;
        return $core . ($check === 10 ? 'X' : (string) $check);
    }
}
