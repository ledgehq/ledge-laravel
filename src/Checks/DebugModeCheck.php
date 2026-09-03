<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Settings;

class DebugModeCheck implements CheckInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly Settings $settings,
    ) {}

    public function getName(): string
    {
        return 'debugMode';
    }

    public function run(): ?CheckResult
    {
        $debug = (bool) $this->config->get('app.debug', false);
        $status = $this->settings->debugModeStatus;

        $meta = [
            'debug' => $debug,
            'environment' => $this->app->environment(),
            'thresholds' => ['debugModeStatus' => $status],
        ];

        if ($debug && $status !== CheckResult::STATUS_HEALTHY) {
            return new CheckResult($this->getName(), $status, $meta, 'APP_DEBUG is enabled');
        }

        return CheckResult::healthy($this->getName(), $meta);
    }
}
