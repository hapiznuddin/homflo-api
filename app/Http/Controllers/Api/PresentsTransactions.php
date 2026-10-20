<?php

namespace App\Http\Controllers\Api;

use App\Models\Bill;
use App\Models\Transaction;

/**
 * Single home for the transaction API shape (D-08). BillController and
 * TransactionController share it so the bill_payment linkage can never
 * drift between the payment endpoint and the generic transaction
 * endpoints. The using class supplies presentWalletLink(), which both
 * controllers already define.
 */
trait PresentsTransactions
{
    /**
     * Present one transaction. bill_payment is derived from the morph
     * source (no column, no aggregate query) and is exposed only for a
     * same-household bill source — a cross-household or orphaned source
     * surfaces as null, never as a leak.
     *
     * @return array{id: string, household_id: string, type: string, amount: string, occurred_on: string, description: ?string, wallet: ?string, wallet_id: ?string, destination_wallet_id: ?string, source_wallet: ?array{id: string, name: string, type: string, is_active: bool}, destination_wallet: ?array{id: string, name: string, type: string, is_active: bool}, category: ?array{id: string, name: string, icon: ?string}, bill_payment: ?array{bill_id: string, bill_title: string}, created_at: mixed, updated_at: mixed}
     */
    private function presentTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing('category', 'sourceWallet', 'destinationWallet', 'source');

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
            'bill_payment' => $this->presentBillPayment($transaction),
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ];
    }

    /**
     * Morph-derived bill linkage for D-08: present only when the source
     * really is a bill of the same household. Generic expense/income/
     * transfer rows (source_type null) yield null without any query —
     * morphTo eager loading skips null keys.
     *
     * @return array{bill_id: string, bill_title: string}|null
     */
    private function presentBillPayment(Transaction $transaction): ?array
    {
        $source = $transaction->source;

        if (! $source instanceof Bill || $source->household_id !== $transaction->household_id) {
            return null;
        }

        return [
            'bill_id' => $source->id,
            'bill_title' => $source->title,
        ];
    }
}
