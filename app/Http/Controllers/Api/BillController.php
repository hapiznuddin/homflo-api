<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BillAlreadyPaidException;
use App\Exceptions\BillArchivedException;
use App\Exceptions\BillNotPaidException;
use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\CategoryTypeMismatchException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\WalletNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexBillRequest;
use App\Http\Requests\Api\PayBillRequest;
use App\Http\Requests\Api\StoreBillRequest;
use App\Http\Requests\Api\UpdateBillRecurrenceRequest;
use App\Http\Requests\Api\UpdateBillRequest;
use App\Models\Bill;
use App\Models\BillRecurrence;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Repositories\Contracts\BillRepositoryInterface;
use App\Services\Finance\BillService;
use App\Services\Household\HouseholdService;
use Illuminate\Database\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BillController extends Controller
{
    use PresentsTransactions;

    public function __construct(
        private readonly BillService $billService,
        private readonly HouseholdService $householdService,
        private readonly BillRepositoryInterface $bills,
    ) {}

    public function index(IndexBillRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Bill::class, $household])) {
            return $this->householdNotFound();
        }

        $result = $this->billService->listWithDerived($household, $request->filters());

        return response()->json([
            'success' => true,
            'data' => $result['items']->map(fn (Bill $bill) => $this->presentBill(
                $bill,
                $result['derived'][$bill->id],
            ))->all(),
        ]);
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Bill::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $bill = $this->billService->record($household, $request->validated());
        } catch (CategoryNotFoundException) {
            return $this->categoryNotFound();
        } catch (CategoryTypeMismatchException $exception) {
            return $this->unprocessable('CATEGORY_TYPE_MISMATCH', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentBill($bill, $this->billService->deriveFor($household, $bill)),
        ], 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        $resolved = $this->resolveBill($bill);

        if ($resolved === null) {
            return $this->billNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->billNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->billNotFound();
        }

        $data = array_merge($this->presentBill($resolved, $this->billService->deriveFor($household, $resolved)), [
            'payments' => $this->bills->paymentsForBill($household, $resolved)
                ->map(fn (Transaction $payment) => $this->presentPayment($payment))
                ->all(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function update(UpdateBillRequest $request, Bill $bill): JsonResponse
    {
        $resolved = $this->resolveBill($bill);

        if ($resolved === null) {
            return $this->billNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->billNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->billNotFound();
        }

        try {
            $updated = $this->billService->change($household, $resolved, $request->validated());
        } catch (BillAlreadyPaidException $exception) {
            return $this->unprocessable('BILL_ALREADY_PAID', $exception->getMessage());
        } catch (CategoryNotFoundException) {
            return $this->categoryNotFound();
        } catch (CategoryTypeMismatchException $exception) {
            return $this->unprocessable('CATEGORY_TYPE_MISMATCH', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentBill($updated, $this->billService->deriveFor($household, $updated)),
        ]);
    }

    public function destroy(Bill $bill): JsonResponse
    {
        $resolved = $this->resolveBill($bill);

        if ($resolved === null) {
            return $this->billNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->billNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->billNotFound();
        }

        try {
            $this->billService->remove($household, $resolved);
        } catch (BillAlreadyPaidException $exception) {
            return $this->unprocessable('BILL_ALREADY_PAID', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => ['id' => $resolved->id],
        ]);
    }

    public function archive(Bill $bill): JsonResponse
    {
        $resolved = $this->resolveBill($bill);

        if ($resolved === null) {
            return $this->billNotFound();
        }

        if (! Gate::allows('archive', $resolved)) {
            return $this->billNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->billNotFound();
        }

        try {
            $archived = $this->billService->archive($household, $resolved);
        } catch (BillNotPaidException $exception) {
            return $this->unprocessable('BILL_NOT_PAID', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentBill($archived, $this->billService->deriveFor($household, $archived)),
        ]);
    }

    /**
     * Pay a bill in full (frozen contract §5.7): owner-only + verified,
     * household-scoped with 404 masking, then one atomic service
     * transaction (lock → lifecycle recheck → inherited category →
     * wallet lock → balance check → single expense row). Every lifecycle
     * 422 is only reachable after scoping and the owner Gate, so no
     * error can confirm a foreign bill's existence.
     */
    public function pay(PayBillRequest $request, Bill $bill): JsonResponse
    {
        $resolved = $this->resolveBill($bill);

        if ($resolved === null) {
            return $this->billNotFound();
        }

        if (! Gate::allows('pay', $resolved)) {
            return $this->billNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->billNotFound();
        }

        try {
            $payment = $this->billService->pay($household, $resolved, $request->validated());
        } catch (ModelNotFoundException) {
            // The bill vanished between the scoped resolve and the row
            // lock — surface the same 404 mask as a missing bill, never
            // a raw framework error body.
            return $this->billNotFound();
        } catch (BillArchivedException $exception) {
            return $this->unprocessable('BILL_ARCHIVED', $exception->getMessage());
        } catch (BillAlreadyPaidException $exception) {
            return $this->unprocessable('BILL_ALREADY_PAID', $exception->getMessage());
        } catch (CategoryNotFoundException) {
            return $this->categoryNotFound();
        } catch (CategoryTypeMismatchException $exception) {
            return $this->unprocessable('CATEGORY_TYPE_MISMATCH', $exception->getMessage());
        } catch (WalletNotFoundException) {
            return $this->walletNotFound();
        } catch (InsufficientFundsException $exception) {
            return $this->unprocessable('INSUFFICIENT_FUNDS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment' => $this->presentTransaction($payment),
                'bill' => $this->presentBill($resolved, $this->billService->deriveFor($household, $resolved)),
            ],
        ], 201);
    }

    public function updateRecurrence(UpdateBillRecurrenceRequest $request, BillRecurrence $recurrence): JsonResponse
    {
        $resolved = $this->resolveRecurrence($recurrence);

        if ($resolved === null) {
            return $this->billNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->billNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->billNotFound();
        }

        try {
            $updated = $this->billService->changeRecurrence($household, $resolved, $request->validated());
        } catch (CategoryNotFoundException) {
            return $this->categoryNotFound();
        } catch (CategoryTypeMismatchException $exception) {
            return $this->unprocessable('CATEGORY_TYPE_MISMATCH', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentRecurrence($updated),
        ]);
    }

    private function resolveHousehold(mixed $user): ?Household
    {
        $context = $this->householdService->resolveUserHouseholdContext($user);

        if ($context['household'] === null) {
            return null;
        }

        return Household::query()->find($context['household']['id']);
    }

    private function resolveBill(Bill $bill): ?Bill
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($bill->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->bills->findByIdForHousehold($household, $bill->id);
    }

    private function resolveRecurrence(BillRecurrence $recurrence): ?BillRecurrence
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($recurrence->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->bills->findRecurrenceByIdForHousehold($household, $recurrence->id);
    }

    /**
     * Present one bill with its derived payment fields. Money stays an
     * exact two-decimal string; status/overdue/paid amounts are computed
     * per request and never stored.
     *
     * @param  array{paid_amount: string, remaining_amount: string, payment_count: int, status: string, overdue: bool}  $derived
     * @return array{id: string, household_id: string, category: ?array{id: string, name: string, icon: ?string}, title: string, amount: string, due_date: ?string, is_active: bool, notes: ?string, recurrence: ?array{id: string, frequency: string, due_day: int, is_active: bool}, recurrence_period: ?string, paid_amount: string, remaining_amount: string, payment_count: int, status: string, overdue: bool, created_at: mixed, updated_at: mixed}
     */
    private function presentBill(Bill $bill, array $derived): array
    {
        $bill->loadMissing(['category', 'recurrence']);

        return [
            'id' => $bill->id,
            'household_id' => $bill->household_id,
            'category' => $bill->category === null ? null : [
                'id' => $bill->category->id,
                'name' => $bill->category->name,
                'icon' => $bill->category->icon,
            ],
            'title' => $bill->title,
            'amount' => (string) $bill->amount,
            'due_date' => $bill->due_date?->format('Y-m-d'),
            'is_active' => (bool) $bill->is_active,
            'notes' => $bill->notes,
            'recurrence' => $bill->recurrence === null ? null : [
                'id' => $bill->recurrence->id,
                'frequency' => $bill->recurrence->frequency,
                'due_day' => $bill->recurrence->due_day,
                'is_active' => (bool) $bill->recurrence->is_active,
            ],
            'recurrence_period' => $bill->recurrence_period?->format('Y-m-d'),
            'paid_amount' => $derived['paid_amount'],
            'remaining_amount' => $derived['remaining_amount'],
            'payment_count' => $derived['payment_count'],
            'status' => $derived['status'],
            'overdue' => $derived['overdue'],
            'created_at' => $bill->created_at,
            'updated_at' => $bill->updated_at,
        ];
    }

    /**
     * @return array{id: string, household_id: string, title: string, amount: string, notes: ?string, frequency: string, due_day: int, is_active: bool, category: ?array{id: string, name: string, icon: ?string}, created_at: mixed, updated_at: mixed}
     */
    private function presentRecurrence(BillRecurrence $recurrence): array
    {
        $recurrence->loadMissing('category');

        return [
            'id' => $recurrence->id,
            'household_id' => $recurrence->household_id,
            'title' => $recurrence->title,
            'amount' => (string) $recurrence->amount,
            'notes' => $recurrence->notes,
            'frequency' => $recurrence->frequency,
            'due_day' => $recurrence->due_day,
            'is_active' => (bool) $recurrence->is_active,
            'category' => $recurrence->category === null ? null : [
                'id' => $recurrence->category->id,
                'name' => $recurrence->category->name,
                'icon' => $recurrence->category->icon,
            ],
            'created_at' => $recurrence->created_at,
            'updated_at' => $recurrence->updated_at,
        ];
    }

    /**
     * One payment row of a bill detail: an expense transaction whose
     * source morph points at the bill. Read-only history — payments are
     * immutable and there is no payment endpoint in this phase.
     *
     * @return array{id: string, amount: string, occurred_on: ?string, description: ?string, wallet: ?array{id: string, name: string, type: string, is_active: bool}, created_at: mixed}
     */
    private function presentPayment(Transaction $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (string) $payment->amount,
            'occurred_on' => $payment->occurred_on?->format('Y-m-d'),
            'description' => $payment->description,
            'wallet' => $this->presentWalletLink($payment->sourceWallet),
            'created_at' => $payment->created_at,
        ];
    }

    private function presentWalletLink(?Wallet $wallet): ?array
    {
        if ($wallet === null) {
            return null;
        }

        return [
            'id' => $wallet->id,
            'name' => $wallet->name,
            'type' => $wallet->type,
            'is_active' => (bool) $wallet->is_active,
        ];
    }

    private function householdNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'HOUSEHOLD_NOT_FOUND',
                'message' => 'Household not found.',
            ],
        ], 404);
    }

    private function billNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'BILL_NOT_FOUND',
                'message' => 'Bill not found.',
            ],
        ], 404);
    }

    private function categoryNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'CATEGORY_NOT_FOUND',
                'message' => 'Category not found.',
            ],
        ], 404);
    }

    private function walletNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found.',
            ],
        ], 404);
    }

    private function unprocessable(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 422);
    }
}
