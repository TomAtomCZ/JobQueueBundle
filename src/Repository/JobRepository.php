<?php

namespace TomAtom\JobQueueBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use TomAtom\JobQueueBundle\Entity\Job;

/**
 * @method Job|null find($id, $lockMode = null, $lockVersion = null)
 * @method Job|null findOneBy(array $criteria, array $orderBy = null)
 * @method Job[]    findAll()
 * @method Job[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    public function save(Job $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Job $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function isAlreadyCreated(string $command, array $params): bool
    {
        $isCreated = $this->createQueryBuilder('j')
            ->where('j.status = :status')
            ->setParameter('status', Job::STATUS_PLANNED)
            ->andWhere('j.command = :command')
            ->setParameter('command', $command)
            ->andWhere('j.commandParams = :commandParams')
            ->setParameter('commandParams', trim(implode(',', $params)))
            ->getQuery()
            ->getResult();

        if (!empty($isCreated)) {
            return true;
        }

        return false;
    }
}
