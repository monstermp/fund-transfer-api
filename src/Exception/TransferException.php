<?php

declare(strict_types=1);

namespace App\Exception;

class TransferException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public static function accountNotFound(string $accountNumber): self
    {
        return new self("Account '$accountNumber' not found", 'ACCOUNT_NOT_FOUND', 404);
    }

    public static function insufficientFunds(): self
    {
        return new self('Insufficient funds in source account', 'INSUFFICIENT_FUNDS', 422);
    }

    public static function currencyMismatch(): self
    {
        return new self('Currency mismatch between accounts', 'CURRENCY_MISMATCH', 422);
    }

    public static function sameAccount(): self
    {
        return new self('Source and destination accounts must be different', 'SAME_ACCOUNT', 422);
    }
}
