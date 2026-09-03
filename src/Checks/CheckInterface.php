<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

interface CheckInterface
{
    public function getName(): string;

    public function run(): ?CheckResult;
}
