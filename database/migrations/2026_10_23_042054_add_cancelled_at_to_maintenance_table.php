<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * cancelled_at mirrors completed_at (a nullable `date`), so the derived
     * lifecycle stays symmetric: scheduled = both null, completed =
     * completed_at set, cancelled = cancelled_at set. No backfill is
     * needed — every existing row is scheduled.
     */
    public function up(): void
    {
        Schema::table('maintenance', function (Blueprint $table) {
            $table->date('cancelled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
