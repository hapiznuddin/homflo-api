<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

function renameHouseholdFor(User $owner, string $name = 'Rumah A'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $owner->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);

    return $household->refresh();
}

describe('PATCH /api/households/{household}', function () {
    test('guest cannot rename a household', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner);

        $this->patchJson("/api/households/{$household->id}", ['name' => 'Baru'])
            ->assertUnauthorized();
    });

    test('owner can rename the household and receives the household context', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner);

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}",
            ['name' => 'Rumah Tangga Budi']
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.household.id', $household->id);
        $response->assertJsonPath('data.household.name', 'Rumah Tangga Budi');
        $response->assertJsonPath('data.household.role', 'owner');
        $response->assertJsonPath('data.onboarding.required', false);

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'name' => 'Rumah Tangga Budi',
        ]);

        $this->actingAs($owner)->getJson('/api/me')
            ->assertJsonPath('data.household.name', 'Rumah Tangga Budi');
    });

    test('name is trimmed before it is stored', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner);

        $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}",
            ['name' => '   Rumah Baru   ']
        )->assertOk();

        expect($household->refresh()->name)->toBe('Rumah Baru');
    });

    test('whitespace-only name is rejected and the household is unchanged', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner, 'Nama Asli');

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}",
            ['name' => '     ']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);

        expect($household->refresh()->name)->toBe('Nama Asli');
    });

    test('name longer than 255 characters is rejected', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner);

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}",
            ['name' => str_repeat('a', 256)]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    test('member cannot rename the household', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner);
        $member = User::factory()->create();

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($member)->patchJson(
            "/api/households/{$household->id}",
            ['name' => 'Diubah Member']
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'FORBIDDEN');

        expect($household->refresh()->name)->toBe('Rumah A');
    });

    test('non-member is masked as not found and the household is unchanged', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner);
        $outsider = User::factory()->create();
        renameHouseholdFor($outsider, 'Rumah Lain');

        $response = $this->actingAs($outsider)->patchJson(
            "/api/households/{$household->id}",
            ['name' => 'Dibajak']
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        expect($household->refresh()->name)->toBe('Rumah A');
    });

    test('unverified owner cannot rename', function () {
        $owner = User::factory()->unverified()->create();
        $household = renameHouseholdFor($owner);

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}",
            ['name' => 'Belum Verifikasi']
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        expect($household->refresh()->name)->toBe('Rumah A');
    });

    test('client cannot move the household or change its ownership fields', function () {
        $owner = User::factory()->create();
        $household = renameHouseholdFor($owner);
        $otherOwner = User::factory()->create();
        $otherHousehold = renameHouseholdFor($otherOwner, 'Rumah Lain');

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}",
            [
                'name' => 'Rumah Sah',
                'id' => $otherHousehold->id,
                'household_id' => $otherHousehold->id,
                'created_by' => $otherOwner->id,
                'role' => 'member',
                'default_list_id' => null,
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('data.household.id', $household->id);

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'name' => 'Rumah Sah',
            'created_by' => $owner->id,
            'default_list_id' => null,
        ]);
        $this->assertDatabaseHas('households', [
            'id' => $otherHousehold->id,
            'name' => 'Rumah Lain',
        ]);
        expect($household->refresh()->created_by)->toBe($owner->id);
    });
});
