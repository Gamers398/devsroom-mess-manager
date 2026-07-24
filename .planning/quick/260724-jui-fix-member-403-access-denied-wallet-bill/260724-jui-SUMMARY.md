---
status: complete
quick_id: 260724-jui
date: 2026-07-24
---

# Quick Task 260724-jui — Summary

Three issues from the operator, each rooted in a distinct layer:

1. **Member 403 ACCESS DENIED after login** — an auth/role defect, not billing.
2. **Wallet deposit/bill/due "mismatch"** — a display defect; the math was correct.
3. **Statement didn't explain the advance offset.**

## Root causes

### 1. 403 — stranded roles after the `user`→`mess-member` rename
`/my` is gated by `roles:mess-member` → `EnsureAnyTyroRole`, which throws
`AuthorizationException('ACCESS DENIED.')` (the exact string the operator saw).
The one-shot rename migration (`2026_07_23_020000`) shipped using a hardcoded
`role_user` pivot, but Tyro's pivot is `user_roles`; commit `41d6ef3` fixed it to
use Role relationships — but a migration only runs once. On any DB where the
buggy version already ran, pre-existing members were dropped from `user` without
being re-attached to `mess-member`, leaving them role-less. They authenticate
fine (login succeeds) but 403 on `/my`. New members were unaffected
(`MemberController`/`MemberInviteController` call `assignRole('mess-member')`).

### 2. Wallet — disconnected numbers, not bad math
`BillPreviewService::compute()` was already correct: a current-month advance
deposit flows into `advance_balances.balance` immediately via
`PaymentService::create() → AdvanceBalanceService::applyPayment()`, so for a
৳2000 deposit + ৳2878.48 bill, `advance_applied = 2000` and `due = 878.48`.
The defect was the wallet view: the header showed the all-time settled balance
(which *includes* the current deposit) next to a separate raw "pending bill"
banner — with no reconciled Due. Members had to subtract by hand.

### 3. Statement — missing the advance line
The Closing summary went Bill → Paid → Due, hiding the advance offset, so a
member who deposited saw their Due drop with no explanation.

## Commits
- `9e4bddc` fix(roles): reconcile stranded members to mess-member (403 on /my)
- `c6277c5` feat(wallet): reconciled This-month summary (bill/applied/due)
- `2b9db9a` feat(statement): show advance-applied line in closing summary

## What changed
1. **`2026_07_24_010000_reconcile_stranded_member_roles.php`** (NEW) — fresh
   filename so it runs once on every DB regardless of whether the buggy rename
   already executed. Idempotent: (a) reassigns legacy `user`/`admin` holders to
   `mess-member`/`manager`; (b) guarantees every user with a `members` row has
   the `mess-member` role — the branch that rescues role-less members. Uses Role
   relationships throughout (correct `user_roles` pivot). `down()` is a no-op.
2. **`WalletLedgerService::forMember()`** — returns a new `month_summary` from
   the authoritative `BillPreviewService` row (meals, meal_cost, bill,
   bill_payments, advance_applied, due) for the open month.
3. **`wallet/_ledger.blade.php`** (shared by member + manager wallet views) — a
   "This month" card: Bill / Paid / Advance applied / Due, with meals + meal
   cost below. Replaces the bare pending-bill banner. Single reconciled Due.
4. **Both statement views** — "Advance applied" cell between Paid and Due.

## Tests
- NEW `tests/Feature/Role/ReconcileMemberRolesTest.php` (5): stranded member
  recovers role, legacy `user`/`admin` reassign, idempotency, and the recovered
  member reaches `/my` without 403. (Precondition uses the pivot table, not
  `hasRole()`, to avoid poisoning Tyro's in-process slug cache.)
- Extended `WalletLedgerTest` (5): 10 meals + ৳654.20 bazar + ৳500 deposit ⇒
  wallet shows bill 654.20 / advance applied 500 / due 154.20.
- Updated the two `*_StatementTest` Pitfall-3 guards to the new contract
  (human-readable "Advance applied" shown; raw `advance_applied` key still never
  leaks).
- Regression: 54 billing tests (BillPreview / MonthClose / AdvanceBalance /
  MyBillPreview / PaymentHistory / MonthlyCorrection / MemberDashboard) green.

## Answers to the operator's "how is it calculated" questions
- **Meals** = sum of a member's meal weights this month (B/L/D, configurable
  per mess), skipping mess-closed and member-disabled days. Guest meals are a
  separate charge.
- **Meal cost** = member's meals × meal rate.
- **Meal rate** = total bazar this month ÷ total meals eaten by ALL active+former
  members this month (denominator fixed in quick-260723-m1).
- **Bill** = meal cost + fixed share + guest charges.
- **Payments** = one `payments` ledger; `bill_payment` reduces the bill directly,
  `advance_deposit` becomes a running credit (`advance_balances.balance`).
- **Advance applied** = min(available credit, bill − bill payments) — capped so
  it never over-charges.
- **Due** = bill − bill payments − advance applied. This is the number the wallet
  now leads with and the statement shows.
- **Balance / carried forward** = `advance_balances.balance − due_balance`,
  netted at month close. The wallet header shows this all-time settled position;
  the new "This month" card shows the live reconciled Due.

## Deploy note (operator action required)
After pulling on the CloudPanel prod server:
1. `php artisan migrate` (forward only — **never** `migrate:fresh`/`db:seed`:
   they destroy the hand-created accounts). This runs the reconciliation and
   rescues every stranded member.
2. `php artisan cache:clear` so cached bill previews recompute.
3. Confirm a previously-403 member can now reach `/my`.

## Out of scope
- Member-facing Monthly Report stays aggregates-only (locked decision D-19) —
  per-member detail lives on the statement, not the monthly report.
- `super-admin` role creation is owned elsewhere; the reconciliation only
  touches member/manager roles.
- `ExcelExportTest` (PhpSpreadsheet) still crashes the PHP process — pre-existing
  per quick-260723-m1, untouched here.
