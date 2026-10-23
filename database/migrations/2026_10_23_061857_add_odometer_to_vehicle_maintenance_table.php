<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the odometer reading at the moment of service. This is the only
     * vehicle-maintenance field the product decision requires that the base
     * `vehicle_maintenance` table does not already have; no cost, status,
     * priority, or location columns are added.
     */
    public function up(): void
    {
        Schema::table('vehicle_maintenance', function (Blueprint $table) {
            $table->unsignedInteger('odometer')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_maintenance', function (Blueprint $table) {
            $table->dropColumn('odometer');
        });
    }
};
