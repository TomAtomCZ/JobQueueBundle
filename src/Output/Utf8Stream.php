<?php

namespace TomAtom\JobQueueBundle\Output;

/**
 * Turns a byte stream read in arbitrary pieces into valid UTF-8 chunks.
 *
 * - A multi-byte character split between two reads is held back until its remaining bytes arrive,
 * - bytes which are not valid UTF-8 (binary or differently encoded output) are replaced,
 *
 * so every returned chunk can be stored in a utf8mb4 column of a strict MySQL (error 1366 "Incorrect string value"
 * otherwise) and the stored output never contains a broken character.
 */
final class Utf8Stream
{
    private string $carry = '';

    /**
     * Returns the valid UTF-8 part of the data read so far; an incomplete trailing character is kept for the next call.
     */
    public function feed(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bytes = $this->carry . $bytes;
        $complete = self::incompleteTailStart($bytes);
        $this->carry = (string)substr($bytes, $complete);

        return self::scrub(substr($bytes, 0, $complete));
    }

    /**
     * Returns the held back bytes (the stream ended - an incomplete character is replaced).
     */
    public function finish(): string
    {
        $carry = $this->carry;
        $this->carry = '';

        return self::scrub($carry);
    }

    /**
     * Replaces bytes which are not valid UTF-8.
     */
    public static function scrub(string $bytes): string
    {
        if ($bytes === '' || preg_match('//u', $bytes) === 1) {
            return $bytes;
        }

        return mb_scrub($bytes, 'UTF-8');
    }

    /**
     * Largest length <= $length at which $bytes can be cut without splitting a UTF-8 character.
     */
    public static function boundary(string $bytes, int $length): int
    {
        $length = max(0, min($length, strlen($bytes)));
        // A cut before a continuation byte (10xxxxxx) would split a character
        while ($length > 0 && $length < strlen($bytes) && (ord($bytes[$length]) & 0xC0) === 0x80) {
            $length--;
        }

        return $length;
    }

    /**
     * Offset at which an incomplete trailing multi-byte character starts (strlen when the data ends on a whole character).
     */
    private static function incompleteTailStart(string $bytes): int
    {
        $length = strlen($bytes);
        // A character has at most 4 bytes: look at the last 3 bytes for the lead byte of an unfinished one
        for ($i = $length - 1; $i >= 0 && $i >= $length - 3; $i--) {
            $byte = ord($bytes[$i]);
            if (($byte & 0xC0) === 0x80) {
                continue; // continuation byte, keep looking for the lead byte
            }

            $needed = match (true) {
                $byte >= 0xC2 && $byte <= 0xDF => 2,
                $byte >= 0xE0 && $byte <= 0xEF => 3,
                $byte >= 0xF0 && $byte <= 0xF4 => 4,
                default => 1, // ASCII or an invalid byte - nothing to wait for
            };

            return $length - $i < $needed ? $i : $length;
        }

        return $length;
    }
}
