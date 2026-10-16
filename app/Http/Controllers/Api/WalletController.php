<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientFundsException;
use App\Exceptions\WalletAlreadyExistsException;
use App\Exceptions\WalletInUseException;
use App\Exceptions\WalletNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexWalletAdjustmentRequest;
use App\Http\Requests\Api\StoreWalletAdjustmentRequest;
use App\Http\Requests\Api\StoreWalletRequest;
use App\Http\Requests\Api\UpdateWalletRequest;
use App\Models\Household;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use App\Repositories\Contracts\WalletAdjustmentRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Finance\WalletService;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly HouseholdService $householdService,
        private readonly WalletRepositoryInterface $wallets,
        private readonly WalletAdjustmentRepositoryInterface $adjustments,
    ) {}

    public function index(IndexWalletAdjustmentRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Wallet::class, $household])) {
            return $this->householdNotFound();
        }

        $result = $this->walletService->listForHousehold($household);

        return response()->json([
            'success' => true,
            'data' => $result['wallets']->map(fn (Wallet $wallet) => $this->presentWallet(
                $wallet,
                $result['balances'][$wallet->id] ?? '0.00',
            ))->all(),
        ]);
    }

    public function store(StoreWalletRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Wallet::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $wallet = $this->walletService->record($household, $request->validated());
        } catch (WalletAlreadyExistsException $exception) {
            return $this->unprocessable('WALLET_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentWallet($wallet, $this->wallets->balanceFor($wallet)),
        ], 201);
    }

    public function show(Wallet $wallet): JsonResponse
    {
        $resolved = $this->resolveWallet($wallet);

        if ($resolved === null) {
            return $this->walletNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->walletNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentWallet($resolved, $this->wallets->balanceFor($resolved)),
        ]);
    }

    public function update(UpdateWalletRequest $request, Wallet $wallet): JsonResponse
    {
        $resolved = $this->resolveWallet($wallet);

        if ($resolved === null) {
            return $this->walletNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->walletNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->walletNotFound();
        }

        try {
            $updated = $this->walletService->change($household, $resolved, $request->validated());
        } catch (WalletAlreadyExistsException $exception) {
            return $this->unprocessable('WALLET_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentWallet($updated, $this->wallets->balanceFor($updated)),
        ]);
    }

    public function deactivate(Wallet $wallet): JsonResponse
    {
        $resolved = $this->resolveWallet($wallet);

        if ($resolved === null) {
            return $this->walletNotFound();
        }

        if (! Gate::allows('deactivate', $resolved)) {
            return $this->walletNotFound();
        }

        $deactivated = $this->walletService->deactivate($resolved);

        return response()->json([
            'success' => true,
            'data' => $this->presentWallet($deactivated, $this->wallets->balanceFor($deactivated)),
        ]);
    }

    public function destroy(Wallet $wallet): JsonResponse
    {
        $resolved = $this->resolveWallet($wallet);

        if ($resolved === null) {
            return $this->walletNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->walletNotFound();
        }

        try {
            $this->walletService->remove($resolved);
        } catch (WalletInUseException $exception) {
            return $this->unprocessable('WALLET_IN_USE', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => ['id' => $resolved->id],
        ]);
    }

    public function adjustments(IndexWalletAdjustmentRequest $request, Wallet $wallet): JsonResponse
    {
        $resolved = $this->resolveWallet($wallet);

        if ($resolved === null) {
            return $this->walletNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->walletNotFound();
        }

        if (! Gate::allows('viewAny', [WalletAdjustment::class, $household])) {
            return $this->walletNotFound();
        }

        $validated = $request->validated();
        $paginator = $this->adjustments->paginateForWallet($resolved, (int) ($validated['per_page'] ?? 15));

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $paginator->getCollection()->map(fn (WalletAdjustment $adjustment) => $this->presentAdjustment($adjustment))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function adjust(StoreWalletAdjustmentRequest $request, Wallet $wallet): JsonResponse
    {
        $resolved = $this->resolveWallet($wallet);

        if ($resolved === null) {
            return $this->walletNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->walletNotFound();
        }

        if (! Gate::allows('create', [WalletAdjustment::class, $household])) {
            return $this->walletNotFound();
        }

        try {
            $adjustment = $this->walletService->adjust($household, $resolved, $request->user(), $request->validated());
        } catch (WalletNotFoundException) {
            return $this->walletNotFound();
        } catch (InsufficientFundsException $exception) {
            return $this->unprocessable('INSUFFICIENT_FUNDS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentAdjustment($adjustment->refresh()),
        ], 201);
    }

    private function resolveHousehold(mixed $user): ?Household
    {
        $context = $this->householdService->resolveUserHouseholdContext($user);

        if ($context['household'] === null) {
            return null;
        }

        return Household::query()->find($context['household']['id']);
    }

    private function resolveWallet(Wallet $wallet): ?Wallet
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        if ($wallet->household_id !== $household->id) {
            return null;
        }

        return $this->wallets->findByIdForHousehold($household, $wallet->id);
    }

    /**
     * @return array{id: string, household_id: string, name: string, type: string, opening_balance: string, is_active: bool, balance: string, created_at: mixed, updated_at: mixed}
     */
    private function presentWallet(Wallet $wallet, string $balance): array
    {
        return [
            'id' => $wallet->id,
            'household_id' => $wallet->household_id,
            'name' => $wallet->name,
            'type' => $wallet->type,
            'opening_balance' => (string) $wallet->opening_balance,
            'is_active' => (bool) $wallet->is_active,
            'balance' => $balance,
            'created_at' => $wallet->created_at,
            'updated_at' => $wallet->updated_at,
        ];
    }

    /**
     * @return array{id: string, household_id: string, wallet_id: string, amount: string, balance_before: string, balance_after: string, reason: string, created_by: ?array{id: string, name: string}, created_at: mixed, updated_at: mixed}
     */
    private function presentAdjustment(WalletAdjustment $adjustment): array
    {
        $adjustment->loadMissing('creator');

        return [
            'id' => $adjustment->id,
            'household_id' => $adjustment->household_id,
            'wallet_id' => $adjustment->wallet_id,
            'amount' => (string) $adjustment->amount,
            'balance_before' => (string) $adjustment->balance_before,
            'balance_after' => (string) $adjustment->balance_after,
            'reason' => $adjustment->reason,
            'created_by' => $adjustment->creator === null ? null : [
                'id' => $adjustment->creator->id,
                'name' => $adjustment->creator->name,
            ],
            'created_at' => $adjustment->created_at,
            'updated_at' => $adjustment->updated_at,
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
