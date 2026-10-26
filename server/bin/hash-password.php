<?php

declare(strict_types=1);

// Only from the command line: never as a web page, should this folder be reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// Usage: php bin/hash-password.php  — prints a hash to use as APP_PASSWORD.

fwrite(STDERR, 'Wachtwoord: ');
$password = trim((string) fgets(STDIN));
if ($password === '') {
    fwrite(STDERR, "Leeg wachtwoord.\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
