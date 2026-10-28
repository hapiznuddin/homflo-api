<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX transactions_list_item_expense_unique
            ON transactions (source_type, source_id)
            WHERE source_type = 'App\\Models\\ListItem';
        ");
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS transactions_list_item_expense_unique");
    }
};