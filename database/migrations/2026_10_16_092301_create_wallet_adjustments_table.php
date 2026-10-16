<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adjustments are an immutable audit trail: there is deliberately no
     * update or delete path. Corrections are recorded as new adjustments.
     * RESTRICT on both household and wallet keeps a correction from ever
     * losing its ledger context silently.
     */
    public function up(): void
    {
        Schema::create('wallet_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->constrained('households')->restrictOnDelete();
            $table->foreignUlid('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->text('reason');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['wallet_id', 'created_at']);
            $table->index(['household_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_adjustments');
    }
};
