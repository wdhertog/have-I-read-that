<?php

declare(strict_types=1);

// Daily database back-up, e.g. from cron:
//   15 3 * * * php /pad/naar/bin/backup.php
// Writes var/backups/boeken-<datum>.sql.gz and keeps the newest BACKUP_KEEP (14).

use App\Backup;
use App\Config;
use App\Database;

require dirname(__DIR__) . '/autoload.php';

$config = Config::load(dirname(__DIR__) . '/.env');
$db = Database::connect($config);
$dir = $config->get('BACKUP_DIR', dirname(__DIR__) . '/var/backups');
echo (new Backup($db))->rotate($dir, $config->int('BACKUP_KEEP', 14)), PHP_EOL;
