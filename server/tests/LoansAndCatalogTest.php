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
        self::assertStringContainsString('<span class="badge lent">bij Anna</span>', $this->app->handle(new Request('GET', '/books'))->body);

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
            'translator' => null, 'original_title' => null, 'original_language' => null,
        ], Kb::parse(self::KB_RECORD));
        // A translation: the translator is no author, and the notes give the original.
        $translation = str_replace(
            '<dc:creator>Reve, Gerard, 1923-2006 (auteur)</dc:creator>',
            '<dc:creator>McCarthy, Cormac (auteur)</dc:creator><dc:creator>Vertommen, Jan (vertaler)</dc:creator>'
            . '<dc:description>Vert. uit het Engels. - Vert. van: The road. - New York : Knopf, 2006</dc:description>',
            self::KB_RECORD,
        );
        $record = Kb::parse($translation);
        self::assertSame(['Cormac McCarthy', 'Jan Vertommen', 'The road', 'en'],
            [$record['author'], $record['translator'], $record['original_title'], $record['original_language']]);
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
        $query = Wikidata::isbnQuery('9789023417069');
        self::assertStringContainsString('"978-90-234-1706-9"', $query);
        self::assertStringContainsString('wdt:P629', $query, 'translations lead to the original work');
        self::assertStringContainsString('(wd:Q47209 0) (wd:Q1 1)', Wikidata::detailsQuery('Q47209', 'Q1'));
        self::assertStringNotContainsString('wd:Q47209 1', Wikidata::detailsQuery('Q47209', 'Q47209'));
        // Search text can't break out of the query.
        self::assertStringNotContainsString('"x"', Wikidata::titleQuery('Een "x" boek'));
    }

    /**
     * A fake Wikidata and Wikipedia for Harry Potter 2: the Dutch edition Q1 is known by
     * ISBN 9789076174082 only; the work Q47209 can be found by title.
     */
    private function fakeWikidata(array &$asked): \Closure
    {
        $rows = static fn (array $rows) => json_encode(['results' => ['bindings' => array_map(
            static fn (array $row) => array_map(static fn ($v) => ['value' => (string) $v], array_filter($row, static fn ($v) => $v !== null)),
            $rows,
        )]]);
        $entity = static fn (string $q) => "http://www.wikidata.org/entity/{$q}";
        return static function (string $url) use (&$asked, $rows, $entity): ?string {
            $asked[] = $url = urldecode($url);
            if (str_starts_with($url, 'https://nl.wikipedia.org/api/rest_v1/page/summary/')) {
                return json_encode(['type' => 'standard', 'extract' => 'Harry Potter en de Geheime Kamer is het tweede boek.']);
            }
            if (!str_starts_with($url, 'https://query.wikidata.org/')) {
                return null;
            }
            if (str_contains($url, 'wdt:P212')) {
                return $rows(str_contains($url, '"978-90-76174-08-2"') ? [['edition' => $entity('Q1'), 'work' => $entity('Q47209')]] : []);
            }
            if (str_contains($url, 'mwapi')) {
                $hit = static fn (string $q, string $name, string $author) => ['item' => $entity($q), 'name' => $name, 'authorName' => $author];
                return $rows([
                    $hit('Q43361', "Harry Potter and the Philosopher's Stone", 'J. K. Rowling'),
                    $hit('Q47209', 'Harry Potter and the Chamber of Secrets', 'J. K. Rowling'),
                    $hit('Q47209', 'Harry Potter en de Geheime Kamer', 'J. K. Rowling'),
                    $hit('Q99999', 'De geheime kamer', 'Iemand Anders'),
                ]);
            }
            if (str_contains($url, 'VALUES (?item ?direct)')) {
                $row = static fn (int $direct, string $prop, string $value, ?string $label = null, ?string $ordinal = null)
                    => ['direct' => $direct, 'prop' => $prop, 'value' => $value, 'valueLabel' => $label ?? $value, 'ordinal' => $ordinal];
                $details = [
                    $row(0, 'series', $entity('Q8337'), 'Harry Potter', '2'),
                    $row(0, 'series', $entity('Q5'), 'Q5'),
                    $row(0, 'title', 'Harry Potter and the Chamber of Secrets'),
                    $row(0, 'language', 'en'),
                    $row(0, 'date', '1998-07-02T00:00:00Z'),
                    $row(0, 'genre', $entity('Q6'), 'fantasy'),
                    $row(0, 'genre', $entity('Q7'), 'kinderliteratuur'),
                    $row(0, 'award', $entity('Q8'), 'Nestlé Smarties Book Prize'),
                    $row(0, 'nlwiki', 'https://nl.wikipedia.org/wiki/Harry_Potter_en_de_Geheime_Kamer'),
                    $row(0, 'enwiki', 'https://en.wikipedia.org/wiki/Harry_Potter_and_the_Chamber_of_Secrets'),
                ];
                if (str_contains($url, 'wd:Q1 1')) {
                    $details[] = $row(1, 'title', 'Harry Potter en de geheime kamer');
                    $details[] = $row(1, 'translator', $entity('Q9'), 'Wiebe Buddingh\'');
                }
                return $rows($details);
            }
            return null;
        };
    }

    public function testWikidataByIsbnFindsTheWorkAndTheTranslation(): void
    {
        $asked = [];
        $found = (new Wikidata($this->fakeWikidata($asked)))->find('9789076174082', 'Harry Potter en de geheime kamer', 'J.K. Rowling');
        self::assertSame([
            'series' => 'Harry Potter', 'series_number' => 2.0, 'wikidata_series' => 'Q8337',
            'original_title' => 'Harry Potter and the Chamber of Secrets', 'original_language' => 'en',
            'first_published' => 1998, 'genre' => 'fantasy, kinderliteratuur', 'translator' => "Wiebe Buddingh'",
            'awards' => 'Nestlé Smarties Book Prize',
            'description' => 'Harry Potter en de Geheime Kamer is het tweede boek.', 'description_language' => 'nl',
        ], $found);
        self::assertCount(3, $asked, 'ISBN, details, Wikipedia');
        self::assertStringEndsWith('/page/summary/Harry_Potter_en_de_Geheime_Kamer', $asked[2]);
    }

    public function testWikidataFallsBackToTitleAndAuthor(): void
    {
        $asked = [];
        $wikidata = new Wikidata($this->fakeWikidata($asked));
        $found = $wikidata->find('9781338878936', 'Harry Potter and the Chamber of Secrets', 'J.K. Rowling');
        self::assertSame(['Harry Potter', 2.0, null, null], [$found['series'], $found['series_number'], $found['original_title'] ?? null, $found['translator'] ?? null],
            'the same title is no "original title"; no edition, so no translator');
        self::assertStringContainsString('harry potter and the chamber of secrets haswbstatement:P50', $asked[1]);
        // A Dutch title with a subtitle and the author written the other way round.
        self::assertSame(2.0, $wikidata->find('9789000000001', 'Harry Potter en de geheime kamer: deel 2', 'Rowling, J.K.')['series_number']);
        // Same title, other author: not taken.
        self::assertNull($wikidata->find('9789000000002', 'De geheime kamer', 'Gerard Reve'));
        // Nothing to search with: only the ISBN is asked.
        $asked = [];
        self::assertNull($wikidata->find('9789000000003'));
        self::assertCount(1, $asked);
        self::assertNull(Wikidata::parseSummary('{"type":"disambiguation","extract":"Kan verwijzen naar"}'));
        self::assertNull(Wikidata::parseDetails('<html>Too many requests'));
        // A title, year or genre of only digits stays text (PHP turns such array keys into ints).
        $digits = json_encode(['results' => ['bindings' => [
            ['direct' => ['value' => '0'], 'prop' => ['value' => 'title'], 'value' => ['value' => '1984']],
            ['direct' => ['value' => '0'], 'prop' => ['value' => 'genre'], 'value' => ['value' => 'http://www.wikidata.org/entity/Q3'], 'valueLabel' => ['value' => '2020']],
        ]]]);
        self::assertSame(['1984', '2020'], [Wikidata::parseDetails($digits)['original_title'], Wikidata::parseDetails($digits)['genre']]);
    }

    public function testDetailsAreMergedAndFilledIn(): void
    {
        $asked = [];
        $kb = $this->fakeGet($asked);
        $wikidata = $this->fakeWikidata($asked);
        $get = static fn (string $url) => str_starts_with($url, 'https://jsru.kb.nl/') ? $kb($url) : $wikidata($url);
        $hardcover = json_encode(['data' => ['editions' => [[
            'title' => 'De avonden', 'pages' => 318, 'release_date' => '2009-01-01',
            'book' => [
                'title' => 'De avonden', 'description' => 'An English blurb.', 'release_year' => 1947,
                'cached_tags' => ['Genre' => [['tag' => 'Classics'], ['tag' => 'Literary Fiction']]],
                'book_series' => [['position' => 1, 'series' => ['name' => 'Reve-reeks']]],
                'contributions' => [['contribution' => null, 'author' => ['name' => 'Gerard Reve']], ['contribution' => 'Translator', 'author' => ['name' => 'Sam Garrett']]],
            ],
        ]]]]);
        $posted = [];
        $post = static function (string $url, string $body, array $headers) use (&$posted, $hardcover): ?string {
            $posted[] = [$url, json_decode($body, true), $headers];
            return $hardcover;
        };
        $config = $this->config(['METADATA_SOURCES' => 'kb,hardcover,wikidata', 'HARDCOVER_TOKEN' => 'abc']);
        $info = (new Catalog($config, $get, $post))->lookup('9789023417069');
        self::assertSame(['kb', 'hardcover'], $info['sources'], 'Wikidata finds nothing for De avonden');
        self::assertSame(['Bearer abc'], [substr($posted[0][2][0], strlen('Authorization: '))]);
        self::assertSame(['9789023417069', '9023417062'], $posted[0][1]['variables']['isbns']);
        self::assertSame([319, 'Reve-reeks', 1.0, 'Classics, Literary Fiction', 'Sam Garrett', 1947, 'An English blurb.'],
            [$info['pages'], $info['series'], $info['series_number'], $info['genre'], $info['translator'], $info['first_published'], $info['description']]);

        // Without a token Hardcover isn't asked at all.
        $posted = [];
        (new Catalog($this->config(['METADATA_SOURCES' => 'hardcover']), $get, $post))->lookup('9789023417069');
        self::assertSame([], $posted);

        // A Dutch description from Wikipedia wins over the English one; the numbers stay with their series.
        $info = (new Catalog($this->config(['METADATA_SOURCES' => 'hardcover,wikidata', 'HARDCOVER_TOKEN' => 'abc']), $get, static fn () => json_encode(['data' => ['editions' => [[
            'title' => 'Harry Potter en de geheime kamer', 'book' => ['description' => 'English.', 'book_series' => [['position' => 7]],
            'contributions' => [['author' => ['name' => 'J.K. Rowling']]]],
        ]]]])))->lookup('9789076174082');
        self::assertSame(['Harry Potter en de Geheime Kamer is het tweede boek.', 'Harry Potter', 2.0, 'en'],
            [$info['description'], $info['series'], $info['series_number'], $info['original_language']]);

        $id = $this->book('Harry Potter en de geheime kamer', ['isbn' => '9789076174082', 'genre' => 'jeugd']);
        $filled = $this->app->books->fillFromCatalog($this->app->books->find($id), $info);
        self::assertSame(['auteur', 'serie', 'deel', 'beschrijving', 'oorspronkelijke titel', 'oorspronkelijke taal', 'vertaler', 'eerste verschijning', 'prijzen'], $filled);
        $book = $this->app->books->find($id);
        self::assertSame(['jeugd', 'en', 1998, "Wiebe Buddingh'"], [$book['genre'], $book['original_language'], $book['first_published'], $book['translator']]);

        $page = $this->app->handle(new Request('GET', "/books/{$id}"))->body;
        self::assertStringContainsString('Harry Potter and the Chamber of Secrets · Engels</dd>', $page);
        self::assertStringContainsString('is het tweede boek.', $page);
        self::assertStringContainsString('Wiebe Buddingh&#039;', $page);
        self::assertSame([$id], array_column($this->app->books->list(null, 'Buddingh'), 'id'), 'search finds the translator');
        self::assertSame([$id], array_column($this->app->books->list(null, 'jeugd'), 'id'), 'and the genre');
    }

    public function testDetailsCanBeEditedAndAreLeftAloneByOlderApps(): void
    {
        $id = $this->book('De avonden');
        $this->post("/books/{$id}/edit", [
            'title' => 'De avonden', 'status' => 'read', 'genre' => 'roman, Roman, klassieker', 'first_published' => '1947',
            'original_language' => 'Nederlands', 'description' => 'Frits van Egters.', 'translator' => '', 'original_title' => '', 'awards' => '',
        ]);
        $book = $this->app->books->find($id);
        self::assertSame(['roman, klassieker', 1947, 'nl', 'Frits van Egters.', null], [$book['genre'], $book['first_published'], $book['original_language'], $book['description'], $book['translator']]);
        self::assertSame(422, $this->post("/books/{$id}/edit", ['title' => 'De avonden', 'first_published' => 'vroeger'])->status);

        // The app doesn't send these fields when saving an edit: they stay.
        $this->app->books->update($id, Books::validate(['title' => 'De avonden', 'status' => 'read', 'publisher' => 'De Bezige Bij']));
        self::assertSame('Frits van Egters.', $this->app->books->find($id)['description']);
    }

    public function testStatsPerLanguageAndGenre(): void
    {
        $a = $this->book('Een', ['genre' => 'fantasy, jeugd']);
        $b = $this->book('Twee', ['genre' => 'Fantasy']);
        $c = $this->book('Drie');
        $this->db->exec("UPDATE books SET language = 'nl', original_language = 'en' WHERE id IN ({$a}, {$b})");
        $this->db->exec("UPDATE books SET language = 'nl' WHERE id = {$c}");
        self::assertSame([
            ['language' => 'en', 'books' => 2, 'translated' => 2],
            ['language' => 'nl', 'books' => 1, 'translated' => 0],
        ], $this->app->books->readByLanguage());
        self::assertSame(['Fantasy' => 2, 'jeugd' => 1], $this->app->books->topGenres(), 'same spelling whatever the order');
        $page = $this->app->handle(new Request('GET', '/stats'))->body;
        self::assertStringContainsString('Engels: 2 boeken', $page);
        self::assertStringContainsString('2 van de 3 boeken (67%) las je in vertaling.', $page);
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
