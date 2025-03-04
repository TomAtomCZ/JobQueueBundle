<?php

namespace TomAtom\JobQueueBundle\Entity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
class Job
{
    const STATUS_PLANNED = 'planned';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUSES = [
        self::STATUS_PLANNED => self::STATUS_PLANNED,
        self::STATUS_RUNNING => self::STATUS_RUNNING,
        self::STATUS_COMPLETED => self::STATUS_COMPLETED,
        self::STATUS_FAILED => self::STATUS_FAILED,
        self::STATUS_CANCELLED => self::STATUS_CANCELLED,
    ];
    const TYPE_ONCE = 'once';
    const TYPE_POSTPONED = 'postponed';
    const TYPE_RECURRING = 'recurring';
    const TYPES = [
        self::TYPE_ONCE => self::TYPE_ONCE,
        self::TYPE_POSTPONED => self::TYPE_POSTPONED,
        self::TYPE_RECURRING => self::TYPE_RECURRING,
    ];
    const COMMAND_OUTPUT_PARAMS = 'OUTPUT PARAMS: ';
    const JOB_CANCELLED_MESSAGE = 'JOB CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $status = null;

    #[ORM\Column(length: 255)]
    private ?string $command = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    private string|array|null $commandParams = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $relatedEntityId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $relatedEntityClassName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private string|array|null $output = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private string|array|null $outputParams = null;

    #[ORM\ManyToOne(targetEntity: Job::class, inversedBy: 'relatedChildren')]
    #[ORM\JoinColumn(name: 'related_parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Job $relatedParent = null;

    #[ORM\OneToMany(targetEntity: Job::class, mappedBy: 'relatedParent')]
    private Collection|array $relatedChildren;

    #[ORM\ManyToOne(targetEntity: JobRecurring::class, inversedBy: 'jobs')]
    #[ORM\JoinColumn(name: 'job_recurring_parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?JobRecurring $jobRecurringParent = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startAt;

    #[ORM\Column(type: Types::DATEINTERVAL, nullable: true)]
    private ?DateInterval $runtime = null;

    #[ORM\Column(length: 10)]
    private ?string $type;

    public function __construct()
    {
        $this->relatedChildren = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->type = self::TYPE_ONCE;
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
     * @param bool $withPath
     * @return string|null
     */
    public function getRelatedEntityClassName(bool $withPath = false): ?string
    {
        if ($withPath) {
            return str_starts_with($this->relatedEntityClassName, 'App\\Entity\\') ? $this->relatedEntityClassName : 'App\\Entity\\' . $this->relatedEntityClassName;
        }
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
     * @return DateTimeImmutable|null
     */
    public function getCancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /**
     * @param DateTimeImmutable|null $cancelledAt
     * @return $this
     */
    public function setCancelledAt(?DateTimeImmutable $cancelledAt): Job
    {
        $this->cancelledAt = $cancelledAt;
        $this->setStatus(self::STATUS_CANCELLED);
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
     * @return array|string|null
     */
    public function getOutputParams(): array|string|null
    {
        return $this->outputParams;
    }

    /**
     * @param array|string|null $outputParams
     * @return $this
     */
    public function setOutputParams(array|string|null $outputParams): Job
    {
        $this->outputParams = $outputParams;
        return $this;
    }

    /**
     * @return Job|null
     */
    public function getRelatedParent(): ?Job
    {
        return $this->relatedParent;
    }

    /**
     * @param Job|null $relatedParent
     * @return $this
     */
    public function setRelatedParent(?Job $relatedParent): Job
    {
        $this->relatedParent = $relatedParent;
        return $this;
    }

    /**
     * @return Collection|array|null
     */
    public function getRelatedChildren(): Collection|array|null
    {
        return $this->relatedChildren;
    }

    /**
     * @param Collection|array $relatedChildren
     * @return $this
     */
    public function setRelatedChildren(Collection|array $relatedChildren): Job
    {
        $this->relatedChildren = $relatedChildren;
        return $this;
    }

    /**
     * @return JobRecurring|null
     */
    public function getJobRecurringParent(): ?JobRecurring
    {
        return $this->jobRecurringParent;
    }

    /**
     * @param JobRecurring|null $jobRecurringParent
     * @return Job
     */
    public function setJobRecurringParent(?JobRecurring $jobRecurringParent): Job
    {
        $this->jobRecurringParent = $jobRecurringParent;
        return $this;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getStartAt(): ?DateTimeImmutable
    {
        return $this->startAt;
    }

    /**
     * @param DateTimeImmutable|null $startAt
     * @return Job
     */
    public function setStartAt(?DateTimeImmutable $startAt): Job
    {
        $startAt === null ? $this->startAt = new DateTimeImmutable() : $this->startAt = $startAt;
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

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string|null $type
     * @return $this
     */
    public function setType(?string $type): Job
    {
        $this->type = $type;
        return $this;
    }

    public function isRunning(): bool
    {
        return $this->getStatus() === self::STATUS_RUNNING;
    }

    public function isPlanned(): bool
    {
        return $this->getStatus() === self::STATUS_PLANNED;
    }

    public function isCancelled(): bool
    {
        return $this->getCancelledAt() !== null && $this->getStatus() === self::STATUS_CANCELLED;
    }

    public function isDeletable(): bool
    {
        return !$this->isRunning();
    }

    public function isCancellable(): bool
    {
        return $this->isRunning() && !$this->isCancelled();
    }

    public function isRecreatable(): bool
    {
        return !$this->isRunning() && !$this->isPlanned();
    }
}
