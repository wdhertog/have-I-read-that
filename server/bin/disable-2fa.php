<?php

declare(strict_types=1);

// Only from the command line: never as a web page, should this folder be reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// Emergency switch when you've lost both your phone and your recovery codes:
//   php bin/disable-2fa.php
// Only someone with access to the server can run this.

use App\Auth;
use App\Config;
use App\Database;

require dirname(__DIR__) . '/autoload.php';

$config = Config::load(dirname(__DIR__) . '/.env');
(new Auth($config, Database::connect($config)))->disableTwoFactor();
echo "Tweestapsverificatie staat uit. Zet hem na het inloggen weer aan onder Instellingen.\n";
