<?php

namespace TomAtom\JobQueueBundle\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;
use Throwable;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Message\JobMessage;

#[AsMessageHandler]
class JobMessageHandler
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(JobMessage $message): void
    {
        // Command data from the job
        $jobId = $message->getJobId();
        $job = $this->entityManager->getRepository(Job::class)->findOneBy(['id' => $jobId]);
        $commandName = $job->getCommand();
        $params = $job->getCommandParams();
        $command = 'php bin/console ' . $commandName;
        if ($params !== null && $params !== [] && $params !== '') {
            if (is_array($params)) {
                $command .= ' ' . implode(' ', $params);
            } else {
                $command .= ' ' . $params;
            }
        }

        // Start the process
        $process = Process::fromShellCommandline($command);
        $process->setWorkingDirectory(dirname(__DIR__, 5));
        $process->enableOutput();
        $process->setTimeout(null);
        $process->start();

        // Update the job
        $job->setStatus(Job::STATUS_RUNNING);
        $job->setStartedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        // Wait for the process to finish and save the command buffer to the output
        while ($process->isRunning()) {
            try {
                // Refresh the job entity to check if it was cancelled and to update its output
                $this->entityManager->refresh($job);
                // Process output buffer
                $this->processBuffer($job, $process->getIncrementalErrorOutput() . $process->getIncrementalOutput());
                if ($job->isCancelled()) {
                    // If job was cancelled stop the process immediately and break the loop
                    $job->setOutput($job->getOutput() . "\n\n" . Job::JOB_CANCELLED_MESSAGE);
                    $process->stop(0);
                    break;
                }
            } catch (Throwable $e) {
                // If exception was thrown stop the process immediately and break the loop
                $job->setOutput($job->getOutput() . "\n\n" . $e->getMessage());
                $process->stop(0);
                break;
            }
        }

        // Process remaining output buffer after process stops
        $this->processBuffer($job, $process->getIncrementalErrorOutput() . $process->getIncrementalOutput());

        if ($job->getStatus() !== Job::STATUS_CANCELLED) {
            $job->setStatus($process->isSuccessful() ? Job::STATUS_COMPLETED : Job::STATUS_FAILED);
        }
        $job->setClosedAt(new DateTimeImmutable());
        $job->setRuntime($job->getStartedAt()->diff($job->getClosedAt()));

        $this->entityManager->flush();
    }

    /**
     * Processes the buffer and updates the job output and output params
     * @param Job $job
     * @param string $buffer
     * @return void
     */
    private function processBuffer(Job $job, string $buffer): void
    {
        // Update the output
        $job->setOutput($job->getOutput() . $buffer);
        $outputParamsList = [];
        // Split the buffer into lines - try to find parameters outputted from the buffer to be saved
        $lines = explode(PHP_EOL, $buffer);
        foreach ($lines as $line) {
            $position = strpos($line, Job::COMMAND_OUTPUT_PARAMS);
            if ($position !== false) {
                // Extract the parameter
                $outputParams = trim(substr($line, $position + strlen(Job::COMMAND_OUTPUT_PARAMS)));
                $outputParamsList[] = $outputParams;
            }
        }
        // Join all found outputted params
        if (!empty($outputParamsList)) {
            $job->setOutputParams(implode(', ', $outputParamsList));
        }
        $this->entityManager->flush();
    }
}
