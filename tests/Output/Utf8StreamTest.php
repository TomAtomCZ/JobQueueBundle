<?php

namespace TomAtom\JobQueueBundle\Tests\Output;

use PHPUnit\Framework\TestCase;
use TomAtom\JobQueueBundle\Output\Utf8Stream;

class Utf8StreamTest extends TestCase
{
    public function testCompleteChunksPassThrough(): void
    {
        $stream = new Utf8Stream();

        self::assertSame("P\u{159}\u{ED}li\u{161} \u{17E}lu\u{165}ou\u{10D}k\u{FD}\n", $stream->feed("P\u{159}\u{ED}li\u{161} \u{17E}lu\u{165}ou\u{10D}k\u{FD}\n"));
        self::assertSame('', $stream->finish());
    }

    public function testSplitCharacterIsHeldBackUntilComplete(): void
    {
        $stream = new Utf8Stream();
        $bytes = "ab\u{1F600}c"; // 4-byte character at offset 2

        $out = '';
        foreach (str_split($bytes) as $byte) {
            $chunk = $stream->feed($byte);
            self::assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'invalid chunk ' . bin2hex($chunk));
            $out .= $chunk;
        }
        $out .= $stream->finish();

        self::assertSame($bytes, $out);
    }

    /**
     * @dataProvider splitPoints
     */
    public function testEverySplitPointKeepsTheText(int $at): void
    {
        $text = "\u{17E}\u{20AC}\u{1F600}x\u{10D}";
        $stream = new Utf8Stream();

        $first = $stream->feed(substr($text, 0, $at));
        $second = $stream->feed(substr($text, $at));

        self::assertTrue(mb_check_encoding($first, 'UTF-8'));
        self::assertTrue(mb_check_encoding($second, 'UTF-8'));
        self::assertSame($text, $first . $second . $stream->finish());
    }

    public static function splitPoints(): array
    {
        return array_map(static fn(int $i) => [$i], range(0, strlen("\u{17E}\u{20AC}\u{1F600}x\u{10D}")));
    }

    public function testInvalidBytesAreReplaced(): void
    {
        $stream = new Utf8Stream();

        $chunk = $stream->feed("ok \xFF\xFE binary \xC5x");

        self::assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        self::assertStringStartsWith('ok ', $chunk);
        self::assertStringEndsWith(' binary ?x', $chunk);
    }

    public function testIncompleteCharacterAtTheEndIsReplacedOnFinish(): void
    {
        $stream = new Utf8Stream();

        self::assertSame('abc', $stream->feed("abc\xE2\x82"));
        $rest = $stream->finish();

        self::assertTrue(mb_check_encoding($rest, 'UTF-8'));
        self::assertNotSame('', $rest);
        self::assertSame('', $stream->finish());
    }

    public function testBoundary(): void
    {
        $text = "a\u{17E}b"; // 61 c5 be 62

        self::assertSame(1, Utf8Stream::boundary($text, 2));
        self::assertSame(3, Utf8Stream::boundary($text, 3));
        self::assertSame(4, Utf8Stream::boundary($text, 10));
        self::assertSame(0, Utf8Stream::boundary($text, -5));
    }
}
