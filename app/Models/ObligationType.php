<?php

namespace App\Models;

use Database\Factories\ObligationTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObligationType extends Model
{
    /** @use HasFactory<ObligationTypeFactory> */
    use HasFactory, HasUlids;

    /**
     * Built-in identifiers. The rows are seeded by migration; the codes keep
     * their meaning stable even if a display name is translated later.
     */
    public const CODE_TAX = 'tax';

    public const CODE_REGISTRATION = 'registration';

    public const CODE_INSPECTION = 'inspection';

    protected $fillable = [
        'household_id',
        'code',
        'name',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(VehicleObligation::class);
    }

    /**
     * Types available to a household: the system catalogue plus that
     * household's custom types. A custom type from another household is
     * never returned by this scope.
     *
     * @param  Builder<ObligationType>  $query
     */
    public function scopeAvailableToHousehold(Builder $query, Household|string $household): void
    {
        $householdId = $household instanceof Household ? $household->id : $household;

        $query->where(function (Builder $query) use ($householdId): void {
            $query->whereNull('household_id')
                ->orWhere('household_id', $householdId);
        });
    }
}
