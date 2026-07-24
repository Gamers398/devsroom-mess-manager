<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Models\BackupConfig;
use App\Models\BackupLog;
use App\Models\Mess;
use App\Models\User;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Notifications\EventHandler;
use Tests\TestCase;

/**
 * Workstream 3 (2026-07-24) — backup visibility.
 *
 *  1. The scheduler-health banner surfaces a missing server cron when no
 *     backup exists despite an automatic cadence being configured.
 *  2. LogScheduledBackupActivity writes a BackupLog row for scheduled (CLI)
 *     backup outcomes so they appear on the Activity log.
 */
class ScheduledBackupLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTyroRoles();

        Mess::factory()->create(['name' => 'Test Mess']);
        config(['mess.active_mess_id' => Mess::first()->id]);
        Mess::forgetActiveIdCache();

        // The index resolves the `backups` disk; fake it so the empty-creds s3
        // adapter doesn't crash (mirrors BackupControllerAuthTest).
        Storage::fake('backups');

        // BackupConfig::current() is statically memoized per process; other
        // backup tests seed a row that survives RefreshDatabase as a stale
        // cache entry. Flush so each test here reads the true DB state, and
        // clear any leftover singleton row so the "no config" + "id=1" cases
        // are deterministic regardless of test-run ordering.
        BackupConfig::flushCache();
        BackupConfig::query()->delete();
        BackupConfig::flushCache();
    }

    protected function tearDown(): void
    {
        EventHandler::enable();
        parent::tearDown();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::where('slug', 'super-admin')->first());

        return $user;
    }

    /**
     * Default cadence is daily (BackupConfig in-memory fallback when no row),
     * and there are no zips + no success logs → the banner must show the cron line.
     */
    public function test_scheduler_health_banner_warns_when_no_backup_exists(): void
    {
        $response = $this->actingAs($this->superAdmin())->get('/dashboard/backups');

        $response->assertOk();
        $response->assertSee(__('Automatic backups may not be running'));
        $response->assertSee(__('No backup has ever been created'), false);
        $response->assertSee('artisan schedule:run'); // the copyable cron line
    }

    /**
     * When backups are intentionally off, the banner must not appear.
     */
    public function test_scheduler_health_banner_hidden_when_backups_off(): void
    {
        // Force the singleton PK = 1. `id` is guarded (not mass-assignable), so
        // set it on the instance — BackupConfig::current() does find(1), and a
        // plain create() would get an auto-increment id after other backup
        // tests' rolled-back inserts, making find(1) miss it.
        $cfg = new BackupConfig;
        $cfg->id = 1;
        $cfg->frequency = 'off';
        $cfg->run_at = '01:30:00';
        $cfg->keep_all_days = 7;
        $cfg->max_mb = 5000;
        $cfg->save();
        BackupConfig::flushCache();

        $response = $this->actingAs($this->superAdmin())->get('/dashboard/backups');

        $response->assertOk();
        $response->assertDontSee(__('Automatic backups may not be running'));
    }

    /**
     * Dispatching a spatie BackupHasFailed (as the scheduler would) writes a
     * failure row to the on-page Activity log. phpunit runs in the console, so
     * the runningInConsole() guard passes — exactly the scheduled-run path.
     */
    public function test_scheduled_backup_failure_is_logged_to_activity_log(): void
    {
        // Silence spatie's own mail/notification EventHandler (needs the backups disk).
        EventHandler::disable();

        $this->assertFalse(BackupLog::query()->where('action', 'backup')->exists());

        event(new BackupHasFailed(new \RuntimeException('mysqldump not found'), 'backups'));

        $row = BackupLog::query()->where('action', 'backup')->where('status', 'failure')->first();
        $this->assertNotNull($row, 'Scheduled backup failure was not logged to the Activity log.');
    }
}
