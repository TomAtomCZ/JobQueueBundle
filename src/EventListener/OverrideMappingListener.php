<?php

namespace TomAtom\JobQueueBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Entity\JobRecurring;

#[AsDoctrineListener(event: 'loadClassMetadata')]
class OverrideMappingListener
{
    public function __construct(
        private readonly string $jobTableName,
        private readonly string $jobRecurringTableName
    )
    {
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
