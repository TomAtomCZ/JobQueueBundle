<?php

namespace TomAtom\JobQueueBundle\MessageHandler;

use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Message\JobRecurringMessage;
use TomAtom\JobQueueBundle\Service\CommandJobFactory;

#[AsMessageHandler]
class JobRecurringMessageHandler
{
    public function __construct(
        private readonly CommandJobFactory $commandJobFactory,
    )
    {
    }

    /**
     * @throws CommandJobException
     * @throws OptimisticLockException
     * @throws ORMException
     */
    public function __invoke(JobRecurringMessage $message): void
    {
        $this->commandJobFactory->createCommandJob(
            $message->getCommandName(),
            $message->getParams(),
            null,
            null,
            null,
            true
        );
    }
}
