<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Events\CommandFinished;
use LedgeHQ\Ledge\Support\SchedulerStore;
use LedgeHQ\Ledge\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ListenersTest extends TestCase
{
    public function test_failed_logins_are_counted_within_the_window(): void
    {
        $this->app['events']->dispatch(new Failed('web', null, ['email' => 'a@example.com']));
        $this->app['events']->dispatch(new Failed('web', null, ['email' => 'a@example.com']));

        $this->health()->assertJsonPath('checks.failedLogins.count', 2)->assertJsonPath('checks.failedLogins.status', 'healthy');
    }

    public function test_schedule_run_completion_is_recorded(): void
    {
        $this->app['events']->dispatch(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));
        self::assertNull($this->app->make(SchedulerStore::class)->lastRunAt());

        $this->app['events']->dispatch(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput, 0));

        self::assertNotNull($this->app->make(SchedulerStore::class)->lastRunAt());
        $this->health()->assertJsonPath('checks.scheduler.status', 'healthy')->assertJsonPath('checks.scheduler.ageSeconds', 0);
    }

    public function test_a_stale_scheduler_degrades(): void
    {
        $this->app->make(SchedulerStore::class)->record(time() - 3600);

        $this->health()
            ->assertJsonPath('checks.scheduler.status', 'degraded')
            ->assertJsonPath('checks.scheduler.thresholds.staleAfter', 600);
    }
}
