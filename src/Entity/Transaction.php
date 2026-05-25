<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions', indexes: [
    new ORM\Index(name: 'idx_idempotency', columns: ['idempotency_key']),
])]
class Transaction
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $fromAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $toAccount;

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private string $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 80, nullable: true, unique: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        Account $from,
        Account $to,
        string $amountMinor,
        string $currency,
        ?string $idempotencyKey = null,
    ) {
        $this->id = Uuid::v7();
        $this->fromAccount = $from;
        $this->toAccount = $to;
        $this->amountMinor = $amountMinor;
        $this->currency = strtoupper($currency);
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
    }

    public function getId(): Uuid { return $this->id; }
    public function getFromAccount(): Account { return $this->fromAccount; }
    public function getToAccount(): Account { return $this->toAccount; }
    public function getAmountMinor(): string { return $this->amountMinor; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getFailureReason(): ?string { return $this->failureReason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
}
