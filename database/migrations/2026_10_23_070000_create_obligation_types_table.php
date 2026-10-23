<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * System types have no household and are seeded once here. Custom types
     * are household-scoped configuration created through the API. A nullable
     * unique code keeps the built-in identifiers stable without forcing
     * custom types to have one.
     */
    public function up(): void
    {
        Schema::create('obligation_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('household_id')->nullable()->constrained('households')->cascadeOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->index(['household_id']);
            $table->unique(['household_id', 'name']);
        });

        $now = now();

        DB::table('obligation_types')->insert([
            [
                'id' => (string) Str::ulid(),
                'household_id' => null,
                'code' => 'tax',
                'name' => 'Pajak Kendaraan',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::ulid(),
                'household_id' => null,
                'code' => 'registration',
                'name' => 'STNK / Registrasi',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::ulid(),
                'household_id' => null,
                'code' => 'inspection',
                'name' => 'KIR / Inspeksi',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('obligation_types');
    }
};
