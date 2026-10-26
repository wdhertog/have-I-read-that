<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Books;
use App\Config;
use App\Database;
use App\Request;
use App\Response;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Notes and quotes, shelves, series, and how they survive a StoryGraph re-import.
 */
final class StepSixTest extends TestCase
{
    private PDO $db;
    private App $app;

    protected function setUp(): void
    {
        $config = new Config([
            'APP_PASSWORD' => 'geheim',
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'COVERS_DIR' => sys_get_temp_dir() . '/hirt-test-covers',
        ]);
        try {
            $this->db = Database::connect($config);
        } catch (\PDOException $e) {
            self::markTestSkipped('No test database: ' . $e->getMessage());
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['posts', 'notes', 'loans', '`reads`', 'books', 'api_tokens', 'login_attempts', 'goals', 'settings', 'schema_migrations'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        Database::migrate($this->db, dirname(__DIR__) . '/migrations');
        $_SESSION = [];
        $this->app = new App($config, $this->db);
        $this->post('/login', ['password' => 'geheim']);
    }

    private function post(string $path, array $data = [], array $files = []): Response
    {
        return $this->app->handle(new Request('POST', $path, [], $data + ['_csrf' => $this->app->auth->csrfToken()], $files, ip: '192.0.2.2'));
    }

    private function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new Request('GET', $path, $query));
    }

    private function import(string $csv): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sg');
        file_put_contents($file, $csv);
        $this->post('/import', [], ['file' => ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK]]);
        $this->post('/import/confirm');
        unlink($file);
    }

    private const HEADER = "Title,Authors,Contributors,ISBN/UID,Format,Read Status,Date Added,Last Date Read,Dates Read,Read Count,"
        . "Moods,Pace,Character- or Plot-Driven?,Strong Character Development?,Loveable Characters?,Diverse Characters?,"
        . "Flawed Characters?,Star Rating,Review,Content Warnings,Content Warning Description,Tags,Owned?\n";

    public function testNotesAndQuotes(): void
    {
        $id = $this->app->books->create(Books::validate(['title' => 'Tirza', 'status' => 'read']));
        $this->post("/books/{$id}/notes", ['kind' => 'quote', 'text' => 'Een mooie zin.', 'page' => '42', 'public' => 'on']);
        $this->post("/books/{$id}/notes", ['kind' => 'note', 'text' => 'Privé-gedachte']);
        $this->post("/books/{$id}/notes", ['kind' => 'note', 'text' => '   ']);
        self::assertCount(2, $this->app->books->notes($id));

        $page = $this->get("/books/{$id}")->body;
        self::assertStringContainsString('Een mooie zin.', $page);
        self::assertStringContainsString('Privé-gedachte', $page);

        $this->app->auth->logout();
        $public = $this->get("/boek/{$id}")->body;
        self::assertStringContainsString('Een mooie zin.', $public);
        self::assertStringNotContainsString('Privé-gedachte', $public, 'private notes never show publicly');

        $token = $this->app->auth->createToken('app');
        $auth = ['authorization' => "Bearer {$token}"];
        $response = $this->app->handle(new Request('POST', "/api/books/{$id}/notes", headers: $auth, body: '{"kind":"quote","text":"Uit de app","page":7}'));
        self::assertSame(201, $response->status);
        $notes = json_decode($response->body, true)['book']['notes'];
        self::assertSame(['Uit de app', 7], [$notes[0]['text'], $notes[0]['page']], 'ordered by page');
        $response = $this->app->handle(new Request('POST', "/api/notes/{$notes[0]['id']}/delete", headers: $auth));
        self::assertCount(2, json_decode($response->body, true)['book']['notes']);
    }

    public function testShelvesAreTags(): void
    {
        $a = $this->app->books->create(Books::validate(['title' => 'A', 'tags' => 'Fantasy, bieb, fantasy ']));
        $this->app->books->create(Books::validate(['title' => 'B', 'tags' => 'fantasy-achtig']));
        self::assertSame('Fantasy, bieb', $this->app->books->find($a)['tags']);
        self::assertSame([['name' => 'bieb', 'count' => 1], ['name' => 'Fantasy', 'count' => 1], ['name' => 'fantasy-achtig', 'count' => 1]],
            $this->app->books->shelves());
        self::assertSame(['A'], array_column($this->app->books->list(null, '', null, 'fantasy'), 'title'), 'exact shelf, not a substring');

        $this->post('/collections/rename', ['from' => 'fantasy-achtig', 'to' => 'Fantasy']);
        self::assertSame([['name' => 'bieb', 'count' => 1], ['name' => 'Fantasy', 'count' => 2]], $this->app->books->shelves());
        $this->post('/collections/rename', ['from' => 'bieb', 'to' => '']);
        self::assertSame('Fantasy', $this->app->books->find($a)['tags']);
        self::assertStringContainsString('Fantasy</a>', $this->get('/collections')->body);
    }

    public function testSeries(): void
    {
        foreach ([['Deel twee', '2'], ['Novelle', '1,5'], ['Deel een', '1']] as [$title, $n]) {
            $this->post('/books/add', ['title' => $title, 'status' => $title === 'Deel een' ? 'read' : 'to-read',
                'series' => 'De Toren', 'series_number' => $n]);
        }
        self::assertSame(['Deel een', 'Novelle', 'Deel twee'], array_column($this->app->books->seriesBooks('De Toren'), 'title'));
        self::assertSame([['name' => 'De Toren', 'books' => 3, 'read' => 1]], $this->app->books->seriesList());
        self::assertStringContainsString('Deel 1,5: Novelle', $this->get('/collections', ['series' => 'De Toren'])->body);
        self::assertSame(422, $this->post('/books/add', ['title' => 'X', 'series_number' => 'drie'])->status);
    }

    public function testReimportKeepsYourOwnWorkAndRecognisesRenamedBooks(): void
    {
        $csv = self::HEADER
            . "Zonder ISBN,Iemand,,sg-uid-123,,read,2023/01/02,2023/02/01,2023/01/10-2023/02/01,1,,,,,,,,4,,,,stapel,No\n"
            . "Met ISBN,Iemand,,9789023417069,,to-read,2023/01/02,,,0,,,,,,,,,,,,,No\n";
        $this->import($csv);
        $id = (int) $this->db->query("SELECT id FROM books WHERE title = 'Zonder ISBN'")->fetchColumn();
        self::assertSame('sg-uid-123', $this->app->books->find($id)['storygraph_id']);

        // You rename it, put it in a series and on another shelf, and write a note.
        $this->post("/books/{$id}/edit", ['title' => 'Nieuwe titel', 'author' => 'Iemand', 'status' => 'read',
            'tags' => 'stapel, favoriet', 'series' => 'Reeks', 'series_number' => '2']);
        $this->post("/books/{$id}/notes", ['text' => 'Mijn notitie']);

        $this->import($csv);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn(), 'no duplicate after renaming');
        $book = $this->app->books->find($id);
        self::assertSame(['Nieuwe titel', 'stapel, favoriet', 'Reeks', 2.0], [$book['title'], $book['tags'], $book['series'], $book['series_number']]);
        self::assertCount(1, $this->app->books->notes($id));
        self::assertCount(1, $this->app->books->reads($id));

        // A book imported by an older version (no id kept) gets its id on the next
        // re-import, matched on title and author; after that renaming it is fine too.
        $old = $this->app->books->create(Books::validate(['title' => 'Oud boek', 'author' => 'Iemand', 'status' => 'read']));
        $csv .= "Oud boek,Iemand,,sg-uid-456,,read,2022/01/01,,,0,,,,,,,,,,,,,No\n";
        $this->import($csv);
        self::assertSame('sg-uid-456', $this->app->books->find($old)['storygraph_id']);
        $this->db->exec("UPDATE books SET title = 'Hernoemd oud boek' WHERE id = {$old}");
        $this->import($csv);
        self::assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn());
    }

    public function testExportRoundTripKeepsSeriesAndUid(): void
    {
        $this->import(self::HEADER . "Zonder ISBN,Iemand,,sg-uid-9,,read,2023/01/02,,,0,,,,,,,,,,,,,No\n");
        $id = (int) $this->db->query('SELECT id FROM books')->fetchColumn();
        $this->db->exec("UPDATE books SET series = 'Reeks', series_number = 3 WHERE id = {$id}");
        $this->app->books->addNote($id, ['text' => 'Notitie']);

        $csv = $this->get('/export.csv')->body;
        self::assertStringContainsString(',sg-uid-9,', $csv);
        self::assertStringContainsString(',Reeks,3', $csv);
        self::assertSame('Notitie', json_decode($this->get('/export.json')->body, true)['books'][0]['notes'][0]['text']);

        $this->db->exec('DELETE FROM books');
        $this->import($csv);
        $book = $this->app->books->list()[0];
        self::assertSame(['sg-uid-9', 'Reeks', 3.0], [$book['storygraph_id'], $book['series'], $book['series_number']]);
    }
}
