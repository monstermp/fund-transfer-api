<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TransferRequest;
use App\Exception\TransferException;
use App\Service\IdempotencyService;
use App\Service\TransferService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1')]
class TransferController
{
    public function __construct(
        private readonly TransferService $transferService,
        private readonly IdempotencyService $idempotency,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/transfers', name: 'transfer_create', methods: ['POST'])]
    public function transfer(
        Request $request,
        #[MapRequestPayload] TransferRequest $payload,
    ): JsonResponse {
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        // Replay cached response if this key has already produced one
        if ($idempotencyKey !== null) {
            $cached = $this->idempotency->get($idempotencyKey);
            if ($cached !== null && ($cached['status'] ?? null) !== 'pending') {
                return new JsonResponse($cached, $cached['_http_status'] ?? 201, [
                    'Idempotent-Replay' => 'true',
                ]);
            }

            if (!$this->idempotency->reserve($idempotencyKey)) {
                return new JsonResponse([
                    'error' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'A request with this Idempotency-Key is already in progress',
                ], 409);
            }
        }

        try {
            $transaction = $this->transferService->transfer(
                $payload->fromAccount,
                $payload->toAccount,
                $payload->amountMinor,
                $payload->currency,
                $idempotencyKey,
            );

            $response = [
                'transactionId' => (string) $transaction->getId(),
                'status' => $transaction->getStatus(),
                'fromAccount' => $transaction->getFromAccount()->getAccountNumber(),
                'toAccount' => $transaction->getToAccount()->getAccountNumber(),
                'amountMinor' => $transaction->getAmountMinor(),
                'currency' => $transaction->getCurrency(),
                'completedAt' => $transaction->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            ];

            if ($idempotencyKey !== null) {
                $this->idempotency->store($idempotencyKey, $response + ['_http_status' => 201]);
            }

            return new JsonResponse($response, 201);
        } catch (TransferException $e) {
            if ($idempotencyKey !== null) {
                // Release the reservation so the client can retry with a corrected payload
                $this->idempotency->release($idempotencyKey);
            }
            throw $e;
        }
    }
}
