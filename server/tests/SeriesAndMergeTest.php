<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Books;
use App\Config;
use App\Database;
use App\Request;
use App\Response;
use App\SeriesParts;
use App\StoryGraph;
use App\Wikidata;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The missing parts of your series (from a fake Wikidata), and merging editions.
 */
final class SeriesAndMergeTest extends TestCase
{
    private PDO $db;
    private App $app;
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'APP_PASSWORD' => 'geheim',
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'COVERS_DIR' => sys_get_temp_dir() . '/hirt-test-covers-' . bin2hex(random_bytes(4)),
        ]);
        try {
            $this->db = Database::connect($this->config);
        } catch (\PDOException $e) {
            self::markTestSkipped('No test database: ' . $e->getMessage());
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['book_aliases', 'series_parts', 'dismissed_suggestions', 'reading_log', 'posts', 'notes', 'loans', '`reads`', 'books',
                  'api_tokens', 'login_attempts', 'goals', 'settings', 'schema_migrations'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        Database::migrate($this->db, dirname(__DIR__) . '/migrations');
        $_SESSION = [];
        $this->app = new App($this->config, $this->db);
        $this->post('/login', ['password' => 'geheim']);
    }

    private function post(string $path, array $data = []): Response
    {
        return $this->app->handle(new Request('POST', $path, [], $data + ['_csrf' => $this->app->auth->csrfToken()], ip: '192.0.2.9'));
    }

    private function book(string $title, array $extra = []): int
    {
        return $this->app->books->create(Books::validate(['title' => $title, 'status' => 'to-read'] + $extra));
    }

    /** A fake Wikidata that knows the series "Harry Potter" (Q8337) with four parts. */
    private function wikidata(array &$asked): Wikidata
    {
        $rows = static fn (array $rows) => json_encode(['results' => ['bindings' => array_map(
            static fn (array $row) => array_map(static fn ($v) => ['value' => (string) $v], array_filter($row, static fn ($v) => $v !== null)),
            $rows,
        )]]);
        $entity = static fn (string $q) => "http://www.wikidata.org/entity/{$q}";
        return new Wikidata(static function (string $url) use (&$asked, $rows, $entity): ?string {
            $asked[] = $url = urldecode($url);
            if (str_contains($url, 'mwapi') && str_contains($url, 'FILTER EXISTS { ?part wdt:P179 ?item }')) {
                return $rows([
                    ['item' => $entity('Q1'), 'name' => 'Harry Potter', 'authorName' => 'Iemand Anders'], // the films, say
                    ['item' => $entity('Q8337'), 'name' => 'Harry Potter', 'authorName' => 'J. K. Rowling'],
                    ['item' => $entity('Q8337'), 'name' => 'Harry Potter-reeks', 'authorName' => 'J. K. Rowling'],
                ]);
            }
            if (str_contains($url, 'ps:P179 wd:Q8337')) {
                $part = static fn (string $q, ?string $n, ?string $nl, ?string $en, string $date)
                    => ['work' => $entity($q), 'ordinal' => $n, 'titleNl' => $nl, 'titleEn' => $en, 'authorName' => 'J. K. Rowling', 'date' => $date];
                return $rows([
                    $part('Q102438', '2', 'Harry Potter en de Geheime Kamer', 'Harry Potter and the Chamber of Secrets', '1998-07-02T00:00:00Z'),
                    $part('Q43361', '1', 'Harry Potter en de Steen der Wijzen', "Harry Potter and the Philosopher's Stone", '1997-06-26T00:00:00Z'),
                    $part('Q47209', '3', 'Harry Potter en de Gevangene van Azkaban', 'Harry Potter and the Prisoner of Azkaban', '1999-07-08T00:00:00Z'),
                    $part('Q46751', '4', null, 'Harry Potter and the Goblet of Fire', '2000-07-08T00:00:00Z'),
                ]);
            }
            return null;
        });
    }

    public function testMissingPartsOfASeries(): void
    {
        $asked = [];
        $parts = new SeriesParts($this->db, $this->app->books, $this->wikidata($asked));
        // Part 1 under its Dutch title, part 2 by its original title, part 3 only by number.
        $this->book('Harry Potter en de steen der wijzen', ['author' => 'J.K. Rowling', 'series' => 'Harry Potter', 'series_number' => '1']);
        $this->book('De geheime kamer', ['author' => 'J.K. Rowling', 'original_title' => 'Harry Potter and the Chamber of Secrets']);
        $third = $this->book('Gevangene', ['author' => 'J.K. Rowling', 'series' => 'Harry Potter', 'series_number' => '3']);

        self::assertSame(['Harry Potter'], $parts->toRefresh(10));
        self::assertSame(4, $parts->refresh('Harry Potter'));
        self::assertSame([], $parts->toRefresh(10), 'kept for 30 days');

        $overview = $parts->overview();
        self::assertCount(1, $overview);
        self::assertSame('Q8337', $overview[0]['qid'], 'the series with an author you have, not the films');
        self::assertSame(3, $overview[0]['have']);
        self::assertSame([1.0, 2.0, 3.0, 4.0], array_column($overview[0]['parts'], 'number'));
        self::assertSame($third, $overview[0]['parts'][2]['book']['id']);
        self::assertSame(['Harry Potter and the Goblet of Fire'], array_column($overview[0]['missing'], 'title'), 'English title when there is no Dutch one');

        // On the to-read list, and then it's no longer missing.
        $this->post('/series/add', ['series' => 'Harry Potter', 'qid' => 'Q46751']);
        $added = $this->app->books->findDuplicate('Harry Potter and the Goblet of Fire', 'J. K. Rowling', null);
        self::assertSame(['to-read', 'Harry Potter', 4.0, 2000], [$added['status'], $added['series'], $added['series_number'], $added['first_published']]);
        self::assertSame([], $parts->overview()[0]['missing']);

        // A book that is part 2 by its title doesn't also count as part 1 by its number.
        $this->db->exec("UPDATE books SET series = 'Harry Potter', series_number = 1 WHERE title = 'De geheime kamer'");
        $this->db->exec("UPDATE books SET series_number = NULL WHERE title = 'Harry Potter en de steen der wijzen'");
        $this->db->exec("UPDATE books SET title = 'Steen' WHERE title = 'Harry Potter en de steen der wijzen'");
        self::assertSame(['Harry Potter en de Steen der Wijzen'], array_column($parts->overview()[0]['missing'], 'title'));

        $page = $this->app->handle(new Request('GET', '/collections', ['series' => 'Harry Potter']))->body;
        self::assertStringContainsString('Je hebt 3 van de 4 delen.', $page);
    }

    public function testDismissedSuggestionsStayAway(): void
    {
        $asked = [];
        $parts = new SeriesParts($this->db, $this->app->books, $this->wikidata($asked));
        $this->book('Harry Potter en de Steen der Wijzen', ['author' => 'J.K. Rowling', 'series' => 'Harry Potter', 'series_number' => '1']);
        $parts->refresh('Harry Potter');
        self::assertCount(3, $parts->overview()[0]['missing']);
        $parts->dismiss('Q47209');
        self::assertSame(['Q102438', 'Q46751'], array_column($parts->overview()[0]['missing'], 'qid'));
    }

    public function testASeriesWikidataDoesntKnow(): void
    {
        $asked = [];
        $parts = new SeriesParts($this->db, $this->app->books, $this->wikidata($asked));
        $this->book('Deel een', ['author' => 'Niemand', 'series' => 'Onbekende reeks', 'series_number' => '1']);
        self::assertNull($parts->refresh('Onbekende reeks'));
        self::assertNull($parts->overview()[0]['qid']);
        self::assertSame([], $parts->toRefresh(10), 'not asked again every time');
    }

    public function testTheSeriesIdFromEnrichingIsUsed(): void
    {
        $id = $this->book('Gevangene', ['author' => 'J.K. Rowling', 'series' => 'Harry Potter', 'isbn' => '9789076174099']);
        $this->app->books->fillFromCatalog($this->app->books->find($id), ['series' => 'Harry Potter', 'wikidata_series' => 'Q8337']);
        self::assertSame('Q8337', $this->app->books->find($id)['wikidata_series']);
        $other = $this->book('Eigen', ['series' => 'Mijn reeks', 'isbn' => '9789076174098']);
        $this->app->books->fillFromCatalog($this->app->books->find($other), ['series' => 'Harry Potter', 'wikidata_series' => 'Q8337']);
        self::assertNull($this->app->books->find($other)['wikidata_series'], 'not for a series of your own');

        $asked = [];
        (new SeriesParts($this->db, $this->app->books, $this->wikidata($asked)))->refresh('Harry Potter');
        self::assertCount(1, $asked, 'no search needed');
    }

    public function testMergingEditions(): void
    {
        $keep = $this->book('De avonden', ['author' => 'Gerard Reve', 'isbn' => '9789023417069', 'tags' => 'klassiek', 'format' => 'paperback']);
        $gone = $this->book('De Avonden', ['author' => 'Gerard Reve', 'isbn' => '9789023417060', 'tags' => 'Klassiek, bieb', 'pages' => '319',
            'review' => 'Prachtig.', 'owned' => 1, 'format' => 'ebook']);
        $this->app->books->start($gone, '2024-01-01');
        $this->app->books->finish($gone, '2024-01-20', 4.5);
        $this->app->books->addNote($gone, ['kind' => 'quote', 'text' => 'Het is weer zondag.']);
        $this->app->loans->lend($gone, 'Anna', '2024-02-01');
        $this->db->exec("UPDATE books SET storygraph_id = 'sg-2', date_added = '2020-05-05' WHERE id = {$gone}");
        $this->db->exec("UPDATE books SET storygraph_id = 'sg-1' WHERE id = {$keep}");

        $this->post("/books/{$keep}/merge", ['other' => (string) $gone]);
        self::assertNull($this->app->books->find($gone));
        $book = $this->app->books->find($keep);
        self::assertSame(['read', 4.5, 319, 'klassiek, bieb', 'Prachtig.', true, '2020-05-05', 'paperback'],
            [$book['status'], $book['rating'], $book['pages'], $book['tags'], $book['review'], $book['owned'], $book['date_added'], $book['format']]);
        self::assertCount(1, $this->app->books->reads($keep));
        self::assertCount(1, $this->app->books->notes($keep));
        self::assertSame('Anna', $book['lent_to']);
        self::assertSame(1, $this->app->books->goalProgress(2024)['read']);

        // The other edition's ISBN and StoryGraph id still find this book.
        self::assertSame($keep, $this->app->books->findByIsbn('9789023417060')['id']);
        self::assertSame($keep, $this->app->books->findDuplicate('Iets anders', 'Iemand', '9789023417060')['id']);
        $csv = "Title,Authors,ISBN/UID,Format,Read Status,Date Added,Last Date Read,Dates Read,Read Count,Star Rating,Review,Owned?,Tags\n"
            . "De Avonden,Gerard Reve,9789023417060,digital,read,2020/05/05,2024/01/20,2024/01/01-2024/01/20,1,4.5,Prachtig.,Yes,bieb\n";
        $result = $this->app->books->import(StoryGraph::parse($csv));
        self::assertSame([], $result['imported'], 'a new import doesn\'t bring the merged edition back');
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn());

        $this->expectException(\InvalidArgumentException::class);
        $this->app->books->merge($keep, $keep);
    }

    public function testLikelyDuplicatesAndCandidates(): void
    {
        $a = $this->book('De avonden: een winterverhaal', ['author' => 'Gerard Reve']);
        $b = $this->book('De Avonden', ['author' => 'Reve, Gerard']);
        $c = $this->book('The Evenings', ['author' => 'Gerard Reve', 'original_title' => 'De avonden']);
        $this->book('De avonden', ['author' => 'Iemand Anders']);
        $this->book('Werther Nieland', ['author' => 'Gerard Reve']);
        $pairs = array_map(static fn (array $p) => [$p[0]['id'], $p[1]['id']], $this->app->books->likelyDuplicates());
        self::assertSame([[$a, $b], [$a, $c]], $pairs);

        $candidates = array_column($this->app->books->mergeCandidates($this->app->books->find($a)), 'id');
        self::assertContains($c, $candidates);
        self::assertNotContains($a, $candidates);
        self::assertStringContainsString('Deze bewaren', $this->app->handle(new Request('GET', '/duplicates'))->body);
    }
}
