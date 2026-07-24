<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Nightly backup schedule (research Pattern 8).
 *
 * backup:purge / backup:run / backup:monitor are scheduled nightly, mirroring
 * the existing telescope:prune class_exists guard pattern. backup:run uses
 * withoutOverlapping (a slow run must not double up) and onOneServer (database
 * cache store already in use, so the lock works out of the box).
 *
 * (backup:restore-test was removed with the restore-test subsystem.)
 */
class ScheduledBackupCommandsTest extends TestCase
{
    /**
     * The three backup commands appear in the schedule. We assert via the
     * Schedule facade's events list (more reliable than parsing schedule:list
     * CLI output, which has platform-dependent color codes).
     */
    public function test_all_three_backup_commands_are_scheduled(): void
    {
        $commands = collect(Schedule::events())
            ->map(fn ($event) => $event->command)
            ->implode("\n");

        // backup:purge (the app's DB-driven cap cleaner) replaces spatie's
        // config-only backup:clean — see routes/console.php.
        $this->assertStringContainsString('backup:purge', $commands, 'backup:purge is not scheduled.');
        $this->assertStringContainsString('backup:run', $commands, 'backup:run is not scheduled.');
        $this->assertStringContainsString('backup:monitor', $commands, 'backup:monitor is not scheduled.');

        // The restore-test command was removed entirely.
        $this->assertStringNotContainsString('backup:restore-test', $commands);
    }

    /**
     * The long-running backup:run command uses withoutOverlapping + has a real
     * cron expression.
     */
    public function test_long_running_backup_run_uses_without_overlapping(): void
    {
        $events = collect(Schedule::events())
            ->keyBy(fn ($event) => $event->command);

        // Strip the "php artisan" prefix for lookup robustness.
        $runEvent = $events->first(fn ($event) => str_contains((string) $event->command, 'backup:run'));

        $this->assertNotNull($runEvent, 'backup:run is not scheduled.');

        $this->assertNotEmpty(
            $runEvent->expression,
            'backup:run has no cron expression (not actually scheduled).',
        );

        // withoutOverlapping flips the withoutOverlapping flag on the event.
        $this->assertTrue(
            $runEvent->withoutOverlapping,
            'backup:run is missing withoutOverlapping (a slow run could double up).',
        );
    }
}
