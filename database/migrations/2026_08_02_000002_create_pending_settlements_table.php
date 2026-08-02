<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending settlements — tracked, per-close snapshots of each member's residual
 * (due or credit) that must be cleared by a real cash transaction instead of
 * silently carrying forward into next month's running balance. See
 * PendingSettlementService + the "Pending Settlements" plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mess_id')->constrained('messes')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('source_closing_id')->constrained('monthly_closings')->cascadeOnDelete();
            // NULL when the settlement originated at month close; the correction
            // id when it was created by MonthlyCorrectionService (source='correction').
            $table->foreignId('monthly_correction_id')->nullable()->constrained('monthly_corrections')->cascadeOnDelete();

            // Denormalized from the closing so the list/FIFO queries need no join.
            $table->unsignedSmallInteger('source_year');
            $table->unsignedSmallInteger('source_month');

            // 'close' (created at month close) | 'correction' (created by a correction)
            $table->string('source', 16)->default('close');

            // 'due' = member owes the mess; 'credit' = mess owes the member.
            $table->string('kind', 10);

            // original_amount = captured snapshot (immutable). amount_settled =
            // running total cleared by settlement_applications (dues) or a manual
            // mark-settled (credits). Status is DERIVED from these two — never
            // stored, so it cannot drift.
            $table->decimal('original_amount', 10, 2);
            $table->decimal('amount_settled', 10, 2)->default(0);

            // Manual credit-clear path (v1). Due settlements are cleared via
            // settlement_applications linked to a payment, so these stay NULL.
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->string('settlement_method', 20)->nullable(); // 'payment' | 'manual'

            $table->timestamps();

            // Outstanding list query: "all outstanding for this mess, by kind".
            $table->index(['mess_id', 'kind']);
            // FIFO walk at payment time: oldest outstanding due for a member first.
            $table->index(['member_id', 'kind', 'source_year', 'source_month'], 'ps_fifo_idx');
            // Trace a settlement back to its originating correction.
            $table->index(['monthly_correction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_settlements');
    }
};
