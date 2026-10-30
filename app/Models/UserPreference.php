<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    use HasUlids;

    public const THEME_SYSTEM = 'system';

    public const THEME_LIGHT = 'light';

    public const THEME_DARK = 'dark';

    public const THEMES = [
        self::THEME_SYSTEM,
        self::THEME_LIGHT,
        self::THEME_DARK,
    ];

    protected $fillable = [
        'user_id',
        'theme',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
