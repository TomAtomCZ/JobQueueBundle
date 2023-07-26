<?php

namespace TomAtom\JobQueueBundle\MessageHandler;

use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Message\JobMessage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
class JobMessageHandler
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function __invoke(JobMessage $message): void
    {
        // Command data from the job
        $jobId = $message->getJobId();
        $job = $this->entityManager->getRepository(Job::class)->findOneBy(['id' => $jobId]);
        $commandName = $job->getCommand();
        $params = $job->getCommandParams();
        $command = sprintf('php bin/console %s %s', $commandName, implode(' ', $params));

        // Start the process
        $process = new Process(explode(' ', $command));
        $process->setWorkingDirectory(dirname(__DIR__, 5));
        $process->enableOutput();
        $process->start();

        // Update the job
        $job->setStatus(Job::STATUS_RUNNING);
        $job->setStartedAt(new DateTimeImmutable());
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        // Wait for the process to finish and save the command buffer to the output
        $process->wait(function ($type, $buffer) use ($job): void {
            $job->setOutput($job->getOutput() . $buffer);
            $this->entityManager->persist($job);
            $this->entityManager->flush();
        });

        $job->setStatus($process->isSuccessful() ? Job::STATUS_COMPLETED : Job::STATUS_FAILED);
        $job->setClosedAt(new DateTimeImmutable());

        $this->entityManager->persist($job);
        $this->entityManager->flush();
    }
}