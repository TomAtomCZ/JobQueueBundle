<?php

namespace TomAtom\JobQueueBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function dirname;

class JobQueueBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}