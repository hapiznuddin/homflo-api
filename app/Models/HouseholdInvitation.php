<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdInvitation extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    /** Derived, never stored: a pending row past its expiry. */
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'household_id',
        'invited_by',
        'email',
        'token_hash',
        'status',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Presentation status: stored lifecycle plus the derived `expired` state.
     * A revoked row is never reported as expired even if its date has passed.
     */
    public function presentStatus(): string
    {
        if ($this->isRevoked()) {
            return self::STATUS_REVOKED;
        }

        if ($this->isAccepted()) {
            return self::STATUS_ACCEPTED;
        }

        if ($this->isExpired()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_PENDING;
    }
}
