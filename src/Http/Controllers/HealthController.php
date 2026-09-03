<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LedgeHQ\Ledge\Checks\CheckResult;
use LedgeHQ\Ledge\HealthService;

class HealthController
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $payload = $health->run();

        return new JsonResponse($payload, $payload['status'] === CheckResult::STATUS_UNHEALTHY ? 503 : 200);
    }
}
