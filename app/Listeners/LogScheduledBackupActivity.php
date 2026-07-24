<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\BackupLog;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * Writes a BackupLog row for SCHEDULED backup outcomes so the nightly
 * backup:run / backup:purge / backup:monitor appear on the Backups page
 * Activity log — not just the manual "Backup now" button (which the controller
 * already logs itself).
 *
 * Logs ONLY when running in the console (`schedule:run` / artisan). A manual
 * "Backup now" calls `backup:run` during an HTTP request, which the controller
 * already logs via recordLog(); the runningInConsole() guard keeps a manual run
 * from producing a second row for the same event.
 */
class LogScheduledBackupActivity
{
    public function handle(
        BackupWasSuccessful|BackupHasFailed|CleanupWasSuccessful|CleanupHasFailed|HealthyBackupWasFound|UnhealthyBackupWasFound $event,
    ): void {
        if (! app()->runningInConsole()) {
            return;
        }

        [$action, $status, $message] = $this->map($event);

        try {
            BackupLog::create([
                'action' => $action,
                'status' => $status,
                'message' => $message,
            ]);
        } catch (\Throwable) {
            // Logging must never break the backup pipeline (e.g. a fresh
            // deploy whose backup_logs table hasn't been migrated yet).
        }
    }

    /**
     * Map a spatie event to a (action, status, message) BackupLog triple.
     * Uses generic messages (no event-property access) so a spatie version
     * bump that renames a property can't break the listener.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function map(object $event): array
    {
        return match (true) {
            $event instanceof BackupWasSuccessful => ['backup', 'success', __('Scheduled backup completed.')],
            $event instanceof BackupHasFailed => ['backup', 'failure', __('Scheduled backup failed.')],
            $event instanceof CleanupWasSuccessful => ['purge', 'success', __('Retention purge completed.')],
            $event instanceof CleanupHasFailed => ['purge', 'failure', __('Retention purge failed.')],
            $event instanceof HealthyBackupWasFound => ['monitor', 'success', __('Backups healthy.')],
            $event instanceof UnhealthyBackupWasFound => ['monitor', 'failure', __('Backups unhealthy.')],
            default => ['backup', 'failure', class_basename($event)],
        };
    }
}
