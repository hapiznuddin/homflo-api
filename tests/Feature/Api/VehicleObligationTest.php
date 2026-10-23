<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ObligationType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehicleObligation;
use Illuminate\Support\Str;

function obligationHousehold(User $owner, string $name = 'Rumah Kewajiban Kendaraan', string $role = 'owner'): Household
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

function obligationVehicle(Household $household, array $overrides = []): Vehicle
{
    return Vehicle::factory()->for($household)->create($overrides);
}

function obligationSystemType(string $code = ObligationType::CODE_TAX): ObligationType
{
    return ObligationType::query()->where('code', $code)->firstOrFail();
}

function obligationCustomType(Household $household, string $name = 'Pajak Daerah'): ObligationType
{
    return ObligationType::factory()->for($household)->create(['name' => $name]);
}

/**
 * @return array<string, mixed>
 */
function obligationPayload(string $typeId, array $overrides = []): array
{
    return array_merge([
        'obligation_type_id' => $typeId,
        'title' => 'Pajak tahunan kendaraan',
        'description' => 'Pembayaran pajak tahunan',
        'issued_date' => '2026-10-01',
        'due_date' => '2026-11-30',
        'amount' => '250000.00',
    ], $overrides);
}

/**
 * @return list<string>
 */
function obligationContractKeys(): array
{
    return [
        'id',
        'vehicle_id',
        'obligation_type',
        'title',
        'description',
        'issued_date',
        'due_date',
        'amount',
        'status',
        'created_at',
        'updated_at',
    ];
}

/**
 * @return list<string>
 */
function obligationTypeContractKeys(): array
{
    return [
        'id',
        'household_id',
        'code',
        'name',
        'is_system',
        'created_at',
        'updated_at',
    ];
}

describe('Vehicle obligation API authentication', function () {
    test('guest cannot access protected obligation endpoints', function () {
        $vehicle = Vehicle::factory()->create();
        $obligation = VehicleObligation::factory()->for($vehicle)->create();
        $type = obligationSystemType();

        $this->getJson('/api/obligation-types')->assertStatus(401);
        $this->getJson("/api/vehicles/{$vehicle->id}/obligations")->assertStatus(401);
        $this->postJson("/api/vehicles/{$vehicle->id}/obligations", obligationPayload($type->id))->assertStatus(401);
        $this->getJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")->assertStatus(401);
        $this->patchJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}", ['title' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")->assertStatus(401);
        $this->postJson('/api/obligation-types', ['name' => 'Pajak Daerah'])->assertStatus(401);
        $this->patchJson("/api/obligation-types/{$type->id}", ['name' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/obligation-types/{$type->id}")->assertStatus(401);

        expect(VehicleObligation::find($obligation->id))->not->toBeNull();
    });

    test('user without a household receives household not found', function () {
        $user = User::factory()->create();
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($user)->getJson('/api/obligation-types')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->getJson("/api/vehicles/{$vehicle->id}/obligations")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Vehicle obligation API authorization and household isolation', function () {
    test('member reads obligations and types but cannot mutate either', function () {
        $member = User::factory()->create();
        $household = obligationHousehold($member, 'Keluarga', 'member');
        $vehicle = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicle)->create();
        $customType = obligationCustomType($household);

        $this->actingAs($member)->getJson('/api/obligation-types')->assertOk();
        $this->actingAs($member)->getJson("/api/vehicles/{$vehicle->id}/obligations")
            ->assertOk()
            ->assertJsonPath('data.0.id', $obligation->id);
        $this->actingAs($member)->getJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")->assertOk();

        $this->actingAs($member)->postJson("/api/vehicles/{$vehicle->id}/obligations", obligationPayload(obligationSystemType()->id))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}", ['title' => 'Oleh member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_OBLIGATION_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_OBLIGATION_NOT_FOUND');

        $this->actingAs($member)->postJson('/api/obligation-types', ['name' => 'Tipe Member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/obligation-types/{$customType->id}", ['name' => 'Diubah member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/obligation-types/{$customType->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_NOT_FOUND');

        expect($obligation->refresh()->title)->not->toBe('Oleh member');
        expect($customType->refresh()->name)->toBe('Pajak Daerah');
    });

    test('unverified owner can read but cannot mutate obligations or types', function () {
        $owner = User::factory()->unverified()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicle)->create();
        $customType = obligationCustomType($household);

        $this->actingAs($owner)->getJson('/api/obligation-types')->assertOk();
        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/obligations")->assertOk();
        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")->assertOk();

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/obligations", obligationPayload($customType->id))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}", ['title' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        $this->actingAs($owner)->postJson('/api/obligation-types', ['name' => 'Tipe Owner'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        $this->actingAs($owner)->patchJson("/api/obligation-types/{$customType->id}", ['name' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        $this->actingAs($owner)->deleteJson("/api/obligation-types/{$customType->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('cross-household vehicle access is masked as not found', function () {
        $owner = User::factory()->create();
        obligationHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = obligationHousehold($stranger, 'Asing');
        $vehicle = obligationVehicle($foreign);
        $obligation = VehicleObligation::factory()->for($vehicle)->create(['title' => 'Rahasia tetangga']);

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/obligations")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_OBLIGATION_NOT_FOUND');

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/obligations", obligationPayload(obligationSystemType()->id))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}", ['title' => 'bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_OBLIGATION_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_OBLIGATION_NOT_FOUND');

        expect($obligation->refresh()->title)->toBe('Rahasia tetangga');
    });

    test('an obligation can only be reached through its own vehicle', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicleA = obligationVehicle($household);
        $vehicleB = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicleB)->create(['title' => 'Milik kendaraan B']);

        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicleA->id}/obligations/{$obligation->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_OBLIGATION_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicleA->id}/obligations/{$obligation->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICLE_OBLIGATION_NOT_FOUND');

        expect($obligation->refresh())->not->toBeNull();
    });

    test('custom type from another household cannot be selected or managed', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicle)->create();

        $stranger = User::factory()->create();
        $foreignHousehold = obligationHousehold($stranger, 'Asing');
        $foreignType = obligationCustomType($foreignHousehold, 'Tipe Rahasia');

        $types = $this->actingAs($owner)->getJson('/api/obligation-types');
        $types->assertOk();
        expect(array_column($types->json('data'), 'name'))->not->toContain('Tipe Rahasia');

        $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/obligations", obligationPayload($foreignType->id))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_NOT_FOUND');

        $this->actingAs($owner)->patchJson(
            "/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}",
            ['obligation_type_id' => $foreignType->id],
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/obligation-types/{$foreignType->id}", ['name' => 'Bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/obligation-types/{$foreignType->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_NOT_FOUND');

        expect($obligation->refresh()->obligation_type_id)->toBe(obligationSystemType()->id);
        expect($foreignType->refresh()->name)->toBe('Tipe Rahasia');
    });

    test('unknown identifiers report not found without leaking', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $ghostVehicle = (string) Str::ulid();
        $ghostObligation = (string) Str::ulid();
        $ghostType = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/vehicles/{$ghostVehicle}/obligations")->assertStatus(404);
        $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/obligations/{$ghostObligation}")->assertStatus(404);
        $this->actingAs($owner)->postJson('/api/vehicles/'.$ghostVehicle.'/obligations', obligationPayload(obligationSystemType()->id))->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/obligation-types/{$ghostType}", ['name' => 'x'])->assertStatus(404);
    });
});

describe('Vehicle obligation API CRUD and validation', function () {
    test('list shows only the routed vehicle obligations in due-date order', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $otherVehicle = obligationVehicle($household);
        $type = obligationSystemType();

        $later = VehicleObligation::factory()->for($vehicle)->create([
            'obligation_type_id' => $type->id,
            'title' => 'Jatuh tempo kedua',
            'due_date' => '2026-12-20',
        ]);
        $sooner = VehicleObligation::factory()->for($vehicle)->create([
            'obligation_type_id' => $type->id,
            'title' => 'Jatuh tempo pertama',
            'due_date' => '2026-11-20',
        ]);
        VehicleObligation::factory()->for($otherVehicle)->create(['title' => 'Kendaraan lain']);

        $response = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/obligations");

        $response->assertOk()->assertJsonPath('success', true);

        $titles = array_column($response->json('data'), 'title');

        expect($titles)->toEqual(['Jatuh tempo pertama', 'Jatuh tempo kedua']);
        expect($response->getContent())->not->toContain('Kendaraan lain');
        expect(array_keys($response->json('data.0')))->toEqual(obligationContractKeys());
        expect($response->json('data.0.obligation_type.id'))->toBe($type->id);
        expect($response->json('data.0.obligation_type.code'))->toBe(ObligationType::CODE_TAX);
    });

    test('owner creates an obligation with only required fields', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $type = obligationSystemType(ObligationType::CODE_REGISTRATION);

        $response = $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/obligations", [
            'obligation_type_id' => $type->id,
            'title' => 'Perpanjang STNK',
            'due_date' => '2026-12-31',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['vehicle_id'])->toBe($vehicle->id);
        expect($data['title'])->toBe('Perpanjang STNK');
        expect($data['description'])->toBeNull();
        expect($data['issued_date'])->toBeNull();
        expect($data['due_date'])->toBe('2026-12-31');
        expect($data['amount'])->toBeNull();
        expect($data['status'])->toBe(VehicleObligation::STATUS_ACTIVE);
        expect($data['obligation_type']['id'])->toBe($type->id);
        expect(array_keys($data))->toEqual(obligationContractKeys());
        expect($data)->not->toHaveKey('household_id');
        expect($data)->not->toHaveKey('paid_at');
        expect($data)->not->toHaveKey('payment_status');
        expect($data)->not->toHaveKey('bill_id');

        expect(VehicleObligation::where('vehicle_id', $vehicle->id)->count())->toBe(1);
    });

    test('owner creates an obligation with issued date and non-negative amount', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $type = obligationSystemType();

        $response = $this->actingAs($owner)->postJson(
            "/api/vehicles/{$vehicle->id}/obligations",
            obligationPayload($type->id, ['amount' => '1250000.50', 'issued_date' => '2026-09-15']),
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.amount', '1250000.50')
            ->assertJsonPath('data.issued_date', '2026-09-15')
            ->assertJsonPath('data.status', VehicleObligation::STATUS_ACTIVE);
    });

    test('a custom type owned by the same household can be used', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $customType = obligationCustomType($household, 'Retribusi Parkir');

        $this->actingAs($owner)->postJson(
            "/api/vehicles/{$vehicle->id}/obligations",
            obligationPayload($customType->id, ['title' => 'Retribusi tahunan']),
        )
            ->assertStatus(201)
            ->assertJsonPath('data.obligation_type.id', $customType->id)
            ->assertJsonPath('data.obligation_type.is_system', false);
    });

    test('store validates required, nullable, and malformed fields', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $type = obligationSystemType();
        $endpoint = "/api/vehicles/{$vehicle->id}/obligations";

        $this->actingAs($owner)->postJson($endpoint, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['obligation_type_id', 'title', 'due_date']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['title' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['title' => str_repeat('t', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['obligation_type_id' => 'not-a-ulid']))
            ->assertStatus(422)->assertJsonValidationErrors(['obligation_type_id']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['due_date' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['due_date']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['due_date' => '30-11-2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['due_date']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['issued_date' => '01-10-2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['issued_date']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['amount' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['amount' => 'mahal']))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson($endpoint, obligationPayload($type->id, ['description' => str_repeat('d', 5001)]))
            ->assertStatus(422)->assertJsonValidationErrors(['description']);

        expect(VehicleObligation::count())->toBe(0);
    });

    test('payload cannot forge vehicle, household, or initial status fields', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $type = obligationSystemType();

        $stranger = User::factory()->create();
        $foreign = obligationHousehold($stranger, 'Asing');
        $foreignVehicle = obligationVehicle($foreign);

        $response = $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/obligations", obligationPayload($type->id, [
            'vehicle_id' => $foreignVehicle->id,
            'household_id' => $foreign->id,
            'status' => VehicleObligation::STATUS_INACTIVE,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.status', VehicleObligation::STATUS_ACTIVE);

        expect($response->json('data'))->not->toHaveKey('household_id');
        expect(VehicleObligation::where('vehicle_id', $foreignVehicle->id)->count())->toBe(0);
    });

    test('due date passage does not change administrative status', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $type = obligationSystemType();

        $response = $this->actingAs($owner)->postJson("/api/vehicles/{$vehicle->id}/obligations", obligationPayload($type->id, [
            'due_date' => '2020-01-01',
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.status', VehicleObligation::STATUS_ACTIVE)
            ->assertJsonPath('data.due_date', '2020-01-01');
    });

    test('owner updates writable fields and cannot move the obligation', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $otherVehicle = obligationVehicle($household);
        $type = obligationSystemType();
        $otherType = obligationSystemType(ObligationType::CODE_INSPECTION);
        $obligation = VehicleObligation::factory()->for($vehicle)->create([
            'obligation_type_id' => $type->id,
            'title' => 'Awal',
            'due_date' => '2026-11-01',
        ]);

        $response = $this->actingAs($owner)->patchJson(
            "/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}",
            [
                'obligation_type_id' => $otherType->id,
                'title' => 'Diubah',
                'description' => null,
                'issued_date' => '2026-10-05',
                'due_date' => '2026-12-15',
                'amount' => '0',
                'status' => VehicleObligation::STATUS_INACTIVE,
                'vehicle_id' => $otherVehicle->id,
                'household_id' => 'forged',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('data.title', 'Diubah')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.issued_date', '2026-10-05')
            ->assertJsonPath('data.due_date', '2026-12-15')
            ->assertJsonPath('data.amount', '0.00')
            ->assertJsonPath('data.status', VehicleObligation::STATUS_INACTIVE)
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.obligation_type.id', $otherType->id);

        expect($obligation->refresh()->vehicle_id)->toBe($vehicle->id);
    });

    test('administrative status is server-validated and reversible', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicle)->create();

        $this->actingAs($owner)->patchJson(
            "/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}",
            ['status' => 'paid'],
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($owner)->patchJson(
            "/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}",
            ['status' => VehicleObligation::STATUS_INACTIVE],
        )
            ->assertOk()
            ->assertJsonPath('data.status', VehicleObligation::STATUS_INACTIVE);

        $this->actingAs($owner)->patchJson(
            "/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}",
            ['status' => VehicleObligation::STATUS_ACTIVE],
        )
            ->assertOk()
            ->assertJsonPath('data.status', VehicleObligation::STATUS_ACTIVE);

        expect(VehicleObligation::statuses())->toEqual(['active', 'inactive']);
    });

    test('update rejects malformed fields, unknown types, and null due_date', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicle)->create(['title' => 'Awal']);
        $endpoint = "/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}";

        $this->actingAs($owner)->patchJson($endpoint, ['title' => 123])
            ->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->actingAs($owner)->patchJson($endpoint, ['due_date' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['due_date']);

        $this->actingAs($owner)->patchJson($endpoint, ['issued_date' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors(['issued_date']);

        $this->actingAs($owner)->patchJson($endpoint, ['amount' => -0.01])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->patchJson($endpoint, ['obligation_type_id' => (string) Str::ulid()])
            ->assertStatus(422)->assertJsonPath('error.code', 'OBLIGATION_TYPE_NOT_FOUND');

        expect($obligation->refresh()->title)->toBe('Awal');
        expect($obligation->due_date)->not->toBeNull();
    });

    test('owner deletes one obligation while the vehicle and siblings stay intact', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicle)->create();
        $other = VehicleObligation::factory()->for($vehicle)->create();

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $obligation->id);

        expect(VehicleObligation::find($obligation->id))->toBeNull();
        expect(VehicleObligation::find($other->id))->not->toBeNull();
        expect(Vehicle::find($vehicle->id))->not->toBeNull();
    });

    test('detail exposes only the obligation contract fields', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $obligation = VehicleObligation::factory()->for($vehicle)->create();

        $detail = $this->actingAs($owner)->getJson("/api/vehicles/{$vehicle->id}/obligations/{$obligation->id}");
        $detail->assertOk();

        expect(array_keys($detail->json('data')))->toEqual(obligationContractKeys());
        expect($detail->json('data'))->not->toHaveKey('household_id');
        expect($detail->json('data'))->not->toHaveKey('vehicle');
        expect($detail->json('data'))->not->toHaveKey('recurrence');
        expect($detail->json('data'))->not->toHaveKey('paid_at');
        expect($detail->json('data'))->not->toHaveKey('payment_status');
        expect($detail->json('data'))->not->toHaveKey('bill_id');
    });
});

describe('Obligation type API', function () {
    test('built-in types are available to every household and custom types are scoped', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $custom = obligationCustomType($household, 'Retribusi Parkir');

        $stranger = User::factory()->create();
        $foreignHousehold = obligationHousehold($stranger, 'Asing');
        obligationCustomType($foreignHousehold, 'Tipe Tetangga');

        $response = $this->actingAs($owner)->getJson('/api/obligation-types');

        $response->assertOk();

        $data = $response->json('data');
        $codes = array_column($data, 'code');
        $names = array_column($data, 'name');

        expect($codes)->toContain(
            ObligationType::CODE_TAX,
            ObligationType::CODE_REGISTRATION,
            ObligationType::CODE_INSPECTION,
        );
        expect($names)->toContain('Pajak Kendaraan', 'STNK / Registrasi', 'KIR / Inspeksi', 'Retribusi Parkir');
        expect($names)->not->toContain('Tipe Tetangga');
        expect(array_keys($data[0]))->toEqual(obligationTypeContractKeys());

        $system = array_values(array_filter($data, fn (array $type) => $type['is_system'] === true));
        expect($system)->toHaveCount(3);

        $customRow = array_values(array_filter($data, fn (array $type) => $type['id'] === $custom->id))[0];
        expect($customRow['household_id'])->toBe($household->id);
        expect($customRow['code'])->toBeNull();
        expect($customRow['is_system'])->toBeFalse();
    });

    test('owner creates, renames, and deletes an unused custom type', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);

        $created = $this->actingAs($owner)->postJson('/api/obligation-types', [
            'name' => 'Retribusi Pelabuhan',
            'household_id' => 'forged',
            'is_system' => true,
            'code' => 'forged',
        ]);

        $created->assertStatus(201)
            ->assertJsonPath('data.name', 'Retribusi Pelabuhan')
            ->assertJsonPath('data.household_id', $household->id)
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('data.is_system', false);

        $typeId = $created->json('data.id');
        expect(array_keys($created->json('data')))->toEqual(obligationTypeContractKeys());

        $this->actingAs($owner)->patchJson("/api/obligation-types/{$typeId}", [
            'name' => 'Retribusi Pelabuhan Baru',
            'household_id' => 'forged',
            'is_system' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Retribusi Pelabuhan Baru')
            ->assertJsonPath('data.household_id', $household->id)
            ->assertJsonPath('data.is_system', false);

        $this->actingAs($owner)->deleteJson("/api/obligation-types/{$typeId}")
            ->assertOk()
            ->assertJsonPath('data.id', $typeId);

        expect(ObligationType::find($typeId))->toBeNull();
    });

    test('custom type names are unique against system and household types', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        obligationCustomType($household, 'Retribusi Parkir');

        $this->actingAs($owner)->postJson('/api/obligation-types', ['name' => 'Retribusi Parkir'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_ALREADY_EXISTS');

        $this->actingAs($owner)->postJson('/api/obligation-types', ['name' => 'Pajak Kendaraan'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_ALREADY_EXISTS');

        $stranger = User::factory()->create();
        $other = obligationHousehold($stranger, 'Tetangga');

        $this->actingAs($stranger)->postJson('/api/obligation-types', ['name' => 'Retribusi Parkir'])
            ->assertStatus(201);

        expect($other->obligationTypes()->where('name', 'Retribusi Parkir')->exists())->toBeTrue();
    });

    test('built-in types cannot be changed or deleted', function () {
        $owner = User::factory()->create();
        obligationHousehold($owner);
        $tax = obligationSystemType(ObligationType::CODE_TAX);

        $this->actingAs($owner)->patchJson("/api/obligation-types/{$tax->id}", ['name' => 'Diubah'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_IMMUTABLE');

        $this->actingAs($owner)->deleteJson("/api/obligation-types/{$tax->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_IMMUTABLE');

        expect($tax->refresh()->name)->toBe('Pajak Kendaraan');
        expect($tax->refresh()->is_system)->toBeTrue();
    });

    test('a custom type still referenced by an obligation cannot be deleted', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $customType = obligationCustomType($household, 'Retribusi Aman');
        $obligation = VehicleObligation::factory()->for($vehicle)->create([
            'obligation_type_id' => $customType->id,
        ]);

        $this->actingAs($owner)->deleteJson("/api/obligation-types/{$customType->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_IN_USE');

        expect(ObligationType::find($customType->id))->not->toBeNull();
        expect($obligation->refresh()->obligation_type_id)->toBe($customType->id);
    });

    test('custom type update rejects a duplicate name', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        obligationCustomType($household, 'Retribusi Parkir');
        $type = obligationCustomType($household, 'Retribusi Pelabuhan');

        $this->actingAs($owner)->patchJson("/api/obligation-types/{$type->id}", ['name' => 'Retribusi Parkir'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OBLIGATION_TYPE_ALREADY_EXISTS');

        expect($type->refresh()->name)->toBe('Retribusi Pelabuhan');
    });
});

describe('Vehicle obligation deletion and retention', function () {
    test('deleting a vehicle cascades its obligations but keeps its custom types', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $customType = obligationCustomType($household, 'Tipe Tetap');
        $obligation = VehicleObligation::factory()->for($vehicle)->create([
            'obligation_type_id' => $customType->id,
        ]);

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vehicle->id);

        expect(VehicleObligation::find($obligation->id))->toBeNull();
        expect(ObligationType::find($customType->id))->not->toBeNull();
    });

    test('vehicle maintenance history still blocks vehicle deletion and preserves obligations', function () {
        $owner = User::factory()->create();
        $household = obligationHousehold($owner);
        $vehicle = obligationVehicle($household);
        $maintenance = VehicleMaintenance::factory()->for($vehicle)->create();
        $obligation = VehicleObligation::factory()->for($vehicle)->create();

        $this->actingAs($owner)->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VEHICLE_HAS_MAINTENANCE_HISTORY');

        expect(VehicleMaintenance::find($maintenance->id))->not->toBeNull();
        expect(VehicleObligation::find($obligation->id))->not->toBeNull();
    });
});
