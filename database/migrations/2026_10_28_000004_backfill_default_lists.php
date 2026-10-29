<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        DB::statement("
            WITH new_defaults AS (
                SELECT
                    h.id AS household_id,
                    l.id AS list_id,
                    h.created_by AS default_created_by
                FROM households h
                JOIN lists l ON l.household_id = h.id AND l.type = 'shopping'
                LEFT JOIN lists l2 ON l2.household_id = h.id AND l2.archived_at IS NULL AND l2.id != l.id
                WHERE l.archived_at IS NULL
                  AND (l2.id IS NULL OR l.created_at > l2.created_at OR (l.created_at = l2.created_at AND l.id > l2.id))
                GROUP BY h.id, l.id
                HAVING COUNT(l2.id) < 1
                  OR (SELECT COUNT(*) FROM lists l3 WHERE l3.household_id = h.id AND l3.archived_at IS NULL AND l3.type = 'shopping' AND (l3.created_at < l.created_at OR (l3.created_at = l.created_at AND l3.id < l.id))) = 0
            )
            UPDATE households
            SET default_list_id = new_defaults.list_id
            FROM new_defaults
            WHERE households.id = new_defaults.household_id
              AND households.default_list_id IS NULL;
        ");
    }

    /**
     * Reverse the migrations.
     *
     * This migration only backfills data owned by the previous migration.
     * Dropping `default_list_id` here would duplicate that migration's
     * down() and, on SQLite, fail while the foreign key still references
     * the column. The column lifecycle stays in
     * 2026_10_28_000003_add_default_list_id_to_households_table.
     */
    public function down(): void
    {
        // No schema change to reverse.
    }
};