<?php

namespace App\Http\Controllers\Api;

use App\Models\HouseholdList;
use App\Models\ListItem;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreListItemRequest;
use App\Http\Requests\Api\UpdateListItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListItemController extends Controller
{
    public function index(Request $request, HouseholdList $list): JsonResponse
    {
        // Cross-household access masked as 404
        $household = $list->household;

        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        $items = $list->items();

        // Filter by status if provided
        if ($request->has('status')) {
            $status = $request->query('status');
            if ($status === 'active') {
                $items->whereNull('completed_at');
            } elseif ($status === 'completed') {
                $items->whereNotNull('completed_at');
            }
        }

        $items = $items->get();

        return response()->json([
            'success' => true,
            'data' => $items->map(fn (ListItem $item) => $this->presentItem($item))->all(),
        ]);
    }

    public function store(StoreListItemRequest $request, HouseholdList $list): JsonResponse
    {
        // Cross-household access masked as 404
        $household = $list->household;

        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        // Item harus list aktif
        if ($list->isArchived()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ARCHIVED',
                    'message' => 'List is archived; items cannot be added.',
                ],
            ], 409);
        }

        $item = new ListItem([
            'list_id' => $list->id,
            'title' => $request->validated()['title'],
            'quantity' => $request->validated()['quantity'],
            'unit' => $request->validated()['unit'],
            'estimated_price' => $request->validated()['estimated_price'],
            'created_by' => $request->user()->id,
        ]);

        $item->save();

        $list->touch();

        return response()->json([
            'success' => true,
            'data' => $this->presentItem($item),
        ], 201);
    }

    public function show(ListItem $item): JsonResponse
    {
        // Cross-household access masked as 404
        $household = $item->household;

        if ($household === null || $item->list->household_id !== $household->id) {
            return $this->itemNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentItem($item),
        ]);
    }

    public function update(UpdateListItemRequest $request, ListItem $item): JsonResponse
    {
        // Cross-household access masked as 404
        $household = $item->household;

        if ($household === null || $item->list->household_id !== $household->id) {
            return $this->itemNotFound();
        }

        // Item archived → read-only
        if ($item->list->isArchived()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ARCHIVED',
                    'message' => 'List is archived; item updates disallowed.',
                ],
            ], 409);
        }

        // Completion check: bila item sudah completed dan request bukan uncomplete
        if ($item->isCompleted() && !$request->filled('uncomplete')) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ITEM_ALREADY_COMPLETED',
                    'message' => 'Item already completed. Use uncomplete first if needed.',
                ],
            ], 409);
        }

        // Actual price lock: bila sudah ada Expense, tidak boleh diubah
        if ($item->hasExpense() && $request->filled('actual_price')) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ITEM_PRICE_LOCKED',
                    'message' => 'Actual price locked; Expense already exists.',
                ],
            ], 409);
        }

        $updates = [];

        if ($request->filled('title')) {
            $updates['title'] = $request->validated()['title'];
        }
        if ($request->filled('quantity')) {
            $updates['quantity'] = $request->validated()['quantity'];
        }
        if ($request->filled('unit')) {
            $updates['unit'] = $request->validated()['unit'];
        }
        if ($request->filled('estimated_price')) {
            $updates['estimated_price'] = $request->validated()['estimated_price'];
        }
        // actual_price tidak boleh diupdate lewat PATCH jika sudah ada Expense;
        // dilarang, biarkan service Expense yang mengelolanya

        $item->update($updates);
        $item->list->touch();

        return response()->json([
            'success' => true,
            'data' => $this->presentItem($item),
        ]);
    }

    public function complete(ListItem $item): JsonResponse
    {
        // Cross-household access masked as 404
        $household = $item->household;

        if ($household === null || $item->list->household_id !== $household->id) {
            return $this->itemNotFound();
        }

        // Item archived → cannot complete
        if ($item->list->isArchived()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ARCHIVED',
                    'message' => 'List is archived; completion disallowed.',
                ],
            ], 409);
        }

        // Item already completed
        if ($item->isCompleted()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ITEM_ALREADY_COMPLETED',
                    'message' => 'Item already completed.',
                ],
            ], 409);
        }

        // Expense check: jika item sudah punya Expense, completion tetap bole tapi
        // actual_price harus null atau nilai yang ada dipertahankan
        // completion tidak membuat Expense

        return $item->completeTransaction(function () use ($item) {
            // Server-side: set completed_at, completed_by
            $item->completed_at = now()->utc();
            $item->completed_by = request()->user()->id;
            $item->list->touch();

            return $item->save();
        });
    }

    public function uncomplete(ListItem $item): JsonResponse
    {
        // Hanya diizinkan jika A-18 disetujui (AMD-05)
        // Kontrak: A-18 disetujui — uncompletion Completed → Active diizinkan.

        // Cross-household access masked as 404
        $household = $item->household;

        if ($household === null || $item->list->household_id !== $household->id) {
            return $this->itemNotFound();
        }

        // Item archived → cannot uncomplete
        if ($item->list->isArchived()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ARCHIVED',
                    'message' => 'List is archived; uncompletion disallowed.',
                ],
            ], 409);
        }

        // Item not completed → error
        if (!$item->isCompleted()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ITEM_NOT_COMPLETED',
                    'message' => 'Item is not completed.',
                ],
            ], 409);
        }

        // Uncomplete: clear completed_at,pertahankan actual_price
        return $item->completeTransaction(function () use ($item) {
            $item->completed_at = null;
            $item->completed_by = null;
            // actual_price dipertahankan (tidak direset)
            $item->save();
        });
    }

    public function expense(ListItem $item): JsonResponse
    {
        // Cross-household access masked as 404
        $household = $item->household;

        if ($household === null || $item->list->household_id !== $household->id) {
            return $this->itemNotFound();
        }

        // Item harus completed sebelum Expense dibuat
        if (!$item->isCompleted()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ITEM_NOT_COMPLETED',
                    'message' => 'Item must be completed before creating Expense.',
                ],
            ], 409);
        }

        // Maksimal satu Expense per item
        if ($item->hasExpense()) {
            // Kembalikan transaksi yang sudah ada (idempoten-200)
            $existing = $item->expense()->first();

            return response()->json([
                'success' => true,
                'data' => $this->presentExpense($existing),
                'info' => 'Expense already exists for this item. Klik generate untuk detail.',
            ]);
        }

        // Validasi: actual_price harus ada dan > 0
        $actualPrice = $item->actual_price;

        if ($actualPrice === null || $actualPrice <= 0) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ITEM_ACTUAL_PRICE_REQUIRED',
                    'message' => 'Actual price is required for Expense and must be > 0.',
                ],
            ], 422);
        }

        // Resolve wallet dan kategori
        $wallet = $this->resolveWallet($household);

        if ($wallet === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WALLET_NOT_FOUND',
                    'message' => 'No active wallet found for household.',
                ],
            ], 404);
        }

        // Cek saldo non-negatif
        $saldoCents = $wallet->balance_cents; // asumsi Wallet model punya field ini
        if ($saldoCents < ($actualPrice * 100)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_WALLET_BALANCE',
                    'message' => 'Insufficient wallet balance for this expense.',
                ],
            ], 422);
        }

        // Buat transaksi expense dalam satu DB::transaction
        $expense = \App\Transaction::transaction(function () use ($item, $wallet, $actualPrice) {
            $expense = new \App\Transaction([
                'type' => 'expense',
                'amount' => $actualPrice, // decimal string 2 digit
                'occurred_on' => now()->localeFormat('Y-m-d'), // Asia/Jakarta business today bisa difallback
                'wallet_id' => $wallet->id,
                'source_type' => ListItem::class,
                'source_id' => $item->id,
                'household_id' => $household->id,
                // description server-side: snapshot title
                'description' => $item->title,
                // source morph sudah di-set di atas
            ]);

            $expense->save();

            // Update actual_price pada item bila payload mengirim (tapi di sini kita pakai existing)
            // di sini actual_price sudah pasti > 0 karena sudah divalidasi di atas
            $item->update(['actual_price' => $actualPrice]);

            // Update wallet saldo
            // $wallet->decrement('balance_cents', $actualPrice * 100);

            return $expense;
        });

        return response()->json([
            'success' => true,
            'data' => $this->presentExpense($expense),
        ], 201);
    }

    private function resolveWallet(\App\Models\Household $household): ?\App\Models\Wallet
    {
        // Cari wallet aktif milik household
        return \App\Models\Wallet::where('household_id', $household->id)
            ->where('is_active', true)
            ->first();
    }

    private function presentExpense(\App\Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'occurred_on' => $transaction->occurred_on,
            'wallet_id' => $transaction->wallet_id,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'description' => $transaction->description,
            'household_id' => $transaction->household_id,
        ];
    }

    private function itemNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'LIST_ITEM_NOT_FOUND',
                'message' => 'List item not found.',
            ],
        ], 404);
    }
}