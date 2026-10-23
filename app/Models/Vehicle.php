<?php

namespace App\Models;

use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'household_id',
        'name',
        'brand',
        'model',
        'year',
        'type',
        'plate_number',
        'odometer',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'odometer' => 'integer',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    /**
     * @param  Builder<Vehicle>  $query
     */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where('household_id', $household instanceof Household ? $household->id : $household);
    }
}
