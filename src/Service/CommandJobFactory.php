<?php

namespace TomAtom\JobQueueBundle\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Message\JobMessage;

class CommandJobFactory
{
    private EntityManager $entityManager;
    private MessageBusInterface $messageBus;
    private TranslatorInterface $translator;

    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, TranslatorInterface $translator)
    {
        $this->entityManager = $entityManager;
        $this->messageBus = $bus;
        $this->translator = $translator;
    }

    /**
     * @param string $commandName
     * @param array $params
     * @param int|null $entityId - Entity ID
     * @param string|null $entityClassName - Entity class name (self:class)
     * @return Job
     * @throws CommandJobException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function createCommandJob(string $commandName, array $params, int $entityId = null, string $entityClassName = null): Job
    {
        // Check if the same exact job exists, throw exception if it does
        if ($this->entityManager->getRepository(Job::class)->isAlreadyCreated($commandName, $params)) {
            throw new CommandJobException($this->translator->trans('job.already_exists'));
        }

        // Save init data of the job to db
        $job = new Job();
        $job->setCommand($commandName);
        $job->setCommandParams($params);
        $job->setStatus(Job::STATUS_PLANNED);
        $job->setRelatedEntityId($entityId);
        $job->setRelatedEntityClassName($entityClassName);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Dispatch the message to the message bus
        $message = new JobMessage($job->getId());
        $this->messageBus->dispatch($message);

        // Return created job
        return $job;
    }
}