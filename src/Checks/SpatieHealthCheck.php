<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Enums\Status;
use Throwable;

/**
 * Exposes a spatie/laravel-health check to Ledge without rewriting it:
 *
 *   Ledge::register(SpatieHealthCheck::wrap(UsedDiskSpaceCheck::new()));
 *
 * ok → healthy, warning → degraded, failed / crashed → unhealthy, skipped →
 * omitted. The spatie result's meta becomes the check's data and its
 * notification message (or short summary) becomes the output. The check's
 * own schedule expression is ignored; it runs on every health poll.
 *
 * spatie/laravel-health is not a dependency of this package; install it
 * yourself before wrapping its checks.
 */
final class SpatieHealthCheck implements CheckInterface
{
    public function __construct(
        private readonly Check $check,
        private readonly ?string $name = null,
    ) {}

    public static function wrap(Check $check, ?string $name = null): self
    {
        return new self($check, $name);
    }

    public function getName(): string
    {
        return $this->name ?? lcfirst($this->check->getName());
    }

    public function run(): ?CheckResult
    {
        try {
            $result = $this->check->run();
        } catch (Throwable $e) {
            return CheckResult::unhealthy($this->getName(), ['source' => 'spatie/laravel-health'], $e->getMessage());
        }

        if ($result->status->equals(Status::skipped())) {
            return null;
        }

        $status = match (true) {
            $result->status->equals(Status::ok()) => CheckResult::STATUS_HEALTHY,
            $result->status->equals(Status::warning()) => CheckResult::STATUS_DEGRADED,
            default => CheckResult::STATUS_UNHEALTHY,
        };

        $output = $result->getNotificationMessage() !== '' ? $result->getNotificationMessage() : $result->getShortSummary();

        return new CheckResult(
            $this->getName(),
            $status,
            $result->meta + ['source' => 'spatie/laravel-health'],
            $output !== '' ? $output : null,
        );
    }
}
