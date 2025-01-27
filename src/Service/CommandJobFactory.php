<?php

namespace TomAtom\JobQueueBundle\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Message\JobMessage;
use TomAtom\JobQueueBundle\Security\JobQueuePermissions;

class CommandJobFactory
{
    private EntityManager $entityManager;
    private MessageBusInterface $messageBus;
    private TranslatorInterface $translator;
    private Security $security;

    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, TranslatorInterface $translator, Security $security)
    {
        $this->entityManager = $entityManager;
        $this->messageBus = $bus;
        $this->translator = $translator;
        $this->security = $security;
    }

    /**
     * @param string $commandName - Command name
     * @param array $params - Command params
     * @param int|null $entityId - Entity ID
     * @param string|null $entityClassName - Entity class name (self:class)
     * @param Job|null $parentJob - Parent Job entity
     * @param bool $recurring
     * @return Job
     * @throws CommandJobException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function createCommandJob(string $commandName, array $params, int $entityId = null, string $entityClassName = null, Job $parentJob = null, bool $recurring = false): Job
    {
        if ($this->security->getUser() && !$this->security->isGranted(JobQueuePermissions::ROLE_JOB_CREATE)) {
            throw new CommandJobException($this->translator->trans('job.creation.error_security'));
        }

        // Check if the same exact not recurring job exists, throw exception if it does
        if (!$recurring && $this->entityManager->getRepository(Job::class)->isAlreadyCreated($commandName, $params)) {
            throw new CommandJobException($this->translator->trans('job.creation.error_already_exists'));
        }

        // Save init data of the job to db
        $job = new Job();
        $job->setCommand($commandName)
            ->setCommandParams($params)
            ->setStatus(Job::STATUS_PLANNED)
            ->setRelatedEntityId($entityId)
            ->setRelatedEntityClassName($entityClassName)
            ->setRelatedParent($parentJob)
            ->setRecurring($recurring);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Dispatch the message to the message bus
        $message = new JobMessage($job->getId());
        $this->messageBus->dispatch($message);

        // Return created job
        return $job;
    }
}
