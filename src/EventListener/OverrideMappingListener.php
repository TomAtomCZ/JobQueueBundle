<?php

namespace TomAtom\JobQueueBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Entity\JobRecurring;

class OverrideMappingListener implements EventSubscriber
{
    private string $jobTableName;
    private string $jobRecurringTableName;

    public function __construct(string $jobTableName, string $jobRecurringTableName)
    {
        $this->jobTableName = $jobTableName;
        $this->jobRecurringTableName = $jobRecurringTableName;
    }

    public function getSubscribedEvents(): array
    {
        return ['loadClassMetadata'];
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $entityClass = $metadata->getName();

        if ($entityClass === Job::class) {
            $metadata->setPrimaryTable(['name' => $this->jobTableName]);
        }

        if ($entityClass === JobRecurring::class) {
            $metadata->setPrimaryTable(['name' => $this->jobRecurringTableName]);
        }
    }
}