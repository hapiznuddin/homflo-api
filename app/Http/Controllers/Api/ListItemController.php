<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompleteListItemRequest;
use App\Http\Requests\Api\StoreListExpenseRequest;
use App\Http\Requests\Api\StoreListItemRequest;
use App\Http\Requests\Api\UpdateListItemRequest;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdList;
use App\Models\HouseholdMember;
use App\Models\ListItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Finance\LedgerMath;
use App\Services\Household\HouseholdService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListItemController extends Controller
{
    public function __construct(
        private readonly HouseholdService $householdService,
        private readonly WalletRepositoryInterface $wallets,
    ) {}

    public function index(Request $request, HouseholdList $list): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        $request->validate([
            'status' => ['sometimes', 'string', 'in:active,completed'],
        ]);

        $status = $request->query('status');
        $relations = ['creator', 'completer', 'expense.sourceWallet', 'expense.category'];

        $active = $list->items()
            ->with($relations)
            ->whereNull('completed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $completed = $list->items()
            ->with($relations)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        $items = match ($status) {
            'active' => $active,
            'completed' => $completed,
            default => $active->concat($completed),
        };

        return response()->json([
            'success' => true,
            'data' => $items->map(fn (ListItem $item) => $this->presentItem($item))->all(),
        ]);
    }

    public function store(StoreListItemRequest $request, HouseholdList $list): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        if ($list->isArchived()) {
            return $this->listArchived('List is archived; items cannot be added.');
        }

        $validated = $request->validated();

        $item = ListItem::create([
            'list_id' => $list->id,
            'title' => $validated['title'],
            'quantity' => $validated['quantity'] ?? null,
            'unit' => $validated['unit'] ?? null,
            'estimated_price' => $validated['estimated_price'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $list->touch();

        $item->load(['creator', 'completer', 'expense.sourceWallet', 'expense.category']);

        return response()->json([
            'success' => true,
            'data' => $this->presentItem($item),
        ], 201);
    }

    public function show(Request $request, HouseholdList $list, ListItem $item): JsonResponse
    {
        $resolved = $this->resolveItem($request->user(), $list, $item);

        if ($resolved === null) {
            return $this->itemNotFound();
        }

        $resolved->load(['creator', 'completer', 'expense.sourceWallet', 'expense.category']);

        return response()->json([
            'success' => true,
            'data' => $this->presentItem($resolved),
        ]);
    }

    public function update(UpdateListItemRequest $request, HouseholdList $list, ListItem $item): JsonResponse
    {
        $resolved = $this->resolveItem($request->user(), $list, $item);

        if ($resolved === null) {
            return $this->itemNotFound();
        }

        if ($resolved->list->isArchived()) {
            return $this->listArchived('List is archived; item updates disallowed.');
        }

        $validated = $request->validated();
        $updates = [];

        if ($resolved->isCompleted()) {
            $editable = ['title', 'quantity', 'unit', 'estimated_price'];

            foreach ($editable as $field) {
                if (array_key_exists($field, $validated)) {
                    return $this->error(
                        'LIST_ITEM_ALREADY_COMPLETED',
                        'Item already completed. Use uncomplete first if needed.',
                        409,
                    );
                }
            }

            if (array_key_exists('actual_price', $validated)) {
                if ($resolved->hasExpense()) {
                    return $this->error(
                        'LIST_ITEM_PRICE_LOCKED',
                        'Actual price locked; Expense already exists.',
                        409,
                    );
                }

                $updates['actual_price'] = $validated['actual_price'];
            }
        } else {
            if (array_key_exists('actual_price', $validated)) {
                return $this->error(
                    'LIST_ITEM_NOT_COMPLETED',
                    'Actual price can only be changed after completion.',
                    422,
                );
            }

            foreach (['title', 'quantity', 'unit', 'estimated_price'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }
        }

        if ($updates !== []) {
            $resolved->update($updates);
            $resolved->list->touch();
        }

        $resolved->refresh()->load(['creator', 'completer', 'expense.sourceWallet', 'expense.category']);

        return response()->json([
            'success' => true,
            'data' => $this->presentItem($resolved),
        ]);
    }

    public function complete(CompleteListItemRequest $request, HouseholdList $list, ListItem $item): JsonResponse
    {
        $resolved = $this->resolveItem($request->user(), $list, $item);

        if ($resolved === null) {
            return $this->itemNotFound();
        }

        if ($resolved->list->isArchived()) {
            return $this->listArchived('List is archived; completion disallowed.');
        }

        if ($resolved->isCompleted()) {
            return $this->error('LIST_ITEM_ALREADY_COMPLETED', 'Item already completed.', 409);
        }

        $validated = $request->validated();
        $hasActualPrice = array_key_exists('actual_price', $validated);

        if ($hasActualPrice && $resolved->hasExpense()
            && ! $this->sameDecimal($resolved->actual_price, $validated['actual_price'])) {
            return $this->error(
                'LIST_ITEM_PRICE_LOCKED',
                'Actual price locked; Expense already exists.',
                409,
            );
        }

        $result = DB::transaction(function () use ($resolved, $request, $validated, $hasActualPrice): array {
            /** @var HouseholdList $lockedList */
            $lockedList = HouseholdList::query()
                ->whereKey($resolved->list_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ListItem $lockedItem */
            $lockedItem = ListItem::query()
                ->whereKey($resolved->id)
                ->where('list_id', $lockedList->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedList->archived_at !== null) {
                return ['error' => 'archived'];
            }

            if ($lockedItem->isCompleted()) {
                return ['error' => 'already_completed'];
            }

            if ($hasActualPrice && $lockedItem->hasExpense()
                && ! $this->sameDecimal($lockedItem->actual_price, $validated['actual_price'])) {
                return ['error' => 'price_locked'];
            }

            $lockedItem->completed_at = now()->utc();
            $lockedItem->completed_by = $request->user()->id;

            if ($hasActualPrice) {
                $lockedItem->actual_price = $validated['actual_price'];
            }

            $lockedItem->save();
            $lockedList->touch();

            return ['item' => $lockedItem];
        });

        return match ($result['error'] ?? null) {
            'archived' => $this->listArchived('List is archived; completion disallowed.'),
            'already_completed' => $this->error('LIST_ITEM_ALREADY_COMPLETED', 'Item already completed.', 409),
            'price_locked' => $this->error('LIST_ITEM_PRICE_LOCKED', 'Actual price locked; Expense already exists.', 409),
            default => $this->presentItemResponse($result['item']),
        };
    }

    public function uncomplete(Request $request, HouseholdList $list, ListItem $item): JsonResponse
    {
        $resolved = $this->resolveItem($request->user(), $list, $item);

        if ($resolved === null) {
            return $this->itemNotFound();
        }

        if ($resolved->list->isArchived()) {
            return $this->listArchived('List is archived; uncompletion disallowed.');
        }

        if (! $resolved->isCompleted()) {
            return $this->error('LIST_ITEM_NOT_COMPLETED', 'Item is not completed.', 409);
        }

        $result = DB::transaction(function () use ($resolved): array {
            /** @var HouseholdList $lockedList */
            $lockedList = HouseholdList::query()
                ->whereKey($resolved->list_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ListItem $lockedItem */
            $lockedItem = ListItem::query()
                ->whereKey($resolved->id)
                ->where('list_id', $lockedList->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedList->archived_at !== null) {
                return ['error' => 'archived'];
            }

            if (! $lockedItem->isCompleted()) {
                return ['error' => 'not_completed'];
            }

            $lockedItem->completed_at = null;
            $lockedItem->completed_by = null;
            // actual_price is deliberately retained (A-10 / AMD-05).
            $lockedItem->save();
            $lockedList->touch();

            return ['item' => $lockedItem];
        });

        return match ($result['error'] ?? null) {
            'archived' => $this->listArchived('List is archived; uncompletion disallowed.'),
            'not_completed' => $this->error('LIST_ITEM_NOT_COMPLETED', 'Item is not completed.', 409),
            default => $this->presentItemResponse($result['item']),
        };
    }

    public function destroy(Request $request, HouseholdList $list, ListItem $item): JsonResponse
    {
        $resolved = $this->resolveItem($request->user(), $list, $item);

        if ($resolved === null) {
            return $this->itemNotFound();
        }

        if ($resolved->list->isArchived()) {
            return $this->listArchived('List is archived; item deletion disallowed.');
        }

        if ($resolved->hasExpense()) {
            return $this->error(
                'LIST_ITEM_HAS_EXPENSE',
                'Item has an Expense and cannot be deleted.',
                409,
            );
        }

        $result = DB::transaction(function () use ($resolved): array {
            /** @var HouseholdList $lockedList */
            $lockedList = HouseholdList::query()
                ->whereKey($resolved->list_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ListItem $lockedItem */
            $lockedItem = ListItem::query()
                ->whereKey($resolved->id)
                ->where('list_id', $lockedList->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedList->archived_at !== null) {
                return ['error' => 'archived'];
            }

            if ($lockedItem->hasExpense()) {
                return ['error' => 'has_expense'];
            }

            $id = $lockedItem->id;
            $lockedItem->delete();
            $lockedList->touch();

            return ['id' => $id];
        });

        return match ($result['error'] ?? null) {
            'archived' => $this->listArchived('List is archived; item deletion disallowed.'),
            'has_expense' => $this->error('LIST_ITEM_HAS_EXPENSE', 'Item has an Expense and cannot be deleted.', 409),
            default => response()->json([
                'success' => true,
                'data' => ['id' => $result['id']],
            ]),
        };
    }

    public function expense(StoreListExpenseRequest $request, HouseholdList $list, ListItem $item): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null || $list->household_id !== $household->id || $item->list_id !== $list->id) {
            return $this->itemNotFound();
        }

        if ($list->isArchived()) {
            return $this->listArchived('List is archived; Expense creation disallowed.');
        }

        if (! $this->isOwner($request->user(), $household)) {
            return $this->error('FORBIDDEN', 'Only household owners can create Expenses.', 403);
        }

        if (! $item->isCompleted()) {
            return $this->error(
                'LIST_ITEM_NOT_COMPLETED',
                'Item must be completed before creating Expense.',
                409,
            );
        }

        // Expense is idempotent-200: a retry returns the existing row.
        $existing = $item->expense()->with(['sourceWallet', 'category'])->first();

        if ($existing !== null) {
            return response()->json([
                'success' => true,
                'data' => $this->presentExpense($existing),
                'info' => 'Expense already exists for this item. Klik generate untuk detail.',
            ]);
        }

        $validated = $request->validated();
        $actualPrice = array_key_exists('actual_price', $validated)
            ? $validated['actual_price']
            : $item->actual_price;

        if ($actualPrice === null || (float) $actualPrice <= 0) {
            return $this->error(
                'LIST_ITEM_ACTUAL_PRICE_REQUIRED',
                'Actual price is required for Expense and must be > 0.',
                422,
            );
        }

        $wallet = $this->resolveWallet($household, $validated['wallet_id'] ?? null);

        if ($wallet === null) {
            return $this->error('WALLET_NOT_FOUND', 'No active wallet found for household.', 404);
        }

        $category = $this->resolveCategory($household, $validated['category_id'] ?? null);

        if ($category === false) {
            return $this->error('CATEGORY_TYPE_MISMATCH', 'Category must be an expense category.', 422);
        }

        if ($category === null && array_key_exists('category_id', $validated) && $validated['category_id'] !== null) {
            return $this->error('CATEGORY_NOT_FOUND', 'Category not found.', 404);
        }

        try {
            $expense = DB::transaction(function () use ($list, $item, $household, $wallet, $category, $validated, $actualPrice): Transaction {
                /** @var HouseholdList $lockedList */
                $lockedList = HouseholdList::query()
                    ->whereKey($list->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var ListItem $lockedItem */
                $lockedItem = ListItem::query()
                    ->whereKey($item->id)
                    ->where('list_id', $lockedList->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var Wallet $lockedWallet */
                $lockedWallet = Wallet::query()
                    ->whereKey($wallet->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = $lockedItem->expense()->first();

                if ($existing !== null) {
                    return $existing;
                }

                $balance = $this->wallets->balanceFor($lockedWallet);
                $amount = number_format((float) $actualPrice, 2, '.', '');

                if (LedgerMath::compare($balance, $amount) < 0) {
                    throw new \RuntimeException('INSUFFICIENT_FUNDS');
                }

                $expense = Transaction::create([
                    'household_id' => $household->id,
                    'category_id' => $category?->id,
                    'wallet_id' => $lockedWallet->id,
                    'type' => Transaction::TYPE_EXPENSE,
                    'amount' => $amount,
                    'occurred_on' => $validated['occurred_on'] ?? now()->toDateString(),
                    'description' => $lockedItem->title,
                    'source_type' => ListItem::class,
                    'source_id' => $lockedItem->id,
                ]);

                if (array_key_exists('actual_price', $validated)) {
                    $lockedItem->actual_price = $validated['actual_price'];
                    $lockedItem->save();
                }

                $lockedList->touch();

                return $expense;
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'INSUFFICIENT_FUNDS') {
                return $this->error('INSUFFICIENT_FUNDS', 'Insufficient wallet balance for this expense.', 422);
            }

            throw $exception;
        } catch (QueryException $exception) {
            // The partial unique index is the final guard against two
            // concurrent writers. The failed transaction is rolled back
            // by the time we get here, so a fresh read is safe.
            $existing = $item->expense()->with(['sourceWallet', 'category'])->first();

            if ($existing !== null) {
                return response()->json([
                    'success' => true,
                    'data' => $this->presentExpense($existing),
                    'info' => 'Expense already exists for this item. Klik generate untuk detail.',
                ]);
            }

            throw $exception;
        }

        $expense->load(['sourceWallet', 'category']);

        return response()->json([
            'success' => true,
            'data' => $this->presentExpense($expense),
        ], 201);
    }

    private function resolveHousehold(?User $user): ?Household
    {
        if ($user === null) {
            return null;
        }

        $context = $this->householdService->resolveUserHouseholdContext($user);

        if ($context['household'] === null) {
            return null;
        }

        return Household::query()->find($context['household']['id']);
    }

    /**
     * Resolve a nested item against the authenticated household and the
     * list in the URL. A mismatched list/item pair is a 404, never 403.
     */
    private function resolveItem(?User $user, HouseholdList $list, ListItem $item): ?ListItem
    {
        $household = $this->resolveHousehold($user);

        if ($household === null || $list->household_id !== $household->id) {
            return null;
        }

        if ($item->list_id !== $list->id) {
            return null;
        }

        return $item;
    }

    private function isOwner(User $user, Household $household): bool
    {
        return HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();
    }

    private function resolveWallet(Household $household, ?string $walletId): ?Wallet
    {
        if ($walletId !== null) {
            return Wallet::query()
                ->where('household_id', $household->id)
                ->whereKey($walletId)
                ->where('is_active', true)
                ->first();
        }

        return Wallet::query()
            ->where('household_id', $household->id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return Category|false|null false means the category exists but has
     *                             the wrong type; null means not found.
     */
    private function resolveCategory(Household $household, ?string $categoryId): Category|false|null
    {
        if ($categoryId === null) {
            return null;
        }

        $category = Category::query()
            ->where('household_id', $household->id)
            ->whereKey($categoryId)
            ->first();

        if ($category === null) {
            return null;
        }

        return $category->type === Category::TYPE_EXPENSE ? $category : false;
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return number_format((float) $left, 2, '.', '')
            === number_format((float) $right, 2, '.', '');
    }

    private function presentItemResponse(ListItem $item): JsonResponse
    {
        $item->refresh()->load(['creator', 'completer', 'expense.sourceWallet', 'expense.category']);

        return response()->json([
            'success' => true,
            'data' => $this->presentItem($item),
        ]);
    }

    private function presentItem(ListItem $item): array
    {
        $expense = $item->relationLoaded('expense')
            ? $item->expense->first()
            : $item->expense()->with(['sourceWallet', 'category'])->first();

        return [
            'id' => $item->id,
            'list_id' => $item->list_id,
            'title' => $item->title,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'estimated_price' => $item->estimated_price,
            'actual_price' => $item->actual_price,
            'status' => $item->status(),
            // Kept as an additive compatibility alias for older clients
            // and the legacy PostgreSQL suite; contract uses `status`.
            'is_completed' => $item->isCompleted(),
            'completed_at' => $item->completed_at,
            'completed_by' => $this->presentUser($item->completer),
            'created_by' => $this->presentUser($item->creator),
            'expense' => $expense !== null ? $this->presentItemExpense($expense) : null,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    private function presentItemExpense(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'amount' => $transaction->amount,
            'occurred_on' => $transaction->occurred_on?->format('Y-m-d'),
            'wallet' => $transaction->sourceWallet ? [
                'id' => $transaction->sourceWallet->id,
                'name' => $transaction->sourceWallet->name,
                'type' => $transaction->sourceWallet->type,
                'is_active' => $transaction->sourceWallet->is_active,
            ] : null,
            'category' => $transaction->category ? [
                'id' => $transaction->category->id,
                'name' => $transaction->category->name,
                'icon' => $transaction->category->icon,
            ] : null,
            'created_at' => $transaction->created_at,
        ];
    }

    private function presentExpense(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'occurred_on' => $transaction->occurred_on?->format('Y-m-d'),
            'wallet_id' => $transaction->wallet_id,
            'category_id' => $transaction->category_id,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'description' => $transaction->description,
            'household_id' => $transaction->household_id,
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    private function householdNotFound(): JsonResponse
    {
        return $this->error('HOUSEHOLD_NOT_FOUND', 'Household not found.', 404);
    }

    private function listNotFound(): JsonResponse
    {
        return $this->error('LIST_NOT_FOUND', 'List not found.', 404);
    }

    private function itemNotFound(): JsonResponse
    {
        return $this->error('LIST_ITEM_NOT_FOUND', 'List item not found.', 404);
    }

    private function listArchived(string $message): JsonResponse
    {
        return $this->error('LIST_ARCHIVED', $message, 409);
    }
}
