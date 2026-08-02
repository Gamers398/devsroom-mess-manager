<?php

namespace App\Services;

use App\Models\AdvanceBalance;
use App\Models\BalanceAdjustment;
use App\Models\MonthlyCorrection;
use App\Models\Payment;
use App\Models\PendingSettlement;
use App\Models\SettlementApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the pending_settlements ledger: capturing residuals at close/correction,
 * applying payments FIFO against outstanding dues, reversing those applications
 * on payment update/delete, and the manual credit-clear path.
 *
 * The advance_balances row itself is mutated by AdvanceBalanceService (single
 * owner); this service only mutates the settlement tables and returns the
 * amounts so the caller can keep the running balance invariant intact.
 */
class PendingSettlementService
{
    /**
     * Snapshot a member's residual (due or credit) at month close. Idempotent:
     * firstOrCreate keyed on (source_closing_id, member_id, kind, source='close').
     *
     * @param  array{mess_id:int, source_closing_id:int, source_year:int, source_month:int, member_id:int, kind:string, amount:string}  $attrs
     */
    public function captureFromClose(array $attrs): PendingSettlement
    {
        return PendingSettlement::firstOrCreate(
            [
                'source_closing_id' => $attrs['source_closing_id'],
                'member_id' => $attrs['member_id'],
                'kind' => $attrs['kind'],
                'source' => 'close',
            ],
            [
                'mess_id' => $attrs['mess_id'],
                'source_year' => $attrs['source_year'],
                'source_month' => $attrs['source_month'],
                'original_amount' => $attrs['amount'],
                'amount_settled' => 0,
            ]
        );
    }

    /**
     * Snapshot a correction's signed amount as a tracked pending settlement
     * (source='correction'). One row per correction, keyed on monthly_correction_id.
     */
    public function captureFromCorrection(
        MonthlyCorrection $correction,
        string $kind,
        string $amount,
    ): ?PendingSettlement {
        if (bccomp($amount, '0', 2) === 0) {
            return null;
        }

        return PendingSettlement::firstOrCreate(
            [
                'monthly_correction_id' => $correction->id,
                'member_id' => $correction->member_id,
                'kind' => $kind,
                'source' => 'correction',
            ],
            [
                'mess_id' => $correction->mess_id,
                'source_closing_id' => $correction->monthly_closing_id,
                'source_year' => $correction->applied_to_year,
                'source_month' => $correction->applied_to_month,
                'original_amount' => $amount,
                'amount_settled' => 0,
            ]
        );
    }

    /**
     * Walk the member's outstanding DUE settlements oldest-first, creating a
     * settlement_applications row for each until the payment is consumed.
     * Mutates each settlement's amount_settled. Returns the total applied as a
     * canonical 2-decimal string (≤ payment amount). Pure bookkeeping — never
     * touches advance_balances (the caller does, to preserve the net invariant).
     *
     * MUST run inside the caller's DB::transaction so the ledger writes and the
     * balance mutation commit or roll back together.
     */
    public function applyPayment(Payment $payment): string
    {
        $remaining = number_format((float) $payment->amount, 2, '.', '');

        $dues = PendingSettlement::query()
            ->where('member_id', $payment->member_id)
            ->where('kind', 'due')
            ->whereColumn('amount_settled', '<', 'original_amount')
            ->orderBy('source_year')
            ->orderBy('source_month')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($dues as $settlement) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $owed = bcsub((string) $settlement->original_amount, (string) $settlement->amount_settled, 2);
            $apply = bccomp($remaining, $owed, 2) <= 0 ? $remaining : $owed;

            SettlementApplication::create([
                'pending_settlement_id' => $settlement->id,
                'payment_id' => $payment->id,
                'applied_amount' => $apply,
            ]);

            $settlement->amount_settled = bcadd((string) $settlement->amount_settled, $apply, 2);
            $settlement->save();

            $remaining = bcsub($remaining, $apply, 2);
        }

        return bcsub(number_format((float) $payment->amount, 2, '.', ''), $remaining, 2);
    }

    /**
     * Reverse every application of a payment (called on payment update/delete).
     * Deletes the settlement_applications rows and recomputes each affected
     * settlement's amount_settled from the surviving applications. Returns the
     * total that HAD been applied so the caller can mirror-reverse the balance.
     *
     * MUST run inside the caller's DB::transaction.
     */
    public function reversePayment(Payment $payment): string
    {
        $applications = SettlementApplication::query()
            ->where('payment_id', $payment->id)
            ->lockForUpdate()
            ->get();

        if ($applications->isEmpty()) {
            return '0.00';
        }

        $totalApplied = '0.00';
        $settlementIds = $applications->pluck('pending_settlement_id')->unique()->all();

        foreach ($applications as $application) {
            $totalApplied = bcadd($totalApplied, (string) $application->applied_amount, 2);
            $application->delete();
        }

        // Recompute amount_settled from whatever applications remain.
        foreach ($settlementIds as $id) {
            $sum = (string) SettlementApplication::query()
                ->where('pending_settlement_id', $id)
                ->sum('applied_amount');
            PendingSettlement::where('id', $id)->update([
                'amount_settled' => number_format((float) $sum, 2, '.', ''),
            ]);
        }

        return $totalApplied;
    }

    /**
     * Manually clear a CREDIT settlement (v1): the manager refunded the member
     * in real life and records it here. Marks the settlement settled AND reduces
     * advance_balances.balance by the outstanding amount (clamped at 0) so the
     * credit is actually consumed. Dues are never cleared this way — throw.
     */
    public function markCreditSettledManually(PendingSettlement $settlement, int $userId, string $note): PendingSettlement
    {
        if ($settlement->kind !== 'credit') {
            throw new RuntimeException('Only credit settlements can be manually cleared.');
        }

        $remaining = $settlement->amountRemaining();
        if (bccomp($remaining, '0', 2) <= 0) {
            return $settlement;
        }

        return DB::transaction(function () use ($settlement, $userId, $note, $remaining) {
            $settlement->update([
                'amount_settled' => $settlement->original_amount,
                'settled_by' => $userId,
                'settled_at' => now(),
                'settlement_method' => 'manual',
            ]);

            // Consume the credit from the running balance (clamp at 0).
            $row = AdvanceBalance::query()
                ->where('member_id', $settlement->member_id)
                ->lockForUpdate()
                ->first();

            if ($row && bccomp((string) $row->balance, '0', 2) > 0) {
                $reduce = bccomp($remaining, (string) $row->balance, 2) < 0 ? $remaining : (string) $row->balance;
                $row->balance = bcsub((string) $row->balance, $reduce, 2);
                $row->last_updated_at = now();
                $row->save();
            }

            // Wallet-ledger trail explaining the drop (negative = money out).
            BalanceAdjustment::create([
                'mess_id' => $settlement->mess_id,
                'member_id' => $settlement->member_id,
                'amount' => bcsub('0', $remaining, 2),
                'reason' => $note !== '' ? $note : __('Pending credit settled (manual refund).'),
                'entered_by' => $userId,
            ]);

            app(BillPreviewService::class)->invalidate(now()->year, now()->month);

            return $settlement->refresh();
        });
    }

    /**
     * Outstanding (not fully settled) settlements for the index view.
     *
     * @return Collection<int, PendingSettlement>
     */
    public function outstanding(int $messId, ?string $kind = null): Collection
    {
        return PendingSettlement::query()
            ->where('mess_id', $messId)
            ->whereColumn('amount_settled', '<', 'original_amount')
            ->when($kind, fn (Builder $q) => $q->where('kind', $kind))
            ->with(['member:id,name', 'sourceClosing:id,year,month'])
            ->orderBy('source_year')
            ->orderBy('source_month')
            ->orderBy('kind')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{to_collect:string, to_pay_out:string, net:string}
     */
    public function outstandingTotals(int $messId): array
    {
        $rows = PendingSettlement::query()
            ->where('mess_id', $messId)
            ->whereColumn('amount_settled', '<', 'original_amount')
            ->selectRaw('kind, SUM(original_amount - amount_settled) AS outstanding')
            ->groupBy('kind')
            ->get()
            ->keyBy('kind');

        $due = (string) ($rows->get('due')->outstanding ?? 0);
        $credit = (string) ($rows->get('credit')->outstanding ?? 0);

        return [
            'to_collect' => number_format((float) $due, 2, '.', ''),
            'to_pay_out' => number_format((float) $credit, 2, '.', ''),
            'net' => bcsub($due, $credit, 2),
        ];
    }
}
