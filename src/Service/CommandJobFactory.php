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
        if (!$this->security->isGranted(JobQueuePermissions::ROLE_JOB_CREATE)) {
            throw new CommandJobException($this->translator->trans('job.creation.error_security'));
        }

        // Check if the same exact job exists, throw exception if it does
        if ($this->entityManager->getRepository(Job::class)->isAlreadyCreated($commandName, $params)) {
            throw new CommandJobException($this->translator->trans('job.creation.error_already_exists'));
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
