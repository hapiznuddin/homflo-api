<?php

use App\Models\Bill;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;

function bpHousehold(User $owner, string $name = 'Rumah Bayar Tagihan', string $role = 'owner'): Household
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

function bpCategory(Household $household, string $name = 'Tagihan', string $type = Category::TYPE_EXPENSE): Category
{
    return Category::factory()->for($household)->create(['name' => $name, 'type' => $type]);
}

function bpBill(Household $household, ?Category $category, array $overrides = []): Bill
{
    return Bill::factory()->for($household)->create(array_merge([
        'category_id' => $category?->id,
        'title' => 'Internet Rumah',
        'amount' => '150000.00',
        'due_date' => '2026-12-05',
        'is_active' => true,
    ], $overrides));
}

function bpWallet(Household $household, array $overrides = []): Wallet
{
    return Wallet::factory()->for($household)->create(array_merge([
        'opening_balance' => '1000000.00',
        'is_active' => true,
    ], $overrides));
}

function bpPayload(Wallet $wallet, array $overrides = []): array
{
    return array_merge(['wallet_id' => $wallet->id], $overrides);
}

/**
 * Payment row fixture: expense transaction whose source morph points at
 * the bill (same shape the pay endpoint writes).
 */
function bpPay(Household $household, Bill $bill, array $overrides = []): Transaction
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

function bpPaymentKeys(): array
{
    return [
        'id',
        'household_id',
        'type',
        'amount',
        'occurred_on',
        'description',
        'wallet',
        'wallet_id',
        'destination_wallet_id',
        'source_wallet',
        'destination_wallet',
        'category',
        'bill_payment',
        'created_at',
        'updated_at',
    ];
}

function bpBillKeys(): array
{
    return [
        'id',
        'household_id',
        'category',
        'title',
        'amount',
        'due_date',
        'is_active',
        'notes',
        'recurrence',
        'recurrence_period',
        'paid_amount',
        'remaining_amount',
        'payment_count',
        'status',
        'overdue',
        'created_at',
        'updated_at',
    ];
}

function bpBillPayments(Bill $bill): array
{
    return Transaction::query()
        ->where('source_type', Bill::class)
        ->where('source_id', $bill->id)
        ->get()
        ->all();
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('Bills payment endpoint authentication, authorization, and scoping', function () {
    test('guest cannot pay a bill', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(401);
    });

    test('member (non-owner) reads the bill but cannot pay it', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $memberUser = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $memberUser->id,
            'role' => 'member',
        ]);

        $this->actingAs($memberUser)->getJson("/api/bills/{$bill->id}")->assertOk();

        // Owner-only write: denial masks as BILL_NOT_FOUND so bill ids
        // stay unprobeable for members.
        $this->actingAs($memberUser)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('unverified owner cannot pay until the email is verified', function () {
        $owner = User::factory()->unverified()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('cross-household payment is masked as bill not found', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $outsider = User::factory()->create();
        bpHousehold($outsider, 'Rumah Orang Lain');

        $this->actingAs($outsider)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BILL_NOT_FOUND');

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('unknown bill id reports not found without leaking', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson('/api/bills/'.Str::ulid().'/payments', bpPayload($wallet))
            ->assertStatus(404);
    });
});

describe('Bills payment lifecycle and atomic money', function () {
    test('owner pays a bill in full with one server-computed expense row', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $category = bpCategory($household);
        $bill = bpBill($household, $category);
        $wallet = bpWallet($household);

        $response = $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment.type', 'expense')
            ->assertJsonPath('data.payment.amount', '150000.00')
            ->assertJsonPath('data.payment.wallet_id', $wallet->id)
            ->assertJsonPath('data.payment.occurred_on', '2026-10-19')
            ->assertJsonPath('data.payment.description', null)
            ->assertJsonPath('data.payment.destination_wallet_id', null)
            ->assertJsonPath('data.payment.category.id', $category->id)
            ->assertJsonPath('data.payment.source_wallet.id', $wallet->id)
            ->assertJsonPath('data.payment.bill_payment', [
                'bill_id' => $bill->id,
                'bill_title' => $bill->title,
            ])
            ->assertJsonPath('data.bill.id', $bill->id)
            ->assertJsonPath('data.bill.category.id', $category->id)
            ->assertJsonPath('data.bill.paid_amount', '150000.00')
            ->assertJsonPath('data.bill.remaining_amount', '0.00')
            ->assertJsonPath('data.bill.payment_count', 1)
            ->assertJsonPath('data.bill.status', 'paid');

        expect(array_keys($response->json('data')))->toEqual(['payment', 'bill']);
        expect(array_keys($response->json('data.payment')))->toEqual(bpPaymentKeys());
        expect(array_keys($response->json('data.bill')))->toEqual(bpBillKeys());

        $rows = bpBillPayments($bill);
        expect($rows)->toHaveCount(1);

        $row = $rows[0];
        expect($row->household_id)->toBe($household->id);
        expect($row->type)->toBe('expense');
        expect((string) $row->amount)->toBe('150000.00');
        expect($row->wallet_id)->toBe($wallet->id);
        expect($row->category_id)->toBe($category->id);
        expect($row->destination_wallet_id)->toBeNull();
        expect($row->description)->toBeNull();
        expect($row->occurred_on->format('Y-m-d'))->toBe('2026-10-19');

        // Balance is derived from rows: it fell by exactly the amount once.
        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '850000.00');
    });

    test('client-supplied amount is prohibited and never trusted', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        foreach ([1, 0.01, 999999999, '150000.00'] as $amount) {
            $this->actingAs($owner)
                ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet, ['amount' => $amount]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['amount']);
        }

        expect(bpBillPayments($bill))->toBeEmpty();
        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonPath('data.status', 'unpaid');
    });

    test('client-supplied category_id is prohibited (D-R3)', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);
        $otherCategory = bpCategory($household, 'Kategori Lain');

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet, [
                'category_id' => $otherCategory->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('second payment is refused and money moves exactly once', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);
        $other = bpWallet($household, ['opening_balance' => '0.00']);

        // Transfer and income rows are never bill payments.
        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 20000,
            'occurred_on' => '2026-10-06',
            'wallet_id' => $wallet->id,
            'destination_wallet_id' => $other->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonPath('data.status', 'unpaid');

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_ALREADY_PAID');

        expect(bpBillPayments($bill))->toHaveCount(1);

        // 1,000,000 − 20,000 transfer − 150,000 payment, deducted once.
        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '830000.00');

        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 1)
            ->assertJsonPath('data.paid_amount', '150000.00')
            ->assertJsonPath('data.remaining_amount', '0.00')
            ->assertJsonPath('data.status', 'paid');
    });

    test('archived bill cannot be paid', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household), ['is_active' => false]);
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_ARCHIVED');

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('insufficient funds rolls back the whole payment', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household, ['opening_balance' => '1000.00']);

        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '1000.00');

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        expect(bpBillPayments($bill))->toBeEmpty();
        expect(Transaction::query()->count())->toBe(0);

        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '1000.00');

        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonPath('data.status', 'unpaid');
    });

    test('inactive wallet is refused like a missing wallet', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household, ['is_active' => false]);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('foreign household wallet is refused', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));

        $foreignOwner = User::factory()->create();
        $foreignWallet = bpWallet(bpHousehold($foreignOwner, 'Rumah Asing'));

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($foreignWallet))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('unknown wallet id is refused', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", ['wallet_id' => (string) Str::ulid()])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        expect(bpBillPayments($bill))->toBeEmpty();
    });

    test('bill without category pays with an inherited null category', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, null);
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201)
            ->assertJsonPath('data.payment.category', null)
            ->assertJsonPath('data.bill.category', null);

        $rows = bpBillPayments($bill);
        expect($rows)->toHaveCount(1);
        expect($rows[0]->category_id)->toBeNull();
    });

    test('category retyped to income rejects the payment with full rollback', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $category = bpCategory($household);
        $bill = bpBill($household, $category);
        $wallet = bpWallet($household);

        Category::query()
            ->where('id', $category->id)
            ->update(['type' => Category::TYPE_INCOME]);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        expect(bpBillPayments($bill))->toBeEmpty();
        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '1000000.00');
    });

    test('deleted category nulls on delete and the payment inherits null', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $category = bpCategory($household);
        $bill = bpBill($household, $category);
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->deleteJson("/api/categories/{$category->id}")
            ->assertOk();

        $bill->refresh();
        expect($bill->category_id)->toBeNull();

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201)
            ->assertJsonPath('data.payment.category', null)
            ->assertJsonPath('data.payment.bill_payment.bill_id', $bill->id);

        $rows = bpBillPayments($bill);
        expect($rows)->toHaveCount(1);
        expect($rows[0]->category_id)->toBeNull();
        expect($rows[0]->source_type)->toBe(Bill::class);
        expect($rows[0]->source_id)->toBe($bill->id);
    });

    test('occurred_on accepts past, default, and future dates but rejects bad formats', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $category = bpCategory($household);
        $wallet = bpWallet($household);

        $past = bpBill($household, $category, ['title' => 'Lampau']);
        $explicitNull = bpBill($household, $category, ['title' => 'Null']);
        $omitted = bpBill($household, $category, ['title' => 'Default']);
        $future = bpBill($household, $category, ['title' => 'Masa Depan']);
        $invalid = bpBill($household, $category, ['title' => 'Salah']);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$past->id}/payments", bpPayload($wallet, ['occurred_on' => '2026-01-15']))
            ->assertStatus(201)
            ->assertJsonPath('data.payment.occurred_on', '2026-01-15');

        $this->actingAs($owner)
            ->postJson("/api/bills/{$explicitNull->id}/payments", bpPayload($wallet, ['occurred_on' => null]))
            ->assertStatus(201)
            ->assertJsonPath('data.payment.occurred_on', '2026-10-19');

        $this->actingAs($owner)
            ->postJson("/api/bills/{$omitted->id}/payments", bpPayload($wallet))
            ->assertStatus(201)
            ->assertJsonPath('data.payment.occurred_on', '2026-10-19');

        $this->actingAs($owner)
            ->postJson("/api/bills/{$future->id}/payments", bpPayload($wallet, ['occurred_on' => '2026-11-01']))
            ->assertStatus(201)
            ->assertJsonPath('data.payment.occurred_on', '2026-11-01');

        $this->actingAs($owner)
            ->postJson("/api/bills/{$invalid->id}/payments", bpPayload($wallet, ['occurred_on' => '15-01-2026']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['occurred_on']);

        expect(bpBillPayments($invalid))->toBeEmpty();
        $this->actingAs($owner)->getJson("/api/bills/{$invalid->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 0);
    });

    test('generic transaction store ignores client source linkage keys', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $response = $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
            'source_type' => Bill::class,
            'source_id' => $bill->id,
        ]);

        $response->assertStatus(201);

        $row = Transaction::query()->find($response->json('data.id'));
        expect($row->source_type)->toBeNull();
        expect($row->source_id)->toBeNull();

        // The bill stays unpaid: a forged source on a generic row never
        // becomes a payment.
        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonPath('data.status', 'unpaid');
    });

    test('archiving a paid bill touches no transaction, balance, or aggregate', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $paymentId = bpBillPayments($bill)[0]->id;

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_count', 1);

        $rows = bpBillPayments($bill);
        expect($rows)->toHaveCount(1);
        expect($rows[0]->id)->toBe($paymentId);
        expect((string) $rows[0]->amount)->toBe('150000.00');

        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '850000.00');

        // An archived bill refuses further payment with the lifecycle code.
        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_ARCHIVED');
    });

    test('payment state never becomes a stored column', function () {
        expect(Schema::hasTable('bill_payments'))->toBeFalse();

        $billColumns = Schema::getColumnListing('bills');
        expect($billColumns)->not->toContain('status');
        expect($billColumns)->not->toContain('paid_amount');
        expect($billColumns)->not->toContain('payment_count');

        $transactionColumns = Schema::getColumnListing('transactions');
        expect($transactionColumns)->not->toContain('bill_status');
        expect($transactionColumns)->not->toContain('paid');
    });

    test('failed insert inside the payment transaction rolls back every write', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->mock(TransactionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')->once()->andReturnUsing(function (array $data) {
                // The row is written inside the payment transaction, then
                // the failure fires: survival of this row would mean the
                // transaction was not atomic.
                Transaction::query()->create($data);

                throw new RuntimeException('Simulasi kegagalan insert pembayaran.');
            });
        });

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(500);

        expect(Transaction::query()->count())->toBe(0);

        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonPath('data.status', 'unpaid');

        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '1000000.00');
    });
});

describe('Bill payments are immutable generic-transaction operations', function () {
    test('patching a payment is refused with BILL_PAYMENT_LOCKED', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $payment = bpBillPayments($bill)[0];

        foreach ([
            ['amount' => 1],
            ['type' => 'income'],
            ['wallet_id' => (string) Str::ulid()],
            ['occurred_on' => '2026-01-01'],
            ['category_id' => null],
            ['description' => 'diubah'],
        ] as $payload) {
            $this->actingAs($owner)
                ->patchJson("/api/transactions/{$payment->id}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'BILL_PAYMENT_LOCKED');
        }

        $payment->refresh();
        expect($payment->type)->toBe('expense');
        expect((string) $payment->amount)->toBe('150000.00');
        expect($payment->wallet_id)->toBe($wallet->id);
        expect($payment->occurred_on->format('Y-m-d'))->toBe('2026-10-19');
        expect($payment->category_id)->toBe($bill->category_id);
        expect($payment->description)->toBeNull();
    });

    test('deleting a payment is refused with BILL_PAYMENT_LOCKED', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $payment = bpBillPayments($bill)[0];

        $this->actingAs($owner)
            ->deleteJson("/api/transactions/{$payment->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILL_PAYMENT_LOCKED');

        expect(Transaction::query()->where('id', $payment->id)->exists())->toBeTrue();

        $this->actingAs($owner)->getJson("/api/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_count', 1)
            ->assertJsonPath('data.status', 'paid');
    });

    test('generic transactions keep their previous edit and delete behavior', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $wallet = bpWallet($household);

        $store = $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 20000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
        ]);
        $store->assertStatus(201);
        $id = $store->json('data.id');

        $this->actingAs($owner)
            ->patchJson("/api/transactions/{$id}", ['amount' => 25000])
            ->assertOk()
            ->assertJsonPath('data.amount', '25000.00');

        $this->actingAs($owner)
            ->deleteJson("/api/transactions/{$id}")
            ->assertOk();

        $this->actingAs($owner)->getJson("/api/transactions/{$id}")->assertStatus(404);
    });

    test('foreign payment mutation stays masked as transaction not found', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $payment = bpBillPayments($bill)[0];

        $outsider = User::factory()->create();
        bpHousehold($outsider, 'Rumah Penyusup');

        // Never 422 for a foreign row: the 422 would confirm existence.
        $this->actingAs($outsider)
            ->patchJson("/api/transactions/{$payment->id}", ['amount' => 1])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        $this->actingAs($outsider)
            ->deleteJson("/api/transactions/{$payment->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        expect(bpBillPayments($bill))->toHaveCount(1);
    });

    test('guest, member, and unverified users cannot mutate a payment', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        // Payment row as a fixture: the guest checks must run before any
        // actingAs() call, which would silently persist for the test.
        $payment = bpPay($household, $bill, ['wallet_id' => $wallet->id]);

        $this->patchJson("/api/transactions/{$payment->id}", ['amount' => 1])->assertStatus(401);
        $this->deleteJson("/api/transactions/{$payment->id}")->assertStatus(401);

        $memberUser = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $memberUser->id,
            'role' => 'member',
        ]);

        $this->actingAs($memberUser)
            ->patchJson("/api/transactions/{$payment->id}", ['amount' => 1])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        $unverifiedOwner = User::factory()->unverified()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $unverifiedOwner->id,
            'role' => 'owner',
        ]);

        $this->actingAs($unverifiedOwner)
            ->patchJson("/api/transactions/{$payment->id}", ['amount' => 1])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        expect(bpBillPayments($bill))->toHaveCount(1);
    });

    test('wallet containing payment history can only be deactivated', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $this->actingAs($owner)
            ->deleteJson("/api/wallets/{$wallet->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALLET_IN_USE');

        expect(bpBillPayments($bill))->toHaveCount(1);
    });

    test('deleting the inherited category keeps the payment row intact', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $category = bpCategory($household);
        $bill = bpBill($household, $category);
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $this->actingAs($owner)
            ->deleteJson("/api/categories/{$category->id}")
            ->assertOk();

        $rows = bpBillPayments($bill);
        expect($rows)->toHaveCount(1);
        expect($rows[0]->category_id)->toBeNull();
        expect($rows[0]->source_type)->toBe(Bill::class);
        expect($rows[0]->source_id)->toBe($bill->id);
        expect($rows[0]->type)->toBe('expense');
        expect((string) $rows[0]->amount)->toBe('150000.00');

        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', '850000.00');
    });
});

describe('Bill payment presenter and aggregation', function () {
    test('bill_payment is exposed consistently and stays null for generic rows', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $pay = $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);
        $paymentId = $pay->json('data.payment.id');

        $generic = $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
        ]);
        $generic->assertStatus(201);
        $genericId = $generic->json('data.id');

        // Store response of a generic row carries the key as null (D-08).
        expect(array_keys($generic->json('data')))->toContain('bill_payment');
        expect($generic->json('data.bill_payment'))->toBeNull();

        // Show: payment carries the linkage, generic row stays null.
        $this->actingAs($owner)->getJson("/api/transactions/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.bill_payment', [
                'bill_id' => $bill->id,
                'bill_title' => $bill->title,
            ]);

        $this->actingAs($owner)->getJson("/api/transactions/{$genericId}")
            ->assertOk()
            ->assertJsonPath('data.bill_payment', null);

        // Index carries both shapes in one listing.
        $rows = $this->actingAs($owner)
            ->getJson('/api/transactions?per_page=100')
            ->assertOk()
            ->json('data.data');

        $byId = collect($rows)->keyBy('id');
        expect($byId[$paymentId]['bill_payment'])->toBe([
            'bill_id' => $bill->id,
            'bill_title' => $bill->title,
        ]);
        expect($byId[$genericId]['bill_payment'])->toBeNull();
    });

    test('a forged cross-household bill source never surfaces in bill_payment', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $wallet = bpWallet($household);

        // Another household's bill — the morph target of the forged row.
        $foreignOwner = User::factory()->create();
        $foreignHousehold = bpHousehold($foreignOwner, 'Rumah Asing');
        $foreignBill = bpBill($foreignHousehold, bpCategory($foreignHousehold));

        // Direct DB forge (impossible via the API: source keys are
        // dropped by validation) — the presenter must still refuse it.
        $forged = Transaction::factory()->create([
            'household_id' => $household->id,
            'category_id' => null,
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => '150000.00',
            'occurred_on' => '2026-10-10',
            'source_type' => Bill::class,
            'source_id' => $foreignBill->id,
            'wallet_id' => $wallet->id,
        ]);

        $this->actingAs($owner)->getJson("/api/transactions/{$forged->id}")
            ->assertOk()
            ->assertJsonPath('data.bill_payment', null);

        $rows = $this->actingAs($owner)
            ->getJson('/api/transactions?per_page=100')
            ->assertOk()
            ->json('data.data');

        expect(collect($rows)->firstWhere('id', $forged->id)['bill_payment'])->toBeNull();
    });

    test('listing payments runs a bounded number of queries (no N+1)', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $category = bpCategory($household);
        $wallet = bpWallet($household);

        for ($i = 0; $i < 3; $i++) {
            Transaction::factory()->for($household)->create([
                'category_id' => $category->id,
                'wallet_id' => $wallet->id,
                'type' => Transaction::TYPE_EXPENSE,
                'amount' => '10000.00',
                'occurred_on' => sprintf('2026-10-0%d', $i + 1),
            ]);
        }

        // Warm-up request, then measure the same listing without payments.
        $this->actingAs($owner)->getJson('/api/transactions?per_page=100')->assertOk();

        DB::enableQueryLog();
        $baselineResponse = $this->actingAs($owner)->getJson('/api/transactions?per_page=100')->assertOk();
        $baselineQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        expect($baselineResponse->json('data.data'))->toHaveCount(3);

        // 12 payment rows across 12 distinct bills (setup must not be
        // logged: flush alone does not stop the query log).
        for ($i = 0; $i < 12; $i++) {
            $paymentBill = bpBill($household, $category, ['title' => "Tagihan {$i}"]);
            bpPay($household, $paymentBill, ['wallet_id' => $wallet->id]);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($owner)->getJson('/api/transactions?per_page=100')->assertOk();
        $withPaymentsQueries = count(DB::getQueryLog());

        $rows = $response->json('data.data');
        expect($rows)->toHaveCount(15);

        $linked = count(array_filter($rows, fn (array $row) => $row['bill_payment'] !== null));
        expect($linked)->toBe(12);

        // +12 rows may cost at most +1 query (one batched morph load for
        // the bills). A per-row presenter query would add +12.
        expect($withPaymentsQueries)->toBeLessThanOrEqual($baselineQueries + 1);
    });

    test('payment is counted exactly once in dashboard, budget, and period sums', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $category = bpCategory($household);
        Budget::factory()->for($household)->for($category)->create([
            'year' => 2026,
            'month' => 10,
            'amount' => '500000.00',
        ]);
        $wallet = bpWallet($household);
        $other = bpWallet($household, ['opening_balance' => '0.00']);
        $bill = bpBill($household, $category);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 50000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 20000,
            'occurred_on' => '2026-10-06',
            'wallet_id' => $wallet->id,
            'destination_wallet_id' => $other->id,
        ])->assertStatus(201);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $dashboard = $this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk();
        $budgets = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10')->assertOk();

        // Expense = the payment alone: income and transfer never mix in.
        expect($dashboard->json('data.financial.expense'))->toBe('150000.00');
        expect($dashboard->json('data.financial.income'))->toBe('50000.00');
        expect($dashboard->json('data.financial.net_cash_flow'))->toBe('-100000.00');

        expect($budgets->json('meta.household.actual_spending'))->toBe('150000.00');
        // The inherited category hits its own budget exactly once.
        expect($budgets->json('data.0.actual_spending'))->toBe('150000.00');

        $transactions = app(TransactionRepositoryInterface::class);
        expect($transactions->sumExpenseTotal($household, '2026-10-01', '2026-10-31'))->toBe('150000.00');
        expect($transactions->sumIncomeTotal($household, '2026-10-01', '2026-10-31'))->toBe('50000.00');
    });

    test('money stays an exact two-decimal string everywhere', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201)
            ->assertJsonPath('data.payment.amount', '150000.00');

        $listed = $this->actingAs($owner)->getJson('/api/bills')->assertOk()->json('data.0');
        expect($listed['amount'])->toBe('150000.00');
        expect($listed['paid_amount'])->toBe('150000.00');
        expect($listed['remaining_amount'])->toBe('0.00');

        $paymentRow = bpBillPayments($bill)[0];
        expect((string) $paymentRow->amount)->toBe('150000.00');
    });

    test('dashboard keeps its frozen bills contract', function () {
        $owner = User::factory()->create();
        $household = bpHousehold($owner);
        $bill = bpBill($household, bpCategory($household));
        $wallet = bpWallet($household);

        $this->actingAs($owner)
            ->postJson("/api/bills/{$bill->id}/payments", bpPayload($wallet))
            ->assertStatus(201);

        $response = $this->actingAs($owner)->getJson('/api/dashboard')->assertOk();
        expect($response->json('data.upcoming_bills'))->toBe([]);
        expect($response->json('data.meta.unsupported_domains'))
            ->toBe(['transactions', 'budgets', 'bills', 'maintenance']);
    });
});
