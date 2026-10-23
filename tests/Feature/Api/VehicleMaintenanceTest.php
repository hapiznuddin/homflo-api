<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Support\Str;

function serviceHousehold(User $owner, string $name = 'Rumah Servis Kendaraan', string $role = 'owner'): Household
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

function serviceVehicle(Household $household, array $overrides = []): Vehicle
{
    return Vehicle::factory()->for($household)->create($overrides);
}

/**
 * @return array<string, mixed>
 */
function servicePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Servis rutin 50.000 km',
        'description' => 'Ganti oli, filter, dan tune up',
        'scheduled_date' => '2026-12-01',
        'odometer' => 50000,
    ], $overrides);
}

/**
 * @return list<string>
 */
function serviceContractKeys(): array
{
    return [
        'id',
        'vehicle_id',
        'title',
        'description',
        'scheduled_date',
        'completed_at',
        'odometer',
        'status',
        'created_at',
        'updated_at',
    ];
}

describe('Vehicle maintenance API authentication', function () {
    test('guest cannot access protected service endpoints', function () {
        $vehicle = Vehicle::factory()->create();
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();

        $this->getJson("/api/vehicles/{$vehicle->id}/maintenance")->assertStatus(401);
        $this->postJson("/api/vehicles/{$vehicle->id}/maintenance", servicePayload())->assertStatus(401);
        $this->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")->assertStatus(401);
        $this->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", ['title' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")->assertStatus(401);
        $this->postJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}/complete")->assertStatus(401);

        expect(VehicleMaintenance::find($maintenance->id))->not->toBeNull();
    });

    test('user without household receives household not found', function () {
        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/vehicles/{$vehicle->id}/maintenance")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Vehicle maintenance API authorization and household isolation', function () {
    test('member lists a vehicle service records in scheduled order', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);

        VehicleMaintenance::factory()->for($vehicle)->create([
            'title' => 'Servis Desember',
            'scheduled_date' => '2026-12-05',
        ]);
        VehicleMaintenance::factory()->for($vehicle)->create([
            'title' => 'Servis November',
            'scheduled_date' => '2026-11-05',
        ]);

        $other = serviceVehicle($household);
        VehicleMaintenance::factory()->for($other)->create(['title' => 'Servis kendaraan lain']);

        $response = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance");

        $response->assertOk()->assertJsonPath('success', true);

        $titles = array_column($response->json('data'), 'title');

        expect($titles)->toEqual(['Servis November', 'Servis Desember']);
        expect($response->getContent())->not->toContain('Servis kendaraan lain');
    });

    test('member reads a scheduled and a completed service record', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);

        $scheduled = VehicleMaintenance::factory()->for($vehicle)->create([
            'title' => 'Ganti ban',
            'description' => 'Ban depan',
            'scheduled_date' => '2026-12-10',
            'odometer' => null,
        ]);
        $completed = VehicleMaintenance::factory()->for($vehicle)->completed()->create([
            'scheduled_date' => '2026-10-01',
            'odometer' => 9000,
        ]);

        $scheduledResponse = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$scheduled->id}");
        $scheduledResponse->assertOk()
            ->assertJsonPath('data.id', $scheduled->id)
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.title', 'Ganti ban')
            ->assertJsonPath('data.description', 'Ban depan')
            ->assertJsonPath('data.scheduled_date', '2026-12-10')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.odometer', null)
            ->assertJsonPath('data.status', 'scheduled');

        $completedResponse = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$completed->id}");
        $completedResponse->assertOk()
            ->assertJsonPath('data.completed_at', $completed->completed_at->format('Y-m-d'))
            ->assertJsonPath('data.odometer', 9000)
            ->assertJsonPath('data.status', 'completed');
    });

    test('cross-household vehicle is masked as not found', function () {
        $owner = User::factory()->create();
        serviceHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = serviceHousehold($stranger, 'Asing');
        $vehicle = serviceVehicle($foreign);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create(['title' => 'Rahasia tetangga']);

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", ['title' => 'bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}/complete")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        expect($maintenance->refresh()->title)->toBe('Rahasia tetangga');
    });

    test('a service record can only be reached through its own vehicle', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicleA = serviceVehicle($household);
        $vehicleB = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicleB)->create(['title' => 'Milik kendaraan B']);

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicleA->id}/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicleA->id}/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        expect($maintenance->refresh())->not->toBeNull();
    });

    test('member role reads but cannot mutate the shared service schedule', function () {
        $member = User::factory()->create();
        $household = serviceHousehold($member, 'Keluarga', 'member');
        $vehicle = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();

        $this->actingAs($member)->getJson("/api/vehicles/{$vehicle->id}/maintenance")->assertOk();
        $this->actingAs($member)->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")->assertOk();

        $this->actingAs($member)->postJson("/api/vehicles/{$vehicle->id}/maintenance", servicePayload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", ['title' => 'Oleh member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        $this->actingAs($member)->postJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}/complete")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_NOT_FOUND');

        expect($maintenance->refresh()->title)->not->toBe('Oleh member');
        expect($maintenance->refresh()->completed_at)->toBeNull();
    });

    test('unverified owner can read but cannot mutate service records', function () {
        $owner = User::factory()->unverified()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance")->assertOk();
        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")->assertOk();

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance", servicePayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", ['title' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}/complete")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('unknown identifiers report not found without leaking', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $ghostVehicle = (string) Str::ulid();
        $ghostMaintenance = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/vehicles/{$ghostVehicle}/maintenance")->assertStatus(404);
        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$ghostMaintenance}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$ghostMaintenance}", ['title' => 'x'])->assertStatus(404);
    });
});

describe('Vehicle maintenance API lifecycle and validation', function () {
    test('owner creates a scheduled service record for the routed vehicle', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);

        $response = $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance", servicePayload());

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['vehicle_id'])->toBe($vehicle->id);
        expect($data['title'])->toBe('Servis rutin 50.000 km');
        expect($data['description'])->toBe('Ganti oli, filter, dan tune up');
        expect($data['scheduled_date'])->toBe('2026-12-01');
        expect($data['completed_at'])->toBeNull();
        expect($data['odometer'])->toBe(50000);
        expect($data['status'])->toBe('scheduled');
        expect(array_keys($data))->toEqual(serviceContractKeys());

        expect(VehicleMaintenance::where('vehicle_id', $vehicle->id)->count())->toBe(1);
    });

    test('owner creates a service record with only the required fields', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);

        $response = $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance", [
            'title' => 'Cek rem',
            'scheduled_date' => '2026-12-20',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Cek rem')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.odometer', null)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.completed_at', null);
    });

    test('store validates required and malformed fields', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $endpoint = "/api/vehicles/{$vehicle->id}/maintenance";

        $this->actingAs($owner)->postJson($endpoint, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'scheduled_date']);

        $this->actingAs($owner)->postJson($endpoint, servicePayload(['title' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->postJson($endpoint, servicePayload(['title' => str_repeat('t', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->postJson($endpoint, servicePayload(['scheduled_date' => '01-12-2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['scheduled_date']);

        $this->actingAs($owner)->postJson($endpoint, servicePayload(['description' => str_repeat('d', 5001)]))
            ->assertStatus(422)->assertJsonValidationErrors(['description']);

        $this->actingAs($owner)->postJson($endpoint, servicePayload(['odometer' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        $this->actingAs($owner)->postJson($endpoint, servicePayload(['odometer' => 'jauh']))
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        $this->actingAs($owner)->postJson($endpoint, servicePayload(['odometer' => 2147483648]))
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        expect(VehicleMaintenance::count())->toBe(0);
    });

    test('payload cannot forge server-managed lifecycle or vehicle link fields', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);

        $stranger = User::factory()->create();
        $foreign = serviceHousehold($stranger, 'Asing');
        $foreignVehicle = serviceVehicle($foreign);

        $response = $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance", servicePayload([
            'completed_at' => '2020-01-01',
            'status' => 'completed',
            'vehicle_id' => $foreignVehicle->id,
            'household_id' => $foreign->id,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.status', 'scheduled');

        expect(VehicleMaintenance::where('vehicle_id', $foreignVehicle->id)->count())->toBe(0);
    });

    test('complete sets completed_at server-side and is one-way', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();

        $completed = $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}/complete");

        $completed->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', now()->format('Y-m-d'));

        expect($maintenance->refresh()->completed_at)->not->toBeNull();

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VEHICLE_MAINTENANCE_ALREADY_COMPLETED');
    });

    test('owner updates writable fields and lifecycle fields stay server-managed', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $otherVehicle = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create([
            'title' => 'Awal',
            'scheduled_date' => '2026-11-01',
        ]);

        $response = $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", [
            'title' => 'Diubah',
            'description' => 'Detail baru',
            'scheduled_date' => '2026-11-15',
            'odometer' => 12345,
            'completed_at' => '2020-01-01',
            'vehicle_id' => $otherVehicle->id,
        ]);

        $response->assertOk();

        $fresh = $maintenance->refresh();

        expect($fresh->title)->toBe('Diubah');
        expect($fresh->description)->toBe('Detail baru');
        expect($fresh->scheduled_date?->format('Y-m-d'))->toBe('2026-11-15');
        expect($fresh->odometer)->toBe(12345);
        expect($fresh->completed_at)->toBeNull();
        expect($fresh->vehicle_id)->toBe($vehicle->id);
    });

    test('update rejects malformed fields without changing the record', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create(['title' => 'Awal']);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", ['title' => 123])
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", ['scheduled_date' => '15/11/2026'])
            ->assertStatus(422)->assertJsonValidationErrors(['scheduled_date']);

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}", ['odometer' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['odometer']);

        expect($maintenance->refresh()->title)->toBe('Awal');
    });

    test('owner deletes a service record while the vehicle stays intact', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();
        $other = VehicleMaintenance::factory()->for($vehicle)->create();

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $maintenance->id);

        expect(VehicleMaintenance::find($maintenance->id))->toBeNull();
        expect(VehicleMaintenance::find($other->id))->not->toBeNull();
        expect(Vehicle::find($vehicle->id))->not->toBeNull();
    });

    test('recording a service does not silently rewrite the vehicle odometer', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household, ['odometer' => 1000]);

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/maintenance", servicePayload(['odometer' => 9000]))
            ->assertStatus(201);

        expect($vehicle->refresh()->odometer)->toBe(1000);
    });

    test('responses expose only the service contract fields', function () {
        $owner = User::factory()->create();
        $household = serviceHousehold($owner);
        $vehicle = serviceVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();

        $detail = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/maintenance/{$maintenance->id}");
        $detail->assertOk();

        expect(array_keys($detail->json('data')))->toEqual(serviceContractKeys());
        expect($detail->json('data'))->not->toHaveKey('household_id');
        expect($detail->json('data'))->not->toHaveKey('vehicle');
    });
});
