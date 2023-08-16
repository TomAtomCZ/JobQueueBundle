<?php

namespace TomAtom\JobQueueBundle\Service;

use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Message\JobMessage;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Messenger\MessageBusInterface;

class CommandJobFactory
{
    private EntityManager $entityManager;
    private MessageBusInterface $messageBus;

    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus)
    {
        $this->entityManager = $entityManager;
        $this->messageBus = $bus;
    }

    /**
     * @param string $commandName
     * @param array $params
     * @param int|null $entityId
     * @param string|null $entityName
     * @return Job
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function createCommandJob(string $commandName, array $params, int $entityId = null, string $entityName = null): Job
    {
        // Save init data of the job to db
        $job = new Job();
        $job->setCommand($commandName);
        $job->setCommandParams($params);
        $job->setStatus(Job::STATUS_PLANNED);
        $job->setRelatedEntityId($entityId);
        $job->setRelatedEntityName($entityName);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Dispatch the message to the message bus
        $message = new JobMessage($job->getId());
        $this->messageBus->dispatch($message);

        // Return created job
        return $job;
    }
}