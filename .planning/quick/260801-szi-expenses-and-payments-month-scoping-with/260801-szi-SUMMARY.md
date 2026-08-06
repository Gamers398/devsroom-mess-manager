---
quick_task: 260801-szi
plan: 01
status: complete
subsystem: reports-listing-billing
tags: [expenses, payments, monthly-report, brought-forward, period-scoping, filtering]
started: "2026-08-01T19:42:48Z"
completed: "2026-08-01T19:49:35Z"
duration: ~7m
branch: master
commits:
  - "6beaf5a: feat(260801-szi): scope expenses/payments lists by month with current-month default"
  - "b12ff75: feat(260801-szi): add brought-forward split to bill preview, month-close, and reports"
  - "684736d: feat(260801-szi): restructure monthly report into Brought forward / This month / Closing"
tech-stack:
  added: ["app/Support/Period.php (shared period-scoping helper)"]
  patterns: ["Year/Month period selector replacing From/To date pickers", "Cache-key version bump (v1 -> v2) for backward-incompatible preview shape change", "Brought-forward opening-net split in report tables"]
key-files:
  created:
    - app/Support/Period.php
    - database/migrations/2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php
  modified:
    - app/Services/ExpenseService.php
    - app/Services/PaymentService.php
    - app/Http/Controllers/Mess/ExpenseController.php
    - app/Http/Controllers/Mess/PaymentController.php
    - resources/views/mess/expenses/index.blade.php
    - resources/views/mess/payments/index.blade.php
    - app/Services/BillPreviewService.php
    - app/Services/MonthCloseService.php
    - app/Services/ReportService.php
    - app/Models/MonthlyMemberSummary.php
    - resources/views/mess/reports/monthly.blade.php
decisions:
  - "All 4 LOCKED decisions from CONTEXT.md implemented exactly as specified"
  - "Settlement model unchanged (carry-forward); changes are additive presentation + filtering only"
  - "Cache key bumped v1->v2 so no stale preview without brought_forward can be served"
---

# Quick Task 260801-szi: Expenses/Payments month-scoping + Monthly Report balance split + settlement clarity

## Summary

Implemented the four LOCKED decisions: both Expenses and Payments list pages now default to the current month with a Year/Month period selector (This month / Specific month / Whole year / All time); the Monthly Report splits each member row into Brought forward / This month (Bill/Paid/Due) / Closing (net) so a prior-month advance is never misread as this-month income; month-close freezes the brought-forward amount into the snapshot; and the settlement engine is completely untouched.

## What Shipped

### Task 1: Expenses & Payments list — month/year period scoping (commit 6beaf5a)

- **`app/Support/Period.php`** (new): a final static helper mirroring `PaymentType`/`ExpenseKind` style. Constants `THIS_MONTH`/`MONTH`/`YEAR`/`ALL` + `MODES`. `apply(Builder, Request, column)` resolves `period`/`year`/`month` from the query string (default current month, clamped 1-12, rejects unknown modes) and scopes the query via `whereYear`/`whereMonth`. `options()` returns the `[value => label]` dropdown map.
- **`ExpenseService::list()`**: calls `Period::apply($query, $request)` right after the query is built, before the kind filter. The default (no query params) now scopes to the current month — the "current month starts fresh" requirement satisfied by default-scoping with no data migration.
- **`PaymentService::list()`**: the `from`/`to` date-pickers logic is removed entirely; `Period::apply($query, $request)` is called right after the query is built. Member + method filters, `latest('date')->latest('id')` ordering, and `paginate(50)->withQueryString()` are all retained.
- **`ExpenseController::index()`**: passes `$periodOptions` and `$filters = $request->only(['period','year','month','kind'])` to the view.
- **`PaymentController::index()`**: `$filters` changed from `['member_id','method','from','to']` to `['member_id','method','period','year','month']`; `$periodOptions` added.
- **`expenses/index.blade.php`**: new GET filter form above the list (Period select + Year select + Month select + Kind select + Filter/Reset). Mobile cards, desktop table, and pagination untouched.
- **`payments/index.blade.php`**: From/To date inputs replaced with Period + Year + Month selects. Member and Method selects and the `_list` partial (with pagination) untouched.

### Task 2: Brought-forward computation + cache-key version bump + additive column + month-close freeze + report surfacing (commit b12ff75)

- **Migration** (`2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php`): adds nullable `brought_forward DECIMAL(12,2)` column after `balance_due`. Additive — safe for normal `php artisan migrate` on prod. Applied cleanly.
- **`MonthlyMemberSummary` model**: `brought_forward` added to `#[Fillable]` and `casts()` (`'decimal:2'`).
- **`BillPreviewService::compute()`**: computes `$broughtForward = round(($advanceBalance - $dueBalance) - $advancePayments, 2)` per member (the three inputs already exist in the row builder) and adds `'brought_forward'` to each `$rows[]` entry. Cache key bumped from `bill-preview:` to `bill-preview:v2:` in the single `cacheKey()` source — `BillPreviewInvalidator` and `AppServiceProvider` go through `cacheKey()`, so reads, writes, and all invalidation paths re-key automatically. `emptyPreview()` also returns the key.
- **`MonthCloseService::close()`**: the summary `create([...])` call now includes `'brought_forward' => $this->money($row['brought_forward'] ?? 0)` — frozen from the pre-mutation preview via the existing `money()` helper (canonical 2-decimal string, never round-trips through float). Settlement block (consumeAdvance/carryForward/settle) untouched.
- **`ReportService::monthlyFromSnapshot()`**: snapshot row now includes `'brought_forward' => $row->brought_forward !== null ? (float) $row->brought_forward : 0.0`. The live path already returns the field after the BillPreviewService change, so no separate live-path edit was needed.

### Task 3: Monthly Report view — Brought forward / This month / Closing (net) (commit 684736d)

- **`monthly.blade.php`** per-member table restructured with a two-row `<thead>`: Member (rowspan=2), Status (rowspan=2), This month (colspan=4: Meals/Bill/Paid/Due), Brought forward (rowspan=2), Closing (net) (rowspan=2).
- **Brought forward column**: reads `(float) ($row['brought_forward'] ?? 0)`; sign-aware rendering matching the existing Balance cell (positive=emerald "Credit", negative=rose "Owes", zero=plain `Money::taka(0)`). Pre-deploy snapshot rows (NULL column) degrade gracefully to 0.00.
- **Closing (net) column**: the existing `$rowNet` validated math (`advance_balance + bill_payments - bill - due_balance` for live; `advance_balance - due_balance` for snapshot) is preserved byte-for-byte; only the column header relabelled from "Balance" to "Closing (net)".
- **Footnote**: rewritten to explain the three-part split: Brought forward = opening net from before this month; This month = bill/paid/due for the current month only; Closing (net) = brought forward + this-month net.
- Meal cost / Fixed / Guest columns removed from the per-member table (details still available in the member-statement and totals grid). Totals grid (6 cards) and all `$data`/`$year`/`$month` wiring unchanged. No JS added.

## Deviations from Plan

None — all changes match the plan and CONTEXT.md LOCKED decisions exactly.

One verify-script adaptation (not a code deviation): Task 1 verify step 3 specified `app('db')->table('expenses')->getQuery()` which is not a valid method on the query builder; used `App\Models\Expense::query()` (a proper Eloquent Builder) instead — functionally equivalent and proves the helper loads and the default-mode signature resolves.

## Verification Results

| Check | Result |
|-------|--------|
| `php artisan view:cache` | PASS (all modified blades compile) |
| `php artisan migrate` (additive) | PASS (`brought_forward` column confirmed via `Schema::hasColumn`) |
| `vendor/bin/pint --test` on all 13 modified files | PASS |
| `Period::apply` in both services | 1 match each (PASS) |
| From/To logic removed from PaymentService | No matches (PASS) |
| `name="period"` in both index blades | Present in both (PASS) |
| `name="from"`/`name="to"` in payments blade | No matches (PASS) |
| Cache key `bill-preview:v2:` single source | 1 match, no stale old keys (PASS) |
| `brought_forward` in preview output | `HAS_KEY` (PASS) |
| Model fillable + cast | Count = 2 (PASS) |
| MonthClose freezes via `$this->money()` | 1 match (PASS) |
| ReportService surfaces `brought_forward` | 1 match (PASS) |
| "Brought forward" in monthly report | Count = 3 (PASS) |
| "Closing (net)" in monthly report | Count = 3 (PASS) |
| Validated rowNet math intact | Count = 4 (PASS) |
| `migrate:fresh` / `db:seed` executed | NO (never run — guardrail honored) |

## Deploy Steps

```bash
php artisan migrate      # additive only — adds brought_forward column
php artisan config:clear
php artisan view:clear
```

No destructive commands. No settlement math altered.

## Manual Smoke (deferred to human)

Load `/mess/reports/monthly` for the current open month and confirm Rakib (the member with the 2,000 prior-month advance, no this-month payment) shows: Brought forward 2,000, This month bill 0 / paid 0 / due 0, Closing Credit 2,000 — the credit is clearly labelled as brought forward, not as this-month activity.

## Self-Check: PASSED

- `app/Support/Period.php` — FOUND (created)
- `database/migrations/2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php` — FOUND (created)
- Commit `6beaf5a` — FOUND
- Commit `b12ff75` — FOUND
- Commit `684736d` — FOUND
