<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TransferRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $fromAccount,

        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $toAccount,

        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^\d+$/', message: 'Amount must be a positive integer (minor units)')]
        public string $amountMinor,

        #[Assert\NotBlank]
        #[Assert\Length(exactly: 3)]
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'Currency must be a 3-letter ISO code')]
        public string $currency,
    ) {}
}
