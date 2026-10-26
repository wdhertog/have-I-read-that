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

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Have I read that? heeft PHP 8.1 of nieuwer nodig; deze server draait PHP ' . PHP_VERSION . '.');
}

// Never show PHP errors to visitors (they can contain paths or credentials); log them.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require dirname(__DIR__) . '/autoload.php';

$config = Config::load(dirname(__DIR__) . '/.env');

// The URL prefix the app lives under. When the whole server/ folder is uploaded into
// public_html, the root .htaccess rewrites into public/ but URLs don't contain "/public".
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_ends_with($basePath, '/public') && $uriPath !== $basePath && !str_starts_with($uriPath, $basePath . '/')) {
    $basePath = substr($basePath, 0, -strlen('/public'));
}

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('hirt_session');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => $basePath . '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
// Own session folder: on shared hosting the default one is cleaned by other apps or
// cron after ~24 minutes, which would ignore our 30-day lifetime.
$sessionDir = dirname(__DIR__) . '/var/sessions';
if ((is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
}
ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

try {
    $db = Database::connect($config);
    // Creates or updates the tables, so hosting without SSH needs no manual step.
    Database::migrateIfNeeded($db, dirname(__DIR__) . '/migrations');
} catch (PDOException $e) {
    error_log((string) $e);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kan de database niet gebruiken. Controleer DB_HOST, DB_NAME, DB_USER en DB_PASSWORD in .env.\n";
    if ($config->bool('APP_DEBUG')) {
        echo "\n" . $e->getMessage() . "\n";
    }
    exit;
}

$trustedProxies = array_values(array_filter(array_map('trim', explode(',', $config->get('TRUSTED_PROXIES')))));
$request = Request::fromGlobals($basePath, $trustedProxies);
if (!str_starts_with($request->path, '/api/')) {
    session_start();
}

$app = new App($config, $db, $basePath);
$app->handle($request)->send();
