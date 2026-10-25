<?php

declare(strict_types=1);

namespace App\Tests;

use App\StoryGraph;
use PHPUnit\Framework\TestCase;

final class StoryGraphTest extends TestCase
{
    public function testParse(): void
    {
        [$avonden, $hailMary, $dune, $moby] = StoryGraph::parse(Fixtures::STORYGRAPH_CSV);

        self::assertSame('read', $avonden['status']);
        self::assertSame(4.25, $avonden['rating']);
        self::assertSame('Mooi, "grijs" boek.', $avonden['review']);
        self::assertSame(1, $avonden['owned']);
        self::assertSame('2023-01-02', $avonden['date_added']);
        self::assertSame([['2023-01-05', '2023-01-20'], ['2024-03-01', '2024-03-09']], $avonden['reads']);

        self::assertSame('reading', $hailMary['status']);
        self::assertNull($hailMary['isbn'], 'an ASIN is not an ISBN');
        self::assertSame([['2024-05-02', null]], $hailMary['reads']);

        self::assertSame('to-read', $dune['status']);
        self::assertSame([], $dune['reads']);

        self::assertSame('dnf', $moby['status']);
        self::assertSame([[null, '2021-07-01']], $moby['reads']);
    }

    public function testSingleDateMeansFinishedForReadBooks(): void
    {
        self::assertSame([[null, '2023-01-20']], StoryGraph::parseDatesRead('2023/01/20', 'read'));
        self::assertSame([['2023-01-20', null]], StoryGraph::parseDatesRead('2023/01/20', 'reading'));
    }

    public function testParseDate(): void
    {
        self::assertSame('2023-01-05', StoryGraph::parseDate('2023/1/5'));
        self::assertSame('2023-01-05', StoryGraph::parseDate('05/01/2023'));
        self::assertNull(StoryGraph::parseDate('2023/02/30'));
        self::assertNull(StoryGraph::parseDate('gisteren'));
    }

    public function testRejectsOtherCsv(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StoryGraph::parse("foo,bar\n1,2\n");
    }
}
