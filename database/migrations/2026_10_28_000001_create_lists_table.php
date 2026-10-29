<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('lists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20); // 'shopping' or 'custom'
            $table->foreignUlid('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Indexes per contract §6.2
            $table->index(['household_id', 'archived_at']);
            $table->index(['household_id', 'type', 'archived_at']);
        });

        // Partial index: recently updated active lists
        // CREATE INDEX lists_household_active_order_idx ON lists (household_id, updated_at DESC, id DESC) WHERE archived_at IS NULL
        DB::statement("
            CREATE INDEX lists_household_active_order_idx
            ON lists (household_id, updated_at DESC, id DESC)
            WHERE archived_at IS NULL;
        ");
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        // Drop the partial index before the table. SQLite has no
        // `DROP INDEX ... ON table` syntax, and PostgreSQL accepts the
        // table-less form as well.
        DB::statement('DROP INDEX IF EXISTS lists_household_active_order_idx');
        Schema::dropIfExists('lists');
    }
};