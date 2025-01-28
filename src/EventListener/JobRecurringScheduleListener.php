<?php

namespace TomAtom\JobQueueBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\RecurringMessage;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Message\JobRecurringMessage;

class JobRecurringScheduleListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory            $lockFactory,

    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreRunEvent::class => 'onPreRunEvent'
        ];
    }

    /**
     * @throws Exception
     */
    public function onPreRunEvent(PreRunEvent $event): void
    {
        // Check if EM is closed
        if (!$this->entityManager->isOpen()) {
            return;
        }

        // Access the running schedule
        $currentSchedule = $event->getSchedule();

        // Clear existing recurring messages
        foreach ($currentSchedule->getSchedule()->getRecurringMessages() as $key => $recurringMessage) {
            if ($key === 0) {
                // Skip the heartbeat message - it's always the first one
                // TODO check via message name to be sure ($recurringMessage->getMessages() - need to get $context)
                continue;
            }
            $currentSchedule->getSchedule()->remove($recurringMessage);
        }

        // Fetch active recurring jobs
        $recurringJobs = $this->entityManager->getRepository(JobRecurring::class)
            ->findBy(['active' => true]);

        // Re-add recurring jobs to the schedule
        /** @var JobRecurring $recurringJob */
        foreach ($recurringJobs as $recurringJob) {
            $currentSchedule->getSchedule()->add(RecurringMessage::cron(
                $recurringJob->getFrequency(),
                new JobRecurringMessage($recurringJob->getCommand(), $recurringJob->getCommandParams())
            ))
                ->lock($this->lockFactory->createLock(
                    sprintf('%s_%s_%s',
                        JobRecurring::SCHEDULER_NAME,
                        $recurringJob->getCommand(),
                        md5(serialize($recurringJob->getCommandParams()))
                    )
                ));
        }
    }
}
