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

    /** @return array<string, string> */
    private function configValues(): array
    {
        return [
            'APP_PASSWORD' => 'geheim',
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'COVERS_DIR' => sys_get_temp_dir() . '/hirt-test-covers',
        ];
    }

    protected function setUp(): void
    {
        $config = new Config($this->configValues());
        try {
            $this->db = Database::connect($config);
        } catch (\PDOException $e) {
            self::markTestSkipped('No test database: ' . $e->getMessage());
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['posts', '`reads`', 'books', 'api_tokens', 'login_attempts', 'goals', 'settings', 'schema_migrations'] as $table) {
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

    /** Upload a CSV and confirm the import; returns the result page. */
    private function importFile(string $path): Response
    {
        $preview = $this->web('POST', '/import', [], ['file' => ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK]]);
        self::assertStringContainsString('Bevestigen en importeren', $preview->body);
        return $this->web('POST', '/import/confirm');
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

    public function testMigratingTablesThatWereCreatedByHand(): void
    {
        // As if 001_init.sql had been run in phpMyAdmin: tables and data, but no bookkeeping.
        $this->db->exec("INSERT INTO books (title, status, format, date_added) VALUES ('Bestaand', 'read', 'Digital', CURDATE())");
        $this->db->exec('DROP TABLE schema_migrations');
        $this->db->exec('DROP TABLE login_attempts');

        Database::migrateIfNeeded($this->db, dirname(__DIR__) . '/migrations');

        self::assertSame(['Bestaand', 'ebook'], $this->db->query('SELECT title, format FROM books')->fetch(PDO::FETCH_NUM));
        self::assertSame(count(glob(dirname(__DIR__) . '/migrations/*.sql')), (int) $this->db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
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

    public function testIpv6AttemptsCountPerSlash64(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->app->handle(new Request('POST', '/login', [], ['password' => 'fout', '_csrf' => $this->app->auth->csrfToken()], ip: "2001:db8:1:2::{$i}"));
        }
        $this->app->handle(new Request('POST', '/login', [], ['password' => 'geheim', '_csrf' => $this->app->auth->csrfToken()], ip: '2001:db8:1:2::ff'));
        self::assertFalse($this->app->auth->loggedIn());
    }

    public function testChangingThePasswordEndsSessions(): void
    {
        $this->login();
        self::assertTrue($this->app->auth->loggedIn());
        $other = new App(new Config(['APP_PASSWORD' => 'nieuw'] + $this->configValues()), $this->db);
        self::assertFalse($other->auth->loggedIn());
    }

    public function testBcryptHashFromOtherTools(): void
    {
        $hash = '$2b$' . substr(password_hash('geheim', PASSWORD_BCRYPT), 4);
        $app = new App(new Config(['APP_PASSWORD' => $hash] + $this->configValues()), $this->db);
        self::assertTrue($app->auth->checkPassword('geheim'));
        self::assertFalse($app->auth->checkPassword($hash));
    }

    public function testOddInputNeverGivesA500(): void
    {
        $response = $this->app->handle(new Request('POST', '/login', [], ['_csrf' => ['x'], 'password' => 'geheim']));
        self::assertSame(400, $response->status);
        $this->login();
        $response = $this->web('POST', '/books/add', ['title' => "kapot \xFF", 'status' => 'read']);
        self::assertSame(422, $response->status);
        self::assertSame(200, $this->app->handle(new Request('HEAD', '/'))->status);
    }

    public function testImportClipsLongFieldsAndNormalizesFormats(): void
    {
        $this->login();
        $csv = "Title,Authors,ISBN/UID,Format,Read Status\n" . str_repeat('t', 600) . ",A,,Digital,read\nB,B,,Kleitablet,read\n";
        $file = tempnam(sys_get_temp_dir(), 'sg');
        file_put_contents($file, $csv);
        $this->importFile($file);
        unlink($file);
        $rows = $this->db->query('SELECT CHAR_LENGTH(title) AS len, format FROM books ORDER BY id')->fetchAll();
        self::assertSame([['len' => 500, 'format' => 'ebook'], ['len' => 1, 'format' => null]], $rows);
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

        self::assertStringContainsString('<strong>4</strong> nieuwe boeken', $this->web('POST', '/import', [], $upload)->body);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn(), 'the preview saves nothing');
        self::assertStringContainsString('4 boeken toegevoegd', $this->web('POST', '/import/confirm')->body);
        self::assertStringContainsString('0 boeken toegevoegd, 0 aangevuld, 4 ongewijzigd', $this->importFile($file)->body);
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

    // --- stap 2: snelle winst ------------------------------------------------

    public function testReadingProgress(): void
    {
        $token = $this->app->auth->createToken('t');
        [, $body] = $this->api('POST', '/api/books', ['title' => 'Tirza', 'author' => 'Arnon Grunberg', 'pages' => 400, 'status' => 'reading'], $token);
        $id = $body['book']['id'];

        [$status, $body] = $this->api('POST', "/api/books/{$id}/progress", ['page' => 100], $token);
        self::assertSame(200, $status);
        self::assertSame([100, 25], [$body['book']['progress_page'], $body['book']['progress_percent']]);
        self::assertStringContainsString('25% gelezen van Tirza', $body['book']['toot_preview']['progress']);

        [, $body] = $this->api('POST', "/api/books/{$id}/progress", ['percent' => '50'], $token);
        self::assertSame([200, 50], [$body['book']['progress_page'], $body['book']['progress_percent']]);
        [$status] = $this->api('POST', "/api/books/{$id}/progress", ['page' => 401], $token);
        self::assertSame(422, $status);
        [$status] = $this->api('POST', "/api/books/{$id}/progress", ['page' => 'veel'], $token);
        self::assertSame(422, $status);

        [, $body] = $this->api('GET', '/api/books?status=reading', null, $token);
        self::assertSame(50, $body['books'][0]['progress_percent']);

        // Finishing closes the read; a new read starts without progress.
        $this->api('POST', "/api/books/{$id}/finish", [], $token);
        [, $body] = $this->api('POST', "/api/books/{$id}/start", [], $token);
        self::assertNull($body['book']['progress_percent']);

        $this->login();
        $this->web('POST', "/books/{$id}/progress", ['page' => '300']);
        self::assertStringContainsString('p. 300 van 400 · 75%', $this->web('GET', "/books/{$id}")->body);
    }

    public function testReadingGoal(): void
    {
        $this->login();
        $year = (int) date('Y');
        $this->web('POST', '/stats/goal', ['year' => (string) $year, 'books' => '2']);
        $this->web('POST', '/books/add', ['title' => 'Een', 'status' => 'read']);
        self::assertStringContainsString('1 van 2 boeken', $this->web('GET', '/stats')->body);
        self::assertStringContainsString('1 van 2 boeken', $this->app->handle(new Request('GET', '/'))->body);

        [, $me] = $this->api('GET', '/api/me');
        self::assertSame(['year' => $year, 'goal' => 2, 'read' => 1], $me['goal']);

        $this->web('POST', '/stats/goal', ['year' => (string) $year, 'books' => '']);
        self::assertNull($this->app->books->goal($year));
        self::assertSame(302, $this->web('POST', '/stats/goal', ['year' => 'x', 'books' => '3'])->status);
    }

    public function testExportRoundTrip(): void
    {
        $this->login();
        $file = tempnam(sys_get_temp_dir(), 'sg');
        file_put_contents($file, Fixtures::STORYGRAPH_CSV);
        $this->importFile($file);
        $this->web('POST', '/books/add', ['title' => '=HYPERLINK("x")', 'author' => '+31 auteur', 'status' => 'to-read']);

        $csv = $this->web('GET', '/export.csv');
        self::assertStringContainsString('attachment;', $csv->headers['Content-Disposition']);
        self::assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $csv->body);
        $json = json_decode($this->web('GET', '/export.json')->body, true);
        self::assertCount(5, $json['books']);

        // Importing the export into an empty library gives the same books and reads back.
        $before = $this->db->query('SELECT title, author, status, rating, format FROM books ORDER BY title')->fetchAll();
        $reads = $this->db->query('SELECT b.title, r.started, r.finished FROM `reads` r JOIN books b ON b.id = r.book_id ORDER BY 1, 2, 3')->fetchAll();
        $this->db->exec('DELETE FROM books');
        file_put_contents($file, $csv->body);
        $this->importFile($file);
        unlink($file);
        self::assertSame($before, $this->db->query('SELECT title, author, status, rating, format FROM books ORDER BY title')->fetchAll());
        self::assertSame($reads, $this->db->query('SELECT b.title, r.started, r.finished FROM `reads` r JOIN books b ON b.id = r.book_id ORDER BY 1, 2, 3')->fetchAll());
    }

    public function testDuplicatesAreCaught(): void
    {
        $token = $this->app->auth->createToken('t');
        $this->api('POST', '/api/books', ['title' => 'De Avonden', 'author' => 'Gerard Reve'], $token);

        [$status, $body] = $this->api('POST', '/api/books', ['title' => 'de  avonden ', 'author' => 'gerard reve'], $token);
        self::assertSame(409, $status);
        self::assertSame('De Avonden', $body['existing']['title']);
        [$status] = $this->api('POST', '/api/books', ['title' => 'De avonden', 'author' => 'Gerard Reve', 'allow_duplicate' => true], $token);
        self::assertSame(201, $status);

        $this->login();
        $response = $this->web('POST', '/books/add', ['title' => 'De avonden', 'author' => 'Gerard Reve']);
        self::assertSame(409, $response->status);
        self::assertStringContainsString('Toch toevoegen', $response->body);
        self::assertSame(302, $this->web('POST', '/books/add', ['title' => 'De avonden', 'author' => 'Gerard Reve', 'allow_duplicate' => 'on'])->status);
    }

    public function testEditingFromTheApp(): void
    {
        $token = $this->app->auth->createToken('t');
        [, $body] = $this->api('POST', '/api/books', ['title' => 'Tikfout', 'status' => 'read'], $token);
        $id = $body['book']['id'];
        $readId = $body['book']['reads'][0]['id'];

        [$status, $body] = $this->api('POST', "/api/books/{$id}", ['title' => 'Goed', 'author' => 'A', 'status' => 'read', 'format' => 'ebook', 'rating' => 4], $token);
        self::assertSame(200, $status);
        self::assertSame(['Goed', 'ebook', 4.0], [$body['book']['title'], $body['book']['format'], (float) $body['book']['rating']]);

        [, $body] = $this->api('POST', "/api/reads/{$readId}", ['started' => '2024-01-01', 'finished' => '2024-02-01'], $token);
        self::assertSame('2024-02-01', $body['book']['last_finished']);
        [, $body] = $this->api('POST', "/api/books/{$id}/reads", ['finished' => '2025-03-03'], $token);
        self::assertCount(2, $body['book']['reads']);
        [$status] = $this->api('POST', "/api/books/{$id}/reads", ['started' => 'gisteren'], $token);
        self::assertSame(422, $status);
        [, $body] = $this->api('POST', "/api/reads/{$readId}/delete", null, $token);
        self::assertCount(1, $body['book']['reads']);

        [$status] = $this->api('POST', "/api/books/{$id}/delete", null, $token);
        self::assertSame(200, $status);
        [$status] = $this->api('GET', "/api/books/{$id}", null, $token);
        self::assertSame(404, $status);
    }

    public function testReimportFillsInWhatsMissing(): void
    {
        $this->login();
        // Situation: imported with an older version that didn't keep everything, then edited.
        $this->db->exec("INSERT INTO books (title, author, isbn, status, rating, review, source, date_added)
                         VALUES ('De avonden', 'Gerard Reve', NULL, 'read', 3.0, 'Mijn eigen recensie', 'storygraph', '2023-01-02')");
        $id = (int) $this->db->lastInsertId();
        $this->db->exec("INSERT INTO `reads` (book_id, started, finished) VALUES ({$id}, '2023-01-05', '2023-01-20')");

        $csv = "Title,Authors,Contributors,ISBN/UID,Format,Read Status,Date Added,Last Date Read,Dates Read,Read Count,Moods,Pace,"
            . "Character- or Plot-Driven?,Strong Character Development?,Loveable Characters?,Diverse Characters?,Flawed Characters?,"
            . "Star Rating,Review,Content Warnings,Content Warning Description,Tags,Owned?,Pages,Shelf\n"
            . "De avonden,Gerard Reve,Vertaald door Niemand,9789023417069,paperback,read,2023/01/02,2024/03/09,"
            . "\"2023/01/05-2023/01/20, 2024/03/01-2024/03/09\",2,reflective,slow,Character,Yes,No,No,Yes,4.25,StoryGraph-recensie,"
            . "Grief,Over verlies,klassiekers,Yes,320,favorieten\n";
        $file = tempnam(sys_get_temp_dir(), 'sg');
        file_put_contents($file, $csv);

        $preview = $this->web('POST', '/import', [], ['file' => ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK]])->body;
        self::assertStringContainsString('<strong>0</strong> nieuwe boeken, <strong>1</strong> bestaande boeken aangevuld', $preview);
        self::assertStringContainsString("ISBN, formaat, pagina&#039;s, tags, moods, medewerkers", $preview);
        self::assertStringContainsString('tempo', $preview);
        self::assertStringContainsString('1 leesmoment', $preview);
        self::assertNull($this->db->query("SELECT isbn FROM books WHERE id = {$id}")->fetchColumn(), 'the preview saves nothing');

        $this->web('POST', '/import/confirm');
        unlink($file);
        $book = $this->app->books->find($id);
        self::assertSame(3.0, $book['rating'], 'your own rating stays');
        self::assertSame('Mijn eigen recensie', $book['review'], 'your own review stays');
        self::assertSame(['9789023417069', 'paperback', 320, 'reflective', 'Vertaald door Niemand', 'Grief', 'Over verlies', true],
            [$book['isbn'], $book['format'], $book['pages'], $book['moods'], $book['contributors'], $book['content_warnings'],
             $book['content_warning_description'], $book['owned']]);
        self::assertSame(['Pace' => 'slow', 'Character- or Plot-Driven?' => 'Character', 'Strong Character Development?' => 'Yes',
            'Loveable Characters?' => 'No', 'Diverse Characters?' => 'No', 'Flawed Characters?' => 'Yes', 'Shelf' => 'favorieten'], $book['details']);
        self::assertCount(2, $this->app->books->reads($id));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn());

        $page = $this->web('GET', "/books/{$id}")->body;
        self::assertStringContainsString('Personages met gebreken?', $page);
        self::assertStringContainsString('Over verlies', $page);

        // The export carries it all back out.
        $export = $this->web('GET', '/export.csv')->body;
        self::assertStringContainsString('Vertaald door Niemand', $export);
        self::assertStringContainsString(',slow,Character,Yes,No,No,Yes,', $export);
    }
}
