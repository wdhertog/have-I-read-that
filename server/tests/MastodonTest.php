<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Mastodon;
use PHPUnit\Framework\TestCase;

final class MastodonTest extends TestCase
{
    public function testStars(): void
    {
        self::assertSame('★★★★½', Mastodon::stars(4.25));
        self::assertSame('★★★☆☆', Mastodon::stars(3));
        self::assertSame('', Mastodon::stars(null));
    }

    public function testCompose(): void
    {
        $text = Mastodon::compose(Config::DEFAULT_TEMPLATE_FINISHED, [
            'title' => 'De avonden', 'author' => 'Gerard Reve', 'rating' => 4.5, 'review' => 'Prachtig.',
        ]);
        self::assertSame("📖 Uitgelezen: De avonden – Gerard Reve\n★★★★½\n\nPrachtig.\n\n#boeken #bookstodon", $text);
    }

    public function testComposeWithoutReviewHasNoBlankGap(): void
    {
        $text = Mastodon::compose(Config::DEFAULT_TEMPLATE_FINISHED, ['title' => 'T', 'author' => 'A']);
        self::assertStringNotContainsString("\n\n\n", $text);
    }

    public function testComposeTruncatesLongReview(): void
    {
        $text = Mastodon::compose(Config::DEFAULT_TEMPLATE_FINISHED, ['title' => 'T', 'author' => 'A', 'review' => str_repeat('é', 1000)]);
        self::assertLessThanOrEqual(500, mb_strlen($text));
        self::assertStringContainsString('…', $text);
        self::assertStringEndsWith('#bookstodon', $text);
    }

    public function testInstanceGetsScheme(): void
    {
        self::assertSame('https://mastodon.social', (new Mastodon('mastodon.social/', 't'))->instance());
    }
}
