<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUlids;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::TYPE_INCOME, self::TYPE_EXPENSE];
    }

    protected $fillable = [
        'household_id',
        'name',
        'icon',
        'type',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where('household_id', $household instanceof Household ? $household->id : $household);
    }
}
