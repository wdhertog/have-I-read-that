<?php

declare(strict_types=1);

// Post the year-in-review toot, e.g. from cron on 31 December:
//   0 20 31 12 * php /pad/naar/bin/year-review.php
// Options: a year (default: this year), --dry-run (print instead of posting),
// --no-collage (text only).

use App\Config;
use App\Database;
use App\Mastodon;
use App\MastodonException;
use App\Books;
use App\YearReview;

require dirname(__DIR__) . '/autoload.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$collage = !in_array('--no-collage', $args, true);
$years = array_values(array_filter($args, static fn (string $a) => ctype_digit($a)));
$year = (int) ($years[0] ?? date('Y'));

$config = Config::load(dirname(__DIR__) . '/.env');
$db = Database::connect($config);
Database::migrateIfNeeded($db, dirname(__DIR__) . '/migrations');
$review = new YearReview(new Books($db), Mastodon::fromConfig($config));

echo $review->compose($year), PHP_EOL;
if ($dryRun) {
    $image = $collage ? $review->collage($year) : null;
    echo $image ? PHP_EOL . 'Collage: ' . strlen($image['bytes']) . ' bytes. Alt: ' . $image['alt'] . PHP_EOL : '';
    exit(0);
}
if (!$review->books($year)) {
    fwrite(STDERR, "Geen uitgelezen boeken in {$year}; niets gepost.\n");
    exit(0);
}
try {
    echo PHP_EOL, 'Gepost: ', $review->post($year, null, $collage) ?? '(geen link)', PHP_EOL;
} catch (MastodonException $e) {
    fwrite(STDERR, 'Posten mislukt: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
