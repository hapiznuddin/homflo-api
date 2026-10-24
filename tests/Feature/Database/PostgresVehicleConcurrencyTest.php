<?php

use App\Exceptions\VehicleHasMaintenanceHistoryException;
use App\Exceptions\VehicleOdometerBelowRecordedException;
use App\Models\Household;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Services\Vehicle\VehicleMaintenanceService;
use App\Services\Vehicle\VehicleService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Point the pgsql connection at the dedicated test database and prove the
 * live target before any destructive migration runs.
 *
 * @return bool true when PostgreSQL is reachable, false otherwise
 */
function pgVehConnect(): bool
{
    config(['database.connections.pgsql.database' => env('DB_TEST_DATABASE', 'homflo_test')]);
    DB::purge('pgsql');

    try {
        $row = DB::connection('pgsql')->selectOne('select current_database() as db');
    } catch (Throwable) {
        return false;
    }

    if (($row->db ?? null) !== 'homflo_test') {
        throw new RuntimeException('Refusing to run destructive PostgreSQL tests against '.($row->db ?? 'unknown').'.');
    }

    return true;
}

/**
 * @return array{user_id: string, household_id: string}
 */
function pgVehSeedHousehold(): array
{
    $userId = (string) Str::ulid();
    $householdId = (string) Str::ulid();

    DB::connection('pgsql')->table('users')->insert([
        'id' => $userId,
        'username' => 'pg-veh-'.$userId,
        'name' => 'PG Vehicle',
        'email' => 'pg-veh-'.$userId.'@example.com',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('pgsql')->table('households')->insert([
        'id' => $householdId,
        'name' => 'PG Vehicle House',
        'created_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['user_id' => $userId, 'household_id' => $householdId];
}

function pgVehInsertVehicle(Connection $connection, string $householdId, ?string $plate, int $odometer, string $name = 'Kendaraan PG'): string
{
    $vehicleId = (string) Str::ulid();

    $connection->table('vehicles')->insert([
        'id' => $vehicleId,
        'household_id' => $householdId,
        'name' => $name,
        'brand' => null,
        'model' => null,
        'year' => null,
        'type' => null,
        'plate_number' => $plate,
        'odometer' => $odometer,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $vehicleId;
}

function pgVehSeedVehicle(string $householdId, ?string $plate, int $odometer, string $name = 'Kendaraan PG'): string
{
    return pgVehInsertVehicle(DB::connection('pgsql'), $householdId, $plate, $odometer, $name);
}

function pgVehSeedMaintenance(string $vehicleId, int $odometer, string $title = 'Servis PG'): string
{
    $maintenanceId = (string) Str::ulid();

    DB::connection('pgsql')->table('vehicle_maintenance')->insert([
        'id' => $maintenanceId,
        'vehicle_id' => $vehicleId,
        'title' => $title,
        'description' => null,
        'scheduled_date' => '2026-12-01',
        'completed_at' => null,
        'odometer' => $odometer,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $maintenanceId;
}

function pgVehSecondConnection(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    DB::purge('pgsql_second');

    return DB::connection('pgsql_second');
}

function pgVehReleaseSecondConnection(): void
{
    DB::connection('pgsql_second')->disconnect();
    config()->offsetUnset('database.connections.pgsql_second');
    DB::purge('pgsql_second');
}

/**
 * Wait until a second backend is genuinely blocked on a PostgreSQL lock.
 * This is the synchronization barrier for the plate race tests.
 */
function pgVehWaitForBlockedQuery(string $needle, int $timeoutMs = 5000): bool
{
    $deadline = microtime(true) + ($timeoutMs / 1000);

    do {
        $row = DB::connection('pgsql')->selectOne(
            "select count(*) as c
               from pg_stat_activity
              where datname = current_database()
                and pid <> pg_backend_pid()
                and state = 'active'
                and wait_event_type = 'Lock'
                and query ilike ?",
            ['%'.$needle.'%']
        );

        if ((int) ($row->c ?? 0) > 0) {
            return true;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    return false;
}

/**
 * Start the real service in an independent PHP process. A separate process is
 * required: forking after PostgreSQL connections exist shares the session
 * socket and can terminate the parent backend.
 *
 * @param  array{household_id: string, vehicle_id?: string, plate: string}  $payload
 */
function pgVehStartPlateWriter(string $mode, array $payload): Process
{
    $command = [
        PHP_BINARY,
        base_path('tests/Support/vehicle_plate_race_writer.php'),
        $mode,
        $payload['household_id'],
        $payload['plate'],
    ];

    if ($mode === 'update') {
        $command[] = $payload['vehicle_id'];
    }

    $process = new Process($command, base_path());
    $process->start();

    return $process;
}

function pgVehExpectLockTimeout(callable $callback): bool
{
    try {
        $callback();
    } catch (QueryException $exception) {
        if (! str_contains($exception->getMessage(), 'lock timeout')) {
            throw $exception;
        }

        return true;
    }

    return false;
}

describe('postgresql vehicle production-readiness concurrency', function () {
    test('concurrent vehicle create with the same plate has one winner and one domain conflict', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        $first = pgVehSecondConnection();
        $writer = null;

        try {
            $first->beginTransaction();
            pgVehInsertVehicle($first, $ids['household_id'], 'B 7001 RACE', 10000, 'Writer A');

            $writer = pgVehStartPlateWriter('create', [
                'household_id' => $ids['household_id'],
                'plate' => 'B 7001 RACE',
            ]);

            expect(pgVehWaitForBlockedQuery('insert into "vehicles"'))->toBeTrue();

            $first->commit();
            $writer->wait();

            expect([
                $writer->getExitCode(),
                trim($writer->getOutput()),
                trim($writer->getErrorOutput()),
            ])->toBe([0, 'domain', '']);
            expect(
                DB::connection('pgsql')
                    ->table('vehicles')
                    ->where('household_id', $ids['household_id'])
                    ->where('plate_number', 'B 7001 RACE')
                    ->count()
            )->toBe(1);
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }

            if ($writer !== null && $writer->isRunning()) {
                $writer->stop(1);
            }

            pgVehReleaseSecondConnection();
            config(['database.default' => $previousDefault]);
        }
    });

    test('concurrent vehicle update to the same plate has one winner and one domain conflict', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $firstVehicleId = pgVehSeedVehicle($ids['household_id'], 'B 8001 ONE', 10000, 'Writer B');
        $secondVehicleId = pgVehSeedVehicle($ids['household_id'], 'B 8002 TWO', 10000, 'Writer A');

        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        $first = pgVehSecondConnection();
        $writer = null;

        try {
            $first->beginTransaction();
            $first->table('vehicles')
                ->where('id', $secondVehicleId)
                ->update([
                    'plate_number' => 'B 8003 RACE',
                    'updated_at' => now(),
                ]);

            $writer = pgVehStartPlateWriter('update', [
                'household_id' => $ids['household_id'],
                'vehicle_id' => $firstVehicleId,
                'plate' => 'B 8003 RACE',
            ]);

            expect(pgVehWaitForBlockedQuery('update "vehicles"'))->toBeTrue();

            $first->commit();
            $writer->wait();

            expect([
                $writer->getExitCode(),
                trim($writer->getOutput()),
                trim($writer->getErrorOutput()),
            ])->toBe([0, 'domain', '']);
            expect(
                DB::connection('pgsql')
                    ->table('vehicles')
                    ->where('household_id', $ids['household_id'])
                    ->where('plate_number', 'B 8003 RACE')
                    ->count()
            )->toBe(1);
            expect(
                DB::connection('pgsql')->table('vehicles')->where('id', $firstVehicleId)->value('plate_number')
            )->toBe('B 8001 ONE');
            expect(
                DB::connection('pgsql')->table('vehicles')->where('id', $secondVehicleId)->value('plate_number')
            )->toBe('B 8003 RACE');
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }

            if ($writer !== null && $writer->isRunning()) {
                $writer->stop(1);
            }

            pgVehReleaseSecondConnection();
            config(['database.default' => $previousDefault]);
        }
    });

    test('a service insert serializes with a vehicle odometer update and the lower update is rejected', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $vehicleId = pgVehSeedVehicle($ids['household_id'], 'B 9001 ODO', 6000);

        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        $first = pgVehSecondConnection();

        try {
            $household = Household::query()->findOrFail($ids['household_id']);
            $vehicle = Vehicle::query()->findOrFail($vehicleId);

            $first->beginTransaction();
            $first->table('vehicle_maintenance')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $vehicleId,
                'title' => 'Insert A',
                'description' => null,
                'scheduled_date' => '2026-12-01',
                'completed_at' => null,
                'odometer' => 5000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");

            expect(pgVehExpectLockTimeout(
                fn () => app(VehicleService::class)->change($household, $vehicle, ['odometer' => 1000])
            ))->toBeTrue();

            $first->commit();
            DB::connection('pgsql')->statement('SET lock_timeout = 0');

            expect(fn () => app(VehicleService::class)->change($household, $vehicle, ['odometer' => 1000]))
                ->toThrow(VehicleOdometerBelowRecordedException::class);

            expect(DB::connection('pgsql')->table('vehicles')->where('id', $vehicleId)->value('odometer'))->toBe(6000);
            expect(
                DB::connection('pgsql')->table('vehicle_maintenance')->where('vehicle_id', $vehicleId)->max('odometer')
            )->toBe(5000);
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }

            DB::connection('pgsql')->statement('SET lock_timeout = 0');
            pgVehReleaseSecondConnection();
            config(['database.default' => $previousDefault]);
        }
    });

    test('a service insert above the vehicle odometer is rejected after serializing with a lower update', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $vehicleId = pgVehSeedVehicle($ids['household_id'], 'B 9002 ODO', 6000);

        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        $first = pgVehSecondConnection();

        try {
            $vehicle = Vehicle::query()->findOrFail($vehicleId);

            $first->beginTransaction();
            $first->table('vehicles')
                ->where('id', $vehicleId)
                ->update([
                    'odometer' => 4000,
                    'updated_at' => now(),
                ]);

            DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");

            expect(pgVehExpectLockTimeout(
                fn () => app(VehicleMaintenanceService::class)->record($vehicle, [
                    'title' => 'Writer B',
                    'scheduled_date' => '2026-12-01',
                    'odometer' => 5000,
                ])
            ))->toBeTrue();

            $first->commit();
            DB::connection('pgsql')->statement('SET lock_timeout = 0');

            $caught = null;

            try {
                app(VehicleMaintenanceService::class)->record($vehicle, [
                    'title' => 'Writer B',
                    'scheduled_date' => '2026-12-01',
                    'odometer' => 5000,
                ]);
            } catch (ValidationException $exception) {
                $caught = $exception;
            }

            expect($caught)->not->toBeNull();
            expect($caught->errors())->toHaveKey('odometer');
            expect(DB::connection('pgsql')->table('vehicles')->where('id', $vehicleId)->value('odometer'))->toBe(4000);
            expect(
                DB::connection('pgsql')->table('vehicle_maintenance')->where('vehicle_id', $vehicleId)->count()
            )->toBe(0);
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }

            DB::connection('pgsql')->statement('SET lock_timeout = 0');
            pgVehReleaseSecondConnection();
            config(['database.default' => $previousDefault]);
        }
    });

    test('a service odometer update serializes with a vehicle update and is rejected against the new odometer', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $vehicleId = pgVehSeedVehicle($ids['household_id'], 'B 9003 ODO', 6000);
        $maintenanceId = pgVehSeedMaintenance($vehicleId, 3000, 'Existing service');

        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        $first = pgVehSecondConnection();

        try {
            $vehicle = Vehicle::query()->findOrFail($vehicleId);
            $maintenance = VehicleMaintenance::query()->findOrFail($maintenanceId);

            $first->beginTransaction();
            $first->table('vehicles')
                ->where('id', $vehicleId)
                ->update([
                    'odometer' => 4000,
                    'updated_at' => now(),
                ]);

            DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");

            expect(pgVehExpectLockTimeout(
                fn () => app(VehicleMaintenanceService::class)->change($vehicle, $maintenance, ['odometer' => 5500])
            ))->toBeTrue();

            $first->commit();
            DB::connection('pgsql')->statement('SET lock_timeout = 0');

            $caught = null;

            try {
                app(VehicleMaintenanceService::class)->change($vehicle, $maintenance, ['odometer' => 5500]);
            } catch (ValidationException $exception) {
                $caught = $exception;
            }

            expect($caught)->not->toBeNull();
            expect($caught->errors())->toHaveKey('odometer');
            expect(DB::connection('pgsql')->table('vehicles')->where('id', $vehicleId)->value('odometer'))->toBe(4000);
            expect(
                DB::connection('pgsql')->table('vehicle_maintenance')->where('id', $maintenanceId)->value('odometer')
            )->toBe(3000);
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }

            DB::connection('pgsql')->statement('SET lock_timeout = 0');
            pgVehReleaseSecondConnection();
            config(['database.default' => $previousDefault]);
        }
    });

    test('valid sequential odometer writes still succeed on postgresql', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $vehicleId = pgVehSeedVehicle($ids['household_id'], 'B 9004 ODO', 10000);

        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        try {
            $household = Household::query()->findOrFail($ids['household_id']);
            $vehicle = Vehicle::query()->findOrFail($vehicleId);

            $maintenance = app(VehicleMaintenanceService::class)->record($vehicle, [
                'title' => 'Valid service',
                'scheduled_date' => '2026-12-01',
                'odometer' => 10000,
            ]);

            $updatedMaintenance = app(VehicleMaintenanceService::class)->change(
                $vehicle,
                $maintenance,
                ['odometer' => 9000]
            );

            $updatedVehicle = app(VehicleService::class)->change($household, $vehicle, ['odometer' => 9000]);

            $equalService = app(VehicleMaintenanceService::class)->change(
                $updatedVehicle,
                $updatedMaintenance,
                ['odometer' => 9000]
            );

            expect($updatedVehicle->odometer)->toBe(9000);
            expect($equalService->odometer)->toBe(9000);
            expect(
                DB::connection('pgsql')->table('vehicle_maintenance')->where('id', $maintenance->id)->value('odometer')
            )->toBe(9000);
        } finally {
            config(['database.default' => $previousDefault]);
        }
    });
});

describe('postgresql vehicle foreign-key integrity', function () {
    test('vehicle deletion is blocked by history and cascades obligations when history is absent', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $vehicleWithHistory = pgVehSeedVehicle($ids['household_id'], 'B 9101 FK', 10000);
        $vehicleWithObligation = pgVehSeedVehicle($ids['household_id'], 'B 9102 FK', 10000);
        pgVehSeedMaintenance($vehicleWithHistory, 9000, 'History that blocks deletion');

        $obligationTypeId = (string) Str::ulid();
        DB::connection('pgsql')->table('obligation_types')->insert([
            'id' => $obligationTypeId,
            'household_id' => $ids['household_id'],
            'code' => null,
            'name' => 'Custom Type FK',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obligationId = (string) Str::ulid();
        DB::connection('pgsql')->table('vehicle_obligations')->insert([
            'id' => $obligationId,
            'vehicle_id' => $vehicleWithObligation,
            'obligation_type_id' => $obligationTypeId,
            'title' => 'Obligation FK',
            'description' => null,
            'issued_date' => null,
            'due_date' => '2026-12-31',
            'amount' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        try {
            $blockedVehicle = Vehicle::query()->findOrFail($vehicleWithHistory);
            $deletableVehicle = Vehicle::query()->findOrFail($vehicleWithObligation);

            expect(fn () => app(VehicleService::class)->remove($blockedVehicle))
                ->toThrow(VehicleHasMaintenanceHistoryException::class);

            expect(DB::connection('pgsql')->table('vehicles')->where('id', $vehicleWithHistory)->exists())->toBeTrue();
            expect(DB::connection('pgsql')->table('vehicle_maintenance')->where('vehicle_id', $vehicleWithHistory)->count())->toBe(1);

            app(VehicleService::class)->remove($deletableVehicle);

            expect(DB::connection('pgsql')->table('vehicles')->where('id', $vehicleWithObligation)->exists())->toBeFalse();
            expect(DB::connection('pgsql')->table('vehicle_obligations')->where('id', $obligationId)->exists())->toBeFalse();
            expect(DB::connection('pgsql')->table('obligation_types')->where('id', $obligationTypeId)->exists())->toBeTrue();
        } finally {
            config(['database.default' => $previousDefault]);
        }
    });

    test('household deletion cascades vehicles and dependent rows together with the household custom type', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $vehicleId = pgVehSeedVehicle($ids['household_id'], 'B 9201 FK', 10000);
        $maintenanceId = pgVehSeedMaintenance($vehicleId, 9000, 'Cascade history');

        $obligationTypeId = (string) Str::ulid();
        DB::connection('pgsql')->table('obligation_types')->insert([
            'id' => $obligationTypeId,
            'household_id' => $ids['household_id'],
            'code' => null,
            'name' => 'Custom Type Cascade',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obligationId = (string) Str::ulid();
        DB::connection('pgsql')->table('vehicle_obligations')->insert([
            'id' => $obligationId,
            'vehicle_id' => $vehicleId,
            'obligation_type_id' => $obligationTypeId,
            'title' => 'Cascade obligation',
            'description' => null,
            'issued_date' => null,
            'due_date' => '2026-12-31',
            'amount' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('pgsql')->table('households')->where('id', $ids['household_id'])->delete();

        expect(DB::connection('pgsql')->table('households')->where('id', $ids['household_id'])->exists())->toBeFalse();
        expect(DB::connection('pgsql')->table('vehicles')->where('id', $vehicleId)->exists())->toBeFalse();
        expect(DB::connection('pgsql')->table('vehicle_maintenance')->where('id', $maintenanceId)->exists())->toBeFalse();
        expect(DB::connection('pgsql')->table('vehicle_obligations')->where('id', $obligationId)->exists())->toBeFalse();
        expect(DB::connection('pgsql')->table('obligation_types')->where('id', $obligationTypeId)->exists())->toBeFalse();
        expect(DB::connection('pgsql')->table('obligation_types')->where('is_system', true)->count())->toBe(3);
    });

    test('a custom obligation type with a NO ACTION reference is deletable only after its obligations are removed', function () {
        if (! pgVehConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgVehSeedHousehold();
        $vehicleId = pgVehSeedVehicle($ids['household_id'], 'B 9301 FK', 10000);

        $obligationTypeId = (string) Str::ulid();
        DB::connection('pgsql')->table('obligation_types')->insert([
            'id' => $obligationTypeId,
            'household_id' => $ids['household_id'],
            'code' => null,
            'name' => 'Custom Type NO ACTION',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obligationId = (string) Str::ulid();
        DB::connection('pgsql')->table('vehicle_obligations')->insert([
            'id' => $obligationId,
            'vehicle_id' => $vehicleId,
            'obligation_type_id' => $obligationTypeId,
            'title' => 'NO ACTION obligation',
            'description' => null,
            'issued_date' => null,
            'due_date' => '2026-12-31',
            'amount' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $blockedByForeignKey = false;

        try {
            DB::connection('pgsql')->table('obligation_types')->where('id', $obligationTypeId)->delete();
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'violates foreign key constraint')) {
                throw $exception;
            }

            $blockedByForeignKey = true;
        }

        expect($blockedByForeignKey)->toBeTrue();
        expect(DB::connection('pgsql')->table('obligation_types')->where('id', $obligationTypeId)->exists())->toBeTrue();

        DB::connection('pgsql')->table('vehicle_obligations')->where('id', $obligationId)->delete();
        DB::connection('pgsql')->table('obligation_types')->where('id', $obligationTypeId)->delete();

        expect(DB::connection('pgsql')->table('obligation_types')->where('id', $obligationTypeId)->exists())->toBeFalse();
        expect(DB::connection('pgsql')->table('vehicles')->where('id', $vehicleId)->exists())->toBeTrue();
    });
});
