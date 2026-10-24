<?php

declare(strict_types=1);

// Usage: php bin/migrate.php  — creates/updates the database tables.

use App\Config;
use App\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::load(dirname(__DIR__) . '/.env');
$applied = Database::migrate(Database::connect($config), dirname(__DIR__) . '/migrations');
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : 'Database is up to date.' . PHP_EOL;
