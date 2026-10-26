<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Books;
use App\Config;
use App\Database;
use App\Request;
use App\Tooter;
use App\YearReview;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers on toots, the year review, public book pages and the feed.
 */
final class StepThreeTest extends TestCase
{
    private PDO $db;
    private Books $books;

    public static function setUpBeforeClass(): void
    {
        MastodonServerTest::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        MastodonServerTest::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $config = $this->config();
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
        $this->books = new Books($this->db);
        $_SESSION = [];
        MastodonServerTest::clearLog();
    }

    /** @param array<string, string> $extra */
    private function config(array $extra = []): Config
    {
        return new Config($extra + [
            'APP_PASSWORD' => 'geheim',
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'COVERS_DIR' => sys_get_temp_dir() . '/hirt-test-covers',
        ]);
    }

    private function addBook(string $title, string $status = 'read', array $extra = []): int
    {
        return $this->books->create(Books::validate(['title' => $title, 'author' => 'Auteur', 'status' => $status] + $extra), null, $extra['finished'] ?? null);
    }

    /** A real image, as Open Library would return it. */
    private static function cover(int $width = 180, int $height = 270): array
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 60));
        ob_start();
        imagejpeg($image);
        return ['bytes' => (string) ob_get_clean(), 'mime' => 'image/jpeg'];
    }

    public function testTootCarriesCoverWithAltText(): void
    {
        $id = $this->addBook('Tirza', 'read', ['isbn' => '9789038801969']);
        $tooter = new Tooter($this->books, MastodonServerTest::client(), $this->config(), static fn () => self::cover());
        $tooter->post($id, 'finished');

        $requests = MastodonServerTest::requests();
        $upload = array_values(array_filter($requests, static fn ($r) => $r['path'] === '/api/v2/media'))[0];
        self::assertSame('Omslag van Tirza door Auteur', $upload['post']['description']);
        $status = array_values(array_filter($requests, static fn ($r) => $r['path'] === '/api/v1/statuses'))[0];
        self::assertSame(['10987654321'], $status['post']['media_ids']);
    }

    public function testTootWithoutCoverOrWithFailedUploadStillPosts(): void
    {
        $id = $this->addBook('Geen omslag', 'read', ['isbn' => '9789038801969']);
        $config = $this->config();
        (new Tooter($this->books, MastodonServerTest::client(), $config, static fn () => null))->post($id, 'finished');
        self::assertSame(['/api/v1/statuses'], array_column(MastodonServerTest::requests(), 'path'));

        $noCover = new Tooter($this->books, MastodonServerTest::client(), $this->config(['MASTODON_ATTACH_COVER' => 'false']), static fn () => self::cover());
        self::assertNotNull($noCover->post($id, 'finished'));
    }

    public function testUrlPlaceholder(): void
    {
        $id = $this->addBook('Gelezen');
        $private = $this->addBook('Verlanglijst', 'to-read');
        $template = ['MASTODON_TEMPLATE_FINISHED' => '{title} {url}', 'APP_URL' => 'https://lezen.example/'];
        $tooter = new Tooter($this->books, MastodonServerTest::client(), $this->config($template), static fn () => null);
        self::assertSame("Gelezen https://lezen.example/boek/{$id}", $tooter->compose($this->books->find($id), 'finished'));
        self::assertSame('Verlanglijst', $tooter->compose($this->books->find($private), 'finished'));
    }

    public function testYearReview(): void
    {
        $this->addBook('Matig', 'read', ['rating' => '3', 'pages' => '200', 'isbn' => '9780000000001', 'finished' => '2025-03-01']);
        $this->addBook('Topper', 'read', ['rating' => '5', 'pages' => '1100', 'isbn' => '9780000000002', 'finished' => '2025-06-01']);
        $this->addBook('Zonder cover', 'read', ['finished' => '2025-07-01']);
        $this->addBook('Vorig jaar', 'read', ['rating' => '5', 'finished' => '2024-07-01']);

        $fetched = [];
        $review = new YearReview($this->books, MastodonServerTest::client(), static function (array $book) use (&$fetched) {
            if (!$book['isbn']) {
                return null;
            }
            $fetched[] = $book['isbn'];
            return self::cover($book['isbn'] === '9780000000001' ? 400 : 180, 270);
        });
        $text = $review->compose(2025);
        self::assertStringContainsString("3 boeken, 1.300 pagina's", $text);
        self::assertStringContainsString('Favoriet: Topper – Auteur ★★★★★', $text);

        $collage = $review->collage(2025);
        self::assertSame(['9780000000002', '9780000000001'], $fetched);
        $size = getimagesizefromstring($collage['bytes']);
        self::assertSame([400, 300, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
        self::assertStringContainsString('2 boeken die ik in 2025 las: Topper (Auteur), Matig (Auteur)', $collage['alt']);

        $review->post(2025);
        $status = array_values(array_filter(MastodonServerTest::requests(), static fn ($r) => $r['path'] === '/api/v1/statuses'))[0];
        self::assertSame(['10987654321'], $status['post']['media_ids']);
        self::assertStringContainsString('#leesjaar2025', $status['post']['status']);
    }

    public function testPublicBookPageAndFeed(): void
    {
        $read = $this->addBook('Gelezen <boek>', 'read', ['review' => 'Mooi & spannend', 'isbn' => '9789038801969', 'finished' => '2025-05-05']);
        $wish = $this->addBook('Verlanglijst', 'to-read');
        $app = new App($this->config(['APP_URL' => 'https://lezen.example']), $this->db);

        $page = $app->handle(new Request('GET', "/boek/{$read}"));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<meta property="og:title" content="Gelezen &lt;boek&gt;">', $page->body);
        self::assertStringContainsString("content=\"https://lezen.example/boek/{$read}\"", $page->body);
        self::assertStringContainsString("content=\"https://lezen.example/cover/{$read}?v=9789038801969\"", $page->body);
        self::assertArrayNotHasKey('Cache-Control', $page->headers);
        self::assertSame(404, $app->handle(new Request('GET', "/boek/{$wish}"))->status);

        $feed = $app->handle(new Request('GET', '/feed.xml'));
        self::assertSame('application/rss+xml; charset=utf-8', $feed->headers['Content-Type']);
        $xml = simplexml_load_string($feed->body);
        self::assertCount(1, $xml->channel->item);
        self::assertSame('Gelezen <boek> – Auteur', (string) $xml->channel->item[0]->title);
        self::assertSame("https://lezen.example/boek/{$read}", (string) $xml->channel->item[0]->link);
        self::assertStringContainsString('Mooi & spannend', (string) $xml->channel->item[0]->description);

        $closed = new App($this->config(['PUBLIC_PAGE' => 'false']), $this->db);
        self::assertSame(404, $closed->handle(new Request('GET', "/boek/{$read}"))->status);
        self::assertSame(404, $closed->handle(new Request('GET', '/feed.xml'))->status);
    }
}
