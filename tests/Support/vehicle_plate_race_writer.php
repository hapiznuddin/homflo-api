<?php

use App\Exceptions\VehiclePlateAlreadyExistsException;
use App\Models\Household;
use App\Models\Vehicle;
use App\Services\Vehicle\VehicleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$householdId = $argv[2] ?? '';
$plate = $argv[3] ?? '';
$vehicleId = $argv[4] ?? null;

$database = getenv('DB_TEST_DATABASE') ?: 'homflo_test';

if ($database !== 'homflo_test') {
    fwrite(STDERR, 'Refusing unsafe PostgreSQL target: '.$database.PHP_EOL);
    exit(3);
}

config(['database.connections.pgsql.database' => $database]);
config(['database.default' => 'pgsql']);
DB::purge('pgsql');

try {
    $live = DB::connection('pgsql')->selectOne('select current_database() as db');

    if (($live->db ?? null) !== 'homflo_test') {
        fwrite(STDERR, 'Refusing unsafe live PostgreSQL target: '.($live->db ?? 'unknown').PHP_EOL);
        exit(4);
    }

    $household = Household::query()->findOrFail($householdId);
    $service = app(VehicleService::class);

    if ($mode === 'create') {
        $service->record($household, [
            'name' => 'Writer B',
            'plate_number' => $plate,
            'odometer' => 10000,
        ]);
    } elseif ($mode === 'update') {
        $vehicle = Vehicle::query()->findOrFail($vehicleId);
        $service->change($household, $vehicle, ['plate_number' => $plate]);
    } else {
        throw new RuntimeException('Unknown race writer mode: '.$mode);
    }

    echo "success\n";
    exit(10);
} catch (VehiclePlateAlreadyExistsException) {
    echo "domain\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
