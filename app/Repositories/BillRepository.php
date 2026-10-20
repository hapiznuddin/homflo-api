<?php

namespace App\Repositories;

use App\Models\Bill;
use App\Models\BillRecurrence;
use App\Models\Household;
use App\Models\Transaction;
use App\Repositories\Contracts\BillRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class BillRepository implements BillRepositoryInterface
{
    /**
     * @param  array{include_archived?: bool}  $filters
     * @return Collection<int, Bill>
     */
    public function listForHousehold(Household $household, array $filters): Collection
    {
        $query = Bill::query()
            ->with(['category', 'recurrence'])
            ->where('household_id', $household->id);

        if (! ($filters['include_archived'] ?? false)) {
            $query->where('is_active', true);
        }

        return $query
            ->orderByRaw('(due_date IS NULL) ASC')
            ->orderBy('due_date', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    public function findByIdForHousehold(Household $household, string $billId): ?Bill
    {
        return Bill::query()
            ->with(['category', 'recurrence'])
            ->where('household_id', $household->id)
            ->where('id', $billId)
            ->first();
    }

    public function lockBillForHousehold(Household $household, string $billId): ?Bill
    {
        return Bill::query()
            ->where('household_id', $household->id)
            ->where('id', $billId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Bill
    {
        return Bill::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Bill $bill, array $attributes): Bill
    {
        $bill->fill($attributes);
        $bill->save();

        return $bill;
    }

    /**
     * @param  array<int, string>  $billIds
     * @param  array<string, mixed>  $attributes
     */
    public function updateBills(array $billIds, array $attributes): int
    {
        if ($billIds === []) {
            return 0;
        }

        return Bill::query()
            ->whereIn('id', $billIds)
            ->update($attributes);
    }

    public function delete(Bill $bill): void
    {
        $bill->delete();
    }

    /**
     * Single grouped query over the source morph — SUM + COUNT for many
     * bills at once, so listing N bills never issues N queries.
     *
     * @param  array<int, string>  $billIds
     * @return array<string, array{sum: mixed, count: int}>
     */
    public function paymentAggregatesForHousehold(Household $household, array $billIds): array
    {
        $billIds = array_values(array_unique($billIds));

        if ($billIds === []) {
            return [];
        }

        $rows = Transaction::query()
            ->where('household_id', $household->id)
            ->where('source_type', Bill::class)
            ->whereIn('source_id', $billIds)
            ->where('type', Transaction::TYPE_EXPENSE)
            ->groupBy('source_id')
            ->selectRaw('source_id, SUM(amount) AS aggregate_sum, COUNT(*) AS aggregate_count')
            ->get();

        $aggregates = [];

        foreach ($rows as $row) {
            $aggregates[$row->source_id] = [
                'sum' => $row->aggregate_sum,
                'count' => (int) $row->aggregate_count,
            ];
        }

        return $aggregates;
    }

    /**
     * @param  array<int, string>  $billIds
     * @return array<int, string>
     */
    public function paidBillIdsForHousehold(Household $household, array $billIds): array
    {
        $paid = [];

        foreach ($this->paymentAggregatesForHousehold($household, $billIds) as $billId => $aggregate) {
            if ($aggregate['count'] >= 1) {
                $paid[] = $billId;
            }
        }

        return $paid;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function paymentsForBill(Household $household, Bill $bill): Collection
    {
        return $bill->payments()
            ->with('sourceWallet:id,name,type,is_active')
            ->orderByDesc('occurred_on')
            ->orderByDesc('created_at')
            ->get();
    }

    public function findRecurrenceByIdForHousehold(Household $household, string $recurrenceId): ?BillRecurrence
    {
        return BillRecurrence::query()
            ->with('category')
            ->where('household_id', $household->id)
            ->where('id', $recurrenceId)
            ->first();
    }

    /**
     * @return Collection<int, BillRecurrence>
     */
    public function activeRecurrencesForHousehold(Household $household): Collection
    {
        return BillRecurrence::query()
            ->where('household_id', $household->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRecurrence(array $attributes): BillRecurrence
    {
        return BillRecurrence::query()->create($attributes);
    }

    public function lockRecurrenceForHousehold(Household $household, string $recurrenceId): BillRecurrence
    {
        return BillRecurrence::query()
            ->with('category')
            ->where('household_id', $household->id)
            ->where('id', $recurrenceId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function periodExists(string $recurrenceId, string $periodMonth): bool
    {
        // Date columns serialize as 'Y-m-d H:i:s' on SQLite but as plain
        // 'Y-m-d' on PostgreSQL (date typmod truncates the time), so a
        // literal equality would silently skip the recheck on one of the
        // two drivers. Matching on the shared month prefix keeps this
        // defensive check portable; the partial unique index remains the
        // fail-loud backstop either way.
        return Bill::query()
            ->where('recurrence_id', $recurrenceId)
            ->whereRaw('substr(recurrence_period, 1, 7) = ?', [substr($periodMonth, 0, 7)])
            ->exists();
    }

    public function firstPeriodForRecurrence(BillRecurrence $recurrence): ?Bill
    {
        return Bill::query()
            ->where('recurrence_id', $recurrence->id)
            ->orderBy('recurrence_period')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Candidate rows are locked (FOR UPDATE) *before* the payment
     * recheck (D-R2 / NEW-1 fix): a payment writer racing this path
     * either commits before the lock — and the fresh recheck excludes
     * that bill — or waits and finds its bill untouched. Paid and
     * archived bills are never returned.
     *
     * @return Collection<int, Bill>
     */
    public function unpaidActivePeriodBills(Household $household, BillRecurrence $recurrence): Collection
    {
        $active = Bill::query()
            ->where('household_id', $household->id)
            ->where('recurrence_id', $recurrence->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();

        if ($active->isEmpty()) {
            return $active;
        }

        $paid = array_flip($this->paidBillIdsForHousehold($household, $active->pluck('id')->all()));

        return $active
            ->filter(fn (Bill $bill) => ! array_key_exists($bill->id, $paid))
            ->values();
    }
}
