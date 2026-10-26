<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Backup;
use App\Books;
use App\Config;
use App\Covers;
use App\Database;
use App\Request;
use App\Response;
use App\Totp;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers on our own server, back-ups, two-step verification and read-only tokens.
 */
final class StepFourTest extends TestCase
{
    private PDO $db;
    private App $app;
    private string $coverDir;

    protected function setUp(): void
    {
        $this->coverDir = sys_get_temp_dir() . '/hirt-covers-' . bin2hex(random_bytes(4));
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
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->coverDir . '/*') ?: []);
        @rmdir($this->coverDir);
    }

    private function config(): Config
    {
        return new Config([
            'APP_PASSWORD' => 'geheim',
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) getenv('TEST_DB_USER'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'COVERS_DIR' => $this->coverDir,
        ]);
    }

    private function post(string $path, array $data = [], array $query = []): Response
    {
        return $this->app->handle(new Request('POST', $path, $query, $data + ['_csrf' => $this->app->auth->csrfToken()], ip: '192.0.2.1'));
    }

    private static function jpeg(int $width = 300, int $height = 450, bool $withExif = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        // A fake APP1/Exif segment right after the SOI marker, like a phone photo's GPS data.
        return $withExif ? substr($bytes, 0, 2) . "\xFF\xE1\x00\x10Exif\x00\x00GPS-SECRET" . substr($bytes, 2) : $bytes;
    }

    private function addBook(string $title, string $status, ?string $isbn = null): int
    {
        return $this->app->books->create(Books::validate(['title' => $title, 'status' => $status, 'isbn' => $isbn]));
    }

    // --- covers ---------------------------------------------------------------

    public function testCoversAreFetchedOnceAndKept(): void
    {
        $calls = 0;
        $covers = new Covers($this->coverDir, function () use (&$calls) {
            $calls++;
            return ['bytes' => self::jpeg(), 'mime' => 'image/jpeg'];
        });
        $book = ['id' => 1, 'isbn' => '9789038801969'];
        self::assertSame('image/jpeg', $covers->image($book)['mime']);
        $covers->image($book);
        self::assertSame(1, $calls);

        $none = new Covers($this->coverDir, function () use (&$calls) {
            $calls++;
            return null;
        });
        self::assertNull($none->image(['id' => 2, 'isbn' => '9780000000002']));
        self::assertNull($none->image(['id' => 2, 'isbn' => '9780000000002']));
        self::assertSame(2, $calls, 'a missing cover is not asked for again right away');
    }

    public function testUploadedCoverIsReencodedAndWins(): void
    {
        $covers = new Covers($this->coverDir, static fn () => null);
        $file = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($file, self::jpeg(1200, 1800, withExif: true));
        $covers->saveUpload(7, $file);
        $image = $covers->image(['id' => 7, 'isbn' => null]);
        self::assertStringNotContainsString('GPS-SECRET', $image['bytes']);
        self::assertSame([600, 900], array_slice(getimagesizefromstring($image['bytes']), 0, 2));

        file_put_contents($file, '<?php echo "hi"; ?>');
        $this->expectException(\InvalidArgumentException::class);
        try {
            $covers->saveUpload(7, $file);
        } finally {
            unlink($file);
        }
    }

    public function testCoverAccess(): void
    {
        $public = $this->addBook('Gelezen', 'read');
        $private = $this->addBook('Verlanglijst', 'to-read');
        $covers = new Covers($this->coverDir, static fn () => null);
        $file = tempnam(sys_get_temp_dir(), 'up');
        foreach ([$public, $private] as $id) {
            file_put_contents($file, self::jpeg());
            $covers->saveUpload($id, $file);
        }
        unlink($file);

        $response = $this->app->handle(new Request('GET', "/cover/{$public}"));
        self::assertSame([200, 'image/jpeg'], [$response->status, $response->headers['Content-Type']]);
        self::assertStringStartsWith('public', $response->headers['Cache-Control']);

        self::assertSame(404, $this->app->handle(new Request('GET', "/cover/{$private}"))->status);
        $token = $this->app->auth->createToken('app', 'read');
        $withToken = $this->app->handle(new Request('GET', "/cover/{$private}", headers: ['authorization' => "Bearer {$token}"]));
        self::assertSame(200, $withToken->status);
        self::assertStringStartsWith('private', $withToken->headers['Cache-Control']);

        // Pages and the API point at our own server; the CSP no longer allows other image hosts.
        $json = json_decode($this->app->handle(new Request('GET', "/api/books/{$private}", headers: ['authorization' => "Bearer {$token}"]))->body, true);
        self::assertStringContainsString("/cover/{$private}?v=u", $json['book']['cover_url']);
        self::assertStringNotContainsString('https:', $response->headers['Content-Security-Policy']);
    }

    // --- back-up --------------------------------------------------------------

    public function testBackupRestoresEverything(): void
    {
        $this->addBook("Titel met 'quotes' en \\ backslash", 'read');
        $this->app->auth->createToken('app');
        $sql = (new Backup($this->db))->toString();
        self::assertStringContainsString('INSERT INTO `books`', $sql);
        $before = $this->db->query('SELECT title, status FROM books')->fetchAll();

        $this->db->exec('DELETE FROM books');
        $this->db->exec($sql);
        self::assertSame($before, $this->db->query('SELECT title, status FROM books')->fetchAll());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn());

        $dir = sys_get_temp_dir() . '/hirt-backups-' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 3; $i++) {
            touch((new Backup($this->db))->rotate($dir, 2), time() - $i);
            sleep(1); // file names carry seconds
        }
        $files = glob($dir . '/*.sql.gz');
        self::assertCount(2, $files);
        self::assertStringContainsString('INSERT INTO `books`', gzdecode((string) file_get_contents($files[0])));
        array_map('unlink', $files);
        rmdir($dir);
    }

    // --- two-step verification ------------------------------------------------

    public function testTotpMatchesRfc6238(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        self::assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        self::assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        self::assertSame(intdiv(1111111109, 30), Totp::verify($secret, '081 804', 0, 1111111109));
        self::assertNull(Totp::verify($secret, '081804', intdiv(1111111109, 30), 1111111109), 'a code works once');
        self::assertNull(Totp::verify($secret, '000000', 0, 1111111109));
    }

    public function testLoginWithSecondStep(): void
    {
        $secret = Totp::generateSecret();
        $recovery = $this->app->auth->enableTwoFactor($secret);

        $response = $this->post('/login', ['password' => 'geheim'], ['next' => '/stats']);
        self::assertSame('/login/code?next=%2Fstats', $response->headers['Location']);
        self::assertFalse($this->app->auth->loggedIn(), 'the password alone does not log you in');
        self::assertSame(302, $this->app->handle(new Request('GET', '/books'))->status);

        $this->post('/login/code', ['code' => '000000']);
        self::assertFalse($this->app->auth->loggedIn());

        $code = Totp::code($secret, intdiv(time(), 30));
        self::assertSame('/stats', $this->post('/login/code', ['code' => $code], ['next' => '/stats'])->headers['Location']);
        self::assertTrue($this->app->auth->loggedIn());

        // The same code can't be used again, a recovery code works exactly once.
        $this->app->auth->logout();
        $this->post('/login', ['password' => 'geheim']);
        $this->post('/login/code', ['code' => $code]);
        self::assertFalse($this->app->auth->loggedIn());
        $this->post('/login/code', ['code' => strtoupper($recovery[0])]);
        self::assertTrue($this->app->auth->loggedIn());
        self::assertSame(7, $this->app->auth->recoveryCodesLeft());
        $this->app->auth->logout();
        $this->post('/login', ['password' => 'geheim']);
        $this->post('/login/code', ['code' => $recovery[0]]);
        self::assertFalse($this->app->auth->loggedIn());

        // The code step can't be skipped by going straight to the code form.
        $_SESSION = [];
        $this->post('/login/code', ['code' => Totp::code($secret, intdiv(time(), 30) + 1)]);
        self::assertFalse($this->app->auth->loggedIn());
    }

    public function testSettingUpTwoFactorFromSettings(): void
    {
        $this->post('/login', ['password' => 'geheim']);
        $page = $this->post('/settings/2fa/start')->body;
        self::assertStringContainsString('data-uri="otpauth://totp/', $page);
        $secret = $_SESSION['totp_setup'];
        $page = $this->post('/settings/2fa/enable', ['code' => Totp::code($secret, intdiv(time(), 30))])->body;
        self::assertTrue($this->app->auth->twoFactorEnabled());
        self::assertTrue($this->app->auth->loggedIn(), 'turning it on keeps you logged in');
        self::assertMatchesRegularExpression('/[a-z2-7]{4}-[a-z2-7]{4}-[a-z2-7]{4}/', $page);
    }

    // --- tokens ---------------------------------------------------------------

    public function testReadOnlyToken(): void
    {
        $token = $this->app->auth->createToken('widget', 'read');
        $auth = ['authorization' => "Bearer {$token}"];
        self::assertSame(200, $this->app->handle(new Request('GET', '/api/books', headers: $auth))->status);
        $response = $this->app->handle(new Request('POST', '/api/books', headers: $auth, body: '{"title":"X"}'));
        self::assertSame(403, $response->status);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM books')->fetchColumn());
    }
}
