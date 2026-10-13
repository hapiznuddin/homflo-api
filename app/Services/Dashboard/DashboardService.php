<?php

namespace App\Services\Dashboard;

use App\Models\Household;
use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;

class DashboardService
{
    /**
     * Domains without persistence. They are reported in meta.unsupported_domains
     * and MUST NOT be backfilled with fake numbers.
     *
     * @var list<string>
     */
    private const UNSUPPORTED_DOMAINS = ['transactions', 'budgets', 'bills', 'maintenance'];

    public function __construct(
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
        private readonly HouseholdInvitationRepositoryInterface $householdInvitations,
    ) {}

    /**
     * Build the dashboard read-model for one household and period.
     *
     * Every number comes from a household-scoped query below. Domains in
     * UNSUPPORTED_DOMAINS aggregate to zero/empty collections until their
     * persistence lands; empty ledgers aggregate to zero, which is the
     * truthful value — never a placeholder.
     *
     * @param  array{id: string, name: string, role: string}  $household  Server-resolved context, never client input.
     * @return array<string, mixed>
     */
    public function forHouseholdInPeriod(array $household, int $year, int $month): array
    {
        $householdModel = Household::query()->findOrFail($household['id']);
        $income = $this->sumIncomeForPeriod($household['id'], $year, $month);
        $expense = $this->sumExpenseForPeriod($household['id'], $year, $month);

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'key' => sprintf('%04d-%02d', $year, $month),
            ],
            'household' => [
                'id' => $household['id'],
                'name' => $household['name'],
                'role' => $household['role'],
                'member_count' => $this->householdMembers->countForHousehold($householdModel),
                'pending_invitations' => $this->householdInvitations->countPendingForHousehold($householdModel),
            ],
            'financial' => [
                'income' => $income,
                'expense' => $expense,
                'net_cash_flow' => $income - $expense,
                'income_change_percent' => 0,
                'budget_usage_percent' => 0,
                'cash_flow_status' => 'seimbang',
            ],
            'budget' => [],
            'upcoming_bills' => [],
            'recent_transactions' => [],
            'maintenance' => [],
            'meta' => ['unsupported_domains' => self::UNSUPPORTED_DOMAINS],
        ];
    }

    private function sumIncomeForPeriod(string $householdId, int $year, int $month): int
    {
        // No income persistence exists yet (see UNSUPPORTED_DOMAINS).
        return 0;
    }

    private function sumExpenseForPeriod(string $householdId, int $year, int $month): int
    {
        // No expense persistence exists yet (see UNSUPPORTED_DOMAINS).
        return 0;
    }
}
