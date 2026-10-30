<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

function backfillMembership(User $user, string $name, string $createdAt): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $user->id,
    ]);

    $membership = HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $membership->forceFill(['created_at' => $createdAt])->save();

    return $household->refresh();
}

function runActiveHouseholdBackfill(): void
{
    $migration = require database_path('migrations/2026_10_29_000002_backfill_active_household_id.php');
    $migration->up();
}

describe('active_household_id migration and backfill', function () {
    test('the users table has a nullable active_household_id column', function () {
        expect(Schema::hasColumn('users', 'active_household_id'))->toBeTrue();

        $user = User::factory()->create();

        expect($user->refresh()->active_household_id)->toBeNull();
    });

    test('backfill picks the oldest membership for users with several households', function () {
        $user = User::factory()->create();
        $newer = backfillMembership($user, 'Rumah Baru', '2026-03-01 00:00:00');
        $older = backfillMembership($user, 'Rumah Lama', '2026-01-01 00:00:00');

        expect($user->refresh()->active_household_id)->toBeNull();

        runActiveHouseholdBackfill();

        expect($user->refresh()->active_household_id)->toBe($older->id);
        expect($older->id)->not->toBe($newer->id);
    });

    test('backfill uses the membership id as a deterministic tie-break', function () {
        $user = User::factory()->create();
        $first = backfillMembership($user, 'Rumah Satu', '2026-01-01 00:00:00');
        $second = backfillMembership($user, 'Rumah Dua', '2026-01-01 00:00:00');

        runActiveHouseholdBackfill();

        // Both memberships share a timestamp; the smallest membership ULID
        // wins, which keeps the result stable across runs and databases.
        $expected = HouseholdMember::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->value('household_id');

        expect($user->refresh()->active_household_id)->toBe($expected);
        expect($first->id)->not->toBe($second->id);
    });

    test('backfill leaves users without membership untouched', function () {
        $user = User::factory()->create();

        runActiveHouseholdBackfill();

        expect($user->refresh()->active_household_id)->toBeNull();
    });

    test('backfill is idempotent', function () {
        $user = User::factory()->create();
        $home = backfillMembership($user, 'Rumah', '2026-01-01 00:00:00');

        runActiveHouseholdBackfill();
        runActiveHouseholdBackfill();

        expect($user->refresh()->active_household_id)->toBe($home->id);
        expect(HouseholdMember::query()->where('user_id', $user->id)->count())->toBe(1);
    });
});
