<?php

namespace App\Models;

use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'household_id',
        'name',
        'type',
        'address',
        'description',
        'ownership_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ownership_status' => 'string',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** @param  Builder<Property>  $query */
    public function scopeActive($query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<Property>  $query */
    public function scopeArchived($query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @param  Builder<Property>  $query */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where('household_id', $household instanceof Household ? $household->id : $household);
    }
}
