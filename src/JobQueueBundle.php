<?php

namespace TomAtom\JobQueueBundle;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use TomAtom\JobQueueBundle\DependencyInjection\JobQueueExtension;
use function dirname;

class JobQueueBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new JobQueueExtension();
        }

        return $this->extension;
    }
}