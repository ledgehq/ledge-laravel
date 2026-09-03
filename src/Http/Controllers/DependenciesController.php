<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LedgeHQ\Ledge\DependenciesService;
use Throwable;

class DependenciesController
{
    public function __invoke(DependenciesService $dependencies): JsonResponse
    {
        try {
            $packages = $dependencies->getPackages();
        } catch (Throwable) {
            return new JsonResponse(['error' => 'Composer runtime data is unavailable'], 503);
        }

        return new JsonResponse([
            'composer' => $packages,
            'generatedAt' => date('c'),
            'hash' => $dependencies->getHash($packages),
        ]);
    }
}
