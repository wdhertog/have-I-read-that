<?php

declare(strict_types=1);

// See what each source knows about an ISBN, e.g.:
//   php bin/lookup.php 9789023417069
// Handy to check that the KB, Google Books and Wikidata are reachable from your server.

use App\Catalog;
use App\Config;
use App\StoryGraph;

require dirname(__DIR__) . '/autoload.php';

$isbn = StoryGraph::cleanIsbn($argv[1] ?? '');
if ($isbn === null) {
    fwrite(STDERR, "Gebruik: php bin/lookup.php <ISBN>\n");
    exit(1);
}
$config = Config::load(dirname(__DIR__) . '/.env');
foreach (['kb', 'openlibrary', 'google', 'wikidata'] as $source) {
    $catalog = new Catalog(new Config(['METADATA_SOURCES' => $source, 'COVER_SOURCES' => $source,
        'GOOGLE_BOOKS_API_KEY' => $config->get('GOOGLE_BOOKS_API_KEY')]));
    $info = $catalog->lookup($isbn);
    echo str_pad($source, 12), $info ? json_encode(array_intersect_key($info, array_flip(Catalog::FIELDS)), JSON_UNESCAPED_UNICODE) : 'niets gevonden', PHP_EOL;
    if (in_array($source, ['openlibrary', 'google'], true)) {
        $cover = $catalog->cover($isbn);
        echo str_pad('', 12), $cover ? 'cover: ' . strlen($cover['bytes']) . ' bytes' : 'geen cover', PHP_EOL;
    }
}
$merged = (new Catalog($config))->lookup($isbn);
echo PHP_EOL, 'Samen (', $config->get('METADATA_SOURCES', Catalog::DEFAULT_METADATA_SOURCES), '): ',
    $merged ? json_encode(array_intersect_key($merged, array_flip([...Catalog::FIELDS, 'sources'])), JSON_UNESCAPED_UNICODE) : 'niets', PHP_EOL;
