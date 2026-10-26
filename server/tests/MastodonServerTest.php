<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mastodon;
use App\MastodonException;
use PHPUnit\Framework\TestCase;

/**
 * Talks to a real HTTP server (tests/fixtures/fake-mastodon.php) to check what
 * actually goes over the wire.
 */
final class MastodonServerTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $url = '';
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::$log = tempnam(sys_get_temp_dir(), 'masto');
        $port = 18000 + random_int(0, 999);
        self::$url = "http://127.0.0.1:{$port}";
        $env = ['FAKE_MASTODON_LOG' => self::$log] + getenv();
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/fake-mastodon.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env,
        );
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
        }
        @unlink(self::$log);
    }

    protected function setUp(): void
    {
        self::clearLog();
    }

    public static function clearLog(): void
    {
        file_put_contents(self::$log, '');
    }

    /** @return list<array<string, mixed>> */
    public static function requests(): array
    {
        return array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents(self::$log))));
    }

    public static function client(): Mastodon
    {
        return new Mastodon(self::$url, 'test-token', 'unlisted');
    }

    public static function url(): string
    {
        return self::$url;
    }

    public function testUploadWaitsForProcessingAndPostAttachesMedia(): void
    {
        $client = self::client();
        $id = $client->uploadMedia("\xFF\xD8fake-jpeg", 'image/jpeg', 'Omslag van Tirza');
        self::assertSame('10987654321', $id);
        self::assertSame('https://masto.example/@me/1', $client->post('Hallo', 'key', [$id]));

        [$upload, $poll, $status] = self::requests();
        self::assertSame('/api/v2/media', $upload['path']);
        self::assertSame('Omslag van Tirza', $upload['post']['description']);
        self::assertSame(['name' => 'image.jpg', 'type' => 'image/jpeg', 'size' => 11], $upload['files']['file']);
        self::assertSame('/api/v1/media/10987654321', $poll['path']);
        // Rails needs media_ids[]=…, so the large id stays a string and an array.
        self::assertStringContainsString('media_ids%5B%5D=10987654321', $status['raw']);
        self::assertSame(['10987654321'], $status['post']['media_ids']);
        self::assertSame('unlisted', $status['post']['visibility']);
    }

    public function testUploadErrorsAreReported(): void
    {
        $this->expectException(MastodonException::class);
        self::client()->uploadMedia('x', 'image/jpeg', 'fail');
    }
}
