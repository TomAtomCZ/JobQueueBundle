<?php

namespace TomAtom\JobQueueBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Message\JobRecurringMessage;
use TomAtom\JobQueueBundle\Service\CommandJobFactory;

#[AsMessageHandler]
class JobRecurringMessageHandler
{
    public function __construct(
        private readonly CommandJobFactory $commandJobFactory, private readonly EntityManagerInterface $entityManager,
    )
    {
    }

    /**
     * @param JobRecurringMessage $message
     * @return void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws CommandJobException
     */
    public function __invoke(JobRecurringMessage $message): void
    {
        if ($message->getCommandName() === JobRecurring::HEARTBEAT_MESSAGE) {
            // The heartbeat job doesn't do anything except trigger the event for fetching new recurring jobs
            return;
        }

        $this->commandJobFactory->createCommandJob(
            $message->getCommandName(),
            $message->getParams(),
            null,
            null,
            null,
            $this->entityManager->find(JobRecurring::class, $message->getJobRecurringId()) ?? null
        );
    }
}
