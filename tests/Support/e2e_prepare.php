<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ObligationType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehicleObligation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\E2e\E2eDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/**
 * Prepare the dedicated authenticated E2E database.
 *
 * This script is test-only. It refuses to run unless the explicit environment
 * and the live PostgreSQL connection both resolve to homflo_e2e.
 *
 * Output is a single JSON object containing only non-secret fixture metadata.
 */
function e2ePrepareFail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, '[e2e-prepare] '.$message.PHP_EOL);
    exit($exitCode);
}

try {
    E2eDatabaseGuard::validateEnvironment(E2eDatabaseGuard::environment());
} catch (Throwable $exception) {
    e2ePrepareFail($exception->getMessage(), 2);
}

$runId = getenv('E2E_RUN_ID') ?: '';
$password = getenv('E2E_FIXTURE_PASSWORD') ?: '';

if (! preg_match('/^[a-z0-9][a-z0-9-]{7,47}$/', $runId)) {
    e2ePrepareFail('E2E_RUN_ID must be 8-48 lowercase alphanumeric/hyphen characters.', 2);
}

if (strlen($password) < 16) {
    e2ePrepareFail('E2E_FIXTURE_PASSWORD must be at least 16 characters.', 2);
}

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $exception) {
    e2ePrepareFail('Laravel bootstrap failed: '.$exception->getMessage(), 2);
}

if (! is_string(config('app.key')) || config('app.key') === '') {
    e2ePrepareFail('APP_KEY is not configured. Set it in the ignored API .env file.', 2);
}

$connection = config('database.connections.pgsql');

if (! is_array($connection)) {
    e2ePrepareFail('The pgsql connection is not configured.', 2);
}

try {
    // Re-check the resolved config after bootstrap, including DB_URL.
    E2eDatabaseGuard::validateConnection($connection);
    E2eDatabaseGuard::ensureDatabaseExists($connection);
} catch (Throwable $exception) {
    e2ePrepareFail('Database target check/create failed: '.$exception->getMessage(), 3);
}

try {
    DB::purge('pgsql');
    E2eDatabaseGuard::assertLiveDatabase($connection);
} catch (Throwable $exception) {
    e2ePrepareFail('Live database verification failed: '.$exception->getMessage(), 3);
}

try {
    $status = Artisan::call('migrate:fresh', [
        '--force' => true,
        '--quiet' => true,
    ]);

    if ($status !== 0) {
        e2ePrepareFail('migrate:fresh returned a non-zero status.', 4);
    }
} catch (Throwable $exception) {
    e2ePrepareFail('migrate:fresh failed: '.$exception->getMessage(), 4);
}

try {
    $verifiedAt = now();

    $ownerA = User::create([
        'username' => 'e2e_owner_a_'.$runId,
        'name' => 'E2E Owner A',
        'email' => 'e2e-owner-a-'.$runId.'@homflo.test',
        'password' => $password,
    ]);
    $ownerA->forceFill(['email_verified_at' => $verifiedAt])->save();

    $memberA = User::create([
        'username' => 'e2e_member_a_'.$runId,
        'name' => 'E2E Member A',
        'email' => 'e2e-member-a-'.$runId.'@homflo.test',
        'password' => $password,
    ]);
    $memberA->forceFill(['email_verified_at' => $verifiedAt])->save();

    $unverifiedA = User::create([
        'username' => 'e2e_unverified_a_'.$runId,
        'name' => 'E2E Unverified A',
        'email' => 'e2e-unverified-a-'.$runId.'@homflo.test',
        'password' => $password,
    ]);

    $ownerB = User::create([
        'username' => 'e2e_owner_b_'.$runId,
        'name' => 'E2E Owner B',
        'email' => 'e2e-owner-b-'.$runId.'@homflo.test',
        'password' => $password,
    ]);
    $ownerB->forceFill(['email_verified_at' => $verifiedAt])->save();

    $householdA = Household::create([
        'name' => 'E2E Household A '.$runId,
        'created_by' => $ownerA->id,
    ]);
    $householdB = Household::create([
        'name' => 'E2E Household B '.$runId,
        'created_by' => $ownerB->id,
    ]);

    HouseholdMember::create([
        'household_id' => $householdA->id,
        'user_id' => $ownerA->id,
        'role' => 'owner',
    ]);
    HouseholdMember::create([
        'household_id' => $householdA->id,
        'user_id' => $memberA->id,
        'role' => 'member',
    ]);
    HouseholdMember::create([
        'household_id' => $householdA->id,
        'user_id' => $unverifiedA->id,
        'role' => 'member',
    ]);
    HouseholdMember::create([
        'household_id' => $householdB->id,
        'user_id' => $ownerB->id,
        'role' => 'owner',
    ]);

    $vehicleA = Vehicle::create([
        'household_id' => $householdA->id,
        'name' => 'E2E A Vehicle '.$runId,
        'brand' => 'E2E',
        'model' => 'Fixture A',
        'year' => 2024,
        'type' => 'car',
        'plate_number' => 'E2E-A-'.substr($runId, -8),
        'odometer' => 12000,
    ]);
    $vehicleB = Vehicle::create([
        'household_id' => $householdB->id,
        'name' => 'E2E B Vehicle '.$runId,
        'brand' => 'E2E',
        'model' => 'Fixture B',
        'year' => 2023,
        'type' => 'car',
        'plate_number' => 'E2E-B-'.substr($runId, -8),
        'odometer' => 5000,
    ]);

    $scheduledMaintenance = VehicleMaintenance::create([
        'vehicle_id' => $vehicleA->id,
        'title' => 'E2E A Scheduled '.$runId,
        'description' => 'Seeded scheduled service for authenticated E2E.',
        'scheduled_date' => now()->addDays(7)->toDateString(),
        'completed_at' => null,
        'odometer' => 11000,
    ]);
    $completedMaintenance = VehicleMaintenance::create([
        'vehicle_id' => $vehicleA->id,
        'title' => 'E2E A Completed '.$runId,
        'description' => 'Seeded completed service for authenticated E2E.',
        'scheduled_date' => now()->subDays(45)->toDateString(),
        'completed_at' => now()->subDays(30)->toDateString(),
        'odometer' => 10000,
    ]);

    $systemTax = ObligationType::query()
        ->where('code', ObligationType::CODE_TAX)
        ->where('is_system', true)
        ->firstOrFail();

    $customType = ObligationType::create([
        'household_id' => $householdA->id,
        'code' => null,
        'name' => 'E2E Custom '.$runId,
        'is_system' => false,
    ]);

    $customObligation = VehicleObligation::create([
        'vehicle_id' => $vehicleA->id,
        'obligation_type_id' => $customType->id,
        'title' => 'E2E Custom Obligation '.$runId,
        'description' => 'Seeded custom obligation for authenticated E2E.',
        'issued_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'amount' => 150000,
        'status' => VehicleObligation::STATUS_ACTIVE,
    ]);
    $systemObligation = VehicleObligation::create([
        'vehicle_id' => $vehicleA->id,
        'obligation_type_id' => $systemTax->id,
        'title' => 'E2E Tax Obligation '.$runId,
        'description' => 'Seeded built-in obligation for authenticated E2E.',
        'issued_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->addDays(60)->toDateString(),
        'amount' => 250000,
        'status' => VehicleObligation::STATUS_ACTIVE,
    ]);
} catch (Throwable $exception) {
    e2ePrepareFail('Fixture seeding failed: '.$exception->getMessage(), 5);
}

$output = [
    'run_id' => $runId,
    'database' => E2eDatabaseGuard::DATABASE,
    'users' => [
        'owner_a' => ['id' => $ownerA->id, 'email' => $ownerA->email, 'name' => $ownerA->name],
        'member_a' => ['id' => $memberA->id, 'email' => $memberA->email, 'name' => $memberA->name],
        'unverified_a' => ['id' => $unverifiedA->id, 'email' => $unverifiedA->email, 'name' => $unverifiedA->name],
        'owner_b' => ['id' => $ownerB->id, 'email' => $ownerB->email, 'name' => $ownerB->name],
    ],
    'households' => [
        'a' => ['id' => $householdA->id, 'name' => $householdA->name],
        'b' => ['id' => $householdB->id, 'name' => $householdB->name],
    ],
    'vehicles' => [
        'a' => ['id' => $vehicleA->id, 'name' => $vehicleA->name, 'plate_number' => $vehicleA->plate_number, 'odometer' => $vehicleA->odometer],
        'b' => ['id' => $vehicleB->id, 'name' => $vehicleB->name, 'plate_number' => $vehicleB->plate_number, 'odometer' => $vehicleB->odometer],
    ],
    'maintenance' => [
        'scheduled' => ['id' => $scheduledMaintenance->id, 'title' => $scheduledMaintenance->title],
        'completed' => ['id' => $completedMaintenance->id, 'title' => $completedMaintenance->title],
    ],
    'obligation_types' => [
        'system_tax' => ['id' => $systemTax->id, 'name' => $systemTax->name, 'code' => $systemTax->code],
        'custom' => ['id' => $customType->id, 'name' => $customType->name],
    ],
    'obligations' => [
        'custom' => ['id' => $customObligation->id, 'title' => $customObligation->title],
        'system' => ['id' => $systemObligation->id, 'title' => $systemObligation->title],
    ],
];

fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR).PHP_EOL);
