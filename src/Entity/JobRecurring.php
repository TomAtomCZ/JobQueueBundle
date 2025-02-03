<?php

namespace TomAtom\JobQueueBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
class JobRecurring
{
    public const SCHEDULER_NAME = 'job_recurring';
    public const HEARTBEAT_MESSAGE = 'heartbeat';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private ?string $command = null;

    #[ORM\Column(type: Types::JSON)]
    private ?array $commandParams = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $frequency = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: Job::class, mappedBy: 'jobRecurringParent', cascade: ['persist', 'remove'])]
    private Collection $jobs;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->jobs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }

    public function setCommand(?string $command): JobRecurring
    {
        $this->command = $command;
        return $this;
    }

    public function getCommandParams(): ?array
    {
        return $this->commandParams;
    }

    public function setCommandParams(?array $commandParams): JobRecurring
    {
        $this->commandParams = $commandParams;
        return $this;
    }

    public function getFrequency(): ?string
    {
        return $this->frequency;
    }

    public function setFrequency(?string $frequency): JobRecurring
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeImmutable $createdAt): JobRecurring
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): JobRecurring
    {
        $this->active = $active;
        return $this;
    }

    public function getJobs(): Collection
    {
        return $this->jobs;
    }

    public function addJob(Job $job): void
    {
        if (!$this->jobs->contains($job)) {
            $this->jobs->add($job);
            $job->setJobRecurringParent($this);
        }
    }

    public function removeJob(Job $job): void
    {
        if ($this->jobs->removeElement($job)) {
            if ($job->getJobRecurringParent() === $this) {
                $job->setJobRecurringParent(null);
            }
        }
    }
}