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
use App\Services\Finance\PeriodBounds;
use App\Services\Household\HouseholdService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListItemController extends Controller
{
    use PresentsTransactions;

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

        $relations = ['creator', 'completer', 'expense.sourceWallet', 'expense.category'];

        // Contract §10.6: two groups with independent server-side ordering.
        // The response is an object, never a flat array.
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

        return response()->json([
            'success' => true,
            'data' => [
                'list_id' => $list->id,
                'counts' => [
                    'active' => $active->count(),
                    'completed' => $completed->count(),
                ],
                'active' => $active->map(fn (ListItem $item) => $this->presentItem($item))->all(),
                'completed' => $completed->map(fn (ListItem $item) => $this->presentItem($item))->all(),
            ],
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

        if (! $this->isOwner($request->user(), $household)) {
            return $this->error('FORBIDDEN', 'Only household owners can create Expenses.', 403);
        }

        $validated = $request->validated();

        try {
            $result = DB::transaction(function () use ($list, $item, $household, $validated): array {
                // Lock order per contract §8.4: list -> item -> wallet.
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

                if ($lockedList->archived_at !== null) {
                    return ['error' => 'archived'];
                }

                if (! $lockedItem->isCompleted()) {
                    return ['error' => 'not_completed'];
                }

                // Existing Expense wins over every input check: a retry
                // must return the stored transaction, never a second one.
                $existing = $lockedItem->expense()->with(['sourceWallet', 'category'])->first();

                if ($existing !== null) {
                    return ['created' => false, 'expense' => $existing, 'item' => $lockedItem];
                }

                // Amount is always derived from actual_price. A payload
                // value overrides the snapshot atomically inside this
                // transaction; the client never supplies amount.
                $actualPrice = array_key_exists('actual_price', $validated)
                    ? $validated['actual_price']
                    : $lockedItem->actual_price;

                $amount = $this->normalizePositiveAmount($actualPrice);

                if ($amount === null) {
                    return ['error' => 'actual_price_required'];
                }

                $category = $this->resolveCategory($household, $validated['category_id'] ?? null);

                if ($category === false) {
                    return ['error' => 'category_type_mismatch'];
                }

                if ($category === null && array_key_exists('category_id', $validated) && $validated['category_id'] !== null) {
                    return ['error' => 'category_not_found'];
                }

                /** @var Wallet|null $lockedWallet */
                $lockedWallet = Wallet::query()
                    ->where('household_id', $household->id)
                    ->whereKey($validated['wallet_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($lockedWallet === null) {
                    return ['error' => 'wallet_not_found'];
                }

                $balance = $this->wallets->balanceFor($lockedWallet);

                if (LedgerMath::compare($balance, $amount) < 0) {
                    return ['error' => 'insufficient_funds'];
                }

                $expense = Transaction::create([
                    'household_id' => $household->id,
                    'category_id' => $category?->id,
                    'wallet_id' => $lockedWallet->id,
                    'type' => Transaction::TYPE_EXPENSE,
                    'amount' => $amount,
                    'occurred_on' => $validated['occurred_on'] ?? PeriodBounds::businessToday(),
                    'description' => $lockedItem->title,
                    'source_type' => ListItem::class,
                    'source_id' => $lockedItem->id,
                ]);

                if (array_key_exists('actual_price', $validated)) {
                    $lockedItem->actual_price = $validated['actual_price'];
                    $lockedItem->save();
                }

                $lockedList->touch();

                return ['created' => true, 'expense' => $expense, 'item' => $lockedItem];
            });
        } catch (QueryException $exception) {
            // The partial unique index is the final guard against two
            // concurrent writers. The failed transaction is rolled back
            // by the time we get here, so a fresh read is safe.
            $existing = $item->expense()->with(['sourceWallet', 'category'])->first();

            if ($existing !== null) {
                $item->refresh();

                return $this->expenseResponse($existing, $item, false);
            }

            throw $exception;
        }

        return match ($result['error'] ?? null) {
            'archived' => $this->listArchived('List is archived; Expense creation disallowed.'),
            'not_completed' => $this->error('LIST_ITEM_NOT_COMPLETED', 'Item must be completed before creating Expense.', 409),
            'actual_price_required' => $this->error('LIST_ITEM_ACTUAL_PRICE_REQUIRED', 'Actual price is required for Expense and must be > 0.', 422),
            'category_type_mismatch' => $this->error('CATEGORY_TYPE_MISMATCH', 'Category must be an expense category.', 422),
            'category_not_found' => $this->error('CATEGORY_NOT_FOUND', 'Category not found.', 404),
            'wallet_not_found' => $this->error('WALLET_NOT_FOUND', 'Wallet not found or inactive.', 404),
            'insufficient_funds' => $this->error('INSUFFICIENT_FUNDS', 'Insufficient wallet balance for this expense.', 422),
            default => $this->expenseResponse($result['expense'], $result['item'], (bool) $result['created']),
        };
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

    /**
     * Exact positive amount from the item snapshot or payload override.
     * Returns null for missing, non-numeric, or <= 0 values so the caller
     * can surface LIST_ITEM_ACTUAL_PRICE_REQUIRED.
     */
    private function normalizePositiveAmount(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = number_format((float) $value, 2, '.', '');

        return LedgerMath::compare($amount, '0.00') > 0 ? $amount : null;
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

    /**
     * Contract §10.7: both the created and idempotent paths return the
     * same envelope, `{created, expense: TransactionItem, item: Item}`.
     */
    private function expenseResponse(Transaction $expense, ListItem $item, bool $created): JsonResponse
    {
        $expense->loadMissing(['category', 'sourceWallet', 'destinationWallet', 'source']);
        $item->loadMissing(['creator', 'completer', 'expense.sourceWallet', 'expense.category']);

        return response()->json([
            'success' => true,
            'data' => [
                'created' => $created,
                'expense' => $this->presentTransaction($expense),
                'item' => $this->presentItem($item),
            ],
        ], $created ? 201 : 200);
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
