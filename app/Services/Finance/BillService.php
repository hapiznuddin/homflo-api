<?php

namespace App\Services\Finance;

use App\Exceptions\BillAlreadyPaidException;
use App\Exceptions\BillNotPaidException;
use App\Exceptions\BillPeriodAlreadyExistsException;
use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\CategoryTypeMismatchException;
use App\Models\Bill;
use App\Models\BillRecurrence;
use App\Models\Category;
use App\Models\Household;
use App\Repositories\Contracts\BillRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BillService
{
    /**
     * Horizon per schedule (D-02): every materialization run keeps twelve
     * periods ahead of the cursor, extended twelve more periods at once.
     */
    private const HORIZON_PERIODS = 12;

    /**
     * Extend when the last materialized period sits closer than three
     * months to the business month (matches contract §7.5: "horizon
     * tersisa < 3 bulan, tambah 12 bulan").
     */
    private const EXTENSION_THRESHOLD_MONTHS = 3;

    public function __construct(
        private readonly BillRepositoryInterface $bills,
        private readonly CategoryRepositoryInterface $categories,
    ) {}

    /**
     * List with derived payment fields attached — one grouped aggregate
     * for the whole page (no N+1), never a stored status column.
     *
     * @param  array{include_archived?: bool}  $filters
     * @return array{items: Collection<int, Bill>, derived: array<string, array{paid_amount: string, remaining_amount: string, payment_count: int, status: string, overdue: bool}>}
     */
    public function listWithDerived(Household $household, array $filters): array
    {
        $items = $this->bills->listForHousehold($household, $filters);

        return [
            'items' => $items,
            'derived' => $this->deriveMany($household, $items),
        ];
    }

    /**
     * @return array{paid_amount: string, remaining_amount: string, payment_count: int, status: string, overdue: bool}
     */
    public function deriveFor(Household $household, Bill $bill): array
    {
        return $this->deriveMany($household, [$bill])[$bill->id];
    }

    /**
     * Derive paid/remaining/count/status/overdue from payment rows via
     * LedgerMath integer cents — money never becomes a float and status
     * never becomes a column.
     *
     * @param  iterable<int, Bill>  $bills
     * @return array<string, array{paid_amount: string, remaining_amount: string, payment_count: int, status: string, overdue: bool}>
     */
    public function deriveMany(Household $household, iterable $bills): array
    {
        $ids = [];

        foreach ($bills as $bill) {
            $ids[] = $bill->id;
        }

        if ($ids === []) {
            return [];
        }

        $aggregates = $this->bills->paymentAggregatesForHousehold($household, $ids);
        $today = PeriodBounds::businessToday();
        $derived = [];

        foreach ($bills as $bill) {
            $aggregate = $aggregates[$bill->id] ?? ['sum' => null, 'count' => 0];
            $paidAmount = $this->normalizeDecimal($aggregate['sum']);
            $paymentCount = (int) $aggregate['count'];
            $amount = $this->normalizeDecimal($bill->amount);
            $remaining = LedgerMath::sub($amount, $paidAmount);

            if (LedgerMath::isNegative($remaining)) {
                $remaining = '0.00';
            }

            $status = $paymentCount >= 1 ? 'paid' : 'unpaid';
            $dueDate = $bill->due_date?->format('Y-m-d');

            $derived[$bill->id] = [
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remaining,
                'payment_count' => $paymentCount,
                'status' => $status,
                'overdue' => $dueDate !== null && $dueDate < $today && $status === 'unpaid',
            ];
        }

        return $derived;
    }

    /**
     * Create a one-time bill, or a schedule plus its first twelve
     * materialized periods in one atomic transaction (D-02). The client
     * never supplies household_id; recurrence membership is created here
     * only.
     *
     * @param  array{title: string, amount: mixed, due_date?: ?string, category_id?: ?string, notes?: ?string, recurrence?: ?array{frequency: string}}  $data
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     */
    public function record(Household $household, array $data): Bill
    {
        $category = $this->resolveCategory($household, $data['category_id'] ?? null);
        $amount = $this->normalizeAmount($data['amount']);
        $recurrenceData = $data['recurrence'] ?? null;

        return DB::transaction(function () use ($household, $data, $category, $amount, $recurrenceData): Bill {
            if (! is_array($recurrenceData) || $recurrenceData === []) {
                $bill = $this->bills->create([
                    'household_id' => $household->id,
                    'category_id' => $category?->id,
                    'title' => $data['title'],
                    'amount' => $amount,
                    'due_date' => $data['due_date'] ?? null,
                    'is_active' => true,
                    'notes' => $data['notes'] ?? null,
                ]);

                $this->sweepHouseholdRecurrences($household);

                return $bill;
            }

            // due_date is required with recurrence by the form request and
            // seeds the calendar: due_day = its day of month, anchor
            // period = its month (contract §7.2).
            $dueDate = (string) $data['due_date'];
            $recurrence = $this->bills->createRecurrence([
                'household_id' => $household->id,
                'category_id' => $category?->id,
                'title' => $data['title'],
                'amount' => $amount,
                'notes' => $data['notes'] ?? null,
                'frequency' => $recurrenceData['frequency'],
                'due_day' => (int) substr($dueDate, 8, 2),
                'is_active' => true,
                'materialized_until' => null,
            ]);

            $anchorMonth = substr($dueDate, 0, 7).'-01';
            $this->insertPeriod($recurrence, $anchorMonth);
            $recurrence->materialized_until = $anchorMonth;
            $recurrence->save();
            $this->advanceMaterialization(
                $recurrence,
                $this->addMonths($anchorMonth, 11 * $this->intervalMonths($recurrence->frequency)),
            );

            $this->sweepHouseholdRecurrences($household);

            $firstPeriod = $this->bills->firstPeriodForRecurrence($recurrence);

            if ($firstPeriod === null) {
                throw new RuntimeException('Recurrence materialization produced no first period.');
            }

            return $firstPeriod;
        });
    }

    /**
     * Edit one bill (local override; never the schedule template).
     * Paid bills are refused before any field is applied — including an
     * empty body (deterministic lifecycle guard).
     *
     * @param  array{title?: string, amount?: mixed, due_date?: ?string, category_id?: ?string, notes?: ?string}  $data
     *
     * @throws BillAlreadyPaidException
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     */
    public function change(Household $household, Bill $bill, array $data): Bill
    {
        $this->guardNotPaid($household, $bill);

        $attributes = [];

        if (array_key_exists('category_id', $data)) {
            $category = $this->resolveCategory($household, $data['category_id']);
            $attributes['category_id'] = $category?->id;
        }

        if (array_key_exists('title', $data)) {
            $attributes['title'] = $data['title'];
        }

        if (array_key_exists('amount', $data)) {
            $attributes['amount'] = $this->normalizeAmount($data['amount']);
        }

        if (array_key_exists('due_date', $data)) {
            $attributes['due_date'] = $data['due_date'];
        }

        if (array_key_exists('notes', $data)) {
            $attributes['notes'] = $data['notes'];
        }

        $updated = $this->bills->update($bill, $attributes);

        $this->sweepHouseholdRecurrences($household);

        return $updated;
    }

    /**
     * Delete one unpaid bill. For a period bill this is the permanent
     * skip (D-05): the cursor never rewinds, so the period never comes
     * back. Transactions are never touched (paid bills cannot reach
     * this method).
     *
     * @throws BillAlreadyPaidException
     */
    public function remove(Household $household, Bill $bill): void
    {
        $this->guardNotPaid($household, $bill);

        $this->bills->delete($bill);

        $this->sweepHouseholdRecurrences($household);
    }

    /**
     * Archive a paid bill (one-way, wallet-deactivate precedent).
     * Idempotent on an already archived bill. Touches no transaction,
     * balance, or aggregate.
     *
     * @throws BillNotPaidException
     */
    public function archive(Household $household, Bill $bill): Bill
    {
        $aggregates = $this->bills->paymentAggregatesForHousehold($household, [$bill->id]);

        if (($aggregates[$bill->id]['count'] ?? 0) < 1) {
            throw new BillNotPaidException;
        }

        if (! $bill->is_active) {
            return $bill;
        }

        $archived = $this->bills->update($bill, ['is_active' => false]);

        $this->sweepHouseholdRecurrences($household);

        return $archived;
    }

    /**
     * Change a schedule definition (D-07) inside one transaction:
     * lock the schedule row first, then re-check and apply.
     *
     * Propagation (D-03): only period bills of this series that are
     * still active AND carry no payment receive template edits —
     * overdue unpaid bills included, paid and archived bills never.
     * due_day re-clamps due_date within each period's own month.
     * frequency changes are forward-only (D-09): existing bills and the
     * cursor stay untouched; the new interval applies from the cursor
     * on the next materialization.
     *
     * @param  array{title?: string, amount?: mixed, category_id?: ?string, notes?: ?string, due_day?: int, frequency?: string, is_active?: bool}  $data
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     */
    public function changeRecurrence(Household $household, BillRecurrence $recurrence, array $data): BillRecurrence
    {
        $categoryTouched = array_key_exists('category_id', $data);
        $category = $categoryTouched ? $this->resolveCategory($household, $data['category_id']) : null;

        return DB::transaction(function () use ($household, $data, $category, $categoryTouched, $recurrence): BillRecurrence {
            $locked = $this->bills->lockRecurrenceForHousehold($household, $recurrence->id);

            $attributes = [];

            foreach (['title', 'amount', 'due_day', 'frequency', 'is_active', 'notes'] as $key) {
                if (! array_key_exists($key, $data)) {
                    continue;
                }

                $attributes[$key] = $key === 'amount'
                    ? $this->normalizeAmount($data[$key])
                    : $data[$key];
            }

            if ($categoryTouched) {
                $attributes['category_id'] = $category?->id;
            }

            // Only values that actually change propagate — an unchanged
            // field in the payload must not overwrite local overrides.
            $propagate = [];
            $dueDayChanged = false;

            foreach ($attributes as $key => $value) {
                $original = $locked->getOriginal($key);

                if ($key === 'due_day') {
                    $dueDayChanged = (int) $value !== (int) $original;
                } elseif ($value !== $original) {
                    $propagate[$key] = $value;
                }
            }

            if ($attributes !== []) {
                $locked->fill($attributes);
                $locked->save();

                // The lock query eager-loaded the previous category; drop
                // the stale relation so the presenter reflects the change.
                $locked->unsetRelation('category');
            }

            $templateKeys = array_intersect_key(
                $propagate,
                array_flip(['title', 'amount', 'category_id', 'notes']),
            );

            if ($templateKeys !== [] || $dueDayChanged) {
                $targets = $this->bills->unpaidActivePeriodBills($household, $locked);

                if ($targets->isNotEmpty()) {
                    if ($templateKeys !== []) {
                        $this->bills->updateBills($targets->pluck('id')->all(), $templateKeys);
                    }

                    if ($dueDayChanged) {
                        foreach ($targets as $target) {
                            if ($target->recurrence_period === null || $target->due_date === null) {
                                continue;
                            }

                            $target->due_date = $this->clampDueDate(
                                (int) $attributes['due_day'],
                                $target->recurrence_period->format('Y-m-d'),
                            );
                            $target->save();
                        }
                    }
                }
            }

            $this->sweepHouseholdRecurrences($household);

            return $locked;
        });
    }

    /**
     * Owner-write trigger (D-04): sweep every active schedule of the
     * household through the same idempotent materialization path. Reads
     * never call this.
     */
    private function sweepHouseholdRecurrences(Household $household): void
    {
        foreach ($this->bills->activeRecurrencesForHousehold($household) as $recurrence) {
            $this->materialize($household, $recurrence->id);
        }
    }

    /**
     * Single idempotent materialization path: lock the schedule row,
     * re-read the cursor under the lock, backfill/extend to a target
     * twelve periods ahead (D-10 backfill: missed months are created and
     * may be overdue — the cursor defines "missed", never the clock).
     * All inserts and the cursor advance commit atomically; any failure
     * rolls everything back.
     */
    private function materialize(Household $household, string $recurrenceId): void
    {
        DB::transaction(function () use ($household, $recurrenceId): void {
            $locked = $this->bills->lockRecurrenceForHousehold($household, $recurrenceId);

            if (! $locked->is_active || $locked->materialized_until === null) {
                return;
            }

            $cursor = $locked->materialized_until->format('Y-m-d');
            $todayMonth = substr(PeriodBounds::businessToday(), 0, 7).'-01';

            if ($this->monthsBetween($todayMonth, $cursor) >= self::EXTENSION_THRESHOLD_MONTHS) {
                return;
            }

            $this->advanceMaterialization(
                $locked,
                $this->addMonths($cursor, self::HORIZON_PERIODS * $this->intervalMonths($locked->frequency)),
            );
        });
    }

    /**
     * Insert every not-yet-materialized period strictly after the cursor
     * up to and including the target month, then advance the cursor.
     * The cursor never rewinds (D-05): periods deleted or skipped stay
     * skipped because existence is rechecked before every insert.
     */
    private function advanceMaterialization(BillRecurrence $recurrence, string $targetMonth): void
    {
        $interval = $this->intervalMonths($recurrence->frequency);
        $month = $this->addMonths($recurrence->materialized_until->format('Y-m-d'), $interval);

        while ($month <= $targetMonth) {
            $this->insertPeriod($recurrence, $month);
            $month = $this->addMonths($month, $interval);
        }

        $recurrence->materialized_until = $targetMonth;
        $recurrence->save();
    }

    /**
     * Create one concrete period bill from the schedule template. The
     * existence recheck keeps deletes skipped and re-runs idempotent;
     * the partial unique index (D-06) is the fail-loud backstop if a
     * writer ever slips past the row lock.
     */
    private function insertPeriod(BillRecurrence $recurrence, string $periodMonth): void
    {
        if ($this->bills->periodExists($recurrence->id, $periodMonth)) {
            return;
        }

        try {
            $this->bills->create([
                'household_id' => $recurrence->household_id,
                'category_id' => $recurrence->category_id,
                'title' => $recurrence->title,
                'amount' => $this->normalizeDecimal($recurrence->amount),
                'due_date' => $this->clampDueDate((int) $recurrence->due_day, $periodMonth),
                'is_active' => true,
                'notes' => $recurrence->notes,
                'recurrence_id' => $recurrence->id,
                'recurrence_period' => $periodMonth,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniquePeriodViolation($exception)) {
                throw new BillPeriodAlreadyExistsException(previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * Detect the D-06 backstop firing (or any other unique violation in
     * this migration pair) without swallowing unrelated database errors:
     * SQLSTATE 23505 (PostgreSQL) or driver code 19 / "UNIQUE
     * constraint failed" (SQLite), scoped to the recurrence index.
     */
    private function isUniquePeriodViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $uniqueViolation = ($errorInfo[0] ?? null) === '23505'
            || ($errorInfo[1] ?? null) === 19
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');

        return $uniqueViolation && str_contains($exception->getMessage(), 'recurrence');
    }

    private function guardNotPaid(Household $household, Bill $bill): void
    {
        $aggregates = $this->bills->paymentAggregatesForHousehold($household, [$bill->id]);

        if (($aggregates[$bill->id]['count'] ?? 0) >= 1) {
            throw new BillAlreadyPaidException;
        }
    }

    /**
     * Fail-closed category resolution (contract §4.6): null is a valid
     * "no category" choice; anything else must be an expense category of
     * this household — legacy untyped categories are rejected the same
     * way as non-expense ones.
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     */
    private function resolveCategory(Household $household, ?string $categoryId): ?Category
    {
        $category = $categoryId === null
            ? null
            : $this->categories->findByIdForHousehold($household, $categoryId);

        if ($categoryId !== null && $category === null) {
            throw new CategoryNotFoundException;
        }

        if ($category !== null && $category->type !== Category::TYPE_EXPENSE) {
            throw new CategoryTypeMismatchException('Bill requires an expense category.');
        }

        return $category;
    }

    private function intervalMonths(string $frequency): int
    {
        return match ($frequency) {
            BillRecurrence::FREQUENCY_QUARTERLY => 3,
            BillRecurrence::FREQUENCY_SEMIANNUAL => 6,
            BillRecurrence::FREQUENCY_YEARLY => 12,
            default => 1,
        };
    }

    /**
     * Pure calendar arithmetic on first-of-month strings (Y-m-01): no
     * timezone, no DST, no float — months are integer year*12+month
     * offsets. Asia/Jakarta business time enters only through
     * PeriodBounds::businessToday() comparisons.
     */
    private function addMonths(string $monthFirst, int $months): string
    {
        $total = ((int) substr($monthFirst, 0, 4)) * 12
            + ((int) substr($monthFirst, 5, 2)) - 1
            + $months;

        return sprintf('%04d-%02d-01', intdiv($total, 12), ($total % 12) + 1);
    }

    private function monthsBetween(string $fromMonth, string $toMonth): int
    {
        $from = ((int) substr($fromMonth, 0, 4)) * 12 + ((int) substr($fromMonth, 5, 2)) - 1;
        $to = ((int) substr($toMonth, 0, 4)) * 12 + ((int) substr($toMonth, 5, 2)) - 1;

        return $to - $from;
    }

    /**
     * due_date = min(due_day, days in target month): 31 always lands on
     * the last day of short months (contract §7.3) for every frequency.
     */
    private function clampDueDate(int $dueDay, string $periodMonth): string
    {
        $year = (int) substr($periodMonth, 0, 4);
        $month = (int) substr($periodMonth, 5, 2);

        return sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            min(max($dueDay, 1), $this->daysInMonth($year, $month)),
        );
    }

    private function daysInMonth(int $year, int $month): int
    {
        if ($month === 2 && (($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0)) {
            return 29;
        }

        return [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month - 1];
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
     * aggregates return null.
     */
    private function normalizeDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
