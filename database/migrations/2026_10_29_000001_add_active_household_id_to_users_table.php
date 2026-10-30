<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The pointer is nullable because a user without any membership must keep
     * onboarding required. `nullOnDelete` lets a removed household clear the
     * pointer instead of deleting the user; the resolver then falls back to
     * the oldest remaining membership.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('active_household_id')
                ->nullable()
                ->after('email_verified_at')
                ->constrained('households')
                ->nullOnDelete();
            $table->index('active_household_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The explicit index must go before the column: SQLite rebuilds
            // the table and refuses to drop a column an index still uses.
            $table->dropIndex(['active_household_id']);
            // Drop the constraint together with the column; a bare
            // dropColumn leaves the foreign key pointing at a missing column
            // on SQLite and aborts the rollback.
            $table->dropConstrainedForeignId('active_household_id');
        });
    }
};
