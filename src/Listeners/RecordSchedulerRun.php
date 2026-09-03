<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Listeners;

use Illuminate\Console\Events\CommandFinished;
use LedgeHQ\Ledge\Support\SchedulerStore;

class RecordSchedulerRun
{
    public function __construct(private readonly SchedulerStore $store) {}

    public function handle(CommandFinished $event): void
    {
        if ($event->command === 'schedule:run') {
            $this->store->record();
        }
    }
}
