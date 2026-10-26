<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Books;
use App\Catalog;
use App\Config;
use App\Covers;
use App\Database;
use App\Kb;
use App\Request;
use App\Response;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Lending books, and book data and covers from the KB and Google Books.
 */
final class LoansAndCatalogTest extends TestCase
{
    private const KB_RECORD = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<srw:searchRetrieveResponse xmlns:srw="http://www.loc.gov/zing/srw/" xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcx="http://krait.kb.nl/coop/tel/handbook/telterms.html">
  <srw:numberOfRecords>1</srw:numberOfRecords>
  <srw:records><srw:record><srw:recordData>
    <dc:title>De avonden : een winterverhaal / Gerard Reve</dc:title>
    <dc:creator>Reve, Gerard, 1923-2006 (auteur)</dc:creator>
    <dc:publisher>Amsterdam : De Bezige Bij</dc:publisher>
    <dc:date>[2009]</dc:date>
    <dcterms:extent>319 p. ; 20 cm</dcterms:extent>
    <dc:language>dut</dc:language>
    <dc:identifier>9789023417069</dc:identifier>
  </srw:recordData></srw:record></srw:records>
</srw:searchRetrieveResponse>
XML;

    private const KB_EMPTY = '<srw:searchRetrieveResponse xmlns:srw="http://www.loc.gov/zing/srw/"><srw:numberOfRecords>0</srw:numberOfRecords></srw:searchRetrieveResponse>';

    private PDO $db;
    private App $app;

    protected function setUp(): void
    {
        $config = $this->config();
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

    private function config(array $extra = []): Config
    {
        return new Config($extra + [
            'APP_PASSWORD' => 'geheim',
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'COVERS_DIR' => sys_get_temp_dir() . '/hirt-test-covers',
            'METADATA_SOURCES' => 'kb,google',
            'COVER_SOURCES' => 'google',
        ]);
    }

    private function post(string $path, array $data = []): Response
    {
        return $this->app->handle(new Request('POST', $path, [], $data + ['_csrf' => $this->app->auth->csrfToken()], ip: '192.0.2.3'));
    }

    private function book(string $title, array $extra = []): int
    {
        return $this->app->books->create(Books::validate(['title' => $title, 'status' => 'read', 'owned' => 1] + $extra));
    }

    // --- lending --------------------------------------------------------------

    public function testLendingAndOverviewPerPerson(): void
    {
        $a = $this->book('De avonden');
        $b = $this->book('Tirza');
        $c = $this->book('Het diner');
        $this->post("/books/{$a}/lend", ['borrower' => '  Anna  ', 'date' => '2026-01-10', 'note' => 'met boekenlegger']);
        $this->post("/books/{$b}/lend", ['borrower' => 'anna', 'date' => '2026-02-01']);
        $this->post("/books/{$c}/lend", ['borrower' => 'Bas', 'date' => '2025-12-01']);
        $this->post("/books/{$c}/return", ['date' => '2026-01-01']);

        // One person at a time.
        $this->post("/books/{$a}/lend", ['borrower' => 'Bas']);
        self::assertSame('Anna', $this->app->loans->current($a)['borrower']);
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM loans WHERE book_id = {$a}")->fetchColumn());

        $people = $this->app->loans->byPerson();
        self::assertSame(['Anna', 'Bas'], array_column($people, 'name'), 'same name in any case is one person; people with books out first');
        self::assertSame(['Tirza', 'De avonden'], array_column($people[0]['current'], 'title'));
        self::assertSame([[], ['Het diner']], [$people[1]['current'], array_column($people[1]['history'], 'title')]);
        self::assertSame(31, $people[1]['history'][0]['days']);

        $page = $this->app->handle(new Request('GET', '/loans'))->body;
        self::assertStringContainsString('met boekenlegger', $page);
        self::assertStringContainsString('📕 bij Anna', $this->app->handle(new Request('GET', '/books'))->body);

        $this->app->auth->logout();
        self::assertStringNotContainsString('Anna', $this->app->handle(new Request('GET', "/boek/{$a}"))->body, 'never public');
    }

    public function testLendingThroughTheApi(): void
    {
        $id = $this->book('De avonden');
        $auth = ['authorization' => 'Bearer ' . $this->app->auth->createToken('app')];
        $response = $this->app->handle(new Request('POST', "/api/books/{$id}/lend", headers: $auth, body: '{"borrower":"Anna","date":"2026-03-01"}'));
        $book = json_decode($response->body, true)['book'];
        self::assertSame(['Anna', '2026-03-01'], [$book['lent_to'], $book['lent_since']]);
        self::assertSame(422, $this->app->handle(new Request('POST', "/api/books/{$id}/lend", headers: $auth, body: '{"borrower":""}'))->status);

        $response = $this->app->handle(new Request('POST', "/api/books/{$id}/return", headers: $auth, body: '{}'));
        $book = json_decode($response->body, true)['book'];
        self::assertNull($book['lent_to']);
        self::assertNotNull($book['loans'][0]['returned_on']);
        $loans = json_decode($this->app->handle(new Request('GET', '/api/loans', headers: $auth))->body, true);
        self::assertSame(['Anna'], $loans['borrowers']);
    }

    // --- catalogue --------------------------------------------------------------

    public function testKbRecordIsReadAndCleanedUp(): void
    {
        self::assertSame([
            'title' => 'De avonden: een winterverhaal', 'author' => 'Gerard Reve', 'publisher' => 'De Bezige Bij',
            'year' => 2009, 'pages' => 319, 'language' => 'nl',
        ], Kb::parse(self::KB_RECORD));
        self::assertNull(Kb::parse(self::KB_EMPTY));
        self::assertNull(Kb::parse('<html>Service unavailable'));
    }

    /** A fake internet: answers by URL, records what was asked. */
    private function fakeGet(array &$asked, bool $kbKnowsIt = true): \Closure
    {
        $cover = (static function () {
            $image = imagecreatetruecolor(300, 450);
            ob_start();
            imagejpeg($image);
            return (string) ob_get_clean();
        })();
        return static function (string $url) use (&$asked, $kbKnowsIt, $cover): ?string {
            $asked[] = $url;
            if (str_starts_with($url, 'https://jsru.kb.nl/')) {
                return $kbKnowsIt ? self::KB_RECORD : self::KB_EMPTY;
            }
            if (str_starts_with($url, 'https://www.googleapis.com/books/v1/volumes')) {
                return json_encode(['items' => [['volumeInfo' => [
                    'title' => 'De avonden', 'authors' => ['Gerard Reve'], 'pageCount' => 320, 'publishedDate' => '2015-03-01',
                    'language' => 'nl', 'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9789023417069']],
                    'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id=x&printsec=frontcover&img=1&zoom=1&edge=curl'],
                ]]]]);
            }
            if (str_starts_with($url, 'https://books.google.com/books/content')) {
                return $cover;
            }
            return null;
        };
    }

    public function testLookupMergesSourcesInOrder(): void
    {
        $asked = [];
        $catalog = new Catalog($this->config(), $this->fakeGet($asked));
        $info = $catalog->lookup('9789023417069');
        self::assertSame(['kb', 'google'], $info['sources']);
        self::assertSame([319, 2009, 'De Bezige Bij'], [$info['pages'], $info['year'], $info['publisher']], 'the KB comes first');
        self::assertStringContainsString(rawurlencode('identifier.ISBN exact "9789023417069"'), $asked[0]);

        // When the KB doesn't know it (also not by ISBN-10), Google Books fills in.
        $asked = [];
        $info = (new Catalog($this->config(), $this->fakeGet($asked, kbKnowsIt: false)))->lookup('9789023417069');
        self::assertSame(['google'], $info['sources']);
        self::assertSame(320, $info['pages']);
        self::assertStringContainsString('9023417062', $asked[1], 'ISBN-10 fallback');
    }

    public function testCoverFromGoogleBooksOverHttps(): void
    {
        $asked = [];
        $cover = (new Catalog($this->config(), $this->fakeGet($asked)))->cover('9789023417069');
        self::assertSame('image/jpeg', $cover['mime']);
        $imageUrl = end($asked);
        self::assertStringStartsWith('https://books.google.com/books/content?', $imageUrl);
        self::assertStringContainsString('fife=w600', $imageUrl);
        self::assertStringNotContainsString('edge=curl', $imageUrl);
    }

    public function testMissingCoverIsRetriedWhenSourcesChange(): void
    {
        $dir = sys_get_temp_dir() . '/hirt-covers-' . bin2hex(random_bytes(4));
        $calls = 0;
        $none = function () use (&$calls) {
            $calls++;
            return null;
        };
        (new Covers($dir, $none, 'openlibrary'))->image(['id' => 1, 'isbn' => '9789023417069']);
        (new Covers($dir, $none, 'openlibrary'))->image(['id' => 1, 'isbn' => '9789023417069']);
        self::assertSame(1, $calls);
        (new Covers($dir, $none, 'openlibrary,google'))->image(['id' => 1, 'isbn' => '9789023417069']);
        self::assertSame(2, $calls, 'a new source gets a chance');
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    public function testFillingInNeverOverwrites(): void
    {
        $id = $this->book('De avonden', ['isbn' => '9789023417069', 'pages' => '300']);
        $asked = [];
        $info = (new Catalog($this->config(), $this->fakeGet($asked)))->lookup('9789023417069');
        $filled = $this->app->books->fillFromCatalog($this->app->books->find($id), $info);
        self::assertSame(['auteur', 'uitgever', 'jaar', 'taal'], $filled);
        $book = $this->app->books->find($id);
        self::assertSame([300, 'Gerard Reve', 'De Bezige Bij', 2009, 'nl'], [$book['pages'], $book['author'], $book['publisher'], $book['published_year'], $book['language']]);
        self::assertSame(0, $this->app->books->countToEnrich());
        self::assertSame([], $this->app->books->fillFromCatalog($this->app->books->find($id), null));
    }
}
