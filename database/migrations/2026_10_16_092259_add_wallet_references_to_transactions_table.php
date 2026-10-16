<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Both references are nullable so historical transactions without a
     * wallet keep working. RESTRICT keeps ledger rows pointing at a
     * wallet from ever losing their wallet silently; wallets carrying
     * history are additionally protected at the service layer and can
     * only be deactivated, never deleted.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUlid('wallet_id')->nullable()->constrained('wallets')->restrictOnDelete();
            $table->foreignUlid('destination_wallet_id')->nullable()->constrained('wallets')->restrictOnDelete();
            $table->index(['wallet_id']);
            $table->index(['destination_wallet_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['destination_wallet_id']);
            $table->dropIndex(['destination_wallet_id']);
            $table->dropColumn('destination_wallet_id');
            $table->dropForeign(['wallet_id']);
            $table->dropIndex(['wallet_id']);
            $table->dropColumn('wallet_id');
        });
    }
};
