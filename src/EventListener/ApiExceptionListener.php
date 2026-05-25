<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\TransferException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener(event: 'kernel.exception', method: 'onKernelException', priority: 100)]
class ApiExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof TransferException) {
            $event->setResponse(new JsonResponse([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus));
            return;
        }

        if ($exception instanceof HttpException) {
            $previous = $exception->getPrevious();
            if ($previous instanceof ValidationFailedException) {
                $violations = [];
                foreach ($previous->getViolations() as $v) {
                    $violations[] = [
                        'field' => $v->getPropertyPath(),
                        'message' => $v->getMessage(),
                    ];
                }
                $event->setResponse(new JsonResponse([
                    'error' => 'VALIDATION_FAILED',
                    'violations' => $violations,
                ], 422));
                return;
            }

            $event->setResponse(new JsonResponse([
                'error' => 'HTTP_ERROR',
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode()));
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'INTERNAL_ERROR',
            'message' => 'An unexpected error occurred',
        ], 500));
    }
}
