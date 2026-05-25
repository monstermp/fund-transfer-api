<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $accountNumber;

    /**
     * Balance stored as integer minor units (e.g. cents) to avoid float errors.
     */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private string $balanceMinor = '0';

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $accountNumber, string $currency, string $initialBalanceMinor = '0')
    {
        $this->id = Uuid::v7();
        $this->accountNumber = $accountNumber;
        $this->currency = strtoupper($currency);
        $this->balanceMinor = $initialBalanceMinor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function getBalanceMinor(): string
    {
        return $this->balanceMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function debit(string $amountMinor): void
    {
        if (bccomp($amountMinor, '0', 0) <= 0) {
            throw new \DomainException('Debit amount must be positive');
        }
        if (bccomp($this->balanceMinor, $amountMinor, 0) < 0) {
            throw new \DomainException('Insufficient funds');
        }
        $this->balanceMinor = bcsub($this->balanceMinor, $amountMinor, 0);
    }

    public function credit(string $amountMinor): void
    {
        if (bccomp($amountMinor, '0', 0) <= 0) {
            throw new \DomainException('Credit amount must be positive');
        }
        $this->balanceMinor = bcadd($this->balanceMinor, $amountMinor, 0);
    }
}
