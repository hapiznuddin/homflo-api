<?php

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory, HasUlids;

    public const TYPE_CASH = 'cash';

    public const TYPE_BANK = 'bank';

    public const TYPE_E_WALLET = 'e-wallet';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::TYPE_CASH, self::TYPE_BANK, self::TYPE_E_WALLET];
    }

    protected $fillable = [
        'household_id',
        'name',
        'type',
        'opening_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Ledger rows posted against this wallet (income received, expense
     * paid, or transfer source).
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Transfer rows received by this wallet.
     *
     * @return HasMany<Transaction, $this>
     */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Transaction::class, 'destination_wallet_id');
    }

    /**
     * @return HasMany<WalletAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(WalletAdjustment::class);
    }

    /**
     * @param  Builder<Wallet>  $query
     */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where('household_id', $household instanceof Household ? $household->id : $household);
    }

    /**
     * @param  Builder<Wallet>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
