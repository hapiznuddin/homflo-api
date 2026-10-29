<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('list_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('list_id')->constrained('lists')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('estimated_price', 15, 2)->nullable();
            $table->decimal('actual_price', 15, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Partial indexes per contract §6.3
        // Active ordering: CREATE INDEX list_items_active_order_idx ON list_items (list_id, created_at, id) WHERE completed_at IS NULL
        DB::statement("
            CREATE INDEX list_items_active_order_idx
            ON list_items (list_id, created_at, id)
            WHERE completed_at IS NULL;
        ");

        // Completed ordering: CREATE INDEX list_items_completed_order_idx ON list_items (list_id, completed_at DESC, id DESC) WHERE completed_at IS NOT NULL
        // PostgreSQL partial index with DESC requires native SQL
        DB::statement("
            CREATE INDEX list_items_completed_order_idx
            ON list_items (list_id, completed_at DESC, id DESC)
            WHERE completed_at IS NOT NULL;
        ");
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        // Drop partial indexes before the table; SQLite does not accept the
        // `DROP INDEX ... ON table` form.
        DB::statement('DROP INDEX IF EXISTS list_items_active_order_idx');
        DB::statement('DROP INDEX IF EXISTS list_items_completed_order_idx');
        Schema::dropIfExists('list_items');
    }
};