<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Books;
use App\Config;
use App\Database;
use App\Request;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Pages per day (from the reading diary, or estimated), and pages per genre, shelf
 * and language.
 */
final class PagesTest extends TestCase
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
        foreach (['reading_log', 'posts', 'notes', 'loans', '`reads`', 'books', 'api_tokens', 'login_attempts', 'goals', 'settings', 'schema_migrations'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        Database::migrate($this->db, dirname(__DIR__) . '/migrations');
        $_SESSION = [];
        $this->app = new App($config, $this->db);
        $this->app->handle(new Request('POST', '/login', [], ['password' => 'geheim', '_csrf' => $this->app->auth->csrfToken()], ip: '192.0.2.8'));
    }

    private function book(string $title, int $pages, array $extra = []): int
    {
        return $this->app->books->create(Books::validate(['title' => $title, 'status' => 'to-read', 'pages' => (string) $pages] + $extra));
    }

    public function testTheDiaryCountsWhatYouReadEachDay(): void
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $id = $this->book('Tirza', 300);
        $this->app->books->start($id, $yesterday);
        $read = (int) $this->db->query("SELECT id FROM `reads` WHERE book_id = {$id}")->fetchColumn();
        $this->db->exec("INSERT INTO reading_log (read_id, day, page) VALUES ({$read}, '{$yesterday}', 40)");
        $this->app->books->setProgress($id, 90, null);
        $this->app->books->setProgress($id, 120, null); // the last update of the day counts
        self::assertSame([$yesterday => 40, $today => 80], $this->app->books->pagesPerDay($yesterday, $today)['days']);

        $this->app->books->finish($id, $today);
        self::assertSame([$yesterday => 40, $today => 260], $this->app->books->pagesPerDay($yesterday, $today)['days']);
    }

    public function testABookYouWereAlreadyReadingCountsFromWhereYouWere(): void
    {
        $id = $this->book('Het diner', 200);
        $this->app->books->start($id);
        $this->app->books->setProgress($id, 150, null);
        // As the migration does for books in progress: the diary starts at page 150.
        $this->db->exec('DELETE FROM reading_log');
        $this->db->exec("UPDATE `reads` SET log_from_page = 150 WHERE book_id = {$id}");
        $this->app->books->setProgress($id, 170, null);
        self::assertSame([date('Y-m-d') => 20], $this->app->books->pagesPerDay(date('Y-m-d'), date('Y-m-d'))['days']);
    }

    public function testReadsWithoutADiaryAreSpreadOut(): void
    {
        $id = $this->book('De avonden', 10);
        $this->app->books->addRead($id, '2024-03-01', '2024-03-04');
        $other = $this->book('Zonder begin', 50);
        $this->app->books->addRead($other, null, '2024-03-10');
        $dnf = $this->book('Half', 80);
        $this->app->books->addRead($dnf, '2024-03-01', '2024-03-02', dnf: true);
        $result = $this->app->books->pagesPerDay('2024-03-02', '2024-03-31');
        self::assertSame(['2024-03-02' => 2, '2024-03-03' => 3, '2024-03-04' => 3, '2024-03-10' => 50], $result['days'],
            '10 pages over 4 days: 2, 2, 3, 3; the 1st falls outside; DNF doesn\'t count');
        self::assertSame($result['days'], $result['estimated']);

        $year = $this->app->books->dailyPages(2024);
        self::assertSame(60, $year['total']);
        self::assertSame(60, $year['months'][3]);
        self::assertNull($year['best'], 'only real diary days are a "best day"');
        self::assertSame(round(60 / 366, 1), $year['average']);
    }

    public function testPagesPerGenreShelfAndLanguage(): void
    {
        $a = $this->book('Een', 300, ['genre' => 'fantasy, jeugd', 'tags' => 'bieb']);
        $b = $this->book('Twee', 200, ['genre' => 'Fantasy']);
        $c = $this->book('Drie', 100, ['tags' => 'bieb, cadeau']);
        foreach ([$a, $b, $c] as $id) {
            $this->app->books->addRead($id, '2025-05-01', '2025-05-20');
        }
        $this->db->exec("UPDATE books SET language = 'nl' WHERE id IN ({$a}, {$c})");
        $this->db->exec("UPDATE books SET language = 'en' WHERE id = {$b}");
        $groups = $this->app->books->pagesBreakdown(2025);
        self::assertSame(['Fantasy' => ['books' => 2, 'pages' => 500], 'jeugd' => ['books' => 1, 'pages' => 300], '(onbekend)' => ['books' => 1, 'pages' => 100]], $groups['genre']);
        self::assertSame(['bieb' => ['books' => 2, 'pages' => 400], '(onbekend)' => ['books' => 1, 'pages' => 200], 'cadeau' => ['books' => 1, 'pages' => 100]], $groups['shelf']);
        self::assertSame(['Nederlands' => ['books' => 2, 'pages' => 400], 'Engels' => ['books' => 1, 'pages' => 200]], $groups['language']);

        $page = $this->app->handle(new Request('GET', '/stats', ['year' => '2025']))->body;
        self::assertStringContainsString("Pagina's in 2025", $page);
        self::assertStringContainsString("Pagina's per dag in 2025", $page);
        self::assertStringContainsString('<strong>600</strong> pagina\'s gelezen', $page);
    }
}
