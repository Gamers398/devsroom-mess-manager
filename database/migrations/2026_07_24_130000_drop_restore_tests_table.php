<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the restore_tests table. The entire restore-test subsystem (nightly
 * scratch-DB verification, the "Run restore-test" button, RestoreTestService,
 * the mysql_restore_test connection) was removed per operator decision — the
 * one-click Restore + the Activity log are sufficient. Forward-only: this only
 * runs the down() shape is intentionally a no-op (the table is gone for good).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('restore_tests');
    }

    public function down(): void
    {
        // Recreating the table would also require restoring the removed model,
        // service, command, and connection — intentionally not provided. The
        // restore-test subsystem is gone; a real restore is verified by running
        // the Restore flow itself, not by a nightly scratch-DB parity job.
    }
};
