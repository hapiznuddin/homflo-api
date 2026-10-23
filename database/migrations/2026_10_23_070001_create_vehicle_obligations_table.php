<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Household ownership is intentionally derived through the vehicle,
     * matching vehicle_maintenance. The vehicle relation cascades on delete
     * as decided for Obligations. A custom type that is still referenced
     * cannot be removed: the foreign key is NO ACTION (checked at statement
     * end, so a household cascade can remove both rows together), while the
     * service surfaces a domain error instead of silently deleting or
     * detaching obligations.
     */
    public function up(): void
    {
        Schema::create('vehicle_obligations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignUlid('obligation_type_id')->constrained('obligation_types')->noActionOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('issued_date')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['vehicle_id', 'due_date']);
            $table->index(['obligation_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_obligations');
    }
};
