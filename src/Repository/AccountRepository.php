<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    public function findOneByAccountNumber(string $accountNumber): ?Account
    {
        return $this->findOneBy(['accountNumber' => $accountNumber]);
    }

    /**
     * Acquire a pessimistic write lock (SELECT ... FOR UPDATE) on the account row.
     * Must be called inside an active DB transaction.
     */
    public function findAndLock(string $accountNumber): ?Account
    {
        return $this->createQueryBuilder('a')
            ->where('a.accountNumber = :num')
            ->setParameter('num', $accountNumber)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
