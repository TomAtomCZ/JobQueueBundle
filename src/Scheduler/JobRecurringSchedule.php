<?php

namespace TomAtom\JobQueueBundle\Scheduler;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Message\JobRecurringMessage;

#[AsSchedule('job_recurring_schedule')]
final class JobRecurringSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface         $cache,
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        $recurringJobs = $this->entityManager->getRepository(JobRecurring::class)->findBy(['active' => true]);
        foreach ($recurringJobs as $recurringJob) {
            $schedule->add(
                RecurringMessage::cron($recurringJob->getFrequency(), new JobRecurringMessage(
                    $recurringJob->getCommand(),
                    $recurringJob->getCommandParams()
                ))
            );
        }

        return $schedule->stateful($this->cache);
    }
}
