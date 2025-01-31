<?php

namespace TomAtom\JobQueueBundle\Service;

use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Message\JobMessage;
use TomAtom\JobQueueBundle\Security\JobQueuePermissions;

class CommandJobFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface    $messageBus,
        private readonly TranslatorInterface    $translator,
        private readonly Security               $security
    )
    {
    }

    /**
     * @param string $commandName - Command name
     * @param array $commandParams - Command params
     * @param int|null $entityId - Entity ID
     * @param string|null $entityClassName - Entity class name (self:class)
     * @param Job|null $parentJob - Parent Job entity
     * @param JobRecurring|null $jobRecurring - Recurring job from which was created
     * @param DateTimeImmutable|null $startAt - Postpone job at time
     * @return Job
     * @throws CommandJobException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function createCommandJob(string $commandName, array $commandParams, int $entityId = null, string $entityClassName = null, Job $parentJob = null, JobRecurring $jobRecurring = null, ?DateTimeImmutable $startAt = null): Job
    {
        // Check if user is loaded and is granted job creation
        if ($this->security->getUser() && !$this->security->isGranted(JobQueuePermissions::ROLE_JOB_CREATE)) {
            throw new CommandJobException($this->translator->trans('job.creation.error_security'));
        }

        // Job has to be only one of recurring or postponed
        if ($jobRecurring && $startAt) {
            throw new CommandJobException($this->translator->trans('job.creation.error_recurring_start_at'));
        }

        // Get job type
        $type = Job::TYPE_ONCE;
        if ($startAt) {
            $type = Job::TYPE_POSTPONED;
        } else if ($jobRecurring) {
            $type = Job::TYPE_RECURRING;
        }

        // Check if the same exact job exists, throw exception if it does
        if ($this->entityManager->getRepository(Job::class)->isAlreadyCreated($commandName, $commandParams)) {
            throw new CommandJobException($this->translator->trans('job.creation.error_already_exists'));
        }

        // Save init data of the job to db
        $job = new Job();
        $job->setCommand($commandName)
            ->setCommandParams($commandParams)
            ->setStatus(Job::STATUS_PLANNED)
            ->setRelatedEntityId($entityId)
            ->setRelatedEntityClassName($entityClassName)
            ->setRelatedParent($parentJob)
            ->setType($type)
            ->setStartAt($startAt)
            ->setJobRecurringParent($jobRecurring);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Dispatch the message to the message bus
        $message = new JobMessage($job->getId());
        if ($startAt) {
            // Set delay if is postponed in milliseconds
            $delay = $startAt->getTimestamp() - (new DateTime())->getTimestamp();
            $message = new Envelope($message, [new DelayStamp($delay * 1000)]);
        }
        $this->messageBus->dispatch($message);

        // Return created job
        return $job;
    }

    /**
     * Create or update a recurring command job
     * @param JobRecurring|null $jobRecurring
     * @param string $commandName
     * @param array $params
     * @param string $frequency
     * @param bool $active
     * @return JobRecurring
     * @throws CommandJobException|ORMException|OptimisticLockException
     */
    public function saveRecurringCommandJob(?JobRecurring $jobRecurring, string $commandName, array $params, string $frequency, bool $active = true): JobRecurring
    {
        // Validate frequency and duplicates
        $frequency = $this->validateFrequency($frequency);
        if ($this->entityManager->getRepository(JobRecurring::class)->isAlreadyCreated($commandName, $params, $frequency, $active)) {
            throw new CommandJobException($this->translator->trans('job.creation.error_already_exists'));
        }

        // Use existing job or create a new one
        $jobRecurring = $jobRecurring ?? new JobRecurring();
        $jobRecurring->setCommand($commandName)
            ->setCommandParams($params)
            ->setFrequency($frequency)
            ->setActive($active);

        $this->entityManager->persist($jobRecurring);
        $this->entityManager->flush();

        return $jobRecurring;
    }

    /**
     * @throws CommandJobException|OptimisticLockException|ORMException
     */
    public function createRecurringCommandJob(string $commandName, array $params, string $frequency, bool $active = true): JobRecurring
    {
        return $this->saveRecurringCommandJob(null, $commandName, $params, $frequency, $active);
    }

    /**
     * @throws CommandJobException|OptimisticLockException|ORMException
     */
    public function updateRecurringCommandJob(JobRecurring $jobRecurring, string $commandName, array $params, string $frequency, bool $active = true): JobRecurring
    {
        return $this->saveRecurringCommandJob($jobRecurring, $commandName, $params, $frequency, $active);
    }

    /**
     * Validate the cron frequency format
     * @param string $frequency
     * @return string
     * @throws CommandJobException
     */
    public function validateFrequency(string $frequency): string
    {
        $frequency = trim($frequency);

        if (preg_match("/[a-z]/i", $frequency) && (!str_starts_with($frequency, '@') && !str_starts_with($frequency, '#'))) {
            // String cron expressions needs to start with @ or #
            return '@' . $frequency;
        }

        $cronPattern = '/^(\*|[0-5]?\d) (\*|[0-1]?\d|2[0-3]) (\*|0?[1-9]|[12]\d|3[01]) (\*|0?[1-9]|1[0-2]) (\*|[0-6])$/';
        if (!preg_match($cronPattern, $frequency)) {
            // Cron pattern does not match (* * * * *)
            throw new CommandJobException($this->translator->trans('job.recurring.frequency.error'));
        }

        return $frequency;
    }
}
