<?php

namespace TomAtom\JobQueueBundle\Message;

class JobMessage
{
    public function __construct(private readonly int $jobId)
    {
    }

    public function getJobId(): int
    {
        return $this->jobId;
    }
}
