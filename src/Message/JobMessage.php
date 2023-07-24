<?php

namespace TomAtom\JobQueueBundle\Message;

class JobMessage
{
    private int $jobId;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;
    }

    /**
     * @return int
     */
    public function getJobId(): int
    {
        return $this->jobId;
    }
}