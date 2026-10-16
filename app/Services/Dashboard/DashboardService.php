<?php

namespace App\Services\Dashboard;

use App\Models\Household;
use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Finance\LedgerMath;
use App\Services\Finance\PeriodBounds;

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
        private readonly TransactionRepositoryInterface $transactions,
    ) {}

    /**
     * Build the dashboard read-model for one household and period.
     *
     * Every number comes from a household-scoped query below. Financial
     * aggregates share the exact calendar bounds of Actual Spending
     * (PeriodBounds, Asia/Jakarta business date), so Income, Expense, and
     * Net Cash Flow always describe the same effective period.
     *
     * @param  array{id: string, name: string, role: string}  $household  Server-resolved context, never client input.
     * @return array<string, mixed>
     */
    public function forHouseholdInPeriod(array $household, int $year, int $month): array
    {
        $householdModel = Household::query()->findOrFail($household['id']);
        $today = PeriodBounds::businessToday();
        [$start, $end] = PeriodBounds::effectiveBounds($year, $month, $today);

        $income = $start === null
            ? '0.00'
            : $this->transactions->sumIncomeTotal($householdModel, $start, $end);
        $expense = $start === null
            ? '0.00'
            : $this->transactions->sumExpenseTotal($householdModel, $start, $end);
        $netCashFlow = LedgerMath::sub($income, $expense);

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
                'net_cash_flow' => $netCashFlow,
                'income_change_percent' => 0,
                'budget_usage_percent' => 0,
                'cash_flow_status' => match (LedgerMath::compare($netCashFlow, '0.00')) {
                    1 => 'surplus',
                    -1 => 'defisit',
                    default => 'seimbang',
                },
            ],
            'budget' => [],
            'upcoming_bills' => [],
            'recent_transactions' => [],
            'maintenance' => [],
            'meta' => ['unsupported_domains' => self::UNSUPPORTED_DOMAINS],
        ];
    }
}
