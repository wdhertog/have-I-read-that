<?php

declare(strict_types=1);

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
