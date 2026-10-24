<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Support\Str;

function vehicleHousehold(User $owner, string $name = 'Rumah Kendaraan', string $role = 'owner'): Household
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
function vehiclePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Avanza Keluarga',
        'brand' => 'Toyota',
        'model' => 'Avanza G',
        'year' => 2021,
        'type' => 'Mobil',
        'plate_number' => 'B 1234 XYZ',
        'odometer' => 45000,
    ], $overrides);
}

/**
 * @return list<string>
 */
function vehicleContractKeys(): array
{
    return [
        'id',
        'household_id',
        'name',
        'brand',
        'model',
        'year',
        'type',
        'plate_number',
        'odometer',
        'created_at',
        'updated_at',
    ];
}

describe('Vehicles API authentication', function () {
    test('guest cannot access protected vehicle endpoints', function () {
        $vehicle = Vehicle::factory()->create();

        $this->getJson('/api/vehicles')->assertStatus(401);
        $this->postJson('/api/vehicles', vehiclePayload())->assertStatus(401);
        $this->getJson("/api/vehicles/{$vehicle->id}")->assertStatus(401);
        $this->patchJson("/api/vehicles/{$vehicle->id}", ['name' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/vehicles/{$vehicle->id}")->assertStatus(401);

        expect(Vehicle::find($vehicle->id))->not->toBeNull();
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/vehicles')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/vehicles', vehiclePayload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Vehicles API authorization and household isolation', function () {
    test('member lists only their own household vehicles in name order', function () {
        $owner = User::factory()->create();
        $mine = vehicleHousehold($owner);
        Vehicle::factory()->for($mine)->create(['name' => 'Zebra']);
        Vehicle::factory()->for($mine)->create(['name' => 'Apel']);

        $stranger = User::factory()->create();
        $theirs = vehicleHousehold($stranger, 'Tetangga');
        Vehicle::factory()->for($theirs)->create(['name' => 'Rahasia tetangga']);

        $response = $this->actingAs($owner)->getJson('/api/vehicles');

        $response->assertOk()->assertJsonPath('success', true);

        $names = array_column($response->json('data'), 'name');

        expect($names)->toEqual(['Apel', 'Zebra']);
        expect($response->getContent())->not->toContain('Rahasia tetangga');
    });

    test('member reads a vehicle from their own household', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create([
            'name' => 'Motor Harian',
            'brand' => 'Honda',
            'model' => 'Vario 125',
            'year' => 2022,
            'type' => 'Motor',
            'plate_number' => 'D 5678 ABC',
            'odometer' => 12345,
        ]);

        $response = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $vehicle->id)
            ->assertJsonPath('data.household_id', $household->id)
            ->assertJsonPath('data.name', 'Motor Harian')
            ->assertJsonPath('data.brand', 'Honda')
            ->assertJsonPath('data.model', 'Vario 125')
            ->assertJsonPath('data.year', 2022)
            ->assertJsonPath('data.type', 'Motor')
            ->assertJsonPath('data.plate_number', 'D 5678 ABC')
            ->assertJsonPath('data.odometer', 12345);
    });

    test('cross-household access is masked as not found and never modifies the record', function () {
        $owner = User::factory()->create();
        vehicleHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = vehicleHousehold($stranger, 'Asing');
        $vehicle = Vehicle::factory()->for($foreign)->create(['name' => 'Milik tetangga']);

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['name' => 'bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $fresh = Vehicle::find($vehicle->id);

        expect($fresh)->not->toBeNull();
        expect($fresh->name)->toBe('Milik tetangga');
    });

    test('member role reads but cannot mutate the shared vehicle catalogue', function () {
        $member = User::factory()->create();
        $household = vehicleHousehold($member, 'Keluarga', 'member');
        $vehicle = Vehicle::factory()->for($household)->create();

        $this->actingAs($member)->getJson('/api/vehicles')->assertOk();
        $this->actingAs($member)->getJson("/api/vehicles/{$vehicle->id}")->assertOk();

        $this->actingAs($member)->postJson('/api/vehicles', vehiclePayload(['name' => 'Oleh member']))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/vehicles/{$vehicle->id}", ['name' => 'Oleh member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        expect(Vehicle::find($vehicle->id))->not->toBeNull();
        expect(Vehicle::where('name', 'Oleh member')->exists())->toBeFalse();
    });

    test('unverified owner can read but cannot mutate vehicles', function () {
        $owner = User::factory()->unverified()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create();

        $this->actingAs($owner)->getJson('/api/vehicles')->assertOk();
        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}")->assertOk();

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['name' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('unknown ULIDs report not found without leaking', function () {
        $owner = User::factory()->create();
        vehicleHousehold($owner);
        $ghost = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/vehicles/{$ghost}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/vehicles/{$ghost}", ['name' => 'x'])->assertStatus(404);
        $this->actingAs($owner)->deleteJson("/api/vehicles/{$ghost}")->assertStatus(404);
    });
});

describe('Vehicles API validation and persistence', function () {
    test('owner creates a vehicle in the server-resolved household', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload());

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['household_id'])->toBe($household->id);
        expect($data['name'])->toBe('Avanza Keluarga');
        expect($data['brand'])->toBe('Toyota');
        expect($data['model'])->toBe('Avanza G');
        expect($data['year'])->toBe(2021);
        expect($data['type'])->toBe('Mobil');
        expect($data['plate_number'])->toBe('B 1234 XYZ');
        expect($data['odometer'])->toBe(45000);
        expect(array_keys($data))->toEqual(vehicleContractKeys());

        expect(Vehicle::find($data['id']))->not->toBeNull();
        expect(Vehicle::where('household_id', $household->id)->count())->toBe(1);
    });

    test('owner creates a vehicle with only the required fields', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/vehicles', ['name' => 'Tanpa Detail']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Tanpa Detail')
            ->assertJsonPath('data.brand', null)
            ->assertJsonPath('data.model', null)
            ->assertJsonPath('data.year', null)
            ->assertJsonPath('data.type', null)
            ->assertJsonPath('data.plate_number', null)
            ->assertJsonPath('data.odometer', null);

        expect(Vehicle::where('household_id', $household->id)->count())->toBe(1);
    });

    test('store validates required and malformed fields', function () {
        $owner = User::factory()->create();
        vehicleHousehold($owner);

        $this->actingAs($owner)->postJson('/api/vehicles', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['name' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['name' => str_repeat('n', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['brand' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['brand']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['model' => str_repeat('m', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['model']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['year' => 'dua ribu']))
            ->assertStatus(422)->assertJsonValidationErrors(['year']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['year' => 1800]))
            ->assertStatus(422)->assertJsonValidationErrors(['year']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['year' => 2200]))
            ->assertStatus(422)->assertJsonValidationErrors(['year']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['type' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['plate_number' => str_repeat('p', 33)]))
            ->assertStatus(422)->assertJsonValidationErrors(['plate_number']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['odometer' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['odometer' => 'banyak']))
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['odometer' => 2147483648]))
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        expect(Vehicle::count())->toBe(0);
    });

    test('duplicate plate within a household is rejected but allowed across households', function () {
        $owner = User::factory()->create();
        $mine = vehicleHousehold($owner);
        Vehicle::factory()->for($mine)->create(['plate_number' => 'B 9999 AAA']);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload(['plate_number' => 'B 9999 AAA']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VEHICLE_PLATE_ALREADY_EXISTS');

        expect(Vehicle::where('household_id', $mine->id)->count())->toBe(1);

        $stranger = User::factory()->create();
        vehicleHousehold($stranger, 'Tetangga');

        $this->actingAs($stranger)->postJson('/api/vehicles', vehiclePayload(['plate_number' => 'B 9999 AAA']))
            ->assertStatus(201)
            ->assertJsonPath('data.plate_number', 'B 9999 AAA');
    });

    test('duplicate plate on update is rejected but the current plate is excluded', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $first = Vehicle::factory()->for($household)->create(['plate_number' => 'B 1111 AAA']);
        $second = Vehicle::factory()->for($household)->create(['plate_number' => 'B 2222 BBB']);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$second->id}", [
            'name' => 'Tidak Boleh',
            'plate_number' => 'B 1111 AAA',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VEHICLE_PLATE_ALREADY_EXISTS');

        expect($second->refresh()->name)->not->toBe('Tidak Boleh');
        expect($second->plate_number)->toBe('B 2222 BBB');

        $this->actingAs($owner)->patchJson("/api/vehicles/{$second->id}", [
            'name' => 'Tetap Boleh',
            'plate_number' => 'B 2222 BBB',
        ])->assertOk()->assertJsonPath('data.plate_number', 'B 2222 BBB');

        expect($first->refresh()->plate_number)->toBe('B 1111 AAA');
        expect($second->refresh()->name)->toBe('Tetap Boleh');
    });

    test('nullable plates remain independent within the same household', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload([
            'name' => 'Tanpa Plat A',
            'plate_number' => null,
        ]))->assertStatus(201)->assertJsonPath('data.plate_number', null);

        $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload([
            'name' => 'Tanpa Plat B',
            'plate_number' => null,
        ]))->assertStatus(201)->assertJsonPath('data.plate_number', null);

        expect(Vehicle::where('household_id', $household->id)->whereNull('plate_number')->count())->toBe(2);
    });

    test('household ownership cannot be forged through the payload', function () {
        $owner = User::factory()->create();
        $mine = vehicleHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = vehicleHousehold($stranger, 'Asing');

        $response = $this->actingAs($owner)->postJson('/api/vehicles', vehiclePayload([
            'household_id' => $foreign->id,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.household_id', $mine->id);

        expect(Vehicle::where('household_id', $foreign->id)->count())->toBe(0);

        $vehicle = Vehicle::factory()->for($mine)->create();

        $update = $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", [
            'name' => 'diubah',
            'household_id' => $foreign->id,
        ]);

        $update->assertOk();

        expect($vehicle->refresh()->household_id)->toBe($mine->id);
        expect(Vehicle::where('household_id', $foreign->id)->count())->toBe(0);
    });

    test('owner updates vehicle fields and can clear nullable columns', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create([
            'name' => 'Mobil Lama',
            'brand' => 'Daihatsu',
            'model' => 'Xenia',
            'year' => 2015,
            'type' => 'Mobil',
            'plate_number' => 'F 1111 QQ',
            'odometer' => 80000,
        ]);

        $response = $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", [
            'name' => 'Mobil Keluarga',
            'brand' => null,
            'model' => 'Xenia R',
            'year' => 2016,
            'type' => 'MPV',
            'plate_number' => 'F 2222 QQ',
            'odometer' => 85000,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $fresh = $vehicle->refresh();

        expect($fresh->name)->toBe('Mobil Keluarga');
        expect($fresh->brand)->toBeNull();
        expect($fresh->model)->toBe('Xenia R');
        expect($fresh->year)->toBe(2016);
        expect($fresh->type)->toBe('MPV');
        expect($fresh->plate_number)->toBe('F 2222 QQ');
        expect($fresh->odometer)->toBe(85000);
        expect($fresh->household_id)->toBe($household->id);

        // Omitted keys stay untouched on a partial update.
        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['type' => 'SUV'])
            ->assertOk();

        $fresh->refresh();

        expect($fresh->type)->toBe('SUV');
        expect($fresh->name)->toBe('Mobil Keluarga');
        expect($fresh->odometer)->toBe(85000);
    });

    test('update rejects malformed fields without changing the record', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create(['name' => 'Awal']);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['name' => 123])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['year' => 3000])
            ->assertStatus(422)->assertJsonValidationErrors(['year']);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['odometer' => -5])
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        expect($vehicle->refresh()->name)->toBe('Awal');
    });

    test('responses expose only the vehicle contract fields', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create();

        $detail = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}");
        $detail->assertOk();

        expect(array_keys($detail->json('data')))->toEqual(vehicleContractKeys());
        expect($detail->json('data'))->not->toHaveKey('maintenances');
        expect($detail->json('data'))->not->toHaveKey('household');
    });
});

describe('Vehicles API odometer rules', function () {
    test('vehicle odometer cannot drop below a recorded service odometer', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create(['odometer' => 1000]);
        VehicleMaintenance::factory()->for($vehicle)->create(['odometer' => 5000]);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['odometer' => 3000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VEHICLE_ODOMETER_BELOW_RECORDED');

        expect($vehicle->refresh()->odometer)->toBe(1000);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['odometer' => 6000])
            ->assertOk()
            ->assertJsonPath('data.odometer', 6000);

        expect($vehicle->refresh()->odometer)->toBe(6000);
    });

    test('vehicle odometer may be raised to exactly the recorded value', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create(['odometer' => 1000]);
        VehicleMaintenance::factory()->for($vehicle)->create(['odometer' => 5000]);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}", ['odometer' => 5000])
            ->assertOk()
            ->assertJsonPath('data.odometer', 5000);
    });
});

describe('Vehicles API delete lifecycle', function () {
    test('deleting a vehicle without service history removes only the vehicle row', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create();
        $other = Vehicle::factory()->for($household)->create();

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $vehicle->id);

        expect(Vehicle::find($vehicle->id))->toBeNull();
        expect(Vehicle::find($other->id))->not->toBeNull();
    });

    test('deleting a vehicle with service history is rejected and the history stays intact', function () {
        $owner = User::factory()->create();
        $household = vehicleHousehold($owner);
        $vehicle = Vehicle::factory()->for($household)->create();
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VEHICLE_HAS_MAINTENANCE_HISTORY');

        expect(Vehicle::find($vehicle->id))->not->toBeNull();
        expect(VehicleMaintenance::find($maintenance->id))->not->toBeNull();
    });
});
