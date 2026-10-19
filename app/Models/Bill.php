<?php

namespace App\Models;

use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'household_id',
        'category_id',
        'title',
        'amount',
        'due_date',
        'is_active',
        'notes',
        'recurrence_id',
        'recurrence_period',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'is_active' => 'boolean',
            'recurrence_period' => 'date',
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
     * Parent schedule definition; null for one-time bills (including
     * every legacy row — no backfill).
     */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(BillRecurrence::class, 'recurrence_id');
    }

    /**
     * Linked payment rows. Payment = one expense transaction whose
     * source morph points at this bill; scoped to the bill's household
     * and expense type so the relation always agrees with the grouped
     * aggregates used for paid_amount/payment_count/status.
     *
     * @return MorphMany<int, Transaction>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source')
            ->where('household_id', $this->household_id)
            ->where('type', Transaction::TYPE_EXPENSE);
    }

    /**
     * @param  Builder<Bill>  $query
     */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where('household_id', $household instanceof Household ? $household->id : $household);
    }
}
