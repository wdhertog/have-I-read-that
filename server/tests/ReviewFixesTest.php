<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Backup;
use App\Books;
use App\Config;
use App\Database;
use App\Request;
use App\Response;
use App\Totp;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fixes from a review: stats that survive reading a book again, statuses changed by
 * editing, series numbers in the form, the API's detail fields, odd catalogue
 * numbers, and two-step verification and login attempts that can't be abused.
 */
final class ReviewFixesTest extends TestCase
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

    private function post(string $path, array $data = [], string $ip = '192.0.2.7'): Response
    {
        return $this->app->handle(new Request('POST', $path, [], $data + ['_csrf' => $this->app->auth->csrfToken()], ip: $ip));
    }

    private function book(string $title, array $extra = []): int
    {
        return $this->app->books->create(Books::validate(['title' => $title, 'status' => 'to-read'] + $extra));
    }

    public function testReadingAgainKeepsLastYearsRead(): void
    {
        $id = $this->book('Tirza');
        $this->app->books->start($id, '2025-01-02');
        $this->app->books->finish($id, '2025-02-03');
        self::assertSame(1, $this->app->books->goalProgress(2025)['read']);
        $this->app->books->start($id); // "Opnieuw lezen"
        self::assertSame(1, $this->app->books->goalProgress(2025)['read'], 'the earlier read still counts');
        self::assertSame(['2025'], array_column($this->app->books->years(), 'year'));
        self::assertCount(1, $this->app->books->readInYear(2025));

        // Stopping halfway doesn't count, now or later.
        $other = $this->book('Het diner');
        $this->app->books->start($other, '2025-03-01');
        $this->app->books->finish($other, '2025-03-05', dnf: true);
        self::assertSame(1, $this->app->books->goalProgress(2025)['read']);
    }

    public function testStatusChangedByEditingFollowsTheReads(): void
    {
        $id = $this->book('De avonden');
        $edit = fn (string $status) => $this->app->books->update($id, Books::validate(['title' => 'De avonden', 'status' => $status]));
        $year = (int) date('Y');

        $edit('read');
        self::assertSame(1, $this->app->books->goalProgress($year)['read'], 'marking as read counts it');
        $edit('dnf');
        self::assertSame(0, $this->app->books->goalProgress($year)['read'], 'and changing it to DNF no longer');
        $edit('read');
        self::assertSame(1, $this->app->books->goalProgress($year)['read']);
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM `reads` WHERE book_id = {$id}")->fetchColumn(), 'no extra reads');

        $edit('reading');
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM `reads` WHERE book_id = {$id} AND finished IS NULL")->fetchColumn());
    }

    public function testSeriesNumberSurvivesTheEditForm(): void
    {
        foreach ([['10', '10'], ['20', '20'], ['2.5', '2,5'], ['3', '3'], ['100', '100']] as [$number, $shown]) {
            $id = $this->book("Deel {$shown}", ['series' => 'Reeks', 'series_number' => $number]);
            $form = $this->app->handle(new Request('GET', "/books/{$id}/edit"))->body;
            self::assertStringContainsString('name="series_number" value="' . $shown . '"', $form);
        }
    }

    public function testApiSendsTheDetailFields(): void
    {
        $id = $this->book('1984', ['genre' => 'dystopie', 'first_published' => '1949', 'original_language' => 'en']);
        $token = $this->app->auth->createToken('test', 'read');
        $book = json_decode($this->app->handle(new Request('GET', "/api/books/{$id}", headers: ['authorization' => "Bearer {$token}"]))->body, true)['book'];
        self::assertSame(['dystopie', 1949, 'en', null], [$book['genre'], $book['first_published'], $book['original_language'], $book['description']]);
    }

    public function testOddCatalogueNumbersAreSkipped(): void
    {
        $id = $this->book('Raar', ['isbn' => '9789023417069']);
        $filled = $this->app->books->fillFromCatalog($this->app->books->find($id), [
            'pages' => 10_000_000, 'year' => -350, 'first_published' => -350, 'series' => 'Reeks', 'series_number' => 123456.0, 'genre' => 2020,
        ]);
        $book = $this->app->books->find($id);
        self::assertSame([null, null, -350, 'Reeks', null, '2020'], [$book['pages'], $book['published_year'], $book['first_published'], $book['series'], $book['series_number'], $book['genre']]);
        self::assertNotContains("pagina's", $filled);
        self::assertSame(0, $this->app->books->countToEnrich(), 'marked as done anyway');
    }

    public function testTwoFactorCantBeReplacedWithoutTheCurrentCode(): void
    {
        $secret = Totp::generateSecret();
        $this->app->auth->enableTwoFactor($secret);
        $this->post('/settings/2fa/start');
        self::assertArrayNotHasKey('totp_setup', $_SESSION);
        $_SESSION['totp_setup'] = $other = Totp::generateSecret();
        $this->post('/settings/2fa/enable', ['code' => Totp::code($other, intdiv(time(), 30))]);
        self::assertTrue($this->app->auth->verifySecondFactor(Totp::code($secret, intdiv(time(), 30))), 'the old authenticator still works');

        // A code works once, also when it arrives twice.
        self::assertFalse($this->app->auth->verifySecondFactor(Totp::code($secret, intdiv(time(), 30))));

        // Guessing the code to turn it off runs into the same limit as logging in.
        for ($i = 0; $i < 12; $i++) {
            $this->post('/settings/2fa/disable', ['code' => '000000'], '198.51.100.9');
        }
        $this->post('/settings/2fa/disable', ['code' => Totp::code($secret, intdiv(time(), 30) + 1)], '198.51.100.9');
        self::assertTrue($this->app->auth->twoFactorEnabled());
    }

    public function testLockedOutAttemptsAreNotStored(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->app->auth->registerAttempt('203.0.113.5');
        }
        self::assertLessThanOrEqual(11, (int) $this->db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        self::assertFalse($this->app->auth->registerAttempt('203.0.113.5'));
    }

    public function testBackupLeavesOutTheSecondFactor(): void
    {
        $this->app->auth->enableTwoFactor(Totp::generateSecret());
        $sql = (new Backup($this->db))->toString();
        self::assertStringNotContainsString("'totp_secret'", $sql);
        self::assertStringNotContainsString("'recovery_codes'", $sql);
    }

    public function testLoanDaysAreCalendarDays(): void
    {
        $id = $this->book('Uitgeleend', ['owned' => 1]);
        $this->app->loans->lend($id, 'Anna', '2026-03-01');
        $this->app->loans->returnBook($id, '2026-04-01'); // across the change to summer time
        self::assertSame(31, $this->app->loans->byPerson()[0]['history'][0]['days']);
    }
}
