<?php

namespace App\Repositories\Contracts;

use App\Models\Bill;
use App\Models\BillRecurrence;
use App\Models\Household;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

interface BillRepositoryInterface
{
    /**
     * Bills of one household for the index. Archived bills are hidden
     * unless include_archived is set; ordering is portable null-safe
     * due_date ASC (NULLs last) then created_at ASC on SQLite and
     * PostgreSQL alike, with category/recurrence eager-loaded.
     *
     * @param  array{include_archived?: bool}  $filters
     * @return Collection<int, Bill>
     */
    public function listForHousehold(Household $household, array $filters): Collection;

    public function findByIdForHousehold(Household $household, string $billId): ?Bill;

    /**
     * SELECT ... FOR UPDATE of one bill row inside the caller's
     * transaction; returns the fresh row so lifecycle rechecks (paid,
     * archived) happen after the lock is held. Null when the row is
     * missing or belongs to another household (races with a concurrent
     * delete surface as not-found, never as a crash).
     */
    public function lockBillForHousehold(Household $household, string $billId): ?Bill;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Bill;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Bill $bill, array $attributes): Bill;

    /**
     * Mass-update a known set of bills (D-03 schedule propagation).
     * Eloquent adds updated_at automatically.
     *
     * @param  array<int, string>  $billIds
     * @param  array<string, mixed>  $attributes
     */
    public function updateBills(array $billIds, array $attributes): int;

    public function delete(Bill $bill): void;

    /**
     * One grouped morph aggregate for many bills — the single query
     * behind paid_amount/payment_count (never one query per bill).
     *
     * @param  array<int, string>  $billIds
     * @return array<string, array{sum: mixed, count: int}>
     */
    public function paymentAggregatesForHousehold(Household $household, array $billIds): array;

    /**
     * Bill ids that already carry at least one expense payment.
     *
     * @param  array<int, string>  $billIds
     * @return array<int, string>
     */
    public function paidBillIdsForHousehold(Household $household, array $billIds): array;

    /**
     * Payment history for one bill detail (expense morph rows of this
     * household), newest first by occurred_on then created_at.
     *
     * @return Collection<int, Transaction>
     */
    public function paymentsForBill(Household $household, Bill $bill): Collection;

    public function findRecurrenceByIdForHousehold(Household $household, string $recurrenceId): ?BillRecurrence;

    /**
     * Active schedules ordered by id so concurrent sweeps always lock
     * rows in the same global order (no lock-order deadlock).
     *
     * @return Collection<int, BillRecurrence>
     */
    public function activeRecurrencesForHousehold(Household $household): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRecurrence(array $attributes): BillRecurrence;

    /**
     * SELECT ... FOR UPDATE of one schedule inside the caller's
     * transaction; returns the fresh row so the cursor recheck happens
     * after the lock. Throws ModelNotFoundException if the row vanished
     * (not reachable through the API — no delete-schedule endpoint).
     */
    public function lockRecurrenceForHousehold(Household $household, string $recurrenceId): BillRecurrence;

    /**
     * Defensive existence recheck used by materialization (D-05 skips
     * and idempotent re-runs).
     */
    public function periodExists(string $recurrenceId, string $periodMonth): bool;

    /**
     * First (earliest period) bill of a series — the create response.
     */
    public function firstPeriodForRecurrence(BillRecurrence $recurrence): ?Bill;

    /**
     * Propagation targets for D-03: period bills of one series that are
     * still active (not archived). Implementations lock the candidate
     * rows before rechecking payment status (D-R2) so a racing payment
     * can never be overwritten by propagation. Paid bills are never
     * returned.
     *
     * @return Collection<int, Bill>
     */
    public function unpaidActivePeriodBills(Household $household, BillRecurrence $recurrence): Collection;
}
