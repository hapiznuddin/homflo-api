<?php

use App\Models\Asset;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Maintenance;
use App\Models\User;
use Illuminate\Support\Str;

function maintenanceHousehold(User $owner, string $name = 'Rumah Maintenance', string $role = 'owner'): Household
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
function maintenancePayload(string $assetId, array $overrides = []): array
{
    return array_merge([
        'asset_id' => $assetId,
        'title' => 'Cuci dan Service AC',
        'description' => 'Bersihkan filter dan cek freon',
        'location' => 'Ruang Tamu',
        'scheduled_date' => '2026-11-10',
        'priority' => 'rutin',
    ], $overrides);
}

/**
 * @return list<string>
 */
function maintenanceContractKeys(): array
{
    return [
        'id',
        'household_id',
        'asset_id',
        'title',
        'description',
        'location',
        'scheduled_date',
        'completed_at',
        'priority',
        'status',
        'created_at',
        'updated_at',
    ];
}

describe('Maintenance API authentication', function () {
    test('guest cannot access protected maintenance endpoints', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);

        $this->getJson('/api/maintenance')->assertStatus(401);
        $this->postJson('/api/maintenance', maintenancePayload($asset->id))->assertStatus(401);
        $this->getJson("/api/maintenance/{$maintenance->id}")->assertStatus(401);
        $this->patchJson("/api/maintenance/{$maintenance->id}", ['title' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/maintenance/{$maintenance->id}")->assertStatus(401);
        $this->postJson("/api/maintenance/{$maintenance->id}/complete")->assertStatus(401);

        expect(Maintenance::find($maintenance->id))->not->toBeNull();
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/maintenance')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/maintenance', maintenancePayload((string) Str::ulid()))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Maintenance API authorization and household isolation', function () {
    test('member lists only their own household maintenance ordered by scheduled date', function () {
        $owner = User::factory()->create();
        $mine = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($mine)->create();

        Maintenance::factory()->for($mine)->create([
            'asset_id' => $asset->id,
            'title' => 'Servis berikutnya',
            'scheduled_date' => '2026-12-20',
        ]);
        Maintenance::factory()->for($mine)->create([
            'asset_id' => $asset->id,
            'title' => 'Servis terdekat',
            'scheduled_date' => '2026-11-05',
        ]);

        $stranger = User::factory()->create();
        $theirs = maintenanceHousehold($stranger, 'Tetangga');
        $theirAsset = Asset::factory()->for($theirs)->create();
        Maintenance::factory()->for($theirs)->create([
            'asset_id' => $theirAsset->id,
            'title' => 'Rahasia tetangga',
        ]);

        $response = $this->actingAs($owner)->getJson('/api/maintenance');

        $response->assertOk()->assertJsonPath('success', true);

        $titles = array_column($response->json('data'), 'title');

        expect($titles)->toEqual(['Servis terdekat', 'Servis berikutnya']);
        expect($response->getContent())->not->toContain('Rahasia tetangga');
    });

    test('member reads a maintenance record from their own household', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create([
            'asset_id' => $asset->id,
            'title' => 'Ganti Filter Air',
            'description' => 'Filter dispenser',
            'location' => 'Dapur',
            'scheduled_date' => '2026-11-15',
            'priority' => 'sedang',
        ]);

        $response = $this->actingAs($owner)->getJson("/api/maintenance/{$maintenance->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $maintenance->id)
            ->assertJsonPath('data.household_id', $household->id)
            ->assertJsonPath('data.asset_id', $asset->id)
            ->assertJsonPath('data.title', 'Ganti Filter Air')
            ->assertJsonPath('data.description', 'Filter dispenser')
            ->assertJsonPath('data.location', 'Dapur')
            ->assertJsonPath('data.scheduled_date', '2026-11-15')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.priority', 'sedang')
            ->assertJsonPath('data.status', 'scheduled');
    });

    test('cross-household access is masked as not found and never modifies the record', function () {
        $owner = User::factory()->create();
        maintenanceHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = maintenanceHousehold($stranger, 'Asing');
        $foreignAsset = Asset::factory()->for($foreign)->create();
        $maintenance = Maintenance::factory()->for($foreign)->create([
            'asset_id' => $foreignAsset->id,
            'title' => 'Milik tetangga',
        ]);

        $this->actingAs($owner)->getJson("/api/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MAINTENANCE_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['title' => 'bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MAINTENANCE_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MAINTENANCE_NOT_FOUND');

        $this->actingAs($owner)->postJson("/api/maintenance/{$maintenance->id}/complete")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MAINTENANCE_NOT_FOUND');

        $fresh = Maintenance::find($maintenance->id);

        expect($fresh)->not->toBeNull();
        expect($fresh->title)->toBe('Milik tetangga');
        expect($fresh->completed_at)->toBeNull();
    });

    test('member role reads but cannot mutate the shared maintenance schedule', function () {
        $member = User::factory()->create();
        $household = maintenanceHousehold($member, 'Keluarga', 'member');
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);

        $this->actingAs($member)->getJson('/api/maintenance')->assertOk();
        $this->actingAs($member)->getJson("/api/maintenance/{$maintenance->id}")->assertOk();

        $this->actingAs($member)->postJson('/api/maintenance', maintenancePayload($asset->id, ['title' => 'Oleh member']))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/maintenance/{$maintenance->id}", ['title' => 'Oleh member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MAINTENANCE_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MAINTENANCE_NOT_FOUND');

        $this->actingAs($member)->postJson("/api/maintenance/{$maintenance->id}/complete")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'MAINTENANCE_NOT_FOUND');

        expect(Maintenance::find($maintenance->id))->not->toBeNull();
        expect(Maintenance::where('title', 'Oleh member')->exists())->toBeFalse();
    });

    test('unverified owner can read but cannot mutate maintenance', function () {
        $owner = User::factory()->unverified()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);

        $this->actingAs($owner)->getJson('/api/maintenance')->assertOk();
        $this->actingAs($owner)->getJson("/api/maintenance/{$maintenance->id}")->assertOk();

        $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['title' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/maintenance/{$maintenance->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->postJson("/api/maintenance/{$maintenance->id}/complete")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('unknown ULIDs report not found without leaking', function () {
        $owner = User::factory()->create();
        maintenanceHousehold($owner);
        $ghost = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/maintenance/{$ghost}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/maintenance/{$ghost}", ['title' => 'x'])->assertStatus(404);
        $this->actingAs($owner)->deleteJson("/api/maintenance/{$ghost}")->assertStatus(404);
        $this->actingAs($owner)->postJson("/api/maintenance/{$ghost}/complete")->assertStatus(404);
    });
});

describe('Maintenance API validation and persistence', function () {
    test('owner creates maintenance in the server-resolved household', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $response = $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id));

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['household_id'])->toBe($household->id);
        expect($data['asset_id'])->toBe($asset->id);
        expect($data['title'])->toBe('Cuci dan Service AC');
        expect($data['description'])->toBe('Bersihkan filter dan cek freon');
        expect($data['location'])->toBe('Ruang Tamu');
        expect($data['scheduled_date'])->toBe('2026-11-10');
        expect($data['completed_at'])->toBeNull();
        expect($data['priority'])->toBe('rutin');
        expect($data['status'])->toBe('scheduled');
        expect(array_keys($data))->toEqual(maintenanceContractKeys());

        expect(Maintenance::find($data['id']))->not->toBeNull();
        expect(Maintenance::where('household_id', $household->id)->count())->toBe(1);
    });

    test('description may be omitted and defaults to null', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $payload = maintenancePayload($asset->id);
        unset($payload['description']);

        $this->actingAs($owner)->postJson('/api/maintenance', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.description', null);

        expect(Maintenance::where('household_id', $household->id)->first()?->description)->toBeNull();
    });

    test('store requires an asset and rejects a missing one', function () {
        $owner = User::factory()->create();
        maintenanceHousehold($owner);

        $payload = maintenancePayload((string) Str::ulid());
        unset($payload['asset_id']);

        $this->actingAs($owner)->postJson('/api/maintenance', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);

        expect(Maintenance::count())->toBe(0);
    });

    test('create with an asset from another household is masked as not found', function () {
        $owner = User::factory()->create();
        maintenanceHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = maintenanceHousehold($stranger, 'Asing');
        $foreignAsset = Asset::factory()->for($foreign)->create();

        $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($foreignAsset->id))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        expect(Maintenance::count())->toBe(0);
    });

    test('create with an unknown asset is masked as not found', function () {
        $owner = User::factory()->create();
        maintenanceHousehold($owner);

        $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload((string) Str::ulid()))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        expect(Maintenance::count())->toBe(0);
    });

    test('store validates required and malformed fields', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id, ['title' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id, ['title' => str_repeat('t', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $payload = maintenancePayload($asset->id, ['location' => '']);
        $this->actingAs($owner)->postJson('/api/maintenance', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['location']);

        $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id, ['location' => str_repeat('l', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['location']);

        $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id, ['scheduled_date' => '10-11-2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['scheduled_date']);

        $payload = maintenancePayload($asset->id, ['priority' => 'urgent']);
        $this->actingAs($owner)->postJson('/api/maintenance', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['priority']);

        $payload = maintenancePayload($asset->id, ['description' => 123]);
        $this->actingAs($owner)->postJson('/api/maintenance', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['description']);

        expect(Maintenance::count())->toBe(0);
    });

    test('household ownership and server-managed fields cannot be forged through the payload', function () {
        $owner = User::factory()->create();
        $mine = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($mine)->create();

        $stranger = User::factory()->create();
        $foreign = maintenanceHousehold($stranger, 'Asing');

        $response = $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id, [
            'household_id' => $foreign->id,
            'completed_at' => '2026-01-01',
            'status' => 'completed',
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.household_id', $mine->id)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.status', 'scheduled');

        expect(Maintenance::where('household_id', $foreign->id)->count())->toBe(0);
    });

    test('owner updates fields and can clear the description', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create([
            'asset_id' => $asset->id,
            'title' => 'Awal',
            'description' => 'Catatan awal',
            'location' => 'Ruang Tamu',
            'scheduled_date' => '2026-11-10',
            'priority' => 'rutin',
        ]);

        $response = $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", [
            'title' => 'Diperbarui',
            'description' => null,
            'location' => 'Bengkel Servis ABC',
            'scheduled_date' => '2026-11-25',
            'priority' => 'tinggi',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $fresh = $maintenance->refresh();

        expect($fresh->title)->toBe('Diperbarui');
        expect($fresh->description)->toBeNull();
        expect($fresh->location)->toBe('Bengkel Servis ABC');
        expect($fresh->scheduled_date?->format('Y-m-d'))->toBe('2026-11-25');
        expect($fresh->priority)->toBe('tinggi');
        expect($fresh->household_id)->toBe($household->id);

        // Omitted keys stay untouched on a partial update (reschedule only).
        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['scheduled_date' => '2026-12-01'])
            ->assertOk();

        $fresh->refresh();

        expect($fresh->scheduled_date?->format('Y-m-d'))->toBe('2026-12-01');
        expect($fresh->title)->toBe('Diperbarui');
        expect($fresh->location)->toBe('Bengkel Servis ABC');
    });

    test('update rejects malformed fields without changing the record', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create([
            'asset_id' => $asset->id,
            'title' => 'Awal',
            'location' => 'Awal',
            'scheduled_date' => '2026-11-10',
            'priority' => 'rutin',
        ]);

        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['title' => 123])
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['scheduled_date' => '01/12/2026'])
            ->assertStatus(422)->assertJsonValidationErrors(['scheduled_date']);

        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['priority' => 'rendah'])
            ->assertStatus(422)->assertJsonValidationErrors(['priority']);

        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['location' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['location']);

        expect($maintenance->refresh()->title)->toBe('Awal');
        expect($maintenance->refresh()->priority)->toBe('rutin');
    });

    test('update with a foreign asset is masked as not found and never reassigns', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $ownAsset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $ownAsset->id]);

        $stranger = User::factory()->create();
        $foreign = maintenanceHousehold($stranger, 'Asing');
        $foreignAsset = Asset::factory()->for($foreign)->create();

        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['asset_id' => $foreignAsset->id])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');

        expect($maintenance->refresh()->asset_id)->toBe($ownAsset->id);
    });

    test('responses expose only the maintenance contract fields', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);

        $detail = $this->actingAs($owner)->getJson("/api/maintenance/{$maintenance->id}");
        $detail->assertOk();

        expect(array_keys($detail->json('data')))->toEqual(maintenanceContractKeys());
        expect($detail->json('data'))->not->toHaveKey('household');
        expect($detail->json('data'))->not->toHaveKey('asset');
    });
});

describe('Maintenance API lifecycle', function () {
    test('a freshly created maintenance is scheduled with a null completed_at', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $response = $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id));

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.completed_at', null);
    });

    test('complete sets completed_at from the server', function () {
        $this->travelTo('2026-12-01');

        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);

        $response = $this->actingAs($owner)->postJson("/api/maintenance/{$maintenance->id}/complete");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', '2026-12-01');

        $fresh = $maintenance->refresh();

        expect($fresh->completed_at?->format('Y-m-d'))->toBe('2026-12-01');
    });

    test('complete is one-way: repeating it does not damage the lifecycle', function () {
        $this->travelTo('2026-12-01');

        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);

        $this->actingAs($owner)->postJson("/api/maintenance/{$maintenance->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.completed_at', '2026-12-01');

        $this->actingAs($owner)->postJson("/api/maintenance/{$maintenance->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MAINTENANCE_ALREADY_COMPLETED');

        $fresh = $maintenance->refresh();

        expect($fresh->completed_at?->format('Y-m-d'))->toBe('2026-12-01');

        // A completed record still cannot be silently reopened.
        $this->actingAs($owner)->patchJson("/api/maintenance/{$maintenance->id}", ['completed_at' => null])
            ->assertOk();

        expect($maintenance->refresh()->completed_at?->format('Y-m-d'))->toBe('2026-12-01');
    });

    test('reschedule only changes the date and never touches other records', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $target = Maintenance::factory()->for($household)->create([
            'asset_id' => $asset->id,
            'scheduled_date' => '2026-11-10',
        ]);
        $other = Maintenance::factory()->for($household)->create([
            'asset_id' => $asset->id,
            'scheduled_date' => '2026-11-11',
        ]);

        $this->actingAs($owner)->patchJson("/api/maintenance/{$target->id}", ['scheduled_date' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('data.scheduled_date', '2026-12-31')
            ->assertJsonPath('data.status', 'scheduled');

        expect($target->refresh()->scheduled_date?->format('Y-m-d'))->toBe('2026-12-31');
        expect($other->refresh()->scheduled_date?->format('Y-m-d'))->toBe('2026-11-11');
    });

    test('a client cannot forge the completed state through create or update', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();

        $created = $this->actingAs($owner)->postJson('/api/maintenance', maintenancePayload($asset->id, [
            'completed_at' => '2026-05-05',
            'status' => 'completed',
        ]));

        $created->assertStatus(201)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.status', 'scheduled');

        $id = $created->json('data.id');

        $this->actingAs($owner)->patchJson("/api/maintenance/{$id}", [
            'completed_at' => '2026-06-06',
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.completed_at', null)->assertJsonPath('data.status', 'scheduled');
    });
});

describe('Maintenance API delete', function () {
    test('deleting a maintenance removes only that row', function () {
        $owner = User::factory()->create();
        $household = maintenanceHousehold($owner);
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);
        $other = Maintenance::factory()->for($household)->create(['asset_id' => $asset->id]);

        $this->actingAs($owner)->deleteJson("/api/maintenance/{$maintenance->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $maintenance->id);

        expect(Maintenance::find($maintenance->id))->toBeNull();
        expect(Maintenance::find($other->id))->not->toBeNull();
        expect(Asset::find($asset->id))->not->toBeNull();
    });
});
