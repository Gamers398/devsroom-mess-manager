# Quick Task 260801-szi: Expenses/Payments month-scoping + Monthly Report balance split + settlement clarity - Context

**Gathered:** 2026-08-02
**Status:** Ready for planning

<domain>
## Task Boundary

Four related asks on the Devsroom Mess Management app (Laravel 13 + Blade):

1. **Expenses list page** (`mess.expenses.index`): scope by month so a ended month's expenses stay in that month and the current month starts fresh; add a filter to view by **year** or **month**; **default to the current month**; keep pagination.
2. **Payments list page** (`mess.payments.index`): same filtering/default/pagination behavior as Expenses.
3. **Monthly Report bug** (`mess.reports.monthly`): the OPEN (current) month shows a member "Credit ~৳1,985" even though they made no payment this month — because their prior-month advance carries forward into the live balance. Make the report distinguish carried-forward money from this-month activity.
4. **Month-close settlement clarity**: after closing a month the per-member balances (Owes / Credit) do NOT zero out — they are real money that carries forward. Confirm/expose this clearly via the Brought forward / This month / Closing split.

</domain>

<decisions>
## Implementation Decisions (LOCKED — do not revisit)

### Settlement model = Carry forward + clearer report (chosen)
- **Keep the existing running-balance / advance-ledger accounting.** Closing a month freezes each member's net into `monthly_member_summaries.closing_balance` and carries the residual forward as the next month's opening — this is correct and stays.
- Do NOT zero-out balances on close. Do NOT net one member's debt against another's (Rakib's debt and Shubho's credit are independent and stay separate).
- The "fix" for issue #4 is presentational: show each member's **Brought forward → This month activity → Closing balance** so the carried residual is clearly explained, not silently rolled into one "Balance (net)".

### Open-month report = Split Brought forward vs This month (chosen)
- The open-month Monthly Report must stop presenting carried-forward credit as if it were this-month activity.
- Per member, surface three things:
  - **Brought forward (opening net)** = the member's net position at the start of the month.
  - **This month** = bill, payments, and the resulting due for the current month only.
  - **Closing / Net balance** = brought forward + this-month net.
- Rakib in August (no August payment) will show: Brought forward ৳2,000 · This month bill 0 / paid 0 / due 0 · Net credit ৳2,000. The credit stays (it is real money) but is now clearly labelled as brought forward. This is the agreed outcome.

### Brought-forward definition (authoritative)
`brought_forward_net = (advance_balances.balance − advance_balances.due_balance) − (advance deposits in this month)`

Rationale: during an OPEN month the only things that mutate `advance_balances` are advance deposits and manual adjustments (close-time mutations have not run for this month). So the opening net = current net minus this month's deposits. Verified against real data:
- Rakib July (open): balance 2000, due 0, this-month deposits 2000 → brought_forward = 0 ✓
- Rakib August (open): balance 2000, due 0, this-month deposits 0 → brought_forward = 2000 ✓
- Rakib July closing net: 0 + 2000(deposits) + 0(bill_payments) − 3121.32(gross_bill) = −1121.32 (owes 1121.32) ✓ matches the live report.

In `BillPreviewService::compute()` the per-member row already has `advance_balance`, `due_balance`, and `advance_payments` (= this-month deposits). So:
`brought_forward = (advance_balance − due_balance) − advance_payments`

At close time (`MonthCloseService`), compute it from the **pre-mutation** preview the same way and freeze it into the snapshot.

### Filter UX = Year/Month dropdown, default current month (chosen)
- Both Expenses and Payments index pages get a single period selector with modes: **This month (default on first load)**, **Specific month** (year + month), **Whole year** (all months of a chosen year). An "All time" option is acceptable as a fourth mode.
- On Payments this **replaces** the existing From/To date pickers (member + method filters stay).
- On Expenses the page currently has NO filter form — add one (kind filter stays).
- **Pagination stays** (current per-page 50 is fine).
- The "current month starts fresh" requirement is already true in the data (expenses/payments carry a `date` column and belong to their month naturally) — this is purely a default-scope + filter UX change, NOT a data migration.

</decisions>

<specifics>
## Specific Implementation Pointers

### Files involved
- `app/Services/ExpenseService.php` → `list()`: add year/month scoping, default current month.
- `app/Services/PaymentService.php` → `list()`: replace from/to with year/month scope, default current month.
- `app/Http/Controllers/Mess/ExpenseController.php` / `PaymentController.php`: pass filter options/available months to views.
- `resources/views/mess/expenses/index.blade.php`: add filter form (mirror payments).
- `resources/views/mess/payments/index.blade.php`: swap From/To for the period selector.
- `app/Services/BillPreviewService.php` → `compute()`: add `brought_forward` to each member row. **Bump the cache key version** so stale pre-change previews (which lack the field) are never read.
- `app/Services/ReportService.php` → `monthlyFromSnapshot()`: surface `brought_forward` from the snapshot.
- `app/Services/MonthCloseService.php` → `close()`: compute & store `brought_forward` per summary from pre-mutation preview.
- `app/Models/MonthlyMemberSummary.php`: add `brought_forward` fillable.
- **Migration**: add `brought_forward` DECIMAL(12,2) NULL column to `monthly_member_summaries` (additive — safe `php artisan migrate` on prod; do NOT use migrate:fresh).
- `resources/views/mess/reports/monthly.blade.php`: restructure per-member table to Brought forward / This month / Closing(net); update the footnote.

### Real data state (verified read-only)
- Members: 1 = MEHEDI HASSAN SHUBHO, 2 = Mahadi Islam Rakib.
- `advance_balances`: only Rakib — balance ৳2000.00 (his 2026-07-24 advance deposit; not yet consumed).
- `payments`: Rakib ৳2000 advance_deposit (2026-07-24); Shubho ৳3430 bill_payment (2026-07-24).
- `monthly_closings`: **EMPTY** — July was never closed. So "after closing" is hypothetical and the July report the user saw is the LIVE preview.

### Constraints / guardrails
- Project rule: **never run `migrate:fresh` / `db:seed`** — destroys hand-created accounts. Only additive `migrate`.
- "Decimal money, never float" — use BC math / number_format for any money frozen into columns.
- Keep the change additive and backward-compatible; do not alter existing settlement/consumeAdvance/carryForward/settle logic — only ADD brought-forward surfacing.
- Existing test suite (~374 tests) must stay green; add tests for the new scoping + brought-forward field.

</specifics>

<canonical_refs>
## Canonical References
No external specs — requirements fully captured in decisions above. Codebase is the source of truth (see files list).
</canonical_refs>
