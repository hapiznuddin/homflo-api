<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The column is nullable on purpose: only rows with unambiguous
     * evidence are classified. Anything else (unused categories, or
     * categories historically used for both income and expense) stays
     * NULL and is treated as legacy by validation, so no historical
     * data is ever re-typed by guesswork.
     *
     * Evidence order (first match wins):
     *  1. linked to at least one budget  -> 'expense' (budgets are
     *     expense targets by design);
     *  2. used exclusively by income transactions -> 'income';
     *  3. used exclusively by expense transactions -> 'expense'.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('type')->nullable();
            $table->index(['household_id', 'type']);
        });

        DB::table('categories')
            ->whereIn('id', function ($query) {
                $query->select('category_id')->from('budgets')->whereNotNull('category_id');
            })
            ->update(['type' => 'expense', 'updated_at' => now()]);

        $rows = DB::table('transactions')
            ->select('category_id', 'type')
            ->whereNotNull('category_id')
            ->whereIn('type', ['income', 'expense'])
            ->distinct()
            ->get();

        $typesByCategory = [];

        foreach ($rows as $row) {
            $typesByCategory[$row->category_id][$row->type] = true;
        }

        foreach ($typesByCategory as $categoryId => $types) {
            if (count($types) !== 1) {
                continue;
            }

            DB::table('categories')
                ->where('id', $categoryId)
                ->whereNull('type')
                ->update(['type' => array_key_first($types), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'type']);
            $table->dropColumn('type');
        });
    }
};
