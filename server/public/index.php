<?php

declare(strict_types=1);

use App\App;
use App\Config;
use App\Database;
use App\Request;

// Let PHP's built-in dev server serve static files itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::load(dirname(__DIR__) . '/.env');
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('hirt_session');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => $basePath . '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));

$request = Request::fromGlobals($basePath);
if (!str_starts_with($request->path, '/api/')) {
    session_start();
}

$app = new App($config, Database::connect($config), $basePath);
$app->handle($request)->send();
