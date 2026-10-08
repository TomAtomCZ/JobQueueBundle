<?php

namespace TomAtom\JobQueueBundle\Output;

/**
 * Caps the amount of command output stored for one job.
 *
 * Output beyond the cap is dropped (the head of the output is kept) and a truncation marker is emitted exactly once.
 * The cut never splits a UTF-8 character (a broken character cannot be stored in a utf8mb4 column of a strict MySQL),
 * so the stored head may be up to 3 bytes shorter than the cap.
 */
final class OutputLimiter
{
    private bool $truncated = false;

    /**
     * @param int $maxBytes Maximum stored output in bytes, 0 = unlimited
     * @param int $usedBytes Output already stored for the job (e.g. from a previous run)
     */
    public function __construct(private readonly int $maxBytes, private int $usedBytes = 0)
    {
    }

    /**
     * Returns the part of the chunk which may still be stored, including the truncation marker once the cap is hit.
     */
    public function accept(string $chunk): string
    {
        if ($chunk === '' || $this->truncated) {
            return '';
        }

        $length = strlen($chunk);
        if ($this->maxBytes <= 0 || $this->usedBytes + $length <= $this->maxBytes) {
            $this->usedBytes += $length;
            return $chunk;
        }

        $this->truncated = true;
        $room = Utf8Stream::boundary($chunk, $this->maxBytes - $this->usedBytes);
        $head = substr($chunk, 0, $room);
        $this->usedBytes += $room;

        return $head . $this->getMarker();
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function getUsedBytes(): int
    {
        return $this->usedBytes;
    }

    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    public function getMarker(): string
    {
        return self::marker($this->maxBytes);
    }

    public static function marker(int $maxBytes): string
    {
        return sprintf("\n[... output truncated by JobQueueBundle at %d bytes ...]\n", $maxBytes);
    }
}
