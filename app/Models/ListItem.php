<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListItem extends Model
{
    use \Illuminate\Database\Eloquent\ULid;

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

    /** Get the household that owns the list. */
    public function household(): BelongsTo
    {
        return $this->list()->household();
    }

    /** Item status: active or completed. */
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

    /** Check if item has expense. */
    public function hasExpense(): bool
    {
        return $this->expenses()->exists();
    }

    /** Get the expense for this item. */
    public function expense()
    {
        return $this->hasMany(Transaction::class, 'source_id')
            ->where('source_type', ListItem::class);
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