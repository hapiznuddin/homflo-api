<?php

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'created_by',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
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

    public function shoppingItems(): HasMany
    {
        return $this->hasMany(ShoppingItem::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function obligationTypes(): HasMany
    {
        return $this->hasMany(ObligationType::class);
    }

    /**
     * Vehicle obligations are owned through their vehicle. The relation is
     * exposed for household-scoped reads, while mutations still resolve the
     * nested vehicle and obligation explicitly.
     *
     * @return HasManyThrough<int, VehicleObligation, Vehicle>
     */
    public function vehicleObligations(): HasManyThrough
    {
        return $this->hasManyThrough(VehicleObligation::class, Vehicle::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
