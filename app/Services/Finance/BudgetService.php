<?php

namespace App\Services\Finance;

use App\Exceptions\BudgetAlreadyExistsException;
use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\CategoryTypeMismatchException;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class BudgetService
{
    /**
     * Per-request cache of per-period spending maps so listing many
     * budgets never issues one query per budget.
     *
     * @var array<string, array<string, string>>
     */
    private array $spendingCache = [];

    public function __construct(
        private readonly BudgetRepositoryInterface $budgets,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TransactionRepositoryInterface $transactions,
    ) {}

    /**
     * @param  array{year?: int, month?: int}  $filters
     * @return Collection<int, Budget>
     */
    public function listForHousehold(Household $household, array $filters): Collection
    {
        return $this->budgets->listForHousehold($household, $filters);
    }

    /**
     * Set a spending target for one category in one month.
     *
     * Ownership always comes from the server-resolved household; client
     * payload can never set household_id. Spending itself is never stored
     * here — it is derived from transactions when a confirmed definition
     * exists. Only expense-typed categories may carry a target; legacy
     * untyped categories are rejected fail-closed so no target is ever
     * attached to an income semantic by accident.
     *
     * @param  array{category_id: string, year: int, month: int, amount: mixed}  $data
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     * @throws BudgetAlreadyExistsException
     */
    public function record(Household $household, array $data): Budget
    {
        $category = $this->resolveExpenseCategory($household, $data['category_id']);

        if ($this->budgets->existsForPeriod($household, $category->id, (int) $data['year'], (int) $data['month'])) {
            throw new BudgetAlreadyExistsException;
        }

        return $this->budgets->create([
            'household_id' => $household->id,
            'category_id' => $category->id,
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'amount' => $this->normalizeAmount($data['amount']),
        ]);
    }

    /**
     * Change a budget target. Retargeting to another category or period
     * is allowed as long as it does not collide with an existing target.
     *
     * @param  array{category_id?: string, year?: int, month?: int, amount?: mixed}  $data
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     * @throws BudgetAlreadyExistsException
     */
    public function change(Household $household, Budget $budget, array $data): Budget
    {
        $attributes = [];

        $categoryId = $budget->category_id;
        $year = $budget->year;
        $month = $budget->month;

        if (array_key_exists('category_id', $data)) {
            $categoryId = $this->resolveExpenseCategory($household, $data['category_id'])->id;
            $attributes['category_id'] = $categoryId;
        }

        if (array_key_exists('year', $data)) {
            $year = (int) $data['year'];
            $attributes['year'] = $year;
        }

        if (array_key_exists('month', $data)) {
            $month = (int) $data['month'];
            $attributes['month'] = $month;
        }

        if ($this->budgets->existsForPeriod($household, $categoryId, $year, $month, $budget->id)) {
            throw new BudgetAlreadyExistsException;
        }

        if (array_key_exists('amount', $data)) {
            $attributes['amount'] = $this->normalizeAmount($data['amount']);
        }

        return $this->budgets->update($budget, $attributes);
    }

    /**
     * Delete a budget target. Transaction history is never touched:
     * nothing references budgets, so deletion cannot orphan records.
     */
    public function remove(Budget $budget): void
    {
        $this->budgets->delete($budget);
    }

    /**
     * List budgets with live spending attached. Spending is derived from
     * active transactions on every call and never stored.
     *
     * @param  array{year?: int, month?: int}  $filters
     * @return array{items: Collection<int, Budget>, spending: array<string, string>, householdTotal: ?string, period: ?array{year: int, month: int}, today: string}
     */
    public function listWithSpending(Household $household, array $filters, ?string $today = null): array
    {
        $today ??= $this->businessToday();
        $items = $this->budgets->listForHousehold($household, $filters);

        $spending = [];

        foreach ($this->distinctPeriods($items) as [$year, $month]) {
            foreach ($this->spendingMapForPeriod($household, $year, $month, $today) as $categoryId => $total) {
                $spending["{$categoryId}|{$year}-{$month}"] = $total;
            }
        }

        $householdTotal = null;
        $period = null;

        if (isset($filters['year'], $filters['month'])) {
            $period = ['year' => (int) $filters['year'], 'month' => (int) $filters['month']];
            $householdTotal = $this->householdTotalForPeriod($household, $period['year'], $period['month'], $today);
        }

        return [
            'items' => $items,
            'spending' => $spending,
            'householdTotal' => $householdTotal,
            'period' => $period,
            'today' => $today,
        ];
    }

    /**
     * Derive spending fields for one budget. Returned alongside the model
     * so the controller can present a single consistent shape.
     *
     * @return array{actual_spending: string, remaining: string, usage_percentage: ?float, usage_status: string}
     */
    public function deriveFor(Household $household, Budget $budget, ?string $today = null): array
    {
        $today ??= $this->businessToday();
        [$start] = $this->effectiveBounds($budget->year, $budget->month, $today);

        $spent = $start === null
            ? '0.00'
            : $this->spendingMapForPeriod($household, $budget->year, $budget->month, $today)[$budget->category_id] ?? '0.00';

        return $this->deriveFields((string) $budget->amount, $spent);
    }

    /**
     * @return array{actual_spending: string, remaining: string, usage_percentage: ?float, usage_status: string}
     */
    private function deriveFields(string $budgetAmount, string $spent): array
    {
        $budgetAmount = $this->normalizeDecimal($budgetAmount);
        $spent = $this->normalizeDecimal($spent);

        if ($this->isZeroDecimal($budgetAmount)) {
            return [
                'actual_spending' => $spent,
                'remaining' => $this->subDecimal($budgetAmount, $spent),
                'usage_percentage' => null,
                'usage_status' => 'no_budget',
            ];
        }

        return [
            'actual_spending' => $spent,
            'remaining' => $this->subDecimal($budgetAmount, $spent),
            // Display-only percentage rounded to 2dp. Money values above
            // stay exact decimal strings; floats never touch aggregation.
            'usage_percentage' => round(((float) $spent / (float) $budgetAmount) * 100, 2),
            'usage_status' => 'normal',
        ];
    }

    /**
     * Category_id => exact total for one household month, served from the
     * per-request cache. One grouped database aggregation per period.
     *
     * @return array<string, string>
     */
    private function spendingMapForPeriod(Household $household, int $year, int $month, string $today): array
    {
        $key = "{$household->id}|{$year}-{$month}";

        if (! array_key_exists($key, $this->spendingCache)) {
            [$start, $end] = $this->effectiveBounds($year, $month, $today);

            $this->spendingCache[$key] = $start === null
                ? []
                : $this->transactions->sumExpenseByCategory($household, $start, $end);
        }

        return $this->spendingCache[$key];
    }

    private function householdTotalForPeriod(Household $household, int $year, int $month, string $today): string
    {
        [$start, $end] = $this->effectiveBounds($year, $month, $today);

        if ($start === null) {
            return '0.00';
        }

        return $this->normalizeDecimal($this->transactions->sumExpenseTotal($household, $start, $end));
    }

    /**
     * Inclusive calendar bounds capped at the business reference date.
     * Returns [null, null] when the period has not started yet.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function effectiveBounds(int $year, int $month, string $today): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
        $effectiveEnd = min($end, $today);

        if ($effectiveEnd < $start) {
            return [null, null];
        }

        return [$start, $effectiveEnd];
    }

    /**
     * Single server-side business reference date (Asia/Jakarta) for the
     * whole operation. Never the browser clock. The global app timezone
     * and timestamp storage are untouched.
     */
    private function businessToday(): string
    {
        return Carbon::now('Asia/Jakarta')->toDateString();
    }

    /**
     * @param  Collection<int, Budget>  $items
     * @return list<array{0: int, 1: int}>
     */
    private function distinctPeriods(Collection $items): array
    {
        $periods = [];

        foreach ($items as $budget) {
            $periods["{$budget->year}-{$budget->month}"] = [(int) $budget->year, (int) $budget->month];
        }

        return array_values($periods);
    }

    /**
     * Resolve a category strictly inside the household and require the
     * expense type. A missing, foreign, or deleted category surfaces
     * identically as not found, so callers cannot probe other
     * households' categories. Legacy untyped categories are rejected
     * fail-closed: a target must never attach to an income semantic.
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     */
    private function resolveExpenseCategory(Household $household, ?string $categoryId): Category
    {
        $category = $categoryId === null
            ? null
            : $this->categories->findByIdForHousehold($household, $categoryId);

        if ($category === null) {
            throw new CategoryNotFoundException;
        }

        if ($category->type !== Category::TYPE_EXPENSE) {
            throw new CategoryTypeMismatchException('Budget requires an expense category.');
        }

        return $category;
    }

    /**
     * Normalize validated input to the exact decimal(15,2) storage shape.
     *
     * Float64 represents every multiple of 0.01 below 9e13 exactly, so
     * this is lossless inside the validated max (9999999999999.99).
     */
    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Normalize any database SUM result to an exact 2dp decimal string.
     * pgsql returns numeric strings, sqlite returns float|int, empty
     * aggregates return null. Float64 holds every multiple of 0.01
     * below 9e13 exactly, so this is lossless inside the validated
     * column max (9999999999999.99).
     */
    private function normalizeDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $text = (string) $value;
        $sign = str_starts_with($text, '-') ? '-' : '';
        $digits = $text === '' ? '0' : ltrim(ltrim($text, '-'), '0');
        $digits = $digits === '' || str_starts_with($digits, '.') ? '0'.$digits : $digits;
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

        // SUM over decimal(15,2) never exceeds 2dp; truncation here is a
        // defensive guard, not a rounding decision.
        return $sign.$whole.'.'.substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    /**
     * Exact decimal subtraction on 2dp strings via integer cents.
     * PHP ints hold ±9e18; validated amounts stay below 1e15 cents,
     * so no float and no overflow.
     */
    private function subDecimal(string $a, string $b): string
    {
        $diff = $this->toCents($a) - $this->toCents($b);
        $sign = $diff < 0 ? '-' : '';
        $abs = abs($diff);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function toCents(string $value): int
    {
        $normalized = $this->normalizeDecimal($value);
        $sign = str_starts_with($normalized, '-') ? -1 : 1;
        [$whole, $fraction] = explode('.', ltrim($normalized, '-'));

        return $sign * ((int) $whole * 100 + (int) $fraction);
    }

    private function isZeroDecimal(string $value): bool
    {
        return $this->toCents($value) === 0;
    }
}
