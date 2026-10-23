<?php

use App\Models\Asset;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\Category;
use App\Models\File;
use App\Models\Household;
use App\Models\Maintenance;
use App\Models\ShoppingItem;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function financeHousehold(string $name = 'Keluarga Finansial'): Household
{
    return Household::factory()->create(['name' => $name]);
}

describe('financial domain foundation', function () {
    test('all ten tables exist after migration', function () {
        foreach ([
            'categories',
            'transactions',
            'budgets',
            'bills',
            'shopping_items',
            'assets',
            'maintenance',
            'vehicles',
            'vehicle_maintenance',
            'files',
        ] as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    });

    test('migration rolls back and re-runs safely', function () {
        // The newest migration steps own the latest schema additions
        // (the 16.4.1 vehicle Information columns plus the 16.3.2
        // maintenance cancelled_at column plus the 14.3.2 recurrence
        // columns and partial unique index); rolling them back must remove
        // exactly those additions while leaving the older financial
        // foundation — including the wallet tables — untouched, and
        // re-running must restore them.
        expect(Schema::hasTable('bill_recurrences'))->toBeTrue();
        expect(Schema::hasColumn('bills', 'recurrence_id'))->toBeTrue();
        expect(Schema::hasColumn('maintenance', 'cancelled_at'))->toBeTrue();
        expect(Schema::hasColumn('vehicles', 'type'))->toBeTrue();
        expect(Schema::hasColumn('vehicles', 'odometer'))->toBeTrue();
        expect(Schema::hasColumn('vehicle_maintenance', 'odometer'))->toBeTrue();

        $this->artisan('migrate:rollback', ['--step' => 5]);

        expect(Schema::hasTable('bill_recurrences'))->toBeFalse();
        expect(Schema::hasColumn('bills', 'recurrence_id'))->toBeFalse();
        expect(Schema::hasColumn('bills', 'recurrence_period'))->toBeFalse();
        expect(Schema::hasColumn('maintenance', 'cancelled_at'))->toBeFalse();
        expect(Schema::hasColumn('vehicles', 'type'))->toBeFalse();
        expect(Schema::hasColumn('vehicles', 'odometer'))->toBeFalse();
        expect(Schema::hasColumn('vehicle_maintenance', 'odometer'))->toBeFalse();
        expect(Schema::hasTable('wallet_adjustments'))->toBeTrue();
        expect(Schema::hasTable('files'))->toBeTrue();

        $this->artisan('migrate');

        expect(Schema::hasTable('bill_recurrences'))->toBeTrue();
        expect(Schema::hasColumn('bills', 'recurrence_id'))->toBeTrue();
        expect(Schema::hasColumn('maintenance', 'cancelled_at'))->toBeTrue();
        expect(Schema::hasColumn('vehicles', 'type'))->toBeTrue();
        expect(Schema::hasColumn('vehicle_maintenance', 'odometer'))->toBeTrue();
        expect(Schema::hasTable('wallet_adjustments'))->toBeTrue();
    });

    test('every model can be created through its factory with ULID keys', function () {
        $household = financeHousehold();
        $category = Category::factory()->for($household)->create();
        $asset = Asset::factory()->for($household)->create();
        $vehicle = Vehicle::factory()->for($household)->create();
        $bill = Bill::factory()->for($household)->for($category)->create();

        $models = [
            $household,
            $category,
            Transaction::factory()->for($household)->for($category)->create(),
            Budget::factory()->for($household)->for($category)->create(),
            $bill,
            ShoppingItem::factory()->for($household)->create(),
            $asset,
            Maintenance::factory()->for($household)->for($asset, 'asset')->create(),
            $vehicle,
            VehicleMaintenance::factory()->for($vehicle)->create(),
            File::factory()->for($household)->for($asset, 'fileable')->create(),
        ];

        foreach ($models as $model) {
            expect($model->exists)->toBeTrue();
            expect($model->id)->toBeString();
            expect(strlen($model->id))->toBe(26);
        }
    });

    test('household exposes every financial relation', function () {
        $household = financeHousehold();
        $category = Category::factory()->for($household)->create();

        Transaction::factory()->for($household)->for($category)->create();
        Budget::factory()->for($household)->for($category)->create();
        Bill::factory()->for($household)->for($category)->create();
        ShoppingItem::factory()->for($household)->create();
        Asset::factory()->for($household)->create();
        Maintenance::factory()->for($household)->create();
        Vehicle::factory()->for($household)->create();
        File::factory()->for($household)->for(Asset::factory()->for($household)->create(), 'fileable')->create();

        $household->refresh();

        expect($household->categories)->toHaveCount(1);
        expect($household->transactions)->toHaveCount(1);
        expect($household->budgets)->toHaveCount(1);
        expect($household->bills)->toHaveCount(1);
        expect($household->shoppingItems)->toHaveCount(1);
        expect($household->assets)->toHaveCount(2);
        expect($household->maintenances)->toHaveCount(1);
        expect($household->vehicles)->toHaveCount(1);
        expect($household->files)->toHaveCount(1);
    });

    test('category, asset, vehicle and morph relations resolve', function () {
        $household = financeHousehold();
        $category = Category::factory()->for($household)->create();
        $asset = Asset::factory()->for($household)->create();
        $vehicle = Vehicle::factory()->for($household)->create();
        $bill = Bill::factory()->for($household)->for($category)->create();

        $transaction = Transaction::factory()
            ->for($household)
            ->for($category)
            ->create(['source_type' => Bill::class, 'source_id' => $bill->id]);
        $maintenance = Maintenance::factory()->for($household)->for($asset, 'asset')->create();
        $vehicleMaintenance = VehicleMaintenance::factory()->for($vehicle)->create();
        $file = File::factory()->for($household)->for($asset, 'fileable')->create();

        expect($transaction->household->is($household))->toBeTrue();
        expect($transaction->category->is($category))->toBeTrue();
        expect($transaction->source->is($bill))->toBeTrue();
        expect($category->transactions)->toHaveCount(1);
        expect($category->bills)->toHaveCount(1);
        expect($maintenance->asset->is($asset))->toBeTrue();
        expect($asset->maintenances)->toHaveCount(1);
        expect($vehicleMaintenance->vehicle->is($vehicle))->toBeTrue();
        expect($vehicle->maintenances)->toHaveCount(1);
        expect($file->fileable->is($asset))->toBeTrue();
    });

    test('unique constraints reject forbidden duplicates', function () {
        $household = financeHousehold();
        $other = financeHousehold('Tetangga');
        $category = Category::factory()->for($household)->create(['name' => 'Dapur']);

        // Same name in the same household is forbidden.
        expect(fn () => Category::factory()->for($household)->create(['name' => 'Dapur']))
            ->toThrow(QueryException::class);

        // Same name in another household is allowed.
        Category::factory()->for($other)->create(['name' => 'Dapur']);
        expect(Category::forHousehold($other)->count())->toBe(1);

        Budget::factory()->for($household)->for($category)->create(['year' => 2026, 'month' => 10]);

        // Same category + period is forbidden.
        expect(fn () => Budget::factory()->for($household)->for($category)->create(['year' => 2026, 'month' => 10]))
            ->toThrow(QueryException::class);

        // Same category in another month is allowed.
        Budget::factory()->for($household)->for($category)->create(['year' => 2026, 'month' => 11]);
        expect(Budget::forHousehold($household)->count())->toBe(2);

        Vehicle::factory()->for($household)->create(['plate_number' => 'B 1234 ABC']);

        // Same plate in the same household is forbidden.
        expect(fn () => Vehicle::factory()->for($household)->create(['plate_number' => 'B 1234 ABC']))
            ->toThrow(QueryException::class);

        // Same plate in another household is allowed.
        Vehicle::factory()->for($other)->create(['plate_number' => 'B 1234 ABC']);

        // Null plates never collide.
        Vehicle::factory()->for($household)->create(['plate_number' => null]);
        Vehicle::factory()->for($household)->create(['plate_number' => null]);
        expect(Vehicle::forHousehold($household)->whereNull('plate_number')->count())->toBe(2);
    });

    test('dangling foreign keys are rejected', function () {
        $household = financeHousehold();
        $category = Category::factory()->for($household)->create();

        $ghost = '01J0000000000000000000000';

        expect(fn () => Transaction::factory()->create(['household_id' => $ghost]))
            ->toThrow(QueryException::class);
        expect(fn () => Transaction::factory()->for($household)->create(['category_id' => $ghost]))
            ->toThrow(QueryException::class);
        expect(fn () => Budget::factory()->for($household)->create(['category_id' => $ghost]))
            ->toThrow(QueryException::class);
        expect(fn () => Bill::factory()->for($household)->create(['category_id' => $ghost]))
            ->toThrow(QueryException::class);
        expect(fn () => Category::factory()->create(['household_id' => $ghost]))
            ->toThrow(QueryException::class);
        expect(fn () => Maintenance::factory()->for($household)->create(['asset_id' => $ghost]))
            ->toThrow(QueryException::class);
        expect(fn () => VehicleMaintenance::factory()->create(['vehicle_id' => $ghost]))
            ->toThrow(QueryException::class);
    });

    test('forHousehold scopes isolate each household', function () {
        $mine = financeHousehold('Milikku');
        $theirs = financeHousehold('Milik Tetangga');

        $myCategory = Category::factory()->for($mine)->create();
        $theirCategory = Category::factory()->for($theirs)->create();

        Transaction::factory()->for($mine)->for($myCategory)->create();
        Transaction::factory()->for($theirs)->for($theirCategory)->create();
        Budget::factory()->for($mine)->for($myCategory)->create();
        Budget::factory()->for($theirs)->for($theirCategory)->create();
        Bill::factory()->for($theirs)->for($theirCategory)->create();
        ShoppingItem::factory()->for($theirs)->create();
        $theirAsset = Asset::factory()->for($theirs)->create();
        Maintenance::factory()->for($theirs)->for($theirAsset, 'asset')->create();
        Vehicle::factory()->for($theirs)->create();
        File::factory()->for($theirs)->for($theirAsset, 'fileable')->create();

        expect(Transaction::forHousehold($mine)->count())->toBe(1);
        expect(Transaction::forHousehold($mine)->first()?->household_id)->toBe($mine->id);
        expect(Budget::forHousehold($mine)->count())->toBe(1);
        expect(Bill::forHousehold($mine)->count())->toBe(0);
        expect(Category::forHousehold($mine)->count())->toBe(1);
        expect(ShoppingItem::forHousehold($mine)->count())->toBe(0);
        expect(Asset::forHousehold($mine)->count())->toBe(0);
        expect(Maintenance::forHousehold($mine)->count())->toBe(0);
        expect(Vehicle::forHousehold($mine)->count())->toBe(0);
        expect(File::forHousehold($mine)->count())->toBe(0);
        expect(Transaction::forHousehold($theirs)->count())->toBe(1);

        // No row ever leaks across the scope boundary.
        foreach (Transaction::forHousehold($mine)->get() as $row) {
            expect($row->household_id)->toBe($mine->id);
        }
    });

    test('amounts keep exact decimal precision and dates keep calendar shape', function () {
        $household = financeHousehold();
        $category = Category::factory()->for($household)->create();

        $transaction = Transaction::factory()
            ->for($household)
            ->for($category)
            ->create(['amount' => '1234567.89', 'occurred_on' => '2026-10-05']);

        $transaction->refresh();

        expect((string) $transaction->amount)->toBe('1234567.89');
        expect($transaction->occurred_on->format('Y-m-d'))->toBe('2026-10-05');

        $budget = Budget::factory()
            ->for($household)
            ->for($category)
            ->create(['year' => 2026, 'month' => 10, 'amount' => '5000000.00']);

        expect((string) $budget->amount)->toBe('5000000.00');
        expect($budget->year)->toBeInt();
        expect($budget->month)->toBeInt();
    });

    test('budgets store targets only, never derived spending', function () {
        $columns = Schema::getColumnListing('budgets');

        expect($columns)->not->toContain('spent');
        expect($columns)->not->toContain('total_spent');
        expect($columns)->not->toContain('remaining');
    });

    test('delete behavior protects financial history', function () {
        $household = financeHousehold();
        $category = Category::factory()->for($household)->create();
        $transaction = Transaction::factory()->for($household)->for($category)->create();
        $budget = Budget::factory()->for($household)->for($category)->create();
        $bill = Bill::factory()->for($household)->for($category)->create();
        $asset = Asset::factory()->for($household)->create();
        $maintenance = Maintenance::factory()->for($household)->for($asset, 'asset')->create();
        $vehicle = Vehicle::factory()->for($household)->create();
        $vehicleMaintenance = VehicleMaintenance::factory()->for($vehicle)->create();

        // A household holding transactions cannot vanish silently.
        expect(fn () => $household->delete())->toThrow(QueryException::class);

        // A category backing a budget cannot be removed.
        expect(fn () => $category->delete())->toThrow(QueryException::class);

        // Deleting the budget first still leaves the transactions protected.
        $budget->delete();
        expect(fn () => $household->delete())->toThrow(QueryException::class);

        // Removing a category detaches (not destroys) transaction and bill history.
        $bill->delete();
        $transaction->delete();
        $category->delete();

        // A household without transactions cascades its dependent rows.
        $household->delete();

        expect(Household::find($household->id))->toBeNull();
        expect(Bill::find($bill->id))->toBeNull();
        expect(ShoppingItem::forHousehold($household)->count())->toBe(0);

        // Asset deletion keeps the maintenance log, detached.
        $household2 = financeHousehold('Kedua');
        $asset2 = Asset::factory()->for($household2)->create();
        $maintenance2 = Maintenance::factory()->for($household2)->for($asset2, 'asset')->create();
        $asset2->delete();

        expect($maintenance2->refresh()->asset_id)->toBeNull();
        expect(Maintenance::find($maintenance2->id))->not->toBeNull();

        // Vehicle deletion removes its dependent service log.
        $household3 = financeHousehold('Ketiga');
        $vehicle3 = Vehicle::factory()->for($household3)->create();
        $vehicleMaintenance3 = VehicleMaintenance::factory()->for($vehicle3)->create();

        expect(VehicleMaintenance::find($vehicleMaintenance3->id))->not->toBeNull();
        $vehicle3->delete();
        expect(VehicleMaintenance::find($vehicleMaintenance3->id))->toBeNull();

        // Maintenance rows created earlier are gone with their household.
        expect(Maintenance::find($maintenance->id))->toBeNull();
    });
});
