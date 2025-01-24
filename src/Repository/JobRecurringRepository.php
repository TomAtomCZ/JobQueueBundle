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

    public function isAlreadyCreated(string $command, array $params, string $frequency, bool $active): bool
    {
        $result = $this->createQueryBuilder('jr')
            ->select('1')
            ->where('jr.command = :command')
            ->setParameter('command', $command)
            ->andWhere('jr.commandParams = :commandParams')
            ->setParameter('commandParams', trim(implode(',', $params)))
            ->andWhere('jr.frequency = :frequency')
            ->setParameter('frequency', $frequency)
            ->andWhere('jr.active = :active')
            ->setParameter('active', $active)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }
}
