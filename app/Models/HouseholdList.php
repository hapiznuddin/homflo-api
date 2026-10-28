<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdList extends Model
{
    use \Illuminate\Database\Eloquent\ULid;

    protected $table = 'lists';
    public $timestamps = true;

    protected $fillable = [
        'household_id',
        'name',
        'type',
        'created_by',
        'archived_at',
    ];

    protected $casts = [
        'type' => 'string',
    ];

    /** Get the household that owns the list. */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'household_id');
    }

    /** Get the user who created the list. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Get the items in this list. */
    public function items(): HasMany
    {
        return $this->hasMany(ListItem::class, 'list_id');
    }

    /** Get the count of active items. */
    public function activeItemCount(): int
    {
        return $this->items()->whereNull('completed_at')->count();
    }

    /** Get the count of completed items. */
    public function completedItemCount(): int
    {
        return $this->items()->whereNotNull('completed_at')->count();
    }

    /** Check if list is archived. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Check if list is the default for the household. */
    public function isDefaultForHousehold(): bool
    {
        return (bool) $this->household->default_list_id === $this->id;
    }

    /** Scope to active lists only. */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /** Scope to shopping type only. */
    public function scopeShopping($query)
    {
        return $query->where('type', 'shopping');
    }
}