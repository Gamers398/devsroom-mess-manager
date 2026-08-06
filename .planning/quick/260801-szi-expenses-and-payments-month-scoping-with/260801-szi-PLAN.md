---
quick_task: 260801-szi
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Support/Period.php
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
  - database/migrations/2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php
  - resources/views/mess/reports/monthly.blade.php
autonomous: true
requirements: [quick-260801-szi]

must_haves:
  truths:
    - Expenses index defaults to the current month and can be re-scoped by specific month, whole year, or all time (pagination retained)
    - Payments index defaults to the current month via the new period selector and no longer offers From/To date pickers (member + method filters retained; pagination retained)
    - A member's carried-forward balance is never presented as this-month credit — the Monthly Report shows Brought forward, This month (bill/paid/due), and Closing (net) as distinct values
    - After closing a month, each member's brought-forward amount is frozen into the snapshot and surfaces identically on the closed-month report
    - Stale pre-change bill previews (lacking the brought_forward field) can never be served from cache
    - No existing settlement math (consumeAdvance/carryForward/settle/applyPayment) is altered
  artifacts:
    - app/Support/Period.php
    - database/migrations/2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php
  key_links:
    - ExpenseService::list → Period::apply
    - PaymentService::list → Period::apply
    - BillPreviewService::cacheKey → v2 versioned key
    - BillPreviewService::compute member row → brought_forward
    - MonthCloseService::close snapshot → brought_forward column
    - ReportService::monthlyFromSnapshot member row → brought_forward
    - monthly.blade.php per-member table → Brought forward / This month / Closing columns
---

<objective>
Implement the four LOCKED decisions in 260801-szi-CONTEXT.md:
1. Expenses & Payments list pages get a Year/Month period selector (This month default, Specific month, Whole year, All time) with pagination retained.
2. The Monthly Report stops presenting a member's carried-forward advance as this-month credit by splitting each row into Brought forward / This month / Closing (net).
3. Month-close freezes the brought-forward amount into the snapshot so the split stays correct after closing.
4. Settlement model is unchanged (carry-forward) — this is additive presentation + filtering only.

Purpose: An open month currently shows a member "Credit ~৳1,985" for money deposited in a prior month, which looks like this-month income. Listing pages also show every month at once. This plan fixes both the misleading report and the missing list scoping without touching the validated balance/settlement engine.

Output: Period helper + 3 reworked services/controllers/views + 1 additive migration + restructured Monthly Report view.
</objective>

<execution_context>
@$HOME/.claude/gsd-core/workflows/execute-plan.md
@$HOME/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md
@.planning/quick/260801-szi-expenses-and-payments-month-scoping-with/260801-szi-CONTEXT.md

# Authoritative source files (read once, do not rediscover)
@app/Services/ExpenseService.php
@app/Services/PaymentService.php
@app/Services/BillPreviewService.php
@app/Services/MonthCloseService.php
@app/Services/ReportService.php
@app/Models/MonthlyMemberSummary.php
@app/Http/Controllers/Mess/ExpenseController.php
@app/Http/Controllers/Mess/PaymentController.php
@app/Support/PaymentType.php
@app/Support/ExpenseKind.php
@resources/views/mess/expenses/index.blade.php
@resources/views/mess/payments/index.blade.php
@resources/views/mess/payments/_list.blade.php
@resources/views/mess/reports/monthly.blade.php
</context>

<notes>
## Test framework reality (read before writing verify blocks)
CONTEXT.md / constraints reference an "existing PHPUnit suite (~374 tests)" but STATE.md (quick task 260724-pm2) records that the **entire `tests/` folder, `phpunit.xml`, and phpunit/mockery dev-deps were removed**. Verified at planning time: no `tests/` dir, no `phpunit.xml`, no `vendor/phpunit` or `vendor/pestphp`, nothing in `composer.json` require-dev. `php artisan test` exists as a stub but runs zero tests. Therefore NO `vendor/bin/phpunit` command will pass — verification here uses runnable alternatives (`php artisan view:cache`, `php artisan tinker --execute=`, `php artisan migrate`, `vendor/bin/pint --test`, and targeted greps). Reinstalling a test framework is out of scope for this quick task and must be a separate user decision.

## Cache key facts
The bill-preview cache key string is defined in exactly ONE place: `BillPreviewService::cacheKey()` (line 58). `BillPreviewInvalidator::forDate()` and `invalidate()` both call `cacheKey()`, and `AppServiceProvider::registerBillPreviewInvalidation()` goes through the invalidator. So bumping the version inside `cacheKey()` automatically re-keys reads, writes, AND all invalidation paths. The separate `dash:counts:` key is untouched. Stale entries under the old key simply expire (1h TTL) and are never read again.

## brought_forward math (authoritative, per CONTEXT.md)
`brought_forward = (advance_balance − due_balance) − advance_payments`
BillPreviewService::compute() already has all three per member (`advance_balance`, `due_balance`, `advance_payments`). Verified examples:
- Rakib Aug (open): balance 2000, due 0, this-month deposits 0 → brought_forward = 2000 ✓
- Rakib Jul (open): balance 2000, due 0, this-month deposits 2000 → brought_forward = 0 ✓
Derived display math (do NOT change the validated closing net):
- This month net = advance_payments + bill_payments − bill
- Closing net = brought_forward + this_month_net (== existing advance_balance + bill_payments − bill − due_balance on the live path; == advance_balance − due_balance on the snapshot path)

## Guardrails (NON-NEGOTIABLE)
- NEVER run `migrate:fresh` / `db:seed` (destroys hand-created accounts). Only a normal additive `php artisan migrate`.
- Decimal money, never float — freeze with `number_format($v, 2, '.', '')` (the existing `MonthCloseService::money()` helper).
- Additive / backward-compatible only. Do NOT alter consumeAdvance / carryForward / settle / applyPayment / reversePayment.
</notes>

<tasks>

<task type="auto">
  <name>Task 1: Expenses & Payments list — month/year period scoping with current-month default</name>
  <files>app/Support/Period.php, app/Services/ExpenseService.php, app/Services/PaymentService.php, app/Http/Controllers/Mess/ExpenseController.php, app/Http/Controllers/Mess/PaymentController.php, resources/views/mess/expenses/index.blade.php, resources/views/mess/payments/index.blade.php</files>
  <action>
Create `app/Support/Period.php` as a small static helper (mirror the style of the existing `app/Support/PaymentType.php` / `ExpenseKind.php` final classes). It encapsulates the LOCKED filter UX (per CONTEXT "Filter UX = Year/Month dropdown, default current month") so both services stay DRY:
- Constants: `THIS_MONTH = 'this_month'`, `MONTH = 'month'`, `YEAR = 'year'`, `ALL = 'all'`, and `MODES = [self::THIS_MONTH, self::MONTH, self::YEAR, self::ALL]`.
- `apply(\Illuminate\Database\Eloquent\Builder $query, \Illuminate\Http\Request $request, string $column = 'date'): string` — resolve `period` from the query string (default `THIS_MONTH`); reject anything not in `MODES` by falling back to `THIS_MONTH`. Resolve `year` (default `now()->year`) and `month` (default `now()->month`, clamped 1–12) from the query. Then: `THIS_MONTH` → `whereYear($column, now()->year)->whereMonth($column, now()->month)`; `MONTH` → `whereYear($column, $year)->whereMonth($column, $month)`; `YEAR` → `whereYear($column, $year)`; `ALL` → no date condition. Return the resolved mode string so callers/controllers can echo a label if desired.
- `options(): array` — return a `[value => label]` map for the dropdown: This month, Specific month, Whole year, All time (use `__()` on labels).

In `ExpenseService::list()`: keep the existing `kind` filter and `latest('date')` + `paginate(50)->withQueryString()`. INSERT `Period::apply($query, $request)` immediately after the `$query` is created and before the kind filter. This makes the default (no query params) scope to the current month — that is the "current month starts fresh" requirement, satisfied by default-scoping with NO data migration (expenses already carry a `date`). Keep `kind` filtering intact.

In `PaymentService::list()`: REMOVE the `from` and `to` blocks entirely (the From/To pickers are REPLACED by the period selector per CONTEXT). Keep the `member_id` and `method` filters, the `latest('date')->latest('id')` ordering, and `paginate(50)->withQueryString()`. Call `Period::apply($query, $request)` right after the query is built (before member/method filters). Do NOT touch `listForMember()`, `create()`, `update()`, or `delete()`.

In `ExpenseController::index()`: pass the extra data the new filter form needs — at minimum `$periodOptions = \App\Support\Period::options()` and the current `$period`/`$year`/`$month` query values (use `$request->only(['period','year','month','kind'])` as `$filters`). Compact them into the existing `mess.expenses.index` view. Do not change create/store/show/edit/update/destroy.

In `PaymentController::index()`: replace `$filters = $request->only(['member_id','method','from','to'])` with `$request->only(['member_id','method','period','year','month'])` and compact `$periodOptions = \App\Support\Period::options()` alongside the existing `$members`. Do not touch any other method.

In `resources/views/mess/expenses/index.blade.php`: add a GET filter form ABOVE the mobile-cards/desktop-table (mirror the payments index form structure: `grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm`). Include: a Period `<select name="period">` populated from `$periodOptions` with the current value selected via `@selected(($filters['period'] ?? '') === $value)`; a Year `<select name="year">` offering the data-driven years that exist in expenses (you may reuse the simple range `now()->year - 4 .. now()->year`, descending) — keep it small; a Month `<select name="month">` (1–12) shown/used when period is `month`; the existing Kind `<select>` populated from `\App\Support\ExpenseKind::ALL`; and Filter + Reset buttons (Reset links to `route('mess.expenses.index')` with no query). Use `touch-target` + the existing `input`/`btn` classes for consistency. Use `@if`/JS-light approach: render year+month selects always but visually hint they apply to the Specific-month / Whole-year modes (a small `text-xs text-slate-500` note is enough — no JS framework). Keep the existing mobile-card + desktop-table + `$expenses->links()` pagination exactly as-is.

In `resources/views/mess/payments/index.blade.php`: REPLACE the From (`name="from"`) and To (`name="to"`) date inputs with the SAME period selector (Period select + Year select + Month select). Keep the Member and Method selects and the Filter/Reset buttons. Update the grid column count as needed (e.g. `sm:grid-cols-4` → keep 4 columns: Member, Method, Period, Year/Month combined). Keep `@include('mess.payments._list')` untouched — pagination lives in that partial.
  </action>
  <verify>
Run, all must succeed:
1. `php artisan view:cache` exits 0 (compiles both modified index blades — catches any Blade syntax error).
2. `vendor/bin/pint --test app/Support/Period.php app/Services/ExpenseService.php app/Services/PaymentService.php app/Http/Controllers/Mess/ExpenseController.php app/Http/Controllers/Mess/PaymentController.php` exits 0.
3. `php artisan tinker --execute="echo App\\Support\\Period::apply(app('db')->table('expenses')->getQuery(), Illuminate\\Http\\Request::create('/','GET'));"` runs without fatal error (proves the helper loads and the default-mode signature resolves).
4. `grep -c "Period::apply" app/Services/ExpenseService.php` is 1 and `grep -c "Period::apply" app/Services/PaymentService.php` is 1 (both services wired).
5. `grep -vc '^ *//' app/Services/PaymentService.php | xargs -I{} sh -c 'grep -c "from" app/Services/PaymentService.php'` — confirm the From/To `whereDate` logic is gone: `grep -nE "query\\('from'\\)|query\\('to'\\)" app/Services/PaymentService.php` returns no matches.
6. `grep -n 'name="period"' resources/views/mess/expenses/index.blade.php resources/views/mess/payments/index.blade.php` returns a match in BOTH files.
7. `grep -nE 'name="from"|name="to"' resources/views/mess/payments/index.blade.php` returns no match (From/To removed).
  </verify>
  <done>
- Both list pages default to the current month with no query params, paginate at 50/page, and re-scope via the period selector (This month / Specific month / Whole year / All time).
- Payments page no longer shows From/To date pickers; member + method filters still work and stay sticky across pagination.
- Expenses page now has a filter form (period + kind); the old unfiltered "all expenses ever" default is gone.
- No change to any create/update/delete path or to `PaymentService::listForMember`.
  </done>
</task>

<task type="auto">
  <name>Task 2: Brought-forward computation + cache-key version bump + additive column + month-close freeze + report surfacing</name>
  <files>database/migrations/2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php, app/Models/MonthlyMemberSummary.php, app/Services/BillPreviewService.php, app/Services/MonthCloseService.php, app/Services/ReportService.php</files>
  <action>
**Migration** — generate with `php artisan make:migration add_brought_forward_to_monthly_member_summaries_table --table=monthly_member_summaries` (rename the file to `2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php`). In `up()`: `$table->decimal('brought_forward', 12, 2)->nullable()->after('balance_due');`. In `down()`: `$table->dropColumn('brought_forward');`. This is ADDITIVE and safe for a normal `php artisan migrate` on prod — do NOT use `migrate:fresh`.

**MonthlyMemberSummary model** — add `'brought_forward'` to the `#[Fillable([...])]` attribute list, and add `'brought_forward' => 'decimal:2'` to `casts()`. No other model change.

**BillPreviewService::compute()** — for each member row, AFTER `$advanceBalance`, `$dueBalance`, and `$advancePayments` are known, compute `$broughtForward = round(($advanceBalance - $dueBalance) - $advancePayments, 2);` and add `'brought_forward' => $broughtForward,` to the `$rows[]` array (place it near `advance_balance`/`due_balance` for readability). The three inputs already exist in the row builder (`$advanceBalance`, `$dueBalance`, and `$paymentsByMember[$member->id]['advance_payments']`); reuse the latter via the existing `$advancePayments` local if you introduce one, or read it inline exactly as `advance_payments` is already read. Do NOT alter any other math (meal cost, bill, advance_applied, due, active-days). Also bump the cache key version in `cacheKey()`: change the return to `"bill-preview:v2:{$messId}:{$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT);` — the single `v2:` segment guarantees no stale pre-change cached preview (which lacks the `brought_forward` key) can ever be read back. `BillPreviewInvalidator` and `AppServiceProvider` go through `cacheKey()`, so they re-key automatically; do not touch them. Also add `'brought_forward' => 0.0,` to `emptyPreview()` so the no-mess / empty path still returns the key.

**MonthCloseService::close()** — in the `foreach ($preview['members'] as $row)` loop that builds `MonthlyMemberSummary::create([...])`, add `'brought_forward' => $this->money($row['brought_forward'] ?? 0),`. This freezes the brought-forward amount computed from the PRE-MUTATION preview (the preview is recomputed fresh right above via `invalidate()` + `preview()`), exactly as CONTEXT requires. Use the existing `$this->money()` helper (number_format → canonical 2-decimal string) so it never round-trips through float. Do NOT alter the consumeAdvance/carryForward/settle settlement block or the closing_balance freeze.

**ReportService::monthlyFromSnapshot()** — in the `$members[]` row array, add `'brought_forward' => $row->brought_forward !== null ? (float) $row->brought_forward : 0.0,`. The live path (`monthlyReport()` → `BillPreviewService::preview()`) already returns `brought_forward` after the BillPreviewService change above, so no separate live-path edit is needed. Do not change `expenseReport()` / `paymentReport()` / `availableMonthRange()`.
  </action>
  <verify>
Run, all must succeed:
1. `php artisan migrate` applies the additive migration cleanly (NEVER `migrate:fresh`). Confirm the column: `php artisan tinker --execute="echo json_encode(Schema::hasColumn('monthly_member_summaries','brought_forward'));"` prints `true`.
2. `vendor/bin/pint --test app/Models/MonthlyMemberSummary.php app/Services/BillPreviewService.php app/Services/MonthCloseService.php app/Services/ReportService.php database/migrations/2026_08_02_000001_add_brought_forward_to_monthly_member_summaries.php` exits 0.
3. Cache key versioned + single source: `grep -n "bill-preview:v2:" app/Services/BillPreviewService.php` returns exactly one match (the `cacheKey()` return); `grep -rn "bill-preview:" app/ | grep -v "bill-preview:v2:" | grep -v "^[^:]*:[0-9]*:[[:space:]]*//"` returns no code matches outside comments (no stale hardcoded old key in app code).
4. brought_forward is in the preview output: `php artisan tinker --execute="$r=app(App\\Services\\BillPreviewService::class)->preview(now()->year,now()->month); echo isset($r['members'][0]['brought_forward']) ? 'HAS_KEY' : 'NO_KEY';"` prints `HAS_KEY` (or `NO_KEY` only when there are zero members — in that case seed nothing; instead assert the top-level shape via `echo array_key_exists('members',$r)?'OK':'FAIL';`).
5. Model fillable+cast: `grep -c "brought_forward" app/Models/MonthlyMemberSummary.php` is at least 2 (one in Fillable, one in casts).
6. MonthClose freezes it: `grep -n "'brought_forward' => \$this->money" app/Services/MonthCloseService.php` returns one match.
7. Snapshot surfaces it: `grep -n "'brought_forward'" app/Services/ReportService.php` returns one match.
  </verify>
  <done>
- `monthly_member_summaries` has a nullable `brought_forward DECIMAL(12,2)` column, fillable + cast on the model.
- BillPreviewService computes `brought_forward` per member and the cache key is versioned `v2` so no stale preview without the field is ever served.
- Month-close freezes `brought_forward` into each summary from the pre-mutation preview (BC-correct decimal string).
- ReportService surfaces `brought_forward` on both the live and snapshot Monthly Report paths.
- No settlement math touched; migration is additive.
  </done>
</task>

<task type="auto">
  <name>Task 3: Monthly Report view — restructure per-member table into Brought forward / This month / Closing (net)</name>
  <files>resources/views/mess/reports/monthly.blade.php</files>
  <action>
Rework ONLY the per-member `<table>` and its footnote in `resources/views/mess/reports/monthly.blade.php`. Keep the header, `<x-report-toolbar>`, the totals grid (Members/Meals/Meal rate/Total bazar/Total fixed/Balance-net), the empty-state, the closed-month badge, and all `$data`/`$year`/`$month`/`$monthRange` wiring exactly as-is. The LOCKED decision (CONTEXT "Open-month report = Split Brought forward vs This month") requires each member row to clearly show three things: Brought forward (opening net), This month (bill/paid/due), and Closing (net).

Restructure the per-member table columns to this layout (desktop; keep the mobile/card equivalent consistent if one exists, otherwise the table is sufficient since this view is desktop-first):
- Member (link to member-statement, as today)
- Status (pill, as today)
- This-month group: Meals · Bill · Paid · Due (these are the EXISTING columns `meals`, `bill`, `bill_payments`, `due` — keep their values and `Money::taka()` rendering; just visually group them under a single "This month" super-header via a two-row `<thead>` with a colspanned `<th>`).
- Brought forward — new column. Value = `(float) ($row['brought_forward'] ?? 0)`. Render with sign-aware color + label exactly like the existing Balance cell: positive → emerald "Credit", negative → rose "Owes", zero → plain `Money::taka(0)`. Use `abs()` for the displayed amount.
- Closing (net) — this is the EXISTING per-row net (`$rowNet`), kept byte-for-byte (live: `advance_balance + bill_payments - bill - due_balance`; snapshot: `advance_balance - due_balance`). Just relabel the column header from "Balance" to "Closing (net)" and keep its color logic.

Compute `$rowBroughtForward` per row next to the existing `$rowNet` @php block. The totals grid "Balance (net)" card already sums the closing net correctly — leave it; optionally also add a small "Brought forward" total card is NOT required (avoid scope creep — the totals grid stays as the six existing cards).

Update the footnote text below the table to explain the three-part split (replace the current single-sentence Balance footnote). New footnote must state plainly: Brought forward is the member's net position carried in from before this month (a prior-month advance deposit that has not been consumed shows here, NOT as this-month income); This month is bill/paid/due for the current month only; Closing (net) = brought forward + this-month net. Keep `@lang(...)` / `__()` for all new strings.

Defensive read: guard every new value with `?? 0` so that if a snapshot row was written before this deploy (NULL `brought_forward`), the column simply shows ৳0.00 rather than erroring. Do not add JS.
  </action>
  <verify>
Run, all must succeed:
1. `php artisan view:cache` exits 0 (compiles the restructured monthly report blade — catches any Blade/PHP syntax error in the new table).
2. `vendor/bin/pint --test resources/views/mess/reports/monthly.blade.php` exits 0 (Pint formats Blade too).
3. Columns present: `grep -vc '^ *<!--' resources/views/mess/reports/monthly.blade.php | xargs -I{} grep -c "Brought forward" resources/views/mess/reports/monthly.blade.php` — `grep -c "Brought forward" resources/views/mess/reports/monthly.blade.php` is at least 1 (header + footnote).
4. `grep -c "Closing (net)" resources/views/mess/reports/monthly.blade.php` is at least 1.
5. `grep -c "brought_forward" resources/views/mess/reports/monthly.blade.php` is at least 1 (the new column reads the field Task 2 added).
6. The validated closing-net math is preserved: `grep -c "advance_balance.*bill_payments.*bill.*due_balance\|advance_balance.*due_balance" resources/views/mess/reports/monthly.blade.php` is at least 1 (the existing `$rowNet` expression is intact, only relabelled).
7. Manual smoke (human): load `/mess/reports/monthly` for the current open month → confirm Rakib (the member with a ৳2,000 prior-month advance, no this-month payment) now shows Brought forward ৳2,000 · This month bill 0 / paid 0 / due 0 · Closing Credit ৳2,000 — the credit is clearly labelled as brought forward, not as this-month activity.
  </verify>
  <done>
- The Monthly Report per-member table clearly separates Brought forward, This-month activity (meals/bill/paid/due), and Closing (net).
- A prior-month advance carried into an open month is no longer misread as this-month credit.
- The footnote explains the split; closed-month (snapshot) rows show the frozen brought-forward value; pre-deploy snapshot rows degrade gracefully to ৳0.00.
- No totals-grid card removed; no JS added; the existing validated closing-net math is unchanged.
  </done>
</task>

</tasks>

<verification>
- `php artisan view:cache` exits 0 (all touched blades compile).
- `php artisan migrate` ran the additive migration; `Schema::hasColumn('monthly_member_summaries','brought_forward')` is true.
- `vendor/bin/pint --test` clean on every modified file.
- Cache key is `bill-preview:v2:...` in exactly one place; no stale hardcoded old key in app code.
- Expenses & Payments list default to the current month and re-scope via the period selector; pagination intact.
- Monthly Report shows the three-way split; the Rakib-prior-month-advance case reads as Brought forward, not this-month credit.
- No `migrate:fresh` / `db:seed` executed at any point.
- Note: there is no PHPUnit/Pest runner in this repo (removed in quick-260724-pm2); automated coverage was therefore expressed as view:cache + migrate + pint + grep + tinker gates above. Reinstating a test framework is a separate decision.
</verification>

<success_criteria>
- All four LOCKED decisions in 260801-szi-CONTEXT.md are implemented: period-scoped lists with current-month default; Brought forward / This month / Closing split in the Monthly Report; brought-forward frozen into the month-close snapshot; settlement model unchanged.
- Changes are additive/backward-compatible; the validated balance/settlement engine is untouched.
- The bill-preview cache is re-versioned so no preview lacking `brought_forward` can be served.
- Deploy is `php artisan migrate` + `config:clear` + `view:clear` (no destructive commands).
</success_criteria>

<output>
Create `.planning/quick/260801-szi-expenses-and-payments-month-scoping-with/260801-szi-SUMMARY.md` when done.
</output>
