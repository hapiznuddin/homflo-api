<?php

use App\Models\Asset;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Maintenance;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function apHousehold(User $owner, string $name = 'Rumah Properti', string $role = 'owner'): Household
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
function apPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kulkas Properti',
        'description' => 'Kulkas dua pintu',
        'location' => 'Dapur',
        'acquired_on' => '2026-02-10',
        'acquisition_cost' => 5200000,
    ], $overrides);
}

function apProperty(Household $household, array $overrides = []): Property
{
    return Property::factory()->for($household)->create($overrides);
}

/**
 * @return list<string>
 */
function apContractKeys(): array
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

/**
 * @return list<string>
 */
function apPropertySummaryKeys(): array
{
    return ['id', 'name', 'archived_at'];
}

describe('Assets API property relation on create', function () {
    test('creates an asset without a property when property_id is omitted', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/assets', apPayload());

        $response->assertCreated()
            ->assertJsonPath('data.property_id', null)
            ->assertJsonPath('data.property', null);

        $data = $response->json('data');

        expect(array_keys($data))->toEqual(apContractKeys());
        expect(Asset::find($data['id'])->property_id)->toBeNull();
        expect(Asset::where('household_id', $household->id)->count())->toBe(1);
    });

    test('creates an asset without a property when property_id is explicitly null', function () {
        $owner = User::factory()->create();
        apHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/assets', apPayload([
            'property_id' => null,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.property_id', null)
            ->assertJsonPath('data.property', null);

        expect(Asset::find($response->json('data.id'))->property_id)->toBeNull();
    });

    test('creates an asset linked to an active property in the same household', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household, ['name' => 'Rumah Utama']);

        $response = $this->actingAs($owner)->postJson('/api/assets', apPayload([
            'property_id' => $property->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.property_id', $property->id)
            ->assertJsonPath('data.property.id', $property->id)
            ->assertJsonPath('data.property.name', 'Rumah Utama')
            ->assertJsonPath('data.property.archived_at', null);

        expect(array_keys($response->json('data.property')))->toEqual(apPropertySummaryKeys());
        expect(Asset::find($response->json('data.id'))->property_id)->toBe($property->id);
    });

    test('rejects a property from another household without creating the asset', function () {
        $owner = User::factory()->create();
        apHousehold($owner);

        $stranger = User::factory()->create();
        $foreignHousehold = apHousehold($stranger, 'Rumah Tetangga');
        $foreignProperty = apProperty($foreignHousehold, ['name' => 'Properti Rahasia']);

        $response = $this->actingAs($owner)->postJson('/api/assets', apPayload([
            'property_id' => $foreignProperty->id,
        ]));

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PROPERTY_NOT_FOUND');

        expect($response->getContent())->not->toContain('Properti Rahasia');
        expect(Asset::count())->toBe(0);
    });

    test('rejects a missing property without creating the asset', function () {
        $owner = User::factory()->create();
        apHousehold($owner);

        $this->actingAs($owner)->postJson('/api/assets', apPayload([
            'property_id' => (string) Str::ulid(),
        ]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PROPERTY_NOT_FOUND');

        expect(Asset::count())->toBe(0);
    });

    test('rejects an archived property as a new relation without creating the asset', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household, ['archived_at' => now()]);

        $this->actingAs($owner)->postJson('/api/assets', apPayload([
            'property_id' => $property->id,
        ]))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PROPERTY_ARCHIVED');

        expect(Asset::count())->toBe(0);
    });

    test('rejects a malformed property_id with a validation error', function () {
        $owner = User::factory()->create();
        apHousehold($owner);

        $this->actingAs($owner)->postJson('/api/assets', apPayload([
            'property_id' => 'bukan-ulid',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['property_id']);

        expect(Asset::count())->toBe(0);
    });
});

describe('Assets API property relation on update', function () {
    test('omitting property_id keeps the existing relation', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household);
        $asset = Asset::factory()->for($household)->create([
            'property_id' => $property->id,
            'name' => 'Awal',
        ]);

        $response = $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Diubah',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Diubah')
            ->assertJsonPath('data.property_id', $property->id)
            ->assertJsonPath('data.property.id', $property->id);

        expect($asset->refresh()->property_id)->toBe($property->id);
    });

    test('an explicit null detaches the property relation', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household);
        $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);

        $response = $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'property_id' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.property_id', null)
            ->assertJsonPath('data.property', null);

        expect($asset->refresh()->property_id)->toBeNull();
    });

    test('a different active property replaces the relation', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $old = apProperty($household, ['name' => 'Rumah Lama']);
        $target = apProperty($household, ['name' => 'Rumah Baru']);
        $asset = Asset::factory()->for($household)->create(['property_id' => $old->id]);

        $response = $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'property_id' => $target->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.property_id', $target->id)
            ->assertJsonPath('data.property.name', 'Rumah Baru');

        expect($asset->refresh()->property_id)->toBe($target->id);
    });

    test('a property from another household is not found and changes nothing', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $current = apProperty($household);
        $asset = Asset::factory()->for($household)->create([
            'property_id' => $current->id,
            'name' => 'Nama Awal',
        ]);

        $stranger = User::factory()->create();
        $foreignProperty = apProperty(apHousehold($stranger, 'Rumah Asing'));

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Nama Bajak',
            'property_id' => $foreignProperty->id,
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PROPERTY_NOT_FOUND');

        $fresh = $asset->refresh();

        expect($fresh->property_id)->toBe($current->id);
        expect($fresh->name)->toBe('Nama Awal');
    });

    test('a missing replacement property is not found and changes nothing', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $current = apProperty($household);
        $asset = Asset::factory()->for($household)->create(['property_id' => $current->id]);

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'property_id' => (string) Str::ulid(),
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PROPERTY_NOT_FOUND');

        expect($asset->refresh()->property_id)->toBe($current->id);
    });

    test('a different archived property is rejected and changes nothing', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $current = apProperty($household);
        $archived = apProperty($household, ['archived_at' => now()]);
        $asset = Asset::factory()->for($household)->create(['property_id' => $current->id]);

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'property_id' => $archived->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPERTY_ARCHIVED');

        expect($asset->refresh()->property_id)->toBe($current->id);
    });

    test('the same archived property id is accepted as an unchanged relation', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household);
        $asset = Asset::factory()->for($household)->create([
            'property_id' => $property->id,
            'name' => 'Sebelum Arsip',
        ]);

        Property::query()
            ->whereKey($property->id)
            ->update(['archived_at' => now()]);

        $response = $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'name' => 'Sesudah Arsip',
            'property_id' => $property->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Sesudah Arsip')
            ->assertJsonPath('data.property_id', $property->id)
            ->assertJsonPath('data.property.id', $property->id);

        expect($response->json('data.property.archived_at'))->not->toBeNull();
        expect($asset->refresh()->property_id)->toBe($property->id);
    });

    test('an asset without a relation cannot be linked to an archived property', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $archived = apProperty($household, ['archived_at' => now()]);
        $asset = Asset::factory()->for($household)->create(['property_id' => null]);

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'property_id' => $archived->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPERTY_ARCHIVED');

        expect($asset->refresh()->property_id)->toBeNull();
    });

    test('a malformed replacement property_id is rejected with a validation error', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household);
        $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);

        $this->actingAs($owner)->patchJson("/api/assets/{$asset->id}", [
            'property_id' => 'bukan-ulid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['property_id']);

        expect($asset->refresh()->property_id)->toBe($property->id);
    });
});

describe('Assets API property archive and restore lifecycle', function () {
    test('archiving a property keeps the asset relation and shows the archived summary', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household, ['name' => 'Rumah Utama']);
        $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);
        $maintenance = Maintenance::factory()
            ->for($household)
            ->create(['asset_id' => $asset->id, 'title' => 'Servis Rutin']);
        $assetUpdatedAt = $asset->updated_at->toJSON();

        $this->actingAs($owner)->postJson("/api/properties/{$property->id}/archive")
            ->assertOk();

        $property->refresh();
        $asset->refresh();

        expect($asset->property_id)->toBe($property->id);
        expect($asset->updated_at->toJSON())->toBe($assetUpdatedAt);
        expect($maintenance->refresh()->asset_id)->toBe($asset->id);
        expect($maintenance->title)->toBe('Servis Rutin');

        $detail = $this->actingAs($owner)->getJson("/api/assets/{$asset->id}");

        $detail->assertOk()
            ->assertJsonPath('data.property_id', $property->id)
            ->assertJsonPath('data.property.id', $property->id)
            ->assertJsonPath('data.property.name', 'Rumah Utama');

        expect($detail->json('data.property.archived_at'))->toBe($property->archived_at);
        expect($detail->json('data.property.archived_at'))->not->toBeNull();
    });

    test('restoring a property keeps the asset relation and clears the archived summary', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household, ['archived_at' => now()]);
        $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);

        $this->actingAs($owner)->postJson("/api/properties/{$property->id}/restore")
            ->assertOk();

        $property->refresh();

        expect($asset->refresh()->property_id)->toBe($property->id);
        expect($property->archived_at)->toBeNull();

        $this->actingAs($owner)->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.property_id', $property->id)
            ->assertJsonPath('data.property.archived_at', null);
    });
});

describe('Assets API property response consistency', function () {
    test('create, detail, update, and list expose the same property shape', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household, ['name' => 'Rumah Konsisten']);

        $created = $this->actingAs($owner)->postJson('/api/assets', apPayload([
            'property_id' => $property->id,
        ]))->assertCreated();

        $assetId = $created->json('data.id');

        $detail = $this->actingAs($owner)->getJson("/api/assets/{$assetId}")->assertOk();
        $updated = $this->actingAs($owner)->patchJson("/api/assets/{$assetId}", [
            'name' => 'Nama Konsisten',
        ])->assertOk();
        $list = $this->actingAs($owner)->getJson('/api/assets')->assertOk();

        foreach ([$created, $detail, $updated] as $response) {
            expect(array_keys($response->json('data')))->toEqual(apContractKeys());
            expect(array_keys($response->json('data.property')))->toEqual(apPropertySummaryKeys());
            expect($response->json('data.property_id'))->toBe($property->id);
            expect($response->json('data.property.name'))->toBe('Rumah Konsisten');
        }

        $row = collect($list->json('data'))->firstWhere('id', $assetId);

        expect(array_keys($row))->toEqual(apContractKeys());
        expect(array_keys($row['property']))->toEqual(apPropertySummaryKeys());
        expect($row['property_id'])->toBe($property->id);
        expect($row['property']['name'])->toBe('Rumah Konsisten');
    });

    test('list responses keep a consistent shape for linked, archived, and unlinked assets', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $active = apProperty($household, ['name' => 'Rumah Aktif']);
        $archived = apProperty($household, ['name' => 'Rumah Arsip', 'archived_at' => now()]);

        Asset::factory()->for($household)->create(['name' => 'A', 'property_id' => $active->id]);
        Asset::factory()->for($household)->create(['name' => 'B', 'property_id' => $archived->id]);
        Asset::factory()->for($household)->create(['name' => 'C', 'property_id' => null]);

        $response = $this->actingAs($owner)->getJson('/api/assets')->assertOk();

        $rows = $response->json('data');

        expect(array_column($rows, 'name'))->toEqual(['A', 'B', 'C']);

        foreach ($rows as $row) {
            expect(array_keys($row))->toEqual(apContractKeys());
        }

        expect($rows[0]['property'])->toMatchArray([
            'id' => $active->id,
            'name' => 'Rumah Aktif',
            'archived_at' => null,
        ]);
        expect($rows[1]['property_id'])->toBe($archived->id);
        expect($rows[1]['property']['name'])->toBe('Rumah Arsip');
        expect($rows[1]['property']['archived_at'])->not->toBeNull();
        expect($rows[2]['property_id'])->toBeNull();
        expect($rows[2]['property'])->toBeNull();
    });

    test('deleting an asset keeps the linked property intact', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household);
        $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);

        $this->actingAs($owner)->deleteJson("/api/assets/{$asset->id}")->assertOk();

        expect(Asset::find($asset->id))->toBeNull();
        expect(Property::find($property->id))->not->toBeNull();
    });
});

describe('Assets API property authorization', function () {
    test('members may read nested property summaries but cannot mutate the relation', function () {
        $member = User::factory()->create();
        $household = apHousehold($member, 'Rumah Anggota', 'member');
        $property = apProperty($household, ['name' => 'Rumah Bersama']);
        $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);

        $this->actingAs($member)->getJson('/api/assets')
            ->assertOk()
            ->assertJsonPath('data.0.property.name', 'Rumah Bersama');

        $this->actingAs($member)->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.property.name', 'Rumah Bersama');

        $this->actingAs($member)->postJson('/api/assets', apPayload([
            'property_id' => $property->id,
        ]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/assets/{$asset->id}", [
            'property_id' => null,
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        expect($asset->refresh()->property_id)->toBe($property->id);
    });

    test('cross-household asset access does not expose the linked property', function () {
        $owner = User::factory()->create();
        apHousehold($owner);

        $stranger = User::factory()->create();
        $foreignHousehold = apHousehold($stranger, 'Rumah Tetangga');
        $foreignProperty = apProperty($foreignHousehold, ['name' => 'Properti Rahasia']);
        $foreignAsset = Asset::factory()->for($foreignHousehold)->create([
            'property_id' => $foreignProperty->id,
        ]);

        $response = $this->actingAs($owner)->getJson("/api/assets/{$foreignAsset->id}");

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        expect($response->getContent())->not->toContain('Properti Rahasia');
    });
});

describe('Assets API property query efficiency', function () {
    test('listing linked assets issues a constant number of queries regardless of asset count', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);
        $property = apProperty($household);

        for ($i = 0; $i < 3; $i++) {
            Asset::factory()->for($household)->create([
                'name' => "Aset Kecil {$i}",
                'property_id' => $property->id,
            ]);
        }

        $this->actingAs($owner)->getJson('/api/assets')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $small = $this->actingAs($owner)->getJson('/api/assets')->assertOk();
        $smallQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 0; $i < 12; $i++) {
            Asset::factory()->for($household)->create([
                'name' => "Aset Besar {$i}",
                'property_id' => $property->id,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $large = $this->actingAs($owner)->getJson('/api/assets')->assertOk();
        $largeQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        expect($small->json('data'))->toHaveCount(3);
        expect($large->json('data'))->toHaveCount(15);
        expect($largeQueries)->toBe($smallQueries);
    });

    test('listing unlinked assets avoids the property eager-load query', function () {
        $owner = User::factory()->create();
        $household = apHousehold($owner);

        for ($i = 0; $i < 3; $i++) {
            Asset::factory()->for($household)->create([
                'name' => "Aset Tanpa Properti {$i}",
                'property_id' => null,
            ]);
        }

        $this->actingAs($owner)->getJson('/api/assets')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->getJson('/api/assets')->assertOk();
        $unlinkedQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $property = apProperty($household);

        Asset::query()
            ->where('household_id', $household->id)
            ->update(['property_id' => $property->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $linked = $this->actingAs($owner)->getJson('/api/assets')->assertOk();
        $linkedQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        expect($linked->json('data'))->toHaveCount(3);
        expect(collect($linked->json('data'))->pluck('property_id')->unique()->all())->toBe([$property->id]);
        expect($linkedQueries)->toBe($unlinkedQueries + 1);
    });
});
