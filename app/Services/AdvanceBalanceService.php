<?php

namespace App\Services;

use App\Models\AdvanceBalance;
use App\Models\BalanceAdjustment;
use App\Models\Mess;
use App\Models\Payment;
use App\Support\PaymentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdvanceBalanceService
{
    public function __construct(
        private readonly BillPreviewService $billPreview,
    ) {}

    /**
     * Apply the impact of a Payment to the member's advance/due balance.
     * Only `advance_deposit` touches `balance` (D-07). `bill_payment` is a no-op here.
     */
    public function applyPayment(Payment $payment): void
    {
        if ($payment->type !== PaymentType::ADVANCE_DEPOSIT) {
            return;
        }

        DB::transaction(function () use ($payment) {
            $row = AdvanceBalance::query()
                ->where('member_id', $payment->member_id)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = AdvanceBalance::create([
                    'mess_id' => Mess::activeId(),
                    'member_id' => $payment->member_id,
                    'balance' => 0,
                    'due_balance' => 0,
                    'last_updated_at' => now(),
                ]);
            }

            $row->balance = bcadd((string) $row->balance, (string) $payment->amount, 2);
            $row->last_updated_at = now();
            $row->save();
        });
    }

    /**
     * Reverse the prior impact of a Payment on the member's advance/due balance
     * (WR-01). Used by PaymentService::update before re-applying the new values:
     * subtracts the original amount for ADVANCE_DEPOSIT (the mirror of applyPayment).
     * No-op for BILL_PAYMENT (matches applyPayment's no-op).
     */
    public function reversePayment(Payment $payment): void
    {
        if ($payment->type !== PaymentType::ADVANCE_DEPOSIT) {
            return;
        }

        DB::transaction(function () use ($payment) {
            $row = AdvanceBalance::query()
                ->where('member_id', $payment->member_id)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                // Nothing to reverse — the member never had a balance row.
                return;
            }

            $row->balance = bcsub((string) $row->balance, (string) $payment->amount, 2);
            $row->last_updated_at = now();
            $row->save();
        });
    }

    /**
     * Manager-side manual adjustment with a reason (D-07 / D-11).
     */
    public function adjust(int $memberId, float $amount, string $reason, int $enteredBy): AdvanceBalance
    {
        $amountStr = number_format($amount, 2, '.', '');
        if (bccomp($amountStr, '0', 2) === 0) {
            throw new RuntimeException('Adjustment amount cannot be zero.');
        }

        return DB::transaction(function () use ($memberId, $amountStr, $reason, $enteredBy) {
            $row = AdvanceBalance::query()
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['mess_id' => Mess::activeId(), 'member_id' => $memberId],
                    ['balance' => 0, 'due_balance' => 0, 'last_updated_at' => now()]
                );

            if (bccomp($amountStr, '0', 2) > 0) {
                $row->balance = bcadd((string) $row->balance, $amountStr, 2);
            } else {
                $row->due_balance = bcadd((string) $row->due_balance, ltrim($amountStr, '-'), 2);
            }
            $row->last_updated_at = now();
            $row->save();

            // Append a readable history row so the wallet ledger can show this
            // manual adjustment as its own dated line (advance_balances above is
            // mutated in place and carries no record of how it got there).
            BalanceAdjustment::create([
                'mess_id' => Mess::activeId(),
                'member_id' => $memberId,
                'amount' => $amountStr,
                'reason' => $reason,
                'entered_by' => $enteredBy,
            ]);

            // A manual adjust changes the running credit/debt that the bill
            // preview now consumes (advance offsets the live bill), so drop the
            // current month's cached preview so the next read recomputes.
            $this->billPreview->invalidate(now()->year, now()->month);

            Log::info('manual_balance_adjustment', [
                'member_id' => $memberId,
                'amount' => $amountStr,
                'reason' => $reason,
                'entered_by' => $enteredBy,
                'new_balance' => $row->balance,
                'new_due_balance' => $row->due_balance,
            ]);

            return $row;
        });
    }

    /**
     * Carry a signed month-close net bill into the member's running balance (D-09).
     *
     * Positive `$amount` → increases `balance` (advance/credit); negative →
     * increases `due_balance` (debt). This is the single write point that
     * accumulates money across months, so it operates purely in BC math on a
     * 2-decimal string — never float (CR-03: "decimal money, never float").
     *
     * `$amount` MUST be a normalized 2-decimal string (e.g. `'6000.00'`,
     * `'-150.00'`). Callers normalize via `number_format($value, 2, '.', '')`
     * or `bcmul()` before calling.
     */
    public function carryForward(int $memberId, string $amount): AdvanceBalance
    {
        $amountStr = number_format((float) $amount, 2, '.', '');

        return DB::transaction(function () use ($memberId, $amountStr) {
            $row = AdvanceBalance::query()
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['mess_id' => Mess::activeId(), 'member_id' => $memberId],
                    ['balance' => 0, 'due_balance' => 0, 'last_updated_at' => now()]
                );

            if (bccomp($amountStr, '0', 2) > 0) {
                $row->balance = bcadd((string) $row->balance, $amountStr, 2);
            } elseif (bccomp($amountStr, '0', 2) < 0) {
                $row->due_balance = bcadd((string) $row->due_balance, ltrim($amountStr, '-'), 2);
            }
            $row->last_updated_at = now();
            $row->save();

            return $row;
        });
    }

    /**
     * Consume advance credit against a month's bill at close (D-09).
     *
     * Decrements `balance` by exactly `$amount` (a normalized 2-decimal string).
     * This is the write point that makes an advance deposit actually pay down a
     * bill — paired with BillPreviewService's `advance_applied`. BillPreviewService
     * caps advanceApplied at the available credit, so $amount <= balance by
     * construction; the defensive clamp below keeps balance >= 0 regardless.
     *
     * `$amount` MUST be a normalized 2-decimal string (e.g. `'61.75'`).
     */
    public function consumeAdvance(int $memberId, string $amount): AdvanceBalance
    {
        $amountStr = number_format((float) $amount, 2, '.', '');

        return DB::transaction(function () use ($memberId, $amountStr) {
            $row = AdvanceBalance::query()
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['mess_id' => Mess::activeId(), 'member_id' => $memberId],
                    ['balance' => 0, 'due_balance' => 0, 'last_updated_at' => now()]
                );

            // Defensive clamp: never let balance go negative even if a caller
            // somehow passes more than the available credit.
            if (bccomp($amountStr, (string) $row->balance, 2) > 0) {
                $amountStr = (string) $row->balance;
            }

            $row->balance = bcsub((string) $row->balance, $amountStr, 2);
            $row->last_updated_at = now();
            $row->save();

            return $row;
        });
    }

    /**
     * Net a member's credit (balance) against their debt (due_balance) so they
     * never simultaneously owe and are owed (D-09). Settles the smaller of the
     * two against the larger in BC math on the member's locked row.
     *
     * Called once per member at the end of MonthCloseService::close(), after the
     * month's advance has been consumed and any remaining bill carried to due.
     */
    public function settle(int $memberId): ?AdvanceBalance
    {
        return DB::transaction(function () use ($memberId) {
            $row = AdvanceBalance::query()
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return null;
            }

            $balance = (string) $row->balance;
            $due = (string) $row->due_balance;

            if (bccomp($balance, '0', 2) > 0 && bccomp($due, '0', 2) > 0) {
                $settle = bccomp($balance, $due, 2) <= 0 ? $balance : $due;
                $row->balance = bcsub($balance, $settle, 2);
                $row->due_balance = bcsub($due, $settle, 2);
                $row->last_updated_at = now();
                $row->save();
            }

            return $row;
        });
    }

    /**
     * Apply a payment against the member's outstanding DUE settlements FIFO, and
     * mirror the settlement's effect onto advance_balances so the NET
     * (balance − due_balance) changes by exactly +payment.amount regardless of
     * payment type. This is the payment-time half of pending-settlement capture.
     *
     * Ledger bookkeeping lives in PendingSettlementService::applyPayment() (it
     * returns the total `applied`); the balance mutation lives HERE so this
     * service stays the single owner of advance_balances. Both run in one
     * DB::transaction so they commit or roll back together.
     *
     * Math (applied = portion that cleared dues; A = payment amount):
     *   ADVANCE_DEPOSIT: applyPayment() already did balance += A. To reach the
     *     invariant end state (balance += A − applied, due_balance −= applied)
     *     we subtract `applied` from BOTH balance and due_balance — the same
     *     net-off settle() does, scoped to the applied amount.
     *   BILL_PAYMENT: applyPayment() was a no-op. Here we subtract `applied`
     *     from due_balance and add the residual (A − applied) to balance so any
     *     overpayment becomes credit.
     *
     * Returns `applied` (2-decimal string) for logging.
     */
    public function applySettlementToDue(Payment $payment): string
    {
        $amount = number_format((float) $payment->amount, 2, '.', '');

        return DB::transaction(function () use ($payment, $amount) {
            $applied = app(PendingSettlementService::class)->applyPayment($payment);

            $row = AdvanceBalance::query()
                ->where('member_id', $payment->member_id)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['mess_id' => Mess::activeId(), 'member_id' => $payment->member_id],
                    ['balance' => 0, 'due_balance' => 0, 'last_updated_at' => now()]
                );

            if (bccomp($applied, '0', 2) <= 0) {
                // Nothing to settle — balance already reflects applyPayment() (if any).
                return $applied;
            }

            if ($payment->type === PaymentType::ADVANCE_DEPOSIT) {
                $row->balance = bcsub((string) $row->balance, $applied, 2);
                $row->due_balance = bcsub((string) $row->due_balance, $applied, 2);
            } else {
                $row->due_balance = bcsub((string) $row->due_balance, $applied, 2);
                $residual = bcsub($amount, $applied, 2);
                if (bccomp($residual, '0', 2) > 0) {
                    $row->balance = bcadd((string) $row->balance, $residual, 2);
                }
            }

            // Clamp rounding drift so due_balance never goes negative.
            if (bccomp((string) $row->due_balance, '0', 2) < 0) {
                $row->due_balance = '0.00';
            }

            $row->last_updated_at = now();
            $row->save();

            return $applied;
        });
    }

    /**
     * Reverse the settlement impact of a payment (mirror of applySettlementToDue),
     * used by PaymentService::update and ::delete. Deletes the payment's
     * settlement_applications (via PendingSettlementService::reversePayment, which
     * returns the total that had been applied) and restores advance_balances so
     * the net moves by exactly −payment.amount.
     *
     * MUST be called BEFORE applyPayment()/applySettlementToDue() for the new
     * values during an update (same reverse-then-reapply order as reversePayment).
     */
    public function reverseSettlementFromDue(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $applied = app(PendingSettlementService::class)->reversePayment($payment);

            if (bccomp($applied, '0', 2) <= 0) {
                return;
            }

            $row = AdvanceBalance::query()
                ->where('member_id', $payment->member_id)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return;
            }

            $amount = number_format((float) $payment->amount, 2, '.', '');

            if ($payment->type === PaymentType::ADVANCE_DEPOSIT) {
                // Reverse the net-off: add `applied` back to both columns.
                $row->balance = bcadd((string) $row->balance, $applied, 2);
                $row->due_balance = bcadd((string) $row->due_balance, $applied, 2);
            } else {
                // Reverse BILL_PAYMENT: restore due_balance, remove the residual credit.
                $row->due_balance = bcadd((string) $row->due_balance, $applied, 2);
                $residual = bcsub($amount, $applied, 2);
                if (bccomp($residual, '0', 2) > 0) {
                    $row->balance = bcsub((string) $row->balance, $residual, 2);
                    if (bccomp((string) $row->balance, '0', 2) < 0) {
                        $row->balance = '0.00';
                    }
                }
            }

            $row->last_updated_at = now();
            $row->save();
        });
    }
}
