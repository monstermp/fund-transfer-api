<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Exception\TransferException;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TransferService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository $accountRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Atomically transfer funds between two accounts.
     *
     * Concurrency strategy:
     *  - Wrap the whole operation in a DB transaction.
     *  - Acquire pessimistic write locks on BOTH account rows
     *    in a deterministic order (alphabetical by account number)
     *    to avoid deadlocks under high concurrency.
     *  - On any failure the DB transaction is rolled back so balances
     *    never end up in an inconsistent state.
     *
     * @param string $amountMinor Amount in minor units (e.g. cents) as a string for bcmath safety.
     */
    public function transfer(
        string $fromAccountNumber,
        string $toAccountNumber,
        string $amountMinor,
        string $currency,
        ?string $idempotencyKey = null,
    ): Transaction {
        if ($fromAccountNumber === $toAccountNumber) {
            throw TransferException::sameAccount();
        }

        // Lock order: sort the account numbers so concurrent A->B and B->A
        // transfers always acquire locks in the same global order.
        $lockOrder = [$fromAccountNumber, $toAccountNumber];
        sort($lockOrder);

        $this->em->beginTransaction();
        try {
            $locked = [];
            foreach ($lockOrder as $number) {
                $account = $this->accountRepository->findAndLock($number);
                if (!$account) {
                    throw TransferException::accountNotFound($number);
                }
                $locked[$number] = $account;
            }

            $from = $locked[$fromAccountNumber];
            $to = $locked[$toAccountNumber];

            if ($from->getCurrency() !== strtoupper($currency)
                || $to->getCurrency() !== strtoupper($currency)
            ) {
                throw TransferException::currencyMismatch();
            }

            $transaction = new Transaction($from, $to, $amountMinor, $currency, $idempotencyKey);
            $this->em->persist($transaction);

            try {
                $from->debit($amountMinor);
                $to->credit($amountMinor);
            } catch (\DomainException $e) {
                $transaction->markFailed($e->getMessage());
                $this->em->flush();
                $this->em->commit();
                throw TransferException::insufficientFunds();
            }

            $transaction->markCompleted();
            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Transfer completed', [
                'transaction_id' => (string) $transaction->getId(),
                'from' => $fromAccountNumber,
                'to' => $toAccountNumber,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ]);

            return $transaction;
        } catch (TransferException $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            $this->logger->error('Unexpected transfer failure', [
                'error' => $e->getMessage(),
                'from' => $fromAccountNumber,
                'to' => $toAccountNumber,
            ]);
            throw $e;
        }
    }
}
