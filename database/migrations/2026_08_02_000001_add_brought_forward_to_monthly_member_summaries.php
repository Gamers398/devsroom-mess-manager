<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_member_summaries', function (Blueprint $table) {
            // Brought-forward net (opening position carried in from the prior
            // month). Nullable so pre-deploy snapshots (frozen before this
            // column existed) keep working — read paths fall back to 0.00 when
            // null, so the Monthly Report degrades gracefully.
            $table->decimal('brought_forward', 12, 2)->nullable()->after('balance_due');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_member_summaries', function (Blueprint $table) {
            $table->dropColumn('brought_forward');
        });
    }
};
