<?php

namespace TomAtom\JobQueueBundle\Output;

use TomAtom\JobQueueBundle\Entity\Job;

/**
 * Collects the values printed after {@see Job::COMMAND_OUTPUT_PARAMS} in a command output that arrives in chunks.
 *
 * Values are accumulated across chunks and a line split between two chunks is still found.
 */
final class OutputParamsParser
{
    /** Longest incomplete line kept in memory while waiting for its end. */
    private const MAX_CARRY_BYTES = 65536;

    private string $carry = '';

    /** @var list<string> */
    private array $params = [];

    public function feed(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $lines = explode("\n", $this->carry . $chunk);
        // The last element is either '' (chunk ended with a newline) or an incomplete line
        $this->carry = array_pop($lines);
        foreach ($lines as $line) {
            $this->parseLine($line);
        }

        $this->boundCarry();
    }

    /**
     * Parses the trailing line which did not end with a newline (call once the output is complete).
     */
    public function finish(): void
    {
        if ($this->carry !== '') {
            $this->parseLine($this->carry);
            $this->carry = '';
        }
    }

    /**
     * @return list<string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * All found values joined by ", " (the format stored in Job::$outputParams), or null if none was found.
     */
    public function getOutputParams(): ?string
    {
        return $this->params === [] ? null : implode(', ', $this->params);
    }

    private function parseLine(string $line): void
    {
        $position = strpos($line, Job::COMMAND_OUTPUT_PARAMS);
        if ($position !== false) {
            $this->params[] = trim(substr($line, $position + strlen(Job::COMMAND_OUTPUT_PARAMS)));
        }
    }

    /**
     * Keeps the carried incomplete line bounded for commands printing huge output without newlines.
     */
    private function boundCarry(): void
    {
        if (strlen($this->carry) <= self::MAX_CARRY_BYTES) {
            return;
        }

        $position = strpos($this->carry, Job::COMMAND_OUTPUT_PARAMS);
        if ($position !== false) {
            // Keep the marker and what follows it (the value itself is cut at the limit)
            $this->carry = substr($this->carry, $position, self::MAX_CARRY_BYTES);
            return;
        }

        // Keep just enough bytes to find a marker split across the chunk boundary
        $this->carry = substr($this->carry, -(strlen(Job::COMMAND_OUTPUT_PARAMS) - 1));
    }
}
