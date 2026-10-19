<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable recurrence columns keep every legacy bill a one-time bill
     * without backfill: NULL/NULL rows are excluded from the partial
     * unique index and stay unlimited (NULLs are distinct in both
     * SQLite and PostgreSQL).
     *
     * The unique backstop (approved as D-06) is created with native SQL
     * because the Laravel 13 schema grammars compile no WHERE clause for
     * index definitions (verified in vendor: compileUnique/compileIndex
     * emit a bare column list), so a fluent ->where() would be silently
     * dropped. The native CREATE UNIQUE INDEX ... WHERE form is
     * supported identically by SQLite (partial indexes >= 3.8.0) and
     * PostgreSQL, preserving the approved partial-index semantics on
     * both drivers instead of falling back to a plain unique index.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->foreignUlid('recurrence_id')->nullable()->constrained('bill_recurrences')->nullOnDelete();
            $table->date('recurrence_period')->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX bills_recurrence_id_recurrence_period_unique ON bills (recurrence_id, recurrence_period) WHERE recurrence_id IS NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bills_recurrence_id_recurrence_period_unique');

        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['recurrence_id']);
            $table->dropColumn('recurrence_id');
            $table->dropColumn('recurrence_period');
        });
    }
};
