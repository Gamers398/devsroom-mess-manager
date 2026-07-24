---
status: complete
quick_id: 260724-pm2
date: 2026-07-24
---

# Quick Task 260724-pm2 — SUMMARY

**Batch of 6 items from the 2026-07-24 request.** Two were answered in prose (not code); four were built across 4 atomic commits on `master`.

## Answered (no code)

- **#3 — why the member balances system exists.** Explained the two-column model (`advance_balances.balance` = credit/prepaid; `due_balance` = owed), why they're kept separate (so month-close settlement math doesn't double-count — advance is *applied* against the new bill before any remainder becomes dues), and that display surfaces show a single net = credit − dues. Documented in the README "Balance system" subsection.
- **#6 — why no backups were being made (diagnosis).** DB query confirmed `backup_configs` is correctly set (daily 01:30, keep 7d, cap 5000 MB) but `backup_logs` is empty and no zips exist. Root cause: the per-minute server cron (`schedule:run`) is operator-managed and almost certainly not installed on CloudPanel; secondary: only a local destination is configured. Delivered the exact cron line + 3-step fix to the user. (Two code-side visibility improvements were also built — see Workstream 3.)

## Built (4 commits, all on master)

| # | Workstream | Commit | Files |
|---|------------|--------|-------|
| 1 | Dashboard: Total meals card + split Credit/Dues cards | `a3af885` | DashboardService, home.blade, ManagerDashboardTest |
| 2 | Remove the restore-test subsystem entirely | `894c0d7` | service/command/model/factory/test/_health_badge deleted; drop migration; console.php, web.php, BackupController, index.blade, config/backup.php, config/database.php, .env.example, BackupLog/BackupConfig/BackupRestoreService docs; 3 test files updated |
| 3 | Backup visibility: scheduled-run logging + scheduler-health banner | `179d000` | LogScheduledBackupActivity listener (new), AppServiceProvider wiring, BackupController schedulerHealth, index.blade banner, ScheduledBackupLoggingTest (new) |
| 4 | README full feature guide + balance explainer | `1800fd1` | README.md |

### Workstream 1 — Dashboard cards
`DashboardService::managerCards()` now also returns `total_meals`, `total_credit` (Σ `advance_balance`), `total_dues` (Σ `due_balance`), all bill-derived from the existing cached preview (no new queries). `home.blade.php` renders 7 cards: Total Members, Today's Meals, **Total Meals (this month)**, Current Meal Rate, Monthly Expenses, **Total Credit (advance)**, **Total Dues** — the old single net card is replaced by the two gross figures. `total_member_balance` kept for backward compat. 2 new tests.

### Workstream 2 — Restore-test removal
Removed: `RestoreTestService`, `RestoreTestRun` (`backup:restore-test`), `RestoreTest` model + factory, `_health_badge.blade.php`, the `restore_tests` table (forward-only drop migration `2026_07_24_130000`), the 03:00 schedule entry, the `restore-test.run` route, `BackupController::runRestoreTest`, the `restore_test_enabled` config key, the `mysql_restore_test` DB connection, and the `DB_RESTORE_TEST_DATABASE` env key. Real Backups + the one-click Restore are untouched. Tests updated to assert the removal (`assertNotFound`, `assertStringNotContainsString`). Stale comments cleaned. Historical migration files left intact.

### Workstream 3 — Backup visibility
- **`LogScheduledBackupActivity`** listener subscribes to the six spatie events (`BackupWasSuccessful`, `BackupHasFailed`, `CleanupWasSuccessful`, `CleanupHasFailed`, `HealthyBackupWasFound`, `UnhealthyBackupWasFound`) and writes a `BackupLog` row **only when `app()->runningInConsole()`** — so scheduled/CLI runs appear on the Activity log, while a manual HTTP "Backup now" (already logged by the controller) is not double-logged. Registered in `AppServiceProvider::registerBackupFailureListeners()`.
- **Scheduler-health banner**: `BackupController::schedulerHealth()` compares `BackupConfig` cadence against the newest backup moment (max of newest zip mtime + newest success log); when missed it returns an issue + the exact cron line (built from `base_path()` + `PHP_BINARY`). The Backups page renders a yellow banner with the issue, the copyable cron line, and the "click Backup now to test" hint — directly surfacing the missing-cron cause from #6. Thresholds: daily → 25 h, weekly → 7.5 d, monthly → 31 d; `off` → never warns. 3 new tests.
- **Test gotcha fixed**: `BackupConfig::$current` is statically memoized and MySQL auto-increment doesn't revert on transaction rollback, so the singleton test clears the row + memo in setUp and forces `id = 1` via instance assignment (`id` is guarded).

### Workstream 4 — README
Added a **Feature guide** section with step-by-step how-tos for every feature (members, meal grid, meal off, guest meals, bazar, fixed expenses, payments, bill preview, month-close, corrections, reports, dashboard, notifications) + a dedicated **balance-system explainer**. Updated the dashboard cards (now 7) and the Backups section (restore-test removed; scheduler-health banner + scheduled-run activity logging documented; the silent-swallow catch preserved).

## Tests

- Full suite **374 tests / 973 assertions green** (`php -d memory_limit=1024M vendor/bin/phpunit`). No regressions.
- `vendor/bin/pint --test` clean on all changed files.

## Deploy notes (prod)

- `php artisan migrate` — runs the `drop_restore_tests_table` migration (forward-only; drops an empty/test table — no user data affected).
- `php artisan config:clear` + `php artisan cache:clear`.
- `php artisan view:clear` (so the new banner + removed restore-test button render).
- **Install the per-minute cron on CloudPanel if not already present** (the #6 root cause): `* * * * * cd /home/wpmhs-mess/htdocs/mess.wpmhs.com && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1`. After deploy the new scheduler-health banner on the Backups page will confirm whether it's firing.
- Consider configuring at least one off-site backup destination (DO Spaces / R2 / GDrive) — currently local-only, which dies with the server.
