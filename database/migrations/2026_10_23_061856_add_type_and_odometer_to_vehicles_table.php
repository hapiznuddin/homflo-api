<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Vehicle Information fields the product decision requires that
     * are not yet represented: vehicle `type` and the current `odometer`.
     *
     * `name`, `brand`, `model`, `year`, and `plate_number` already exist in
     * the base `vehicles` table and are not duplicated (the product label
     * "license_plate" maps onto the existing `plate_number` column).
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('type')->nullable();
            $table->unsignedInteger('odometer')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['type', 'odometer']);
        });
    }
};
