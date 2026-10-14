<?php

namespace App\Models;

use Database\Factories\VehicleMaintenanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenance extends Model
{
    /** @use HasFactory<VehicleMaintenanceFactory> */
    use HasFactory, HasUlids;

    protected $table = 'vehicle_maintenance';

    protected $fillable = [
        'vehicle_id',
        'title',
        'description',
        'scheduled_date',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'completed_at' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
