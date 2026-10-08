<?php

namespace TomAtom\JobQueueBundle\Tests\Output;

use PHPUnit\Framework\TestCase;
use TomAtom\JobQueueBundle\Output\OutputParamsParser;

class OutputParamsParserTest extends TestCase
{
    public function testParamsAreAccumulatedAcrossChunks(): void
    {
        $parser = new OutputParamsParser();
        $parser->feed("foo\nOUTPUT PARAMS: 123\n");
        $parser->feed(" [INFO] OUTPUT PARAMS: some text value   \nbar\n");
        $parser->feed("OUTPUT PARAMS: ab\n");
        $parser->finish();

        self::assertSame(['123', 'some text value', 'ab'], $parser->getParams());
        self::assertSame('123, some text value, ab', $parser->getOutputParams());
    }

    public function testLineSplitBetweenChunksIsFound(): void
    {
        $parser = new OutputParamsParser();
        $parser->feed("before\nOUTPUT PAR");
        $parser->feed('AMS: 4');
        $parser->feed("2\nafter\n");
        $parser->finish();

        self::assertSame('42', $parser->getOutputParams());
    }

    public function testTrailingLineWithoutNewlineIsParsedOnFinish(): void
    {
        $parser = new OutputParamsParser();
        $parser->feed('OUTPUT PARAMS: last');
        self::assertNull($parser->getOutputParams());

        $parser->finish();
        self::assertSame('last', $parser->getOutputParams());
    }

    public function testNoParams(): void
    {
        $parser = new OutputParamsParser();
        $parser->feed("nothing here\n");
        $parser->finish();

        self::assertSame([], $parser->getParams());
        self::assertNull($parser->getOutputParams());
    }

    public function testWindowsLineEndingsAreTrimmed(): void
    {
        $parser = new OutputParamsParser();
        $parser->feed("OUTPUT PARAMS: 7\r\n");
        $parser->finish();

        self::assertSame('7', $parser->getOutputParams());
    }

    public function testHugeOutputWithoutNewlineKeepsMemoryBoundedAndStillFindsSplitMarker(): void
    {
        $parser = new OutputParamsParser();
        $parser->feed(str_repeat('x', 200000) . 'OUTPUT PA');
        $parser->feed("RAMS: found\n");
        $parser->finish();

        self::assertSame('found', $parser->getOutputParams());
    }
}
