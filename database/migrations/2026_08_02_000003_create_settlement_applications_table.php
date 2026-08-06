<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement applications — the pivot that lets ONE payment clear MULTIPLE
 * pending settlements FIFO (e.g. a ৳1,500 payment clears July's ৳1,121.32 due
 * and part of August's). One row per (settlement, payment) partial or full
 * application. PendingSettlement.amount_settled is the sum of these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pending_settlement_id')->constrained()->cascadeOnDelete();
            // Nullable: credit settlements cleared via manual "Mark settled" have
            // no backing payment and so create no application row.
            $table->foreignId('payment_id')->nullable()->constrained('payments')->cascadeOnDelete();
            $table->decimal('applied_amount', 10, 2);
            $table->timestamps();

            $table->index(['payment_id']);
            // A payment applying to the same settlement twice is always a bug.
            $table->unique(['pending_settlement_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_applications');
    }
};
