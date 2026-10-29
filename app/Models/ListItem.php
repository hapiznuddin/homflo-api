<?php

namespace App\Models;

use Database\Factories\ListItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListItem extends Model
{
    /** @use HasFactory<ListItemFactory> */
    use HasFactory, HasUlids;

    protected $table = 'list_items';

    public $timestamps = true;

    protected $fillable = [
        'list_id',
        'title',
        'quantity',
        'unit',
        'estimated_price',
        'actual_price',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'estimated_price' => 'decimal:2',
        'actual_price' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    /** Get the list this item belongs to. */
    public function list(): BelongsTo
    {
        return $this->belongsTo(HouseholdList::class, 'list_id');
    }

    /** Get the user who created the item. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Get the user who completed the item. */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Item status: active or completed, derived from completed_at. */
    public function status(): string
    {
        return $this->completed_at === null ? 'active' : 'completed';
    }

    /** Check if item is active. */
    public function isActive(): bool
    {
        return $this->completed_at === null;
    }

    /** Check if item is completed. */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** Check if item already has its single Expense. */
    public function hasExpense(): bool
    {
        return $this->expense()->exists();
    }

    /**
     * The single Expense transaction linked to this item.
     *
     * The database enforces at most one row per ListItem through the
     * partial unique index, so callers use ->first() rather than a
     * collection semantics.
     */
    public function expense(): HasMany
    {
        return $this->hasMany(Transaction::class, 'source_id')
            ->where('source_type', self::class);
    }

    /** Scope to active items only. */
    public function scopeActive($query)
    {
        return $query->whereNull('completed_at');
    }

    /** Scope to completed items only. */
    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }
}
