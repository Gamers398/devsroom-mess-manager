<?php

namespace App\Console\Commands;

use App\Models\AdvanceBalance;
use App\Models\MonthlyMemberSummary;
use App\Models\PendingSettlement;
use App\Services\PendingSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills pending_settlements rows for months closed BEFORE the feature
 * existed. Reads each MonthlyMemberSummary with a non-zero closing_balance and
 * creates a source='close' settlement (idempotent via firstOrCreate).
 *
 * Reconcile mode (--reconcile): cross-checks each member's outstanding
 * settlement totals against the live advance_balances columns and warns on
 * mismatch — payments made before this feature existed would have updated the
 * live balance without clearing the settlement ledger.
 */
class BackfillPendingSettlements extends Command
{
    protected $signature = 'settlements:backfill {--reconcile}';

    protected $description = 'Backfill pending_settlements from existing closed-month summaries.';

    public function handle(PendingSettlementService $service): int
    {
        $summaries = MonthlyMemberSummary::query()
            ->whereNotNull('closing_balance')
            ->where('closing_balance', '!=', 0)
            ->orderBy('monthly_closing_id')
            ->get();

        if ($summaries->isEmpty()) {
            $this->info('No closed-month residuals to backfill.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($service, $summaries, &$created, &$skipped) {
            foreach ($summaries as $summary) {
                $closing = (string) $summary->closing_balance;
                $kind = bccomp($closing, '0', 2) < 0 ? 'due' : 'credit';

                $existing = PendingSettlement::query()
                    ->where('source_closing_id', $summary->monthly_closing_id)
                    ->where('member_id', $summary->member_id)
                    ->where('kind', $kind)
                    ->where('source', 'close')
                    ->exists();

                if ($existing) {
                    $skipped++;
                    continue;
                }

                $service->captureFromClose([
                    'mess_id' => $summary->mess_id,
                    'source_closing_id' => $summary->monthly_closing_id,
                    'source_year' => $summary->monthlyClosing->year,
                    'source_month' => $summary->monthlyClosing->month,
                    'member_id' => $summary->member_id,
                    'kind' => $kind,
                    'amount' => ltrim($closing, '-'),
                ]);
                $created++;
            }
        });

        $this->info("Backfill complete: created {$created}, skipped (already existed) {$skipped}.");

        if ($this->option('reconcile')) {
            $this->reconcile();
        }

        return self::SUCCESS;
    }

    /**
     * For each member with outstanding settlements, compare the outstanding due
     * total vs advance_balances.due_balance and the outstanding credit total vs
     * advance_balances.balance. Warn on mismatches (expected when payments were
     * recorded before the settlement feature existed).
     */
    private function reconcile(): void
    {
        $this->info('Running reconciliation checks...');

        $members = PendingSettlement::query()
            ->whereColumn('amount_settled', '<', 'original_amount')
            ->select('member_id', 'mess_id')
            ->distinct()
            ->get();

        $warnings = 0;
        foreach ($members as $row) {
            $outstanding = PendingSettlement::query()
                ->where('member_id', $row->member_id)
                ->whereColumn('amount_settled', '<', 'original_amount')
                ->selectRaw('kind, SUM(original_amount - amount_settled) AS outstanding')
                ->groupBy('kind')
                ->get()
                ->keyBy('kind');

            $dueOutstanding = (float) ($outstanding->get('due')->outstanding ?? 0);
            $creditOutstanding = (float) ($outstanding->get('credit')->outstanding ?? 0);

            $ab = AdvanceBalance::query()->where('member_id', $row->member_id)->first();
            $liveDue = (float) ($ab?->due_balance ?? 0);
            $liveBalance = (float) ($ab?->balance ?? 0);

            if (abs($dueOutstanding - $liveDue) >= 0.01 || abs($creditOutstanding - $liveBalance) >= 0.01) {
                $warnings++;
                $this->warn(sprintf(
                    'Member %d: settlement ledger (due %.2f / credit %.2f) vs live balance (due %.2f / credit %.2f) — MISMATCH. Live balance may already reflect payments that did not clear the settlement ledger.',
                    $row->member_id,
                    $dueOutstanding,
                    $creditOutstanding,
                    $liveDue,
                    $liveBalance,
                ));
            }
        }

        if ($warnings === 0) {
            $this->info('Reconciliation: no mismatches.');
        } else {
            $this->warn("Reconciliation: {$warnings} member(s) with mismatches — review above.");
        }
    }
}
