<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\AcquireService;
use Psr\Log\LoggerInterface;
use Throwable;

class AcquireController
{
    public function __construct(
        private readonly AcquireService $acquire,
        private readonly LoggerInterface $logger,
    ) {}

    public function accept(Request $request): JsonResponse
    {
        try {
            $command = $this->acquire->accept($request->getContent());
        } catch (AcquireException $e) {
            $this->logger->warning(
                "Ledge acquire command rejected: {$e->reason}".($e->detail !== null ? " ({$e->detail})" : ''),
            );

            return new JsonResponse(['accepted' => false, 'reason' => $e->reason], $e->httpStatus);
        } catch (Throwable $e) {
            $this->logger->error("Ledge acquire command failed unexpectedly: {$e->getMessage()}");

            return new JsonResponse(['accepted' => false, 'reason' => 'unexpected_error'], 500);
        }

        return new JsonResponse(['accepted' => true, 'run_id' => $command->runId], 202);
    }

    public function status(string $runId): JsonResponse
    {
        $status = $this->acquire->getStatus($runId);

        if ($status === null) {
            return new JsonResponse(['reason' => 'unknown_run_id'], 404);
        }

        return new JsonResponse($status);
    }
}
