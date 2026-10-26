<?php

declare(strict_types=1);

namespace App;

/**
 * Languages are stored as two-letter codes ("nl"); the sources give anything from
 * "dut" (MARC) to "nederlands" (KB) or "nl" (Google Books).
 */
final class Language
{
    /** Code => Dutch name. */
    public const NAMES = [
        'nl' => 'Nederlands', 'en' => 'Engels', 'de' => 'Duits', 'fr' => 'Frans', 'es' => 'Spaans',
        'it' => 'Italiaans', 'pt' => 'Portugees', 'sv' => 'Zweeds', 'da' => 'Deens', 'no' => 'Noors',
        'fi' => 'Fins', 'pl' => 'Pools', 'ru' => 'Russisch', 'la' => 'Latijn', 'el' => 'Grieks',
        'fy' => 'Fries', 'af' => 'Afrikaans', 'ja' => 'Japans', 'zh' => 'Chinees', 'ar' => 'Arabisch',
        'tr' => 'Turks', 'he' => 'Hebreeuws',
    ];

    /** Other spellings (lowercase) => code. */
    private const ALIASES = [
        'dut' => 'nl', 'nld' => 'nl', 'dutch' => 'nl', 'nederlands' => 'nl', 'vlaams' => 'nl',
        'eng' => 'en', 'english' => 'en', 'engels' => 'en',
        'ger' => 'de', 'deu' => 'de', 'german' => 'de', 'duits' => 'de', 'deutsch' => 'de',
        'fre' => 'fr', 'fra' => 'fr', 'french' => 'fr', 'frans' => 'fr', 'français' => 'fr',
        'spa' => 'es', 'spanish' => 'es', 'spaans' => 'es',
        'ita' => 'it', 'italian' => 'it', 'italiaans' => 'it',
        'por' => 'pt', 'portuguese' => 'pt', 'portugees' => 'pt',
        'swe' => 'sv', 'swedish' => 'sv', 'zweeds' => 'sv',
        'dan' => 'da', 'danish' => 'da', 'deens' => 'da',
        'nor' => 'no', 'nob' => 'no', 'nb' => 'no', 'norwegian' => 'no', 'noors' => 'no',
        'fin' => 'fi', 'finnish' => 'fi', 'fins' => 'fi',
        'pol' => 'pl', 'polish' => 'pl', 'pools' => 'pl',
        'rus' => 'ru', 'russian' => 'ru', 'russisch' => 'ru',
        'lat' => 'la', 'latin' => 'la', 'latijn' => 'la',
        'gre' => 'el', 'ell' => 'el', 'greek' => 'el', 'grieks' => 'el',
        'fry' => 'fy', 'frisian' => 'fy', 'fries' => 'fy',
        'afr' => 'af', 'afrikaans' => 'af',
        'jpn' => 'ja', 'japanese' => 'ja', 'japans' => 'ja',
        'chi' => 'zh', 'zho' => 'zh', 'chinese' => 'zh', 'chinees' => 'zh',
        'ara' => 'ar', 'arabic' => 'ar', 'arabisch' => 'ar',
        'tur' => 'tr', 'turkish' => 'tr', 'turks' => 'tr',
        'heb' => 'he', 'hebrew' => 'he', 'hebreeuws' => 'he',
    ];

    /** A two-letter code, or the trimmed input when it isn't a language we know. */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $key = mb_strtolower($value);
        $key = preg_replace('/[-_].*$/', '', $key); // "nl-NL", "en_US"
        if (isset(self::NAMES[$key])) {
            return $key;
        }
        return self::ALIASES[$key] ?? $value;
    }

    /** "nl" → "Nederlands"; unknown values are shown as they are. */
    public static function label(?string $value): string
    {
        $code = self::normalize($value);
        return self::NAMES[$code] ?? (string) $code;
    }
}
