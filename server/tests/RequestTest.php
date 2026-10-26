<?php

declare(strict_types=1);

namespace App\Tests;

use App\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testForwardedForIsIgnoredWithoutTrustedProxy(): void
    {
        self::assertSame('203.0.113.9', Request::clientIp('203.0.113.9', '1.2.3.4', []));
        self::assertSame('203.0.113.9', Request::clientIp('203.0.113.9', '1.2.3.4', ['172.16.0.0/12']));
    }

    public function testClientBehindTrustedProxy(): void
    {
        // A forged entry on the left is skipped: we take the right-most untrusted address.
        self::assertSame('198.51.100.7', Request::clientIp('172.18.0.1', '6.6.6.6, 198.51.100.7', ['172.16.0.0/12']));
        self::assertSame('2001:db8::1', Request::clientIp('::1', '2001:db8::1', ['::1']));
        self::assertSame('172.18.0.1', Request::clientIp('172.18.0.1', 'garbage', ['172.16.0.0/12']));
    }

    public function testInRanges(): void
    {
        self::assertTrue(Request::inRanges('172.31.255.1', ['172.16.0.0/12']));
        self::assertFalse(Request::inRanges('172.32.0.1', ['172.16.0.0/12']));
        self::assertTrue(Request::inRanges('10.0.0.5', ['10.0.0.5']));
        self::assertTrue(Request::inRanges('2001:db8:1::5', ['2001:db8::/32']));
        self::assertFalse(Request::inRanges('2001:db9::5', ['2001:db8::/32']));
        self::assertFalse(Request::inRanges('nonsense', ['0.0.0.0/0']));
    }
}
