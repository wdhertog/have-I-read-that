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
use App\Language;
use App\Request;
use App\Response;
use App\Wikidata;
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

    /** The work is in two series: the numbered one wins. */
    private const WIKIDATA_ANSWER = '{"head":{"vars":["seriesLabel","ordinal","direct"]},"results":{"bindings":['
        . '{"seriesLabel":{"type":"literal","value":"Q98765"},"direct":{"type":"literal","value":"1"}},'
        . '{"seriesLabel":{"type":"literal","value":"Ooievaar pocket"},"direct":{"type":"literal","value":"1"}},'
        . '{"seriesLabel":{"type":"literal","value":"Winterverhalen"},"ordinal":{"type":"literal","value":"2"},"direct":{"type":"literal","value":"0"}}'
        . ']}}';

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
        // What the KB really sends: a Dutch word first, the code after it.
        $record = str_replace('<dc:language>dut</dc:language>', '<dc:language>nederlands</dc:language><dc:language>dut</dc:language>', self::KB_RECORD);
        self::assertSame('nl', Kb::parse($record)['language']);
        self::assertSame('nl', Kb::parse(str_replace('>dut<', '>nederlands<', self::KB_RECORD))['language']);
    }

    public function testLanguagesAreStoredAsCodesAndShownInDutch(): void
    {
        foreach (['nl', 'NL', 'nl-NL', 'dut', 'nld', 'nederlands', 'Dutch'] as $value) {
            self::assertSame('nl', Language::normalize($value), $value);
        }
        self::assertSame('en', Language::normalize('eng'));
        self::assertSame('Klingon', Language::normalize(' Klingon '));
        self::assertNull(Language::normalize(''));
        self::assertSame('Nederlands', Language::label('nl'));
        self::assertSame('Engels', Language::label('english'));
        self::assertSame('Klingon', Language::label('Klingon'));

        $id = $this->book('De handschoen');
        $this->db->prepare('UPDATE books SET language = ? WHERE id = ?')->execute(['nederlands', $id]);
        $this->db->exec(file_get_contents(dirname(__DIR__) . '/migrations/009_language_codes.sql'));
        self::assertSame('nl', $this->db->query("SELECT language FROM books WHERE id = {$id}")->fetchColumn());
        self::assertStringContainsString('<dd>Nederlands</dd>', $this->app->handle(new Request('GET', "/books/{$id}"))->body);
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
            if (str_starts_with($url, 'https://query.wikidata.org/sparql?format=json&query=')) {
                return self::WIKIDATA_ANSWER;
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

    public function testWikidataIsAskedForEveryHyphenation(): void
    {
        $spellings = Wikidata::spellings('9789023417069');
        self::assertCount(58, $spellings, '28 hyphenations each of the ISBN-13 and ISBN-10, plus both bare');
        self::assertContains('978-90-234-1706-9', $spellings);
        self::assertContains('90-234-1706-2', $spellings);
        self::assertContains('9023417062', $spellings);
        self::assertCount(29, Wikidata::spellings('9791032305690'), 'no ISBN-10 for 979');
        $query = Wikidata::query('9789023417069');
        self::assertStringContainsString('"978-90-234-1706-9"', $query);
        self::assertStringContainsString('wdt:P629', $query, 'translations look at the original work');
    }

    public function testWikidataAnswerIsRead(): void
    {
        self::assertSame(['series' => 'Winterverhalen', 'series_number' => 2.0], Wikidata::parse(self::WIKIDATA_ANSWER));
        self::assertNull(Wikidata::parse('{"results":{"bindings":[]}}'));
        self::assertNull(Wikidata::parse('<html>Too many requests'));
        $odd = '{"results":{"bindings":[{"seriesLabel":{"value":"Schijfwereld"},"ordinal":{"value":"3a"}}]}}';
        self::assertSame(['series' => 'Schijfwereld', 'series_number' => null], Wikidata::parse($odd));
    }

    public function testWikidataFallsBackToTitleAndAuthor(): void
    {
        $hit = static fn (string $item, string $name, string $author, string $series, ?string $ordinal) => [
            'item' => ['value' => "http://www.wikidata.org/entity/{$item}"], 'name' => ['value' => $name],
            'authorName' => ['value' => $author], 'seriesLabel' => ['value' => $series],
        ] + ($ordinal !== null ? ['ordinal' => ['value' => $ordinal]] : []);
        $answer = json_encode(['results' => ['bindings' => [
            $hit('Q43361', "Harry Potter and the Philosopher's Stone", 'J. K. Rowling', 'Harry Potter', '1'),
            $hit('Q47209', 'Harry Potter and the Chamber of Secrets', 'J. K. Rowling', 'Harry Potter', '2'),
            $hit('Q47209', 'Harry Potter en de Geheime Kamer', 'J. K. Rowling', 'Harry Potter', '2'),
            $hit('Q99999', 'De geheime kamer', 'Iemand Anders', 'Spannende reeks', '7'),
        ]]]);
        $asked = [];
        $get = static function (string $url) use (&$asked, $answer): ?string {
            $asked[] = urldecode($url);
            return str_contains(urldecode($url), 'mwapi') ? $answer : '{"results":{"bindings":[]}}';
        };
        $wikidata = new Wikidata(\Closure::fromCallable($get));

        $found = $wikidata->find('9781338878936', 'Harry Potter and the Chamber of Secrets', 'J.K. Rowling');
        self::assertSame(['series' => 'Harry Potter', 'series_number' => 2.0], $found);
        self::assertCount(2, $asked, 'first by ISBN, then by title');
        self::assertStringContainsString('harry potter and the chamber of secrets haswbstatement:P179', $asked[1]);

        // A Dutch translation by its Dutch title; a subtitle doesn't get in the way.
        self::assertSame(2.0, $wikidata->find('9789076174082', 'Harry Potter en de geheime kamer: deel 2', 'Rowling, J.K.')['series_number']);
        // Same title, other author: not taken.
        self::assertNull($wikidata->find('9789000000000', 'De geheime kamer', 'Gerard Reve'));
        // Nothing to search with.
        $asked = [];
        self::assertNull($wikidata->find('9789000000000'));
        self::assertCount(1, $asked);
        // Quotes can't break out of the query.
        self::assertStringNotContainsString('"x"', Wikidata::titleQuery('Een "x" boek'));
    }

        public function testSeriesIsFilledInFromWikidata(): void
    {
        $config = $this->config(['METADATA_SOURCES' => 'kb,wikidata']);
        $asked = [];
        $info = (new Catalog($config, $this->fakeGet($asked)))->lookup('9789023417069');
        self::assertSame(['kb', 'wikidata'], $info['sources']);
        self::assertSame(['Winterverhalen', 2.0], [$info['series'], $info['series_number']]);

        $fresh = $this->book('De avonden', ['isbn' => '9789023417069']);
        self::assertContains('serie', $this->app->books->fillFromCatalog($this->app->books->find($fresh), $info));
        $book = $this->app->books->find($fresh);
        self::assertSame(['Winterverhalen', 2.0], [$book['series'], (float) $book['series_number']]);

        // A series of your own stays, and no number from another series is added to it.
        $own = $this->book('De avonden', ['isbn' => '9789023417069', 'series' => 'Mijn reeks', 'allow_duplicate' => 1]);
        $filled = $this->app->books->fillFromCatalog($this->app->books->find($own), $info);
        self::assertNotContains('serie', $filled);
        self::assertNotContains('deel', $filled);
        self::assertSame(['Mijn reeks', null], [$this->app->books->find($own)['series'], $this->app->books->find($own)['series_number']]);
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
