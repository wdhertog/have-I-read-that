<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Authors;
use App\Books;
use App\Config;
use App\Database;
use App\Request;
use App\Response;
use App\Share;
use App\Wikidata;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Author pages (with a fake Wikidata and Wikipedia), publishers, and sharing a book.
 */
final class AuthorsPublishersShareTest extends TestCase
{
    private PDO $db;
    private App $app;
    private string $photoDir;

    protected function setUp(): void
    {
        $this->photoDir = sys_get_temp_dir() . '/hirt-authors-' . bin2hex(random_bytes(4));
        $config = new Config([
            'APP_PASSWORD' => 'geheim',
            'APP_URL' => 'https://lezen.example.nl',
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
        foreach (['authors', 'book_aliases', 'series_parts', 'dismissed_suggestions', 'reading_log', 'posts', 'notes', 'loans', '`reads`',
                  'books', 'api_tokens', 'login_attempts', 'goals', 'settings', 'schema_migrations'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        Database::migrate($this->db, dirname(__DIR__) . '/migrations');
        $_SESSION = [];
        $this->app = new App($config, $this->db);
        $this->app->handle(new Request('POST', '/login', [], ['password' => 'geheim', '_csrf' => $this->app->auth->csrfToken()], ip: '192.0.2.10'));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->photoDir . '/*') ?: []);
        @rmdir($this->photoDir);
    }

    private function book(string $title, array $extra = []): int
    {
        return $this->app->books->create(Books::validate($extra + ['title' => $title, 'status' => 'read']));
    }

    /** Wikidata knows two Gerard Reves: the writer (Q314281) and a footballer without books. */
    private function authors(array &$asked): Authors
    {
        $rows = static fn (array $rows) => json_encode(['results' => ['bindings' => array_map(
            static fn (array $row) => array_map(static fn ($v) => ['value' => (string) $v], array_filter($row, static fn ($v) => $v !== null)),
            $rows,
        )]]);
        $entity = static fn (string $q) => "http://www.wikidata.org/entity/{$q}";
        $photo = (static function () {
            ob_start();
            imagejpeg(imagecreatetruecolor(400, 500));
            return (string) ob_get_clean();
        })();
        $get = static function (string $url) use (&$asked, $rows, $entity, $photo): ?string {
            $asked[] = $url = urldecode($url);
            if (str_starts_with($url, 'https://commons.wikimedia.org/wiki/Special:FilePath/')) {
                return $photo;
            }
            if (str_starts_with($url, 'https://nl.wikipedia.org/api/rest_v1/page/summary/Gerard_Reve')) {
                return json_encode(['type' => 'standard', 'extract' => 'Gerard Reve was een Nederlands schrijver.']);
            }
            if (str_contains($url, 'haswbstatement:P31=Q5')) {
                return $rows([
                    ['item' => $entity('Q999'), 'name' => 'Gerard Reve', 'titleNl' => 'Een ander boek'],
                    ['item' => $entity('Q314281'), 'name' => 'Gerard Reve', 'titleNl' => 'De avonden'],
                    ['item' => $entity('Q314281'), 'name' => 'Gerard Kornelis van het Reve', 'titleNl' => 'Werther Nieland'],
                ]);
            }
            if (str_contains($url, 'VALUES ?item { wd:Q314281 }')) {
                $row = static fn (string $prop, string $value, ?string $label = null, ?string $lang = null)
                    => ['prop' => $prop, 'value' => $value, 'valueLabel' => $label ?? $value, 'lang' => $lang];
                return $rows([
                    $row('label', 'Gerard Reve', null, 'nl'),
                    $row('description', 'Nederlands schrijver (1923-2006)', null, 'nl'),
                    $row('description', 'Dutch writer', null, 'en'),
                    $row('born', '1923-12-14T00:00:00Z'),
                    $row('died', '2006-04-08T00:00:00Z'),
                    $row('birthplace', $entity('Q727'), 'Amsterdam'),
                    $row('deathplace', $entity('Q12996'), 'Zulte'),
                    $row('country', $entity('Q55'), 'Nederland'),
                    $row('award', $entity('Q1'), 'P.C. Hooftprijs'),
                    $row('image', 'http://commons.wikimedia.org/wiki/Special:FilePath/Gerard%20Reve%201969.jpg'),
                    $row('nlwiki', 'https://nl.wikipedia.org/wiki/Gerard_Reve'),
                ]);
            }
            if (str_contains($url, 'wdt:P50 wd:Q314281')) {
                return $rows([
                    ['work' => $entity('Q2'), 'titleNl' => 'Werther Nieland', 'date' => '1949-01-01T00:00:00Z'],
                    ['work' => $entity('Q3'), 'titleNl' => 'De avonden', 'titleEn' => 'The Evenings', 'date' => '1947-01-01T00:00:00Z'],
                    ['work' => $entity('Q4'), 'titleNl' => 'Op weg naar het einde', 'date' => '1963-01-01T00:00:00Z'],
                ]);
            }
            return null;
        };
        return new Authors($this->db, $this->app->books, new Wikidata(\Closure::fromCallable($get)), $this->photoDir, \Closure::fromCallable($get));
    }

    public function testAnAuthorPage(): void
    {
        $this->book('De avonden', ['author' => 'Gerard Reve', 'rating' => '5', 'pages' => '319']);
        $this->book('Samen', ['author' => 'Reve, Gerard', 'status' => 'to-read']);
        $this->book('Anders', ['author' => 'Jan Wolkers, Gerard Reve']);
        $asked = [];
        $authors = $this->authors($asked);

        self::assertCount(3, $authors->books('Gerard Reve'), '"Reve, Gerard" and a co-author count too');
        $profile = $authors->profile('Gerard Reve');
        self::assertSame('Q314281', $profile['qid'], 'the writer of one of your books, not someone else with that name');
        $d = $profile['data'];
        self::assertSame(['Nederlands schrijver (1923-2006)', 1923, '1923-12-14', 'Amsterdam', 'Zulte', ['Nederland'], ['P.C. Hooftprijs']],
            [$d['description'], $d['born']['year'], $d['born']['date'], $d['birthplace'], $d['deathplace'], $d['countries'], $d['awards']]);
        self::assertSame('Gerard Reve was een Nederlands schrijver.', $d['bio']);
        self::assertSame('https://nl.wikipedia.org/wiki/Gerard_Reve', $d['wikipedia_url']);
        self::assertTrue($d['photo']);
        self::assertNotNull($authors->photo(314281));
        self::assertSame(['De avonden', 'Werther Nieland', 'Op weg naar het einde'], array_column($d['works'], 'title'), 'oldest first');

        $works = $authors->works($d);
        self::assertNotNull($works[0]['book'], 'you have De avonden');
        self::assertNull($works[1]['book']);

        // Kept: no new lookups the second time.
        $count = count($asked);
        $authors->profile('Gerard Reve');
        self::assertCount($count, $asked);

        $id = $authors->addToRead('Gerard Reve', 'Q4');
        $added = $this->app->books->find($id);
        self::assertSame(['Op weg naar het einde', 'Gerard Reve', 'to-read', 1963], [$added['title'], $added['author'], $added['status'], $added['first_published']]);

        $all = $authors->all();
        self::assertSame(['Gerard Reve', 4, 2], [$all[0]['name'], $all[0]['books'], $all[0]['read']]);
        self::assertSame('Jan Wolkers', $all[1]['name']);
    }

    public function testAnAuthorWikidataDoesntKnow(): void
    {
        $this->book('Iets', ['author' => 'Niemand Bekend']);
        $asked = [];
        $profile = $this->authors($asked)->profile('Niemand Bekend');
        self::assertNull($profile['qid']);
        self::assertNull($profile['data']);
        self::assertSame(404, $this->app->handle(new Request('GET', '/author', ['name' => 'Iemand zonder boeken']))->status);
    }

    public function testNames(): void
    {
        self::assertSame(['Reve, Gerard'], Authors::names('Reve, Gerard'));
        self::assertSame(['Jan Wolkers', 'Gerard Reve'], Authors::names('Jan Wolkers, Gerard Reve'));
        self::assertSame(['Jan Wolkers', 'Gerard Reve'], Authors::names('Jan Wolkers en Gerard Reve'));
        self::assertSame([], Authors::names(''));
        self::assertSame('Gerard Reve', Wikidata::firstLast('Reve, Gerard'));
    }

    public function testPublishers(): void
    {
        $this->book('Een', ['publisher' => 'De Bezige Bij', 'rating' => '4', 'pages' => '200']);
        $this->book('Twee', ['publisher' => 'Uitgeverij De Bezige Bij b.v.', 'rating' => '5', 'pages' => '300']);
        $this->book('Drie', ['publisher' => 'Bezige Bij', 'rating' => '3', 'status' => 'to-read']);
        $this->book('Vier', ['publisher' => 'Querido', 'rating' => '5']);
        $publishers = $this->app->books->publishers();
        self::assertSame(['Bezige Bij', 3, 2, 500, 4.0], [$publishers[0]['name'], $publishers[0]['books'], $publishers[0]['read'], $publishers[0]['pages'], $publishers[0]['rating']]);
        self::assertCount(3, $this->app->books->byPublisher('bezige bij'));

        $page = $this->app->handle(new Request('GET', '/publishers'))->body;
        self::assertStringContainsString('Bezige Bij</a> <span class="muted">(4 ★, 3 boeken)', $page, 'best rated needs three books');
        self::assertStringNotContainsString('Querido</a> <span class="muted">(5 ★', $page);
        self::assertStringContainsString('3 boeken, waarvan 2 gelezen', $this->app->handle(new Request('GET', '/publisher', ['name' => 'Bezige Bij']))->body);
    }

    public function testSharing(): void
    {
        $id = $this->book('De avonden', ['author' => 'Gerard Reve', 'rating' => '4.5', 'review' => 'Somber en grappig.']);
        $this->app->loans->lend($id, 'Anna', '2026-09-01');
        $book = $this->app->books->find($id);
        self::assertSame("📖 Ik las “De avonden” van Gerard Reve.\n★★★★½ (4.5)\n\nSomber en grappig.\n\nhttps://lezen.example.nl/boek/{$id}",
            Share::opinion($book, "https://lezen.example.nl/boek/{$id}"));
        self::assertSame('Hoi Anna! Heb je “De avonden” van Gerard Reve nog, dat je van me leende (sinds 1-9-2026)? Zou ik het terug mogen als je het uit hebt? 😊',
            Share::askBack($book));

        $page = $this->app->handle(new Request('GET', "/books/{$id}"))->body;
        self::assertStringContainsString('data-share="share-back"', $page);
        self::assertStringContainsString('https://wa.me/?text=' . rawurlencode(Share::askBack($book)), str_replace('&amp;', '&', $page));
        self::assertStringContainsString("https://lezen.example.nl/boek/{$id}", $page);

        $this->app->loans->returnBook($id, '2026-10-01');
        self::assertNull(Share::askBack($this->app->books->find($id)));
    }
}
