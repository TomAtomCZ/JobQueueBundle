<?php

namespace TomAtom\JobQueueBundle\Message;

class JobRecurringMessage
{
    public function __construct(
        private readonly string $commandName,
        private readonly array  $params
    )
    {
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}
