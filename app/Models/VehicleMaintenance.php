<?php

namespace App\Models;

use Database\Factories\VehicleMaintenanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenance extends Model
{
    /** @use HasFactory<VehicleMaintenanceFactory> */
    use HasFactory, HasUlids;

    protected $table = 'vehicle_maintenance';

    /**
     * MVP lifecycle: a record is Scheduled until completed_at is set, and
     * Completed afterwards. There is no cancelled state for vehicle service
     * (unlike household maintenance). The derived status is never stored.
     */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'vehicle_id',
        'title',
        'description',
        'scheduled_date',
        'completed_at',
        'odometer',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'completed_at' => 'date',
            'odometer' => 'integer',
        ];
    }

    /**
     * Derive the lifecycle status from the server-managed completion date.
     *
     * scheduled = completed_at is null
     * completed = completed_at is set
     */
    public function lifecycleStatus(): string
    {
        return $this->completed_at === null
            ? self::STATUS_SCHEDULED
            : self::STATUS_COMPLETED;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @param  Builder<VehicleMaintenance>  $query
     */
    public function scopeForVehicle(Builder $query, Vehicle|string $vehicle): void
    {
        $query->where('vehicle_id', $vehicle instanceof Vehicle ? $vehicle->id : $vehicle);
    }
}
