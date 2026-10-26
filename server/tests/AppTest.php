<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Config;
use App\Database;
use App\Request;
use App\Response;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs requests through the whole app against a real MySQL test database.
 */
final class AppTest extends TestCase
{
    private App $app;
    private PDO $db;

    protected function setUp(): void
    {
        $config = new Config([
            'APP_PASSWORD' => 'geheim',
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
        ]);
        try {
            $this->db = Database::connect($config);
        } catch (\PDOException $e) {
            self::markTestSkipped('No test database: ' . $e->getMessage());
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['posts', '`reads`', 'books', 'api_tokens', 'login_attempts', 'schema_migrations'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        Database::migrate($this->db, dirname(__DIR__) . '/migrations');
        $_SESSION = [];
        $this->app = new App($config, $this->db);
    }

    /** @param array<string, mixed> $post */
    private function web(string $method, string $path, array $post = [], array $files = []): Response
    {
        if ($method === 'POST') {
            $post['_csrf'] = $this->app->auth->csrfToken();
        }
        return $this->app->handle(new Request($method, $path, [], $post, $files));
    }

    private function login(): void
    {
        self::assertSame(302, $this->web('POST', '/login', ['password' => 'geheim'])->status);
    }

    /** @return array{int, array<string, mixed>} */
    private function api(string $method, string $path, ?array $json = null, ?string $token = null): array
    {
        $token ??= $this->app->auth->createToken('test');
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = new Request($method, (string) parse_url($path, PHP_URL_PATH), $query, [], [], ['authorization' => "Bearer {$token}"],
            $json === null ? '' : json_encode($json));
        $response = $this->app->handle($request);
        return [$response->status, json_decode($response->body, true)];
    }

    public function testLoginRequired(): void
    {
        self::assertStringContainsString('/login', $this->web('GET', '/books')->headers['Location']);
        $this->web('POST', '/login', ['password' => 'fout']);
        self::assertFalse($this->app->auth->loggedIn());
    }

    public function testLoginIsRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->app->handle(new Request('POST', '/login', [], ['password' => 'fout', '_csrf' => $this->app->auth->csrfToken()], ip: '203.0.113.9'));
        }
        // Even the right password is refused while locked out, but only for that address.
        $this->app->handle(new Request('POST', '/login', [], ['password' => 'geheim', '_csrf' => $this->app->auth->csrfToken()], ip: '203.0.113.9'));
        self::assertFalse($this->app->auth->loggedIn());
        $this->app->handle(new Request('POST', '/login', [], ['password' => 'geheim', '_csrf' => $this->app->auth->csrfToken()], ip: '198.51.100.7'));
        self::assertTrue($this->app->auth->loggedIn());
    }

    public function testLoginRedirectStaysOnSite(): void
    {
        foreach (['/\\evil.example', '//evil.example', 'https://evil.example', '/books?x=//evil'] as $next) {
            $response = $this->app->handle(new Request('POST', '/login', ['next' => $next],
                ['password' => 'geheim', '_csrf' => $this->app->auth->csrfToken()]));
            self::assertSame('/books', $response->headers['Location'], $next);
        }
        $response = $this->app->handle(new Request('POST', '/login', ['next' => '/books/12'],
            ['password' => 'geheim', '_csrf' => $this->app->auth->csrfToken()]));
        self::assertSame('/books/12', $response->headers['Location']);
    }

    public function testSecurityHeaders(): void
    {
        $public = $this->app->handle(new Request('GET', '/'));
        self::assertSame('DENY', $public->headers['X-Frame-Options']);
        self::assertStringContainsString("frame-ancestors 'none'", $public->headers['Content-Security-Policy']);
        self::assertArrayNotHasKey('Cache-Control', $public->headers);

        $this->login();
        self::assertSame('no-store', $this->web('GET', '/settings')->headers['Cache-Control']);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $this->login();
        $response = $this->app->handle(new Request('POST', '/books/add', [], ['title' => 'X']));
        self::assertSame(400, $response->status);
    }

    public function testReadingFlow(): void
    {
        $this->login();
        $response = $this->web('POST', '/books/add', ['title' => 'De avonden', 'author' => 'Gerard Reve', 'status' => 'to-read']);
        $location = $response->headers['Location'];
        self::assertMatchesRegularExpression('#^/books/\d+$#', $location);

        $this->web('POST', "{$location}/start", ['started' => '2024-01-01']);
        $this->web('POST', "{$location}/finish", ['finished' => '2024-01-10', 'rating' => '4,5', 'review' => 'Prachtig.', 'toot' => 'on']);

        $page = $this->web('GET', $location)->body;
        self::assertStringContainsString('2024-01-01', $page);
        self::assertStringContainsString('2024-01-10', $page);
        self::assertStringContainsString('Niet gepost: Mastodon is nog niet ingesteld.', $page);
        self::assertStringContainsString('★★★★½', $page);

        $public = $this->app->handle(new Request('GET', '/', ['year' => '2024']))->body;
        self::assertStringContainsString('De avonden', $public);
        self::assertStringContainsString('Prachtig.', $public);
    }

    public function testPublicPageHidesToReadList(): void
    {
        $this->login();
        $this->web('POST', '/books/add', ['title' => 'Geheim verlanglijstje', 'status' => 'to-read']);
        $this->web('POST', '/logout');
        $public = $this->app->handle(new Request('GET', '/'))->body;
        self::assertStringNotContainsString('Geheim verlanglijstje', $public);
    }

    public function testStoryGraphImportIsIdempotent(): void
    {
        $this->login();
        $file = tempnam(sys_get_temp_dir(), 'sg');
        file_put_contents($file, Fixtures::STORYGRAPH_CSV);
        $upload = ['file' => ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'name' => 'sg.csv']];

        self::assertStringContainsString('4 boeken geïmporteerd', $this->web('POST', '/import', [], $upload)->body);
        self::assertStringContainsString('0 boeken geïmporteerd, 4 overgeslagen', $this->web('POST', '/import', [], $upload)->body);
        self::assertSame(4, (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM posts')->fetchColumn());

        $years = array_column($this->app->books->years(), 'books', 'year');
        self::assertSame(['2024' => 1, '2023' => 1], $years);
        unlink($file);
    }

    public function testApiRequiresToken(): void
    {
        [$status] = $this->api('GET', '/api/me', null, 'hirt_wrong');
        self::assertSame(401, $status);
    }

    public function testApiFlow(): void
    {
        $token = $this->app->auth->createToken('Pixel');

        [$status, $body] = $this->api('POST', '/api/books', ['title' => 'Tirza', 'author' => 'Arnon Grunberg',
            'isbn' => '978-90-388-0196-9', 'status' => 'reading', 'toot' => true], $token);
        self::assertSame(201, $status);
        self::assertSame('9789038801969', $body['book']['isbn']);
        self::assertFalse($body['toot']['posted']);
        $id = $body['book']['id'];

        [$status, $body] = $this->api('GET', '/api/books?status=reading', null, $token);
        self::assertSame(200, $status);
        self::assertSame(['Tirza'], array_column($body['books'], 'title'));

        [, $body] = $this->api('POST', "/api/books/{$id}/finish", ['date' => '2024-02-02', 'rating' => 4, 'review' => 'Wow.'], $token);
        self::assertSame('read', $body['book']['status']);
        self::assertSame(4.0, (float) $body['book']['rating']);
        self::assertSame('2024-02-02', $body['book']['last_finished']);
        self::assertStringContainsString('★★★★☆', $body['book']['toot_preview']['finished']);

        [$status, $body] = $this->api('POST', "/api/books/{$id}/finish", ['rating' => 9], $token);
        self::assertSame(422, $status);

        [$status] = $this->api('GET', '/api/books/999999', null, $token);
        self::assertSame(404, $status);

        // Odd or oversized input is a 422, never a 500.
        foreach ([['title' => ['nested']], ['title' => str_repeat('x', 501)], ['title' => 'X', 'pages' => '99999999999'],
                  ['title' => 'X', 'review' => str_repeat('x', 10001)]] as $input) {
            [$status] = $this->api('POST', '/api/books', $input, $token);
            self::assertSame(422, $status, json_encode($input));
        }
        [$status, $body] = $this->api('POST', '/api/books', ['title' => 'X', 'format' => 'kleitablet', 'rating' => ['a']], $token);
        self::assertSame(201, $status);
        self::assertNull($body['book']['format']);
        [$status] = $this->api('POST', "/api/books/{$id}/finish", ['review' => ['a' => 1], 'date' => ['x']], $token);
        self::assertSame(200, $status);
    }
}
