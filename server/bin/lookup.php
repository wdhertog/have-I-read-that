<?php

declare(strict_types=1);

// See what each source knows about an ISBN, e.g.:
//   php bin/lookup.php 9789023417069
// Handy to check that the sources are reachable from your server. With --kb-xml it
// prints the KB's raw record instead.

use App\Catalog;
use App\Config;
use App\Http;
use App\StoryGraph;

require dirname(__DIR__) . '/autoload.php';

$isbn = StoryGraph::cleanIsbn($argv[1] ?? '');
if ($isbn === null) {
    fwrite(STDERR, "Gebruik: php bin/lookup.php <ISBN> [--kb-xml]\n");
    exit(1);
}
if (in_array('--kb-xml', $argv, true)) {
    echo Http::get('https://jsru.kb.nl/sru/sru?version=1.2&operation=searchRetrieve&maximumRecords=1&recordSchema=dcx&x-collection=GGC&query='
        . rawurlencode("identifier.ISBN exact \"{$isbn}\"")) ?? 'KB niet bereikbaar', PHP_EOL;
    exit;
}

/** Long texts cut short, so a line stays readable. */
$short = static fn (array $info) => json_encode(array_map(
    static fn ($v) => is_string($v) && mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : $v,
    array_filter(array_intersect_key($info, array_flip(Catalog::FIELDS)), static fn ($v) => $v !== null),
), JSON_UNESCAPED_UNICODE);

$config = Config::load(dirname(__DIR__) . '/.env');
$sources = ['kb', 'openlibrary', 'google'];
if ($config->get('HARDCOVER_TOKEN') !== '') {
    $sources[] = 'hardcover';
}
foreach ($sources as $source) {
    $catalog = new Catalog(new Config(['METADATA_SOURCES' => $source, 'COVER_SOURCES' => $source,
        'GOOGLE_BOOKS_API_KEY' => $config->get('GOOGLE_BOOKS_API_KEY'), 'HARDCOVER_TOKEN' => $config->get('HARDCOVER_TOKEN')]));
    $info = $catalog->lookup($isbn);
    echo str_pad($source, 12), $info ? $short($info) : 'niets gevonden', PHP_EOL;
    if (in_array($source, ['openlibrary', 'google'], true)) {
        $cover = $catalog->cover($isbn);
        echo str_pad('', 12), $cover ? 'cover: ' . strlen($cover['bytes']) . ' bytes' : 'geen cover', PHP_EOL;
    }
}
// Wikidata searches with the title and author the others found; see below.
echo str_pad('wikidata', 12), (new Catalog($config))->wikidata()->reachable() ? 'bereikbaar' : 'NIET bereikbaar', PHP_EOL;

$merged = (new Catalog($config))->lookup($isbn);
echo PHP_EOL, 'Samen (', $config->get('METADATA_SOURCES', Catalog::DEFAULT_METADATA_SOURCES), '):', PHP_EOL;
if ($merged === null) {
    echo '  niets gevonden', PHP_EOL;
    exit;
}
echo '  ', str_pad('bronnen', 18), implode(', ', $merged['sources']), PHP_EOL;
foreach (Catalog::FIELDS as $field) {
    if (($merged[$field] ?? null) !== null) {
        $value = is_string($merged[$field]) && mb_strlen($merged[$field]) > 100 ? mb_substr($merged[$field], 0, 100) . '…' : $merged[$field];
        echo '  ', str_pad($field, 18), is_string($value) ? $value : json_encode($value), PHP_EOL;
    }
}
