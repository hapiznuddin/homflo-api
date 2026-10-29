<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->foreignUlid('default_list_id')->nullable()->constrained('lists')->nullOnDelete();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            // Drop the foreign key together with the column. A plain
            // dropColumn leaves the constraint pointing at a missing column
            // on SQLite and aborts the rollback.
            $table->dropConstrainedForeignId('default_list_id');
        });
    }
};