<?php

namespace TomAtom\JobQueueBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomAtom\JobQueueBundle\Repository\JobRecurringRepository;

#[ORM\MappedSuperclass]
#[ORM\Entity(repositoryClass: JobRecurringRepository::class)]
#[ORM\Table(name: "job_recurring_queue")]
class JobRecurring
{
    public const SCHEDULER_NAME = 'job_recurring_schedule';
    public const HEARTBEAT_MESSAGE = 'heartbeat';
    public const HEARTBEAT_INTERVAL = '1 minute';

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

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

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
}