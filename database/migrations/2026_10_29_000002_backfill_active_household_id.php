<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotent and chunked: every user with at least one membership gets the
     * oldest membership as the active household (`created_at`, then `id` as a
     * deterministic tie-break). Users without membership stay null so
     * onboarding remains required. No business data is rewritten.
     */
    public function up(): void
    {
        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $membership = DB::table('household_members')
                        ->where('user_id', $user->id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->first();

                    if ($membership === null) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['active_household_id' => $membership->household_id]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Backfill is data, not schema: rolling back the column migration removes
     * the pointer, so there is nothing meaningful to restore here.
     */
    public function down(): void
    {
        //
    }
};
