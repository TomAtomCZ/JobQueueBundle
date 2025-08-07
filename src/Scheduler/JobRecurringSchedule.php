<?php

namespace TomAtom\JobQueueBundle\Scheduler;

namespace TomAtom\JobQueueBundle\Scheduler;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Message\JobRecurringMessage;

#[AsSchedule(JobRecurring::SCHEDULER_NAME)]
final class JobRecurringSchedule implements ScheduleProviderInterface
{

    private ?Schedule $schedule = null;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LockFactory              $lockFactory,
        private readonly CacheInterface           $cache,
        private readonly ParameterBagInterface    $parameterBag,
    )
    {
    }

    public function getSchedule(): Schedule
    {
        $schedule = $this->schedule ??= new Schedule($this->dispatcher);
        if (count($schedule->getRecurringMessages()) === 0) {
            // Set heartbeat message to check periodically for recurring jobs to run in the PreRunEvent
            $schedule->add(RecurringMessage::every($this->parameterBag->get('job_queue.scheduling.heartbeat_interval'),
                new JobRecurringMessage(JobRecurring::HEARTBEAT_MESSAGE, [])
            ))
                ->lock($this->lockFactory->createLock(JobRecurring::SCHEDULER_NAME . '_' . JobRecurring::HEARTBEAT_MESSAGE));
        }
        return $schedule->stateful($this->cache);
    }
}
