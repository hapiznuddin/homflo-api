<?php

use App\Models\Bill;
use App\Models\BillRecurrence;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\BillRepository;
use App\Repositories\Contracts\BillRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function blrHousehold(User $owner, string $name = 'Rumah Bill recurrence'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $owner->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);

    return $household->refresh();
}

function blrCategory(Household $household, string $name = 'Sewa', ?string $type = 'expense'): Category
{
    return Category::factory()->for($household)->create(['name' => $name, 'type' => $type]);
}

function blrPay(Household $household, Bill $bill, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'household_id' => $household->id,
        'category_id' => $bill->category_id,
        'type' => Transaction::TYPE_EXPENSE,
        'amount' => (string) $bill->amount,
        'occurred_on' => '2026-10-10',
        'source_type' => Bill::class,
        'source_id' => $bill->id,
        'wallet_id' => null,
    ], $overrides));
}

function blrStorePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Sewa Rumah',
        'amount' => 150000,
        'category_id' => null,
        'due_date' => '2027-03-15',
        'recurrence' => ['frequency' => 'monthly'],
    ], $overrides);
}

/**
 * Materialized period bills of one household, earliest period first.
 *
 * @return Collection<int, Bill>
 */
function blrPeriodBills(Household $household)
{
    return Bill::query()
        ->where('household_id', $household->id)
        ->orderBy('recurrence_period')
        ->get();
}

function blrCursor(Household $household): ?string
{
    $cursor = DB::table('bill_recurrences')
        ->where('household_id', $household->id)
        ->value('materialized_until');

    return $cursor === null ? null : substr((string) $cursor, 0, 10);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('Bill recurrence calendar rules', function () {
    test('monthly frequency clamps due dates to short months including leap February', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);
        $category = blrCategory($household);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'category_id' => $category->id,
            'due_date' => '2028-01-31',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.due_date', '2028-01-31')
            ->assertJsonPath('data.recurrence_period', '2028-01-01')
            ->assertJsonPath('data.recurrence.frequency', 'monthly')
            ->assertJsonPath('data.recurrence.due_day', 31)
            ->assertJsonPath('data.recurrence.is_active', true)
            ->assertJsonPath('data.status', 'unpaid');

        $bills = blrPeriodBills($household);

        expect($bills)->toHaveCount(12);
        expect($bills->pluck('recurrence_period')->map(fn ($period) => $period->format('Y-m-01'))->all())
            ->toBe([
                '2028-01-01', '2028-02-01', '2028-03-01', '2028-04-01',
                '2028-05-01', '2028-06-01', '2028-07-01', '2028-08-01',
                '2028-09-01', '2028-10-01', '2028-11-01', '2028-12-01',
            ]);
        expect($bills->pluck('due_date')->map(fn ($date) => $date->format('Y-m-d'))->all())
            ->toBe([
                '2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30',
                '2028-05-31', '2028-06-30', '2028-07-31', '2028-08-31',
                '2028-09-30', '2028-10-31', '2028-11-30', '2028-12-31',
            ]);

        // Invariant: a period's due date always sits in its own month.
        foreach ($bills as $bill) {
            expect(substr($bill->due_date->format('Y-m-d'), 0, 7))
                ->toBe($bill->recurrence_period->format('Y-m'));
            expect((string) $bill->amount)->toBe('150000.00');
        }
    });

    test('quarterly frequency advances three months per period', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2027-01-31',
            'recurrence' => ['frequency' => 'quarterly'],
        ]))->assertStatus(201);

        $bills = blrPeriodBills($household);

        expect($bills)->toHaveCount(12);
        expect($bills->pluck('recurrence_period')->map(fn ($period) => $period->format('Y-m-01'))->all())
            ->toBe([
                '2027-01-01', '2027-04-01', '2027-07-01', '2027-10-01',
                '2028-01-01', '2028-04-01', '2028-07-01', '2028-10-01',
                '2029-01-01', '2029-04-01', '2029-07-01', '2029-10-01',
            ]);
        expect($bills[1]->due_date->format('Y-m-d'))->toBe('2027-04-30');
        expect($bills->pluck('due_date')->map(fn ($date) => $date->format('Y-m-d'))->all())
            ->toBe([
                '2027-01-31', '2027-04-30', '2027-07-31', '2027-10-31',
                '2028-01-31', '2028-04-30', '2028-07-31', '2028-10-31',
                '2029-01-31', '2029-04-30', '2029-07-31', '2029-10-31',
            ]);
    });

    test('semiannual frequency advances six months per period', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2027-08-31',
            'recurrence' => ['frequency' => 'semiannual'],
        ]))->assertStatus(201);

        $bills = blrPeriodBills($household);

        expect($bills)->toHaveCount(12);
        expect($bills->pluck('recurrence_period')->map(fn ($period) => $period->format('Y-m-01'))->all())
            ->toBe([
                '2027-08-01', '2028-02-01', '2028-08-01', '2029-02-01',
                '2029-08-01', '2030-02-01', '2030-08-01', '2031-02-01',
                '2031-08-01', '2032-02-01', '2032-08-01', '2033-02-01',
            ]);
        expect($bills[1]->due_date->format('Y-m-d'))->toBe('2028-02-29');
    });

    test('yearly frequency advances twelve months per period', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2027-03-31',
            'recurrence' => ['frequency' => 'yearly'],
        ]))->assertStatus(201);

        $bills = blrPeriodBills($household);

        expect($bills)->toHaveCount(12);
        expect($bills->pluck('recurrence_period')->map(fn ($period) => $period->format('Y-m-01'))->all())
            ->toBe([
                '2027-03-01', '2028-03-01', '2029-03-01', '2030-03-01',
                '2031-03-01', '2032-03-01', '2033-03-01', '2034-03-01',
                '2035-03-01', '2036-03-01', '2037-03-01', '2038-03-01',
            ]);
        expect($bills[1]->due_date->format('Y-m-d'))->toBe('2028-03-31');
        expect($bills[3]->due_date->format('Y-m-d'))->toBe('2030-03-31');
    });
});

describe('Bill recurrence horizon and idempotency', function () {
    test('horizon stays at twelve periods across owner writes', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);
        $category = blrCategory($household);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'category_id' => $category->id,
            'due_date' => '2028-03-15',
        ]))->assertStatus(201);

        expect(blrPeriodBills($household))->toHaveCount(12);

        // Every trigger kind re-runs materialization without duplicating.
        $first = blrPeriodBills($household)->first();
        $this->actingAs($owner)->patchJson("/api/bills/{$first->id}", ['notes' => 'catatan'])->assertOk();

        $this->actingAs($owner)->postJson('/api/bills', [
            'title' => 'Satu Kali',
            'amount' => 25000,
            'due_date' => '2028-05-01',
            'category_id' => $category->id,
        ])->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');
        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['notes' => 'catatan jadwal'])
            ->assertOk();

        expect(blrPeriodBills($household)->whereNotNull('recurrence_id'))->toHaveCount(12);
        expect(Bill::count())->toBe(13);
    });

    test('reads never materialize or write', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload())
            ->assertStatus(201);

        // Move close to the horizon: a write would extend, reads must not.
        Carbon::setTestNow('2028-02-15 12:00:00');

        $billsBefore = DB::table('bills')->count();
        $cursorBefore = blrCursor($household);

        $this->actingAs($owner)->getJson('/api/bills')->assertOk();
        $this->actingAs($owner)->getJson('/api/bills?include_archived=true')->assertOk();

        $first = blrPeriodBills($household)->first();
        $this->actingAs($owner)->getJson("/api/bills/{$first->id}")->assertOk();

        expect(DB::table('bills')->count())->toBe($billsBefore);
        expect(blrCursor($household))->toBe($cursorBefore);
    });

    test('the partial unique index blocks duplicate periods and keeps one-time rows unlimited', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload())
            ->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');

        // Read the stored representation of an existing period so the
        // duplicate insert collides regardless of the driver's date
        // serialization (SQLite keeps ' 00:00:00', PostgreSQL truncates).
        $existingPeriod = DB::table('bills')
            ->where('recurrence_id', $recurrenceId)
            ->value('recurrence_period');

        expect(fn () => DB::table('bills')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $household->id,
            'title' => 'Duplikat Periode',
            'amount' => '1000.00',
            'is_active' => true,
            'recurrence_id' => $recurrenceId,
            'recurrence_period' => $existingPeriod,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        // NULL recurrence keys stay distinct in both engines: any number
        // of one-time bills may share (NULL, NULL).
        foreach (['2030-01-01', '2030-01-02', '2030-01-03'] as $dueDate) {
            DB::table('bills')->insert([
                'id' => (string) Str::ulid(),
                'household_id' => $household->id,
                'title' => 'Satu Kali',
                'amount' => '1000.00',
                'due_date' => $dueDate,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(DB::table('bills')->whereNull('recurrence_id')->count())->toBe(3);
        expect(DB::table('bills')->whereNotNull('recurrence_id')->count())->toBe(12);
    });
});

describe('Bill recurrence backfill and permanent skip', function () {
    test('missed periods backfill from the cursor and may be overdue', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2026-01-15',
        ]))->assertStatus(201);

        // Anchor in the past: 12 seeded periods (Jan-Des 2026) plus the
        // sweep extension because the cursor (Des 2026) sits only two
        // months from the business month (Okt 2026) < 3 -> +12 periods.
        $bills = blrPeriodBills($household);

        expect($bills)->toHaveCount(24);
        expect($bills->first()->recurrence_period->format('Y-m-01'))->toBe('2026-01-01');
        expect($bills->last()->recurrence_period->format('Y-m-01'))->toBe('2027-12-01');
        expect(blrCursor($household))->toBe('2027-12-01');

        $byPeriod = collect($this->actingAs($owner)->getJson('/api/bills')->json('data'))
            ->keyBy('recurrence_period');

        expect($byPeriod['2026-01-01']['due_date'])->toBe('2026-01-15');
        expect($byPeriod['2026-01-01']['overdue'])->toBeTrue();
        expect($byPeriod['2026-09-01']['overdue'])->toBeTrue();
        expect($byPeriod['2026-10-01']['overdue'])->toBeTrue();
        expect($byPeriod['2026-11-01']['overdue'])->toBeFalse();
        expect($byPeriod['2027-12-01']['overdue'])->toBeFalse();
    });

    test('deleting an unpaid period is a permanent skip the cursor never rewinds', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload())
            ->assertStatus(201);

        $cursorBefore = blrCursor($household);
        $skipped = blrPeriodBills($household)[2];

        $this->actingAs($owner)->deleteJson("/api/bills/{$skipped->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $skipped->id);

        expect(DB::table('bills')->count())->toBe(11);

        // The next owner write sweeps again: the deleted period must not
        // reappear and the cursor must not move backwards.
        $this->actingAs($owner)->postJson('/api/bills', [
            'title' => 'Satu Kali',
            'amount' => 25000,
            'due_date' => '2026-11-05',
        ])->assertStatus(201);

        expect(blrCursor($household))->toBe($cursorBefore);
        expect(DB::table('bills')->where('id', $skipped->id)->exists())->toBeFalse();
        expect(DB::table('bills')->whereNotNull('recurrence_id')->count())->toBe(11);
    });

    test('disabling freezes materialization and re-enabling backfills forward', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2027-03-15',
        ]))->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // A disabled schedule never materializes — even close to the
        // horizon — and its existing periods stay readable.
        Carbon::setTestNow('2028-01-15 12:00:00');

        $this->actingAs($owner)->postJson('/api/bills', [
            'title' => 'Satu Kali',
            'amount' => 25000,
            'due_date' => '2028-01-20',
        ])->assertStatus(201);

        expect(blrPeriodBills($household)->whereNotNull('recurrence_id'))->toHaveCount(12);
        expect(blrCursor($household))->toBe('2028-02-01');

        // Re-enabling backfills the missed months from the cursor (D-10).
        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $periods = blrPeriodBills($household)->whereNotNull('recurrence_id')
            ->pluck('recurrence_period')->map(fn ($period) => $period->format('Y-m-01'))->all();

        expect($periods)->toHaveCount(24);
        expect(array_slice($periods, 12))->toBe([
            '2028-03-01', '2028-04-01', '2028-05-01', '2028-06-01',
            '2028-07-01', '2028-08-01', '2028-09-01', '2028-10-01',
            '2028-11-01', '2028-12-01', '2029-01-01', '2029-02-01',
        ]);
        expect(blrCursor($household))->toBe('2029-02-01');
    });

    test('materialization failure rolls back the whole recurrence creation', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);
        $category = blrCategory($household);

        $failing = new class extends BillRepository
        {
            private int $periodInserts = 0;

            public function create(array $attributes): Bill
            {
                if (++$this->periodInserts === 6) {
                    throw new RuntimeException('materialization gagal');
                }

                return parent::create($attributes);
            }
        };

        app()->instance(BillRepositoryInterface::class, $failing);
        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'category_id' => $category->id,
        ])))->toThrow(RuntimeException::class, 'materialization gagal');

        expect(Bill::count())->toBe(0);
        expect(BillRecurrence::count())->toBe(0);
    });
});

describe('Bill recurrence schedule changes', function () {
    test('frequency changes are forward-only and never touch existing bills', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2027-01-15',
            'recurrence' => ['frequency' => 'quarterly'],
        ]))->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');
        $paid = blrPeriodBills($household)->first();
        blrPay($household, $paid);

        $snapshot = blrPeriodBills($household)->mapWithKeys(fn (Bill $bill) => [
            $bill->id => [
                $bill->recurrence_period->format('Y-m-01'),
                $bill->due_date->format('Y-m-d'),
                (string) $bill->amount,
                $bill->title,
            ],
        ]);

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['frequency' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('data.frequency', 'monthly');

        // Existing bills, their dates, amounts, and the cursor all stay.
        expect(blrCursor($household))->toBe('2029-10-01');
        expect(blrPeriodBills($household)->mapWithKeys(fn (Bill $bill) => [
            $bill->id => [
                $bill->recurrence_period->format('Y-m-01'),
                $bill->due_date->format('Y-m-d'),
                (string) $bill->amount,
                $bill->title,
            ],
        ]))->toEqual($snapshot);

        // Close to the horizon under the NEW frequency: periods continue
        // monthly straight from the cursor, quarterly history untouched.
        Carbon::setTestNow('2029-09-15 12:00:00');

        $lastQuarterly = blrPeriodBills($household)->last();
        $this->actingAs($owner)->patchJson("/api/bills/{$lastQuarterly->id}", ['notes' => 'dorong sweep'])
            ->assertOk();

        $newPeriods = blrPeriodBills($household)
            ->filter(fn (Bill $bill) => $bill->recurrence_period->format('Y-m-01') > '2029-10-01')
            ->values();

        expect($newPeriods)->toHaveCount(12);
        expect($newPeriods->pluck('recurrence_period')->map(fn ($period) => $period->format('Y-m-01'))->all())
            ->toBe([
                '2029-11-01', '2029-12-01', '2030-01-01', '2030-02-01',
                '2030-03-01', '2030-04-01', '2030-05-01', '2030-06-01',
                '2030-07-01', '2030-08-01', '2030-09-01', '2030-10-01',
            ]);
        expect($newPeriods->pluck('due_date')->map(fn ($date) => $date->format('d'))->unique()->all())
            ->toBe(['15']);
        expect($paid->refresh()->amount)->toBe('150000.00');
    });

    test('schedule edits touch only unpaid and unarchived periods', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);
        $category = blrCategory($household);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'category_id' => $category->id,
            'due_date' => '2027-03-15',
        ]))->assertStatus(201);

        $bills = blrPeriodBills($household);
        $paid = $bills[0];
        $archived = $bills[1];
        $overdueUnpaid = $bills[2];
        $futureUnpaid = $bills[3];

        blrPay($household, $paid);
        blrPay($household, $archived);
        $this->actingAs($owner)->postJson("/api/bills/{$archived->id}/archive")->assertOk();

        // Local overrides on the unpaid bills: template edits overwrite
        // them because the schedule itself changed (D-03).
        $this->actingAs($owner)->patchJson("/api/bills/{$overdueUnpaid->id}", ['amount' => 999999])
            ->assertOk();
        $this->actingAs($owner)->patchJson("/api/bills/{$futureUnpaid->id}", ['title' => 'Lokal'])
            ->assertOk();

        $newCategory = blrCategory($household, 'Kategori Baru');

        $paidSnapshot = collect($paid->fresh()->toArray())->except(['updated_at']);
        $archivedSnapshot = collect($archived->fresh()->toArray())->except(['updated_at']);

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$paid->recurrence_id}", [
            'title' => 'Sewa Baru',
            'amount' => 750000,
            'due_day' => 20,
            'category_id' => $newCategory->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Sewa Baru')
            ->assertJsonPath('data.amount', '750000.00')
            ->assertJsonPath('data.due_day', 20);

        // Paid and archived periods keep every field untouched.
        expect(collect($paid->fresh()->toArray())->except(['updated_at']))->toEqual($paidSnapshot);
        expect(collect($archived->fresh()->toArray())->except(['updated_at']))->toEqual($archivedSnapshot);

        // Overdue unpaid and future unpaid periods receive the template
        // and the re-clamped due date within their own period month.
        foreach ([$overdueUnpaid, $futureUnpaid] as $bill) {
            $bill->refresh();
            expect($bill->title)->toBe('Sewa Baru');
            expect((string) $bill->amount)->toBe('750000.00');
            expect($bill->category_id)->toBe($newCategory->id);
        }
        expect($overdueUnpaid->refresh()->due_date->format('Y-m-d'))->toBe('2027-05-20');
        expect($futureUnpaid->refresh()->due_date->format('Y-m-d'))->toBe('2027-06-20');
    });

    test('an unchanged template field never overwrites local overrides', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2027-03-15',
        ]))->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');
        $target = blrPeriodBills($household)[4];

        $this->actingAs($owner)->patchJson("/api/bills/{$target->id}", [
            'title' => 'Judul Lokal',
            'amount' => 888888,
        ])->assertOk();

        // Re-send the schedule's current values: nothing actually
        // changes, so local overrides must survive.
        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", [
            'title' => 'Sewa Rumah',
            'amount' => 150000,
        ])->assertOk();

        $target->refresh();
        expect($target->title)->toBe('Judul Lokal');
        expect((string) $target->amount)->toBe('888888.00');
    });

    test('recurrence category changes stay expense-only fail-closed', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload())
            ->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');
        $income = blrCategory($household, 'Gaji', 'income');
        $legacy = blrCategory($household, 'Legacy', null);

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['category_id' => $income->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['category_id' => $legacy->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        $valid = blrCategory($household, 'Layanan');
        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['category_id' => $valid->id])
            ->assertOk()
            ->assertJsonPath('data.category.id', $valid->id);

        // The template change propagates to unpaid periods.
        expect(blrPeriodBills($household)->pluck('category_id')->unique()->all())
            ->toBe([$valid->id]);
    });

    test('archiving a bill never stops its schedule', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => '2027-03-15',
        ]))->assertStatus(201);

        $first = blrPeriodBills($household)->first();
        blrPay($household, $first);
        $this->actingAs($owner)->postJson("/api/bills/{$first->id}/archive")->assertOk();

        // Trigger more writes: the schedule stays active and stable.
        $this->actingAs($owner)->postJson('/api/bills', [
            'title' => 'Satu Kali',
            'amount' => 25000,
            'due_date' => '2026-11-01',
        ])->assertStatus(201);

        expect(DB::table('bill_recurrences')->value('is_active'))->toBe(1);
        expect(DB::table('bills')->whereNotNull('recurrence_id')->count())->toBe(12);
        expect($first->refresh()->is_active)->toBeFalse();
    });
});

describe('Bill recurrence endpoint validation and masking', function () {
    test('schedule creation validates frequency and requires due date', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'recurrence' => ['frequency' => 'weekly'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recurrence.frequency']);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload([
            'due_date' => 'not-a-date',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);

        expect(BillRecurrence::count())->toBe(0);
    });

    test('schedule endpoint validates due day and frequency bounds', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload())
            ->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['due_day' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_day']);

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['due_day' => 32])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_day']);

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['frequency' => 'weekly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['frequency']);

        // Empty body is a valid no-op.
        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$recurrenceId}", [])
            ->assertOk()
            ->assertJsonPath('data.id', $recurrenceId);
    });

    test('schedule endpoint masks membership and household boundaries', function () {
        $owner = User::factory()->create();
        $household = blrHousehold($owner);

        $this->actingAs($owner)->postJson('/api/bills', blrStorePayload())
            ->assertStatus(201);

        $recurrenceId = DB::table('bill_recurrences')->value('id');

        $memberUser = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $memberUser->id,
            'role' => 'member',
        ]);

        $this->actingAs($memberUser)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['title' => 'Bobol'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        $neighbor = User::factory()->create();
        blrHousehold($neighbor, 'Tetangga');

        $this->actingAs($neighbor)->patchJson("/api/bills/recurrences/{$recurrenceId}", ['title' => 'Bobol'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        // The unguessable id of another household masks as not found.
        $foreignOwner = User::factory()->create();
        $foreignHousehold = blrHousehold($foreignOwner, 'Asing');
        $this->actingAs($foreignOwner)->postJson('/api/bills', blrStorePayload())
            ->assertStatus(201);
        $foreignRecurrenceId = DB::table('bill_recurrences')
            ->where('household_id', $foreignHousehold->id)
            ->value('id');

        $this->actingAs($owner)->patchJson("/api/bills/recurrences/{$foreignRecurrenceId}", ['title' => 'Bobol'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        expect(DB::table('bill_recurrences')->value('title'))->not->toBe('Bobol');
    });
});
