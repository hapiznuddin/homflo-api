<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUlids;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_TRANSFER = 'transfer';

    protected $fillable = [
        'household_id',
        'category_id',
        'wallet_id',
        'destination_wallet_id',
        'type',
        'amount',
        'occurred_on',
        'description',
        'wallet',
        'source_type',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Wallet this ledger row is posted against: the receiver for income,
     * the source for expense and transfer.
     */
    public function sourceWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    /**
     * Receiving wallet for transfer rows; null for income and expense.
     */
    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where('household_id', $household instanceof Household ? $household->id : $household);
    }
}
