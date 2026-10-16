<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\CategoryTypeMismatchException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidTransferException;
use App\Exceptions\WalletNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexTransactionRequest;
use App\Http\Requests\Api\StoreTransactionRequest;
use App\Http\Requests\Api\UpdateTransactionRequest;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Finance\TransactionService;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly HouseholdService $householdService,
        private readonly TransactionRepositoryInterface $transactions,
    ) {}

    public function index(IndexTransactionRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Transaction::class, $household])) {
            return $this->householdNotFound();
        }

        $listing = $request->listing();
        $paginator = $this->transactionService->listForHousehold(
            $household,
            $request->filters(),
            $listing['sort_by'],
            $listing['sort_dir'],
            $listing['per_page'],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $paginator->getCollection()->map(fn (Transaction $transaction) => $this->presentTransaction($transaction))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Transaction::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $transaction = $this->transactionService->record($household, $request->validated());
        } catch (CategoryNotFoundException $exception) {
            return $this->categoryNotFound($exception);
        } catch (CategoryTypeMismatchException|InvalidTransferException $exception) {
            return $this->unprocessable('TRANSACTION_INVALID', $exception->getMessage());
        } catch (WalletNotFoundException) {
            return $this->walletNotFound();
        } catch (InsufficientFundsException $exception) {
            return $this->unprocessable('INSUFFICIENT_FUNDS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentTransaction($transaction),
        ], 201);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $resolved = $this->resolveTransaction($transaction);

        if ($resolved === null) {
            return $this->transactionNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->transactionNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentTransaction($resolved),
        ]);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $resolved = $this->resolveTransaction($transaction);

        if ($resolved === null) {
            return $this->transactionNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->transactionNotFound();
        }

        try {
            $updated = $this->transactionService->change($resolved->household, $resolved, $request->validated());
        } catch (CategoryNotFoundException $exception) {
            return $this->categoryNotFound($exception);
        } catch (CategoryTypeMismatchException|InvalidTransferException $exception) {
            return $this->unprocessable('TRANSACTION_INVALID', $exception->getMessage());
        } catch (WalletNotFoundException) {
            return $this->walletNotFound();
        } catch (InsufficientFundsException $exception) {
            return $this->unprocessable('INSUFFICIENT_FUNDS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentTransaction($updated),
        ]);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $resolved = $this->resolveTransaction($transaction);

        if ($resolved === null) {
            return $this->transactionNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->transactionNotFound();
        }

        try {
            $this->transactionService->remove($resolved->household, $resolved);
        } catch (InsufficientFundsException $exception) {
            return $this->unprocessable('INSUFFICIENT_FUNDS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => ['id' => $resolved->id],
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

    private function resolveTransaction(Transaction $transaction): ?Transaction
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($transaction->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->transactions->findByIdForHousehold($household, $transaction->id);
    }

    /**
     * @return array{id: string, household_id: string, type: string, amount: string, occurred_on: string, description: ?string, wallet: ?string, wallet_id: ?string, destination_wallet_id: ?string, source_wallet: ?array{id: string, name: string, type: string, is_active: bool}, destination_wallet: ?array{id: string, name: string, type: string, is_active: bool}, category: ?array{id: string, name: string, icon: ?string}, created_at: mixed, updated_at: mixed}
     */
    private function presentTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing('category', 'sourceWallet', 'destinationWallet');

        return [
            'id' => $transaction->id,
            'household_id' => $transaction->household_id,
            'type' => $transaction->type,
            'amount' => (string) $transaction->amount,
            'occurred_on' => $transaction->occurred_on->format('Y-m-d'),
            'description' => $transaction->description,
            'wallet' => $transaction->wallet,
            'wallet_id' => $transaction->wallet_id,
            'destination_wallet_id' => $transaction->destination_wallet_id,
            'source_wallet' => $this->presentWalletLink($transaction->sourceWallet),
            'destination_wallet' => $this->presentWalletLink($transaction->destinationWallet),
            'category' => $transaction->category === null ? null : [
                'id' => $transaction->category->id,
                'name' => $transaction->category->name,
                'icon' => $transaction->category->icon,
            ],
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ];
    }

    /**
     * @return array{id: string, name: string, type: string, is_active: bool}|null
     */
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

    private function transactionNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'TRANSACTION_NOT_FOUND',
                'message' => 'Transaction not found.',
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

    private function categoryNotFound(CategoryNotFoundException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'CATEGORY_NOT_FOUND',
                'message' => $exception->getMessage(),
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
