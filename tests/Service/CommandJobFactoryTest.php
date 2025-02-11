<?php

namespace TomAtom\JobQueueBundle\Tests\Service;

use DateMalformedStringException;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Entity\JobRecurring;
use TomAtom\JobQueueBundle\Exception\CommandJobException;
use TomAtom\JobQueueBundle\Message\JobMessage;
use TomAtom\JobQueueBundle\Repository\JobRepository;
use TomAtom\JobQueueBundle\Security\JobQueuePermissions;
use TomAtom\JobQueueBundle\Service\CommandJobFactory;

class CommandJobFactoryTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $messageBus;
    private Security $security;
    private CommandJobFactory $factory;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->factory = new CommandJobFactory(
            $this->entityManager,
            $this->messageBus,
            $translator,
            $this->security
        );
    }

    /**
     * @throws OptimisticLockException
     * @throws Exception
     * @throws ORMException
     * @throws CommandJobException
     */
    public function testCreateCommandJobOnce()
    {
        $job = $this->createMockJob();

        // Check if the correct Envelope is dispatched
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($job) {
                if ($message instanceof JobMessage) {
                    // Direct JobMessage is fine when no Envelope is used (no delay)
                    return $message->getJobId() === $job->getId();
                }
                return false;
            }))
            ->willReturnCallback(function ($message) {
                return new Envelope($message);
            });

        // Try to create the job
        $jobResult = $this->factory->createCommandJob('test:command', ['param' => 'value']);

        // Assert the job is created correctly
        $this->assertInstanceOf(Job::class, $jobResult);
        $this->assertEquals('test:command', $jobResult->getCommand());
        $this->assertEquals(['param' => 'value'], $jobResult->getCommandParams());
        $this->assertEquals(Job::TYPE_ONCE, $jobResult->getType());
    }

    /**
     * @throws CommandJobException
     * @throws Exception
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws DateMalformedStringException
     */
    public function testCreateCommandJobPostponed()
    {
        $job = $this->createMockJob();

        // Define the postponement time (e.g., 60 seconds from now)
        $postponedStartAt = (new DateTimeImmutable())->modify('+60 seconds');

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($job, $postponedStartAt) {
                if (!$message instanceof Envelope) {
                    return false;
                }

                $innerMessage = $message->getMessage();
                $stamps = $message->all();
                if ($innerMessage instanceof JobMessage && $innerMessage->getJobId() === $job->getId()) {
                    $delayStamp = $stamps[DelayStamp::class][0] ?? null;
                    if ($delayStamp instanceof DelayStamp) {
                        // Check delay value
                        $expectedDelayMs = ($postponedStartAt->getTimestamp() - (new DateTime())->getTimestamp()) * 1000;
                        return abs($delayStamp->getDelay() - $expectedDelayMs) < 50; // Allow slight timing differences
                    }
                }
                return false;
            }))
            ->willReturnCallback(function ($message) {
                return new Envelope($message);
            });

        // Try to create the postponed job
        $jobResult = $this->factory->createCommandJob(
            'test:command',
            ['param' => 'value'],
            null,
            null,
            null,
            null,
            $postponedStartAt
        );

        // Assert the job is created correctly
        $this->assertInstanceOf(Job::class, $jobResult);
        $this->assertEquals('test:command', $jobResult->getCommand());
        $this->assertEquals(['param' => 'value'], $jobResult->getCommandParams());
        $this->assertEquals($postponedStartAt, $jobResult->getStartAt());
        $this->assertEquals(Job::TYPE_POSTPONED, $jobResult->getType());
    }

    /**
     * @throws CommandJobException
     * @throws Exception
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function testCreateCommandJobRecurring()
    {
        $job = $this->createMockJob();

        // Mock recurring parent
        $recurringParent = new JobRecurring();

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($job) {
                return $message instanceof JobMessage && $message->getJobId() === $job->getId();
            }))
            ->willReturnCallback(function ($message) {
                return new Envelope($message);
            });

        // Try to create the recurring job
        $jobResult = $this->factory->createCommandJob(
            'test:command',
            ['param' => 'value'],
            null,
            null,
            null,
            $recurringParent
        );

        // Assert the job is created correctly
        $this->assertInstanceOf(Job::class, $jobResult);
        $this->assertEquals('test:command', $jobResult->getCommand());
        $this->assertSame($recurringParent, $jobResult->getJobRecurringParent());
        $this->assertEquals(Job::TYPE_RECURRING, $jobResult->getType());
    }

    /**
     * @throws CommandJobException
     * @throws Exception
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function testCreateCommandJobUnauthorized()
    {
        $mockUser = $this->createMock(UserInterface::class);
        $this->security
            ->method('getUser')
            ->willReturn($mockUser);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(CommandJobException::class);

        // Try to create the job - should not get here
        $this->factory->createCommandJob('test:command', ['param' => 'value']);
    }

    /**
     * @throws OptimisticLockException
     * @throws Exception
     * @throws ORMException
     */
    public function testCreateCommandJobDuplicate()
    {
        $mockUser = $this->createMock(UserInterface::class);
        $this->security
            ->method('getUser')
            ->willReturn($mockUser);
        $this->security->method('isGranted')->willReturn(true);

        $jobRepo = $this->createMock(JobRepository::class);
        $jobRepo->method('isAlreadyCreated')->willReturn(true);
        $this->entityManager->method('getRepository')->willReturn($jobRepo);

        $this->expectException(CommandJobException::class);

        // Try to create the job - should not get here
        $this->factory->createCommandJob('test:command', ['param' => 'value']);
    }

    /**
     * @return Job
     * @throws Exception
     */
    private function createMockJob(): Job
    {
        // Mock user and permissions
        $mockUser = $this->createMock(UserInterface::class);
        $this->security
            ->method('getUser')
            ->willReturn($mockUser);
        $this->security
            ->method('isGranted')
            ->with(JobQueuePermissions::ROLE_JOB_CREATE)
            ->willReturn(true);

        // Mock repository to prevent duplicate job creation
        $jobRepo = $this->createMock(JobRepository::class);
        $jobRepo->method('isAlreadyCreated')->willReturn(false);
        $this->entityManager->method('getRepository')->willReturn($jobRepo);

        // Create mock job and set its ID
        $job = new Job();
        $job->setCommand('test:command')
            ->setCommandParams(['param' => 'value'])
            ->setId(123);

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Job::class))
            ->willReturnCallback(function (Job $job) {
                $job->setId(123);
            });
        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        return $job;
    }
}