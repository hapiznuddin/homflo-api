<?php

namespace App\Models;

use Database\Factories\MaintenanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Maintenance extends Model
{
    /** @use HasFactory<MaintenanceFactory> */
    use HasFactory, HasUlids;

    public const PRIORITY_HIGH = 'tinggi';

    public const PRIORITY_MEDIUM = 'sedang';

    public const PRIORITY_ROUTINE = 'rutin';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'maintenance';

    protected $fillable = [
        'household_id',
        'asset_id',
        'title',
        'description',
        'location',
        'scheduled_date',
        'completed_at',
        'cancelled_at',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'completed_at' => 'date',
            'cancelled_at' => 'date',
        ];
    }

    /**
     * Derive the lifecycle status from the server-managed timestamps.
     *
     * scheduled  = completed_at and cancelled_at are both null
     * completed  = completed_at is set
     * cancelled  = cancelled_at is set
     *
     * cancelled takes precedence when both timestamps are somehow present
     * (only possible for legacy or manually crafted rows): the API never
     * produces that state, and treating it as cancelled keeps it terminal
     * without mutating the stored data.
     */
    public function lifecycleStatus(): string
    {
        if ($this->cancelled_at !== null) {
            return self::STATUS_CANCELLED;
        }

        if ($this->completed_at !== null) {
            return self::STATUS_COMPLETED;
        }

        return self::STATUS_SCHEDULED;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @param  Builder<Maintenance>  $query
     */
    public function scopeForHousehold(Builder $query, Household|string $household): void
    {
        $query->where('household_id', $household instanceof Household ? $household->id : $household);
    }
}
