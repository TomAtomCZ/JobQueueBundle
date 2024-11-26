<?php

namespace TomAtom\JobQueueBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use TomAtom\JobQueueBundle\Entity\Job;

/**
 * @extends ServiceEntityRepository<Job>
 */
class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    /**
     * Checks if job with the same command, parameters and status {@see Job::STATUS_PLANNED} exists
     * @param string $command
     * @param array $params
     * @return bool
     */
    public function isAlreadyCreated(string $command, array $params): bool
    {
        $result = $this->createQueryBuilder('j')
            ->select('1')
            ->where('j.status = :status')
            ->setParameter('status', Job::STATUS_PLANNED)
            ->andWhere('j.command = :command')
            ->setParameter('command', $command)
            ->andWhere('j.commandParams = :commandParams')
            ->setParameter('commandParams', trim(implode(',', $params)))
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }
}
