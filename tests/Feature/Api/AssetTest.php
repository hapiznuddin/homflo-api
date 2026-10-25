<?php

use App\Models\Asset;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Maintenance;
use App\Models\User;
use Illuminate\Support\Str;

function assetHousehold(User $owner, string $name = 'Rumah Aset', string $role = 'owner'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $owner->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'role' => $role,
    ]);

    return $household->refresh();
}

/**
 * @return array<string, mixed>
 */
function assetPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kulkas Dapur',
        'description' => 'Kulkas dua pintu',
        'location' => 'Dapur',
        'acquired_on' => '2026-01-15',
        'acquisition_cost' => 4500000,
    ], $overrides);
}

/**
 * @return list<string>
 */
function assetContractKeys(): array
{
    return [
        'id',
        'household_id',
        'name',
        'description',
        'location',
        'acquired_on',
        'acquisition_cost',
        'created_at',
        'updated_at',
        'property_id',
        'property',
    ];
}

describe('Assets API authentication', function () {
    test('guest cannot access protected asset endpoints', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $this->getJson('/api/assets')->assertStatus(401);
        $this->postJson('/api/assets', assetPayload())->assertStatus(401);
        $this->getJson("/api/assets/{$asset->id}")->assertStatus(401);
        $this->patchJson("/api/assets/{$asset->id}", ['name' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(401);

        expect(Asset::find($asset->id))->not->toBeNull();
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/assets')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/assets', assetPayload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Assets API authorization and household isolation', function () {
    test('member lists only their own household assets in name order', function () {
        $owner = User::factory()->create();
        $mine = assetHousehold($owner);
        Asset::factory()->for($mine)->create(['name' => 'Zebra']);
        Asset::factory()->for($mine)->create(['name' => 'Apel']);

        $stranger = User::factory()->create();
        $theirs = assetHousehold($stranger, 'Tetangga');
        Asset::factory()->for($theirs)->create(['name' => 'Rahasia tetangga']);

        $response = $this->actingAs($owner)->getJson('/api/assets');

        $response->assertOk()->assertJsonPath('success', true);

        $names = array_column($response->json('data'), 'name');

        expect($names)->toEqual(['Apel', 'Zebra']);
        expect($response->getContent())->not->toContain('Rahasia tetangga');
    });

    test('member reads an asset from their own household', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create([
            'name' => 'Mesin Cuci',
            'description' => 'Front loading',
            'location' => 'Kamar Mandi',
            'acquired_on' => '2025-06-01',
            'acquisition_cost' => 3200000,
        ]);

        $response = $this->actingAs($owner)->getJson("/api/assets/{$asset->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.household_id', $household->id)
            ->assertJsonPath('data.name', 'Mesin Cuci')
            ->assertJsonPath('data.description', 'Front loading')
            ->assertJsonPath('data.location', 'Kamar Mandi')
            ->assertJsonPath('data.acquired_on', '2025-06-01')
            ->assertJsonPath('data.acquisition_cost', '3200000.00');
    });

    test('cross-household access is masked as not found and never modifies the record', function () {
        $owner = User::factory()->create();
        assetHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = assetHousehold($stranger, 'Asing');
        $asset = Asset::factory()->for($foreign)->create(['name' => 'Milik tetangga']);

        $this->actingAs($owner)->getJson("/api/assets/{$asset->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", ['name' => 'bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/assets/{$asset->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        $fresh = Asset::find($asset->id);

        expect($fresh)->not->toBeNull();
        expect($fresh->name)->toBe('Milik tetangga');
    });

    test('member role reads but cannot mutate the shared asset catalogue', function () {
        $member = User::factory()->create();
        $household = assetHousehold($member, 'Keluarga', 'member');
        $asset = Asset::factory()->for($household)->create();

        $this->actingAs($member)->getJson('/api/assets')->assertOk();
        $this->actingAs($member)->getJson("/api/assets/{$asset->id}")->assertOk();

        $this->actingAs($member)->postJson('/api/assets', assetPayload(['name' => 'Oleh member']))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/assets/{$asset->id}", ['name' => 'Oleh member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/assets/{$asset->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        expect(Asset::find($asset->id))->not->toBeNull();
        expect(Asset::where('name', 'Oleh member')->exists())->toBeFalse();
    });

    test('unverified member can read but cannot mutate assets', function () {
        $owner = User::factory()->unverified()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $this->actingAs($owner)->getJson('/api/assets')->assertOk();
        $this->actingAs($owner)->getJson("/api/assets/{$asset->id}")->assertOk();

        $this->actingAs($owner)->postJson('/api/assets', assetPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", ['name' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/assets/{$asset->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('unknown ULIDs report not found without leaking', function () {
        $owner = User::factory()->create();
        assetHousehold($owner);
        $ghost = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/assets/{$ghost}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/assets/{$ghost}", ['name' => 'x'])->assertStatus(404);
        $this->actingAs($owner)->deleteJson("/api/assets/{$ghost}")->assertStatus(404);
    });
});

describe('Assets API validation and persistence', function () {
    test('owner creates an asset in the server-resolved household', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/assets', assetPayload());

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['household_id'])->toBe($household->id);
        expect($data['name'])->toBe('Kulkas Dapur');
        expect($data['description'])->toBe('Kulkas dua pintu');
        expect($data['location'])->toBe('Dapur');
        expect($data['acquired_on'])->toBe('2026-01-15');
        expect($data['acquisition_cost'])->toBe('4500000.00');
        expect(array_keys($data))->toEqual(assetContractKeys());

        expect(Asset::find($data['id']))->not->toBeNull();
        expect(Asset::where('household_id', $household->id)->count())->toBe(1);
    });

    test('store validates required and malformed fields', function () {
        $owner = User::factory()->create();
        assetHousehold($owner);

        $this->actingAs($owner)->postJson('/api/assets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['name' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['name' => str_repeat('n', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['description' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['description']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['location' => str_repeat('l', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['location']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['acquired_on' => '15-01-2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['acquired_on']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['acquisition_cost' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['acquisition_cost']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['acquisition_cost' => 'mahal']))
            ->assertStatus(422)->assertJsonValidationErrors(['acquisition_cost']);

        $this->actingAs($owner)->postJson('/api/assets', assetPayload(['acquisition_cost' => 10000000000000]))
            ->assertStatus(422)->assertJsonValidationErrors(['acquisition_cost']);

        expect(Asset::count())->toBe(0);
    });

    test('household ownership cannot be forged through the payload', function () {
        $owner = User::factory()->create();
        $mine = assetHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = assetHousehold($stranger, 'Asing');

        $response = $this->actingAs($owner)->postJson('/api/assets', assetPayload([
            'household_id' => $foreign->id,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.household_id', $mine->id);

        expect(Asset::where('household_id', $foreign->id)->count())->toBe(0);

        $asset = Asset::factory()->for($mine)->create();

        $update = $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'name' => 'diubah',
            'household_id' => $foreign->id,
        ]);

        $update->assertOk();

        expect($asset->refresh()->household_id)->toBe($mine->id);
        expect(Asset::where('household_id', $foreign->id)->count())->toBe(0);
    });

    test('owner updates asset fields and can clear nullable columns', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create([
            'name' => 'TV Ruang Tamu',
            'description' => 'Smart TV',
            'location' => 'Ruang Tamu',
            'acquired_on' => '2024-03-10',
            'acquisition_cost' => 7000000,
        ]);

        $response = $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'name' => 'TV Keluarga',
            'description' => null,
            'location' => 'Ruang Keluarga',
            'acquired_on' => '2025-12-31',
            'acquisition_cost' => 7500000,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $fresh = $asset->refresh();

        expect($fresh->name)->toBe('TV Keluarga');
        expect($fresh->description)->toBeNull();
        expect($fresh->location)->toBe('Ruang Keluarga');
        expect($fresh->acquired_on?->format('Y-m-d'))->toBe('2025-12-31');
        expect((string) $fresh->acquisition_cost)->toBe('7500000.00');
        expect($fresh->household_id)->toBe($household->id);

        // Omitted keys stay untouched on a partial update.
        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", ['location' => 'Gudang'])
            ->assertOk();

        $fresh->refresh();

        expect($fresh->location)->toBe('Gudang');
        expect($fresh->name)->toBe('TV Keluarga');
        expect($fresh->acquisition_cost)->toBe('7500000.00');
    });

    test('update rejects malformed fields without changing the record', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create(['name' => 'Awal']);

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", ['name' => 123])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", ['acquired_on' => '31/12/2026'])
            ->assertStatus(422)->assertJsonValidationErrors(['acquired_on']);

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", ['acquisition_cost' => -5])
            ->assertStatus(422)->assertJsonValidationErrors(['acquisition_cost']);

        expect($asset->refresh()->name)->toBe('Awal');
    });

    test('responses expose only the asset contract fields', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $detail = $this->actingAs($owner)->getJson("/api/assets/{$asset->id}");
        $detail->assertOk();

        expect(array_keys($detail->json('data')))->toEqual(assetContractKeys());
        expect($detail->json('data'))->not->toHaveKey('maintenances');
        expect($detail->json('data'))->not->toHaveKey('household');
    });
});

describe('Assets API delete lifecycle', function () {
    test('deleting an asset removes only the asset row', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $other = Asset::factory()->for($household)->create();

        $this->actingAs($owner)->deleteJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $asset->id);

        expect(Asset::find($asset->id))->toBeNull();
        expect(Asset::find($other->id))->not->toBeNull();
    });

    test('deleting an asset detaches maintenance rows instead of deleting them', function () {
        $owner = User::factory()->create();
        $household = assetHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $maintenance = Maintenance::factory()
            ->for($household)
            ->create(['asset_id' => $asset->id]);

        $this->actingAs($owner)->deleteJson("/api/assets/{$asset->id}")->assertOk();

        $fresh = $maintenance->refresh();

        expect(Asset::find($asset->id))->toBeNull();
        expect($fresh)->not->toBeNull();
        expect($fresh->asset_id)->toBeNull();
    });
});
