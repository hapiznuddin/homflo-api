<?php

namespace App\Models;

use Database\Factories\BillRecurrenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillRecurrence extends Model
{
    /** @use HasFactory<BillRecurrenceFactory> */
    use HasFactory, HasUlids;

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_QUARTERLY = 'quarterly';

    public const FREQUENCY_SEMIANNUAL = 'semiannual';

    public const FREQUENCY_YEARLY = 'yearly';

    /**
     * The four MVP frequencies (D-01 final): one month apart up to one
     * year apart, all anchored on the schedule cursor.
     *
     * @var list<string>
     */
    public const FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_QUARTERLY,
        self::FREQUENCY_SEMIANNUAL,
        self::FREQUENCY_YEARLY,
    ];

    protected $fillable = [
        'household_id',
        'category_id',
        'title',
        'amount',
        'notes',
        'frequency',
        'due_day',
        'is_active',
        'materialized_until',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_day' => 'integer',
            'is_active' => 'boolean',
            'materialized_until' => 'date',
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
     * Concrete per-period bills. A deleted period never comes back: the
     * cursor on this definition never rewinds (permanent skip).
     *
     * @return HasMany<int, Bill>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'recurrence_id');
    }
}
