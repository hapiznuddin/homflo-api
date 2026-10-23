<?php

namespace App\Models;

use Database\Factories\VehicleObligationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleObligation extends Model
{
    /** @use HasFactory<VehicleObligationFactory> */
    use HasFactory, HasUlids;

    /**
     * Administrative lifecycle only. Passing due_date never changes this
     * value, and there is deliberately no paid/unpaid payment status.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'vehicle_obligations';

    protected $fillable = [
        'vehicle_id',
        'obligation_type_id',
        'title',
        'description',
        'issued_date',
        'due_date',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_INACTIVE];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function obligationType(): BelongsTo
    {
        return $this->belongsTo(ObligationType::class);
    }

    /**
     * Household ownership is derived through the authorized vehicle; a
     * client can never supply household_id on this resource.
     *
     * @param  Builder<VehicleObligation>  $query
     */
    public function scopeForVehicle(Builder $query, Vehicle|string $vehicle): void
    {
        $query->where('vehicle_id', $vehicle instanceof Vehicle ? $vehicle->id : $vehicle);
    }
}
