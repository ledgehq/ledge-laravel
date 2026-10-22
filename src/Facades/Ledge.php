<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Facades;

use Illuminate\Support\Facades\Facade;
use LedgeHQ\Ledge\Checks\CheckInterface;
use LedgeHQ\Ledge\HealthService;

/**
 * @method static void register(CheckInterface $check)
 * @method static void check(string $name, \Closure $callback)
 * @method static void forget(string $name)
 * @method static array<string, CheckInterface> checks()
 * @method static array<string, mixed> run()
 *
 * @see HealthService
 */
class Ledge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HealthService::class;
    }
}
