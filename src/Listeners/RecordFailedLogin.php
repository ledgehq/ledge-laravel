<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Listeners;

use Illuminate\Auth\Events\Failed;
use LedgeHQ\Ledge\Support\FailedLoginStore;

class RecordFailedLogin
{
    public function __construct(private readonly FailedLoginStore $store) {}

    public function handle(Failed $event): void
    {
        $this->store->record();
    }
}
