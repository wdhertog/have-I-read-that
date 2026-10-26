<?php

// A tiny stand-in for a Mastodon server, run with `php -S` by MastodonServerTest.
// Every request is appended as JSON to the file named in FAKE_MASTODON_LOG.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('FAKE_MASTODON_LOG');
$entry = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'raw' => file_get_contents('php://input'),
    'post' => $_POST,
    'files' => array_map(static fn (array $f) => ['name' => $f['name'], 'type' => $f['type'], 'size' => $f['size']], $_FILES),
];
file_put_contents($log, json_encode($entry) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-token') {
    http_response_code(401);
    exit('{"error":"The access token is invalid"}');
}
if ($path === '/api/v2/media') {
    if (($_POST['description'] ?? '') === 'fail') {
        http_response_code(422);
        exit('{"error":"Validation failed"}');
    }
    // Pretend processing takes a moment: url arrives on the follow-up GET.
    http_response_code(202);
    exit('{"id":"10987654321","url":null}');
}
if (preg_match('#^/api/v1/media/(\d+)$#', $path, $m)) {
    exit(json_encode(['id' => $m[1], 'url' => 'https://files.example/' . $m[1] . '.jpg']));
}
if ($path === '/api/v1/statuses') {
    exit('{"id":"1","url":"https://masto.example/@me/1"}');
}
http_response_code(404);
echo '{"error":"Record not found"}';
