<?php

namespace TomAtom\JobQueueBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use TomAtom\JobQueueBundle\Entity\JobRecurring;

/**
 * @extends ServiceEntityRepository<JobRecurring>
 */
class JobRecurringRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobRecurring::class);
    }
}
