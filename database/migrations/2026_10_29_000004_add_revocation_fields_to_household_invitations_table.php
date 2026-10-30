<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Revoked invitations are kept for audit; there is no cleanup job in the
     * MVP. The extra index serves the owner-side list filters.
     */
    public function up(): void
    {
        Schema::table('household_invitations', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('accepted_at');
            $table->foreignUlid('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->index(['household_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('household_invitations', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'status']);
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn('revoked_at');
        });
    }
};
