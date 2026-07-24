---
quick_id: 260724-jui
mode: quick
date: 2026-07-24
description: "Fix member 403 ACCESS DENIED + wallet/billing deposit/due mismatch"
must_haves:
  truths:
    - "Members who log in must reach /my without a 403, regardless of whether the buggy one-shot role rename migration already ran on the database."
    - "A member's Wallet must show a reconciled current-month summary: Bill, money applied (bill payments + advance applied), and net Due/Credit — not a disconnected Credit + pending banner."
    - "Member Statement closing summary must make the advance-applied offset visible so Bill − Bill payments − Advance applied = Due is transparent."
    - "Money math stays decimal (BC math); no floats in balance write paths."
  artifacts:
    - "database/migrations/2026_07_24_010000_reconcile_stranded_member_roles.php (NEW — idempotent reconciliation)"
    - "app/Services/WalletLedgerService.php (return current-month summary)"
    - "resources/views/wallet/_ledger.blade.php (reconciled This-Month summary card)"
    - "resources/views/my/reports/statement.blade.php (advance_applied line)"
    - "resources/views/mess/reports/member-statement.blade.php (advance_applied line, if it shares the summary structure)"
  key_links:
    - "app/Services/BillPreviewService.php (authoritative per-member row: bill, bill_payments, advance_applied, due)"
    - "app/Services/AdvanceBalanceService.php (applyPayment increments advance_balances.balance on deposit)"
    - "app/Http/Controllers/My/MyWalletController.php"
    - "routes/web.php:275 (roles:mess-member gate — the 403 source)"
---

# Quick Task 260724-jui — Plan

## Diagnosis (evidence-based)

### 1. Member 403 ACCESS DENIED
- `/my` route group is gated by `roles:mess-member` → `HasinHayder\Tyro\Http\Middleware\EnsureAnyTyroRole`,
  which throws `AuthorizationException('ACCESS DENIED.')` (exact string the user sees) when the user
  lacks the `mess-member` role slug.
- New members are fine: `MemberController:106` and `MemberInviteController:39` call
  `$user->assignRole(Role::firstOrCreate(['slug'=>'mess-member'],...))`.
- **Stranded members:** commits `d147b6a`/`4e11098` renamed roles `user`→`mess-member`, `admin`→`manager`.
  Migration `2026_07_23_020000_drop_user_role_reassign_to_mess_member.php` was then fixed in the most
  recent commit `41d6ef3` ("use Role relationships, not hardcoded 'role_user' pivot"). Tyro's pivot is
  `user_roles`, not `role_user`. So the earlier version of that one-shot migration either no-op'd the
  reassignment (wrong/empty pivot) and then deleted the `user` role — leaving pre-existing members with
  **no role**. Re-running `php artisan migrate` will not re-run it (already recorded). These members
  authenticate fine (login succeeds) but `roles:mess-member` 403s on `/my`.
- The local dev DB has 0 users (can't reproduce here); the bug is on the operator-managed prod DB.

### 2. Wallet deposit/bill/due "mismatch"
- `BillPreviewService::compute()` is mathematically correct: for a member with a ৳2000 advance deposit
  this month and ৳2878.48 bill, `applyPayment()` already added 2000 to `advance_balances.balance`, so
  `advance_applied = min(2000, 2878.48) = 2000` and `due = 878.48`. Confirmed: `PaymentService::create()`
  calls `$this->balances->applyPayment($payment)`.
- **The defect is display, not math.** `WalletLedgerService::forMember()` returns only `current_balance`
  (= `AdvanceBalance::netBalance()` — settled across *closed* months, but it *includes* this month's
  deposit) and `pending_bill` (raw bill). The `wallet/_ledger.blade.php` header shows "Credit ৳2000"
  next to a separate "pending bill ৳2878.48" banner — with **no reconciled Due (৳878.48)**. That is the
  "mismatch" the member sees. (Same partial is used by the manager-side member wallet view.)
- Statement `Closing summary` shows Bill / Paid (bill_payments only) / Due, but **not** `advance_applied`,
  so a member who deposited an advance sees Due drop with no explanation.

## Tasks

### Task A — Reconcile stranded member roles (fixes 403)
- **files:** `database/migrations/2026_07_24_010000_reconcile_stranded_member_roles.php` (NEW),
  `tests/Feature/Role/ReconcileMemberRolesTest.php` (NEW)
- **action:**
  - New idempotent migration (fresh filename ⇒ runs once even on DBs where the rename migration already
    executed). Using `Role` relationships (correct `user_roles` pivot), not a hardcoded table:
    1. `firstOrCreate` the `mess-member` and `manager` roles.
    2. Legacy reassign: if a `user` role still exists, `syncWithoutDetaching` its users onto `mess-member`;
       if an `admin` role still exists, likewise onto `manager`. (Covers DBs that haven't run the renames.)
    3. Semantic reconciliation: for every user that has a `members` row and does NOT already have
       `mess-member`, assign `mess-member`. (Covers members stranded role-less by the buggy run.)
    4. Re-fetch slugs is unnecessary — `syncWithoutDetaching` is idempotent and safe to repeat.
  - `down()`: no-op (a reconciliation must not un-assign roles on rollback).
  - Test: build a user with a `members` row and NO role, run the migration body, assert `hasRole('mess-member')`.
- **verify:** `php artisan migrate` (forward only — NEVER fresh/seed on real DB) then the test passes;
  existing role tests still green.
- **done:** migration file exists, is idempotent, and grants `mess-member` to every member-record holder.

### Task B — Wallet reconciled This-Month summary
- **files:** `app/Services/WalletLedgerService.php`, `resources/views/wallet/_ledger.blade.php`,
  `tests/Feature/Wallet/WalletLedgerTest.php`
- **action:**
  - `WalletLedgerService::forMember()`: reuse the `BillPreviewService::forMember()` row it already loads
    for `pending_bill`. Return a new `month_summary` key:
    `['meals','meal_cost','bill','bill_payments','advance_applied','due']` for the current open month
    (null/empty when the month is already closed).
  - `_ledger.blade.php`: add a "This month" summary card showing **Bill**, **Applied** (bill_payments +
    advance_applied), and **Due/Credit** (= `month_summary.due`, signed — rose when owed, emerald when
    credit). Keep the activity ledger table as history. The existing Credit/pending-banner header stays
    as the all-time settled balance, but the new card is the reconciled current-month number the user
    asked for.
  - Test: member deposits 2000 advance, has a 2878.48 bill ⇒ wallet shows due = 878.48.
- **verify:** `php artisan test --filter=WalletLedgerTest` green.
- **done:** wallet surfaces deposit-vs-bill-vs-due explicitly for both member and manager views.

### Task C — Statement advance-applied transparency
- **files:** `resources/views/my/reports/statement.blade.php`,
  `resources/views/mess/reports/member-statement.blade.php`,
  `tests/Feature/Report/MemberStatementTest.php` (or MyStatementTest)
- **action:** in each statement's Closing summary `<dl>`, add an "Advance applied" cell showing
  `Money::taka($row['advance_applied'] ?? 0)` between Paid and Due, so the arithmetic reads
  Bill − Bill payments − Advance applied = Due. (Member row already carries `advance_applied`.)
- **verify:** `php artisan test --filter=StatementTest` green.
- **done:** statement explains where the advance deposit went.

## Out of scope (do not touch)
- No `migrate:fresh`, `db:seed`, or restore-test on the real/prod DB (destroys hand-created accounts —
  per project memory). Tests run only on the isolated `devsroom_mess_management_testing` DB.
- Member-facing Monthly Report stays aggregates-only (locked decision D-19); do not add a per-member
  table there.
- `super-admin` role creation is owned elsewhere; this migration only reconciles member/manager roles.

## Deploy note
After pulling: run `php artisan migrate` (forward) on prod, then `php artisan cache:clear` so cached
bill previews recompute with the current advance/bill math.
