<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Closure;

/**
 * A check defined inline: `Ledge::check('name', fn (): ?CheckResult => ...)`.
 * The callback receives the check name and returns a CheckResult, or null to
 * omit the check from the payload for this run.
 */
final class CallbackCheck implements CheckInterface
{
    /**
     * @param  Closure(string): ?CheckResult  $callback
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $callback,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function run(): ?CheckResult
    {
        return ($this->callback)($this->name);
    }
}
