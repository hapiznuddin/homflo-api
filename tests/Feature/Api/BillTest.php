<?php

use App\Models\Bill;
use App\Models\BillRecurrence;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function blHousehold(User $owner, string $name = 'Rumah Bill', string $role = 'owner'): Household
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

function blCategory(Household $household, string $name = 'Tagihan', ?string $type = 'expense'): Category
{
    return Category::factory()->for($household)->create(['name' => $name, 'type' => $type]);
}

function blBill(Household $household, Category $category, array $overrides = []): Bill
{
    return Bill::factory()
        ->for($household)
        ->for($category)
        ->create(array_merge(['due_date' => '2026-12-01'], $overrides));
}

/**
 * Payment fixture: an expense transaction whose source morph points at
 * the bill — the only payment representation (no bill_payments table).
 */
function blPay(Household $household, Bill $bill, array $overrides = []): Transaction
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

function blPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Internet Rumah',
        'amount' => 150000,
        'due_date' => '2026-12-05',
    ], $overrides);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('Bills API authentication', function () {
    test('guest cannot access protected bill endpoints', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));

        $this->getJson('/api/bills')->assertStatus(401);
        $this->postJson('/api/bills', blPayload())->assertStatus(401);
        $this->getJson("/api/bills/{$bill->id}")->assertStatus(401);
        $this->patchJson("/api/bills/{$bill->id}", ['amount' => 1])->assertStatus(401);
        $this->deleteJson("/api/bills/{$bill->id}")->assertStatus(401);
        $this->postJson("/api/bills/{$bill->id}/archive")->assertStatus(401);
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/bills')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/bills', blPayload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Bills API authorization and household isolation', function () {
    test('member reads but cannot write the shared bills', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));

        $memberUser = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $memberUser->id,
            'role' => 'member',
        ]);

        $this->actingAs($memberUser)->getJson('/api/bills')->assertOk();
        $this->actingAs($memberUser)->getJson("/api/bills/{$bill->id}")->assertOk();

        // Finance writes are owner-only: creating a bill in a household
        // the user does not own surfaces as HOUSEHOLD_NOT_FOUND (same
        // masking as budgets); mutating an existing bill surfaces as
        // BILL_NOT_FOUND so bill ids stay unprobeable.
        $this->actingAs($memberUser)->postJson('/api/bills', blPayload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($memberUser)->patchJson("/api/bills/{$bill->id}", ['amount' => 1])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        $this->actingAs($memberUser)->deleteJson("/api/bills/{$bill->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        $this->actingAs($memberUser)->postJson("/api/bills/{$bill->id}/archive")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');
    });

    test('cross-household bill access never leaks existence', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));

        $neighbor = User::factory()->create();
        blHousehold($neighbor, 'Tetangga');

        $this->actingAs($neighbor)->getJson("/api/bills/{$bill->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        $this->actingAs($neighbor)->patchJson("/api/bills/{$bill->id}", ['amount' => 1])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        $this->actingAs($neighbor)->deleteJson("/api/bills/{$bill->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        $this->actingAs($neighbor)->postJson("/api/bills/{$bill->id}/archive")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');
    });

    test('unverified owner can read but cannot mutate bills', function () {
        $owner = User::factory()->unverified()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));

        $this->actingAs($owner)->getJson('/api/bills')->assertOk();
        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")->assertOk();

        $this->actingAs($owner)->postJson('/api/bills', blPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", ['amount' => 1])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/bills/{$bill->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->postJson("/api/bills/{$bill->id}/archive")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });
});

describe('Bills API CRUD and lifecycle', function () {
    test('owner creates a one-time bill with derived payment defaults', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $category = blCategory($household);

        $response = $this->actingAs($owner)->postJson('/api/bills', blPayload([
            'category_id' => $category->id,
            'notes' => 'Tagihan bulanan',
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Internet Rumah')
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.due_date', '2026-12-05')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.notes', 'Tagihan bulanan')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.recurrence', null)
            ->assertJsonPath('data.recurrence_period', null)
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.remaining_amount', '150000.00')
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.overdue', false);

        expect(Bill::count())->toBe(1);
        expect(BillRecurrence::count())->toBe(0);
    });

    test('owner edits a one-time bill with decimal string amounts', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));

        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", [
            'title' => 'Listrik',
            'amount' => 250750.5,
            'due_date' => '2026-11-20',
            'notes' => 'Direvisi',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Listrik')
            ->assertJsonPath('data.amount', '250750.50')
            ->assertJsonPath('data.due_date', '2026-11-20')
            ->assertJsonPath('data.notes', 'Direvisi')
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.remaining_amount', '250750.50');

        expect($bill->refresh()->amount)->toBe('250750.50');
    });

    test('list sorts due dates null-last and hides archived unless requested', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $category = blCategory($household);

        $late = blBill($household, $category, ['title' => 'Zona', 'due_date' => '2026-12-31']);
        $early = blBill($household, $category, ['title' => 'Awal', 'due_date' => '2026-10-01']);
        $noDate = blBill($household, $category, ['title' => 'TanpaTanggal', 'due_date' => null]);
        $archived = blBill($household, $category, ['title' => 'Diarsip', 'due_date' => '2026-09-01', 'is_active' => false]);

        $list = $this->actingAs($owner)->getJson('/api/bills');

        $list->assertOk()->assertJsonPath('success', true);
        expect(collect($list->json('data'))->pluck('id')->all())->toBe([$early->id, $late->id, $noDate->id]);
        expect($list->getContent())->not->toContain('Diarsip');

        $withArchived = $this->actingAs($owner)->getJson('/api/bills?include_archived=true');

        $withArchived->assertOk();
        expect($withArchived->json('data'))->toHaveCount(4);
        expect(collect($withArchived->json('data'))->pluck('id')->all())
            ->toBe([$archived->id, $early->id, $late->id, $noDate->id]);

        $this->actingAs($owner)->getJson('/api/bills?include_archived=maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['include_archived']);
    });

    test('paid bill cannot be edited or deleted', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));
        $payment = blPay($household, $bill);

        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", ['title' => 'Baru'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_ALREADY_PAID');

        // Even an empty body is refused: lifecycle guards never mutate.
        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_ALREADY_PAID');

        $this->actingAs($owner)->deleteJson("/api/bills/{$bill->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_ALREADY_PAID');

        expect(Bill::count())->toBe(1);
        expect(Transaction::count())->toBe(1);
        expect($payment->refresh()->deleted_at)->toBeNull();
        expect($bill->refresh()->title)->not->toBe('Baru');
    });

    test('paid bill archives once and stays archived; unpaid cannot archive', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $category = blCategory($household);
        $paid = blBill($household, $category);
        $unpaid = blBill($household, $category, ['title' => 'BelumBayar']);
        blPay($household, $paid);

        $this->actingAs($owner)->postJson("/api/bills/{$paid->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.id', $paid->id)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'paid');

        // Idempotent: archiving an archived bill is a no-op success.
        $this->actingAs($owner)->postJson("/api/bills/{$paid->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect($paid->refresh()->is_active)->toBeFalse();

        $this->actingAs($owner)->postJson("/api/bills/{$unpaid->id}/archive")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_NOT_PAID');

        expect($unpaid->refresh()->is_active)->toBeTrue();
    });

    test('archive keeps transactions and payment aggregates untouched', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $category = blCategory($household);
        $bill = blBill($household, $category, ['amount' => 150000]);
        blPay($household, $bill, ['amount' => '150000.00']);

        $sumBefore = Transaction::query()
            ->where('household_id', $household->id)
            ->where('source_type', Bill::class)
            ->where('source_id', $bill->id)
            ->sum('amount');

        $this->actingAs($owner)->postJson("/api/bills/{$bill->id}/archive")->assertOk();

        $sumAfter = Transaction::query()
            ->where('household_id', $household->id)
            ->where('source_type', Bill::class)
            ->where('source_id', $bill->id)
            ->sum('amount');

        expect(Transaction::count())->toBe(1);
        expect((string) $sumAfter)->toBe((string) $sumBefore);

        // Archived bills leave the default list but stay readable and
        // keep their derived fields on the detail endpoint.
        expect($this->actingAs($owner)->getJson('/api/bills')->json('data'))->toHaveCount(0);

        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.paid_amount', '150000.00')
            ->assertJsonPath('data.remaining_amount', '0.00')
            ->assertJsonPath('data.status', 'paid');
    });

    test('bill detail lists payment history newest first', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household), ['amount' => 200000]);
        blPay($household, $bill, ['amount' => '150000.00', 'occurred_on' => '2026-10-05']);
        blPay($household, $bill, ['amount' => '50000.00', 'occurred_on' => '2026-11-05']);

        $response = $this->actingAs($owner)->getJson("/api/bills/{$bill->id}");

        $response->assertOk()
            ->assertJsonPath('data.payment_count', 2)
            ->assertJsonPath('data.paid_amount', '200000.00')
            ->assertJsonPath('data.remaining_amount', '0.00')
            ->assertJsonPath('data.status', 'paid');

        $payments = $response->json('data.payments');

        expect($payments)->toHaveCount(2);
        expect($payments[0]['amount'])->toBe('50000.00');
        expect($payments[0]['occurred_on'])->toBe('2026-11-05');
        expect($payments[1]['amount'])->toBe('150000.00');
        expect($payments[1]['occurred_on'])->toBe('2026-10-05');
    });

    test('overdue reflects unpaid past due dates only', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $category = blCategory($household);

        $overdue = blBill($household, $category, ['title' => 'Terlambat', 'due_date' => '2026-10-18']);
        $dueToday = blBill($household, $category, ['title' => 'HariIni', 'due_date' => '2026-10-19']);
        $future = blBill($household, $category, ['title' => 'Nanti', 'due_date' => '2026-10-20']);
        $paidPast = blBill($household, $category, ['title' => 'LunasLampau', 'due_date' => '2026-01-05']);
        blPay($household, $paidPast);

        $byTitle = collect($this->actingAs($owner)->getJson('/api/bills')
            ->json('data'))
            ->keyBy('title');

        expect($byTitle['Terlambat']['overdue'])->toBeTrue();
        expect($byTitle['HariIni']['overdue'])->toBeFalse();
        expect($byTitle['Nanti']['overdue'])->toBeFalse();
        expect($byTitle['LunasLampau']['overdue'])->toBeFalse();
    });

    test('category rules stay fail-closed for non-expense and foreign categories', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $income = blCategory($household, 'Gaji', 'income');
        $legacy = blCategory($household, 'Legacy', null);

        $this->actingAs($owner)->postJson('/api/bills', blPayload(['category_id' => $income->id]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        $this->actingAs($owner)->postJson('/api/bills', blPayload(['category_id' => $legacy->id]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        $neighbor = User::factory()->create();
        $neighborHousehold = blHousehold($neighbor, 'Tetangga');
        $foreign = blCategory($neighborHousehold, 'Asing');

        $this->actingAs($owner)->postJson('/api/bills', blPayload(['category_id' => $foreign->id]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $this->actingAs($owner)->postJson('/api/bills', blPayload(['category_id' => (string) Str::ulid()]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        // Null is a valid explicit choice: the bill carries no category.
        $this->actingAs($owner)->postJson('/api/bills', blPayload(['category_id' => null]))
            ->assertStatus(201)
            ->assertJsonPath('data.category', null);

        // Legacy untyped categories are also rejected on bill edits.
        $bill = blBill($household, blCategory($household));

        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", ['category_id' => $legacy->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');
    });

    test('patch prohibits is_active and recurrence keys', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));

        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);

        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", ['recurrence' => ['frequency' => 'monthly']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recurrence']);

        expect($bill->refresh()->is_active)->toBeTrue();
    });

    test('money always serializes as two-decimal strings', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $category = blCategory($household);
        blBill($household, $category, ['amount' => 999.9, 'due_date' => '2026-12-01']);
        blBill($household, $category, ['amount' => 1000, 'due_date' => '2026-12-02']);
        $paidBill = blBill($household, $category, ['amount' => 1234567.89, 'due_date' => '2026-12-03']);
        blPay($household, $paidBill, ['amount' => '234567.89']);

        $amounts = collect($this->actingAs($owner)->getJson('/api/bills')->json('data'))->keyBy('id');

        foreach ($amounts as $amount) {
            expect($amount['amount'])->toMatch('/^\d+\.\d{2}$/');
            expect($amount['paid_amount'])->toMatch('/^\d+\.\d{2}$/');
            expect($amount['remaining_amount'])->toMatch('/^\d+\.\d{2}$/');
        }

        expect($amounts[$paidBill->id]['paid_amount'])->toBe('234567.89');
        expect($amounts[$paidBill->id]['remaining_amount'])->toBe('1000000.00');
    });

    test('legacy bill without recurrence stays readable and manageable', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household), ['title' => 'Legacy']);

        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Legacy')
            ->assertJsonPath('data.recurrence', null)
            ->assertJsonPath('data.recurrence_period', null);

        $this->actingAs($owner)->patchJson("/api/bills/{$bill->id}", ['title' => 'Legacy Edit'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Legacy Edit');

        $this->actingAs($owner)->deleteJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $bill->id);

        expect(Bill::count())->toBe(0);
    });

    test('reads never write or materialize', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $bill = blBill($household, blCategory($household));
        blPay($household, $bill);

        $billsBefore = DB::table('bills')->count();
        $transactionsBefore = DB::table('transactions')->count();
        $recurrencesBefore = DB::table('bill_recurrences')->count();

        $this->actingAs($owner)->getJson('/api/bills')->assertOk();
        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")->assertOk();

        expect(DB::table('bills')->count())->toBe($billsBefore);
        expect(DB::table('transactions')->count())->toBe($transactionsBefore);
        expect(DB::table('bill_recurrences')->count())->toBe($recurrencesBefore);
    });
});

describe('Bills API query efficiency', function () {
    test('listing issues a constant number of queries regardless of bill count', function () {
        $owner = User::factory()->create();
        $household = blHousehold($owner);
        $category = blCategory($household);

        for ($i = 0; $i < 3; $i++) {
            blBill($household, $category, ['due_date' => sprintf('2026-11-%02d', $i + 1)]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $small = $this->actingAs($owner)->getJson('/api/bills');
        $smallQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 0; $i < 22; $i++) {
            blBill($household, $category, ['due_date' => sprintf('2025-%02d-05', ($i % 12) + 1)]);
        }

        DB::enableQueryLog();
        $large = $this->actingAs($owner)->getJson('/api/bills');
        $largeQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $small->assertOk();
        $large->assertOk();
        expect($small->json('data'))->toHaveCount(3);
        expect($large->json('data'))->toHaveCount(25);
        expect($largeQueries)->toBe($smallQueries);
    });
});
