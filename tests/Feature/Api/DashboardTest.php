<?php

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function createDashboardHousehold(User $user, string $name = 'Keluarga Dashboard'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $user->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    return $household;
}

function createPendingInvitation(Household $household, User $inviter, string $email, bool $expired = false): void
{
    HouseholdInvitation::create([
        'household_id' => $household->id,
        'invited_by' => $inviter->id,
        'email' => $email,
        'token_hash' => hash('sha256', $email.$household->id),
        'status' => HouseholdInvitation::STATUS_PENDING,
        'expires_at' => $expired ? now()->subDay() : now()->addDay(),
    ]);
}

describe('GET /api/dashboard', function () {
    test('returns 401 for unauthenticated request', function () {
        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    });

    test('authenticated user receives household context and truthful empty aggregates', function () {
        $user = User::factory()->create();
        $household = createDashboardHousehold($user);

        $response = $this->actingAs($user)->getJson('/api/dashboard?month=10&year=2026');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.period', ['year' => 2026, 'month' => 10, 'key' => '2026-10']);
        $response->assertJsonPath('data.household.id', $household->id);
        $response->assertJsonPath('data.household.name', 'Keluarga Dashboard');
        $response->assertJsonPath('data.household.role', 'owner');
        $response->assertJsonPath('data.household.member_count', 1);
        $response->assertJsonPath('data.household.pending_invitations', 0);
        $response->assertJsonPath('data.financial', [
            'income' => 0,
            'expense' => 0,
            'net_cash_flow' => 0,
            'income_change_percent' => 0,
            'budget_usage_percent' => 0,
            'cash_flow_status' => 'seimbang',
        ]);
        $response->assertJsonPath('data.budget', []);
        $response->assertJsonPath('data.upcoming_bills', []);
        $response->assertJsonPath('data.recent_transactions', []);
        $response->assertJsonPath('data.maintenance', []);
        $response->assertJsonPath('data.meta.unsupported_domains', ['transactions', 'budgets', 'bills', 'maintenance']);
    });

    test('user without household receives 404 without leaking data', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });

    test('household is resolved server-side; other household data never leaks', function () {
        $user = User::factory()->create();
        $mine = createDashboardHousehold($user, 'Rumah Saya');

        $other = User::factory()->create();
        $theirs = createDashboardHousehold($other, 'Rumah Tetangga Rahasia');
        createPendingInvitation($theirs, $other, 'spy@example.com');

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.household.id', $mine->id);
        $response->assertJsonPath('data.household.member_count', 1);
        $response->assertJsonPath('data.household.pending_invitations', 0);
        expect($response->getContent())->not->toContain('Rumah Tetangga Rahasia');
    });

    test('defaults to the current period when params are absent', function () {
        $user = User::factory()->create();
        createDashboardHousehold($user);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.period.year', now()->year);
        $response->assertJsonPath('data.period.month', now()->month);
        $response->assertJsonPath('data.period.key', now()->format('Y-m'));
    });

    test('rejects invalid periods with validation errors', function () {
        $user = User::factory()->create();
        createDashboardHousehold($user);

        $this->actingAs($user)->getJson('/api/dashboard?month=0&year=2026')
            ->assertStatus(422)->assertJsonValidationErrors(['month']);

        $this->actingAs($user)->getJson('/api/dashboard?month=13&year=2026')
            ->assertStatus(422)->assertJsonValidationErrors(['month']);

        $this->actingAs($user)->getJson('/api/dashboard?month=oktober&year=2026')
            ->assertStatus(422)->assertJsonValidationErrors(['month']);

        $this->actingAs($user)->getJson('/api/dashboard?month=10&year=1999')
            ->assertStatus(422)->assertJsonValidationErrors(['year']);

        $this->actingAs($user)->getJson('/api/dashboard?month=10&year=2101')
            ->assertStatus(422)->assertJsonValidationErrors(['year']);
    });

    test('unverified user can read the dashboard (read-only, like /api/me)', function () {
        $user = User::factory()->unverified()->create();
        createDashboardHousehold($user);

        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);
    });

    test('member and invitation counts are household-scoped', function () {
        $user = User::factory()->create();
        $mine = createDashboardHousehold($user);

        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $mine->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        createPendingInvitation($mine, $user, 'baru@example.com');
        createPendingInvitation($mine, $user, 'kedaluwarsa@example.com', expired: true);

        $other = User::factory()->create();
        $theirs = createDashboardHousehold($other, 'Rumah Lain');
        createPendingInvitation($theirs, $other, 'asing@example.com');

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.household.member_count', 2);
        $response->assertJsonPath('data.household.pending_invitations', 1);
    });

    test('runs a bounded number of queries (no N+1)', function () {
        $user = User::factory()->create();
        createDashboardHousehold($user);

        DB::enableQueryLog();

        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);

        // Diukur: tepat 5 query (membership + household + find + 2 count),
        // semuanya terfilter household_id/user_id. Batas 6 menangkap regresi
        // N+1 tanpa membuat test rapuh terhadap perubahan framework.
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
    });
});
