<?php

namespace TomAtom\JobQueueBundle\Tests\Output;

use PHPUnit\Framework\TestCase;
use TomAtom\JobQueueBundle\Output\OutputLimiter;

class OutputLimiterTest extends TestCase
{
    public function testChunksBelowCapPassThrough(): void
    {
        $limiter = new OutputLimiter(10);

        self::assertSame('abc', $limiter->accept('abc'));
        self::assertSame('defghij', $limiter->accept('defghij'));
        self::assertSame(10, $limiter->getUsedBytes());
        self::assertFalse($limiter->isTruncated());
    }

    public function testCapCutsChunkAndEmitsMarkerOnce(): void
    {
        $limiter = new OutputLimiter(10);

        self::assertSame('12345678', $limiter->accept('12345678'));
        self::assertSame('90' . OutputLimiter::marker(10), $limiter->accept('90abcdef'));
        self::assertTrue($limiter->isTruncated());
        self::assertSame('', $limiter->accept('more'));
        self::assertSame('', $limiter->accept('and more'));
        self::assertSame(10, $limiter->getUsedBytes());
    }

    public function testMarkerMentionsCap(): void
    {
        self::assertStringContainsString('output truncated by JobQueueBundle at 4194304 bytes', OutputLimiter::marker(4194304));
    }

    public function testZeroMeansUnlimited(): void
    {
        $limiter = new OutputLimiter(0);
        $big = str_repeat('x', 100000);

        self::assertSame($big, $limiter->accept($big));
        self::assertSame($big, $limiter->accept($big));
        self::assertFalse($limiter->isTruncated());
    }

    public function testAlreadyStoredOutputCountsTowardsCap(): void
    {
        $limiter = new OutputLimiter(10, 8);

        self::assertSame('ab' . OutputLimiter::marker(10), $limiter->accept('abc'));
    }

    public function testAlreadyOverCapOnlyEmitsMarker(): void
    {
        $limiter = new OutputLimiter(10, 50);

        self::assertSame(OutputLimiter::marker(10), $limiter->accept('abc'));
        self::assertSame('', $limiter->accept('abc'));
    }

    public function testCapNeverSplitsMultiByteCharacter(): void
    {
        // "abcd" + 2-byte "ž" straddles the cap of 5 bytes
        $limiter = new OutputLimiter(5);

        $stored = $limiter->accept("abcd\u{17E}efgh");

        self::assertSame('abcd' . OutputLimiter::marker(5), $stored);
        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
        self::assertSame(4, $limiter->getUsedBytes());
    }

    /**
     * @dataProvider multiByteCaps
     */
    public function testCapKeepsWholeCharactersOfEveryLength(int $cap, string $expectedHead): void
    {
        // 1 + 2 + 3 + 4 byte characters
        $limiter = new OutputLimiter($cap);

        $stored = $limiter->accept("a\u{17E}\u{20AC}\u{1F600}z");

        self::assertSame($expectedHead . OutputLimiter::marker($cap), $stored);
        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    public static function multiByteCaps(): array
    {
        return [
            [1, 'a'],
            [2, 'a'],
            [3, "a\u{17E}"],
            [5, "a\u{17E}"],
            [6, "a\u{17E}\u{20AC}"],
            [9, "a\u{17E}\u{20AC}"],
            [10, "a\u{17E}\u{20AC}\u{1F600}"],
        ];
    }

    public function testEmptyChunk(): void
    {
        $limiter = new OutputLimiter(1);

        self::assertSame('', $limiter->accept(''));
        self::assertFalse($limiter->isTruncated());
    }
}
