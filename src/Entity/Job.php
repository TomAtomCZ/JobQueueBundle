<?php

namespace TomAtom\JobQueueBundle\Entity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use TomAtom\JobQueueBundle\Repository\JobRepository;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(name: "job_queue")]
class Job
{
    const STATUS_PLANNED = 'planned';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id;

    #[ORM\Column(length: 255)]
    private ?string $status;

    #[ORM\Column(length: 255)]
    private ?string $command;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    private string|array|null $commandParams = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $relatedEntityId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $relatedEntityClassName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private string|array|null $output = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::DATEINTERVAL, nullable: true)]
    private ?DateInterval $runtime = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @param int|null $id
     * @return Job
     */
    public function setId(?int $id): Job
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * @param string|null $status
     * @return Job
     */
    public function setStatus(?string $status): Job
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getRelatedEntityId(): ?int
    {
        return $this->relatedEntityId;
    }

    /**
     * @param int|null $relatedEntityId
     * @return Job
     */
    public function setRelatedEntityId(?int $relatedEntityId): Job
    {
        $this->relatedEntityId = $relatedEntityId;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRelatedEntityClassName(): ?string
    {
        return $this->relatedEntityClassName;
    }

    /**
     * @param string|null $relatedEntityClassName
     * @return Job
     */
    public function setRelatedEntityClassName(?string $relatedEntityClassName): Job
    {
        $this->relatedEntityClassName = $relatedEntityClassName;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCommand(): ?string
    {
        return $this->command;
    }

    /**
     * @param string|null $command
     * @return Job
     */
    public function setCommand(?string $command): Job
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @return array|string|null
     */
    public function getCommandParams(): array|string|null
    {
        return $this->commandParams;
    }

    /**
     * @param array|string|null $commandParams
     * @return Job
     */
    public function setCommandParams(array|string|null $commandParams): Job
    {
        $this->commandParams = $commandParams;
        return $this;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param DateTimeImmutable|null $createdAt
     * @return Job
     */
    public function setCreatedAt(?DateTimeImmutable $createdAt): Job
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * @param DateTimeImmutable|null $startedAt
     * @return Job
     */
    public function setStartedAt(?DateTimeImmutable $startedAt): Job
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    /**
     * @param DateTimeImmutable|null $closedAt
     * @return Job
     */
    public function setClosedAt(?DateTimeImmutable $closedAt): Job
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    /**
     * @return array|string|null
     */
    public function getOutput(): array|string|null
    {
        return $this->output;
    }

    /**
     * @param array|string|null $output
     * @return Job
     */
    public function setOutput(array|string|null $output): Job
    {
        $this->output = $output;
        return $this;
    }

    /**
     * @return DateInterval|null
     */
    public function getRuntime(): ?DateInterval
    {
        return $this->runtime;
    }

    /**
     * @param DateInterval|null $runtime
     * @return Job
     */
    public function setRuntime(?DateInterval $runtime): Job
    {
        $this->runtime = $runtime;
        return $this;
    }
}