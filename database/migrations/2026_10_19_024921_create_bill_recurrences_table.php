<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores one recurrence definition per schedule: template fields
     * (title/amount/category/notes), calendar rules (frequency + due_day
     * where 31 clamps to the end of any target month), the schedule
     * toggle, and the monotonic materialization cursor
     * (materialized_until = first day of the last materialized period
     * month; null = nothing materialized yet). No payment status is
     * stored here or anywhere else — bills derive it from transactions.
     */
    public function up(): void
    {
        Schema::create('bill_recurrences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('title');
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->string('frequency');
            $table->unsignedTinyInteger('due_day');
            $table->boolean('is_active')->default(true);
            $table->date('materialized_until')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_recurrences');
    }
};
