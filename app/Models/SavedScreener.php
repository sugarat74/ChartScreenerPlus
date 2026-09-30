<?php

namespace App\Models;

use Database\Factories\SavedScreenerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named, user-owned screener definition.
 *
 * `user_id` is deliberately absent from `#[Fillable]` (the same structural
 * guard as `User::$role`), and rows are always created through
 * `$request->user()->savedScreeners()->create([...])`, so a client-supplied
 * `user_id` can never be written. `filters` is the canonical seven-key object
 * in API param names (including `sort`) cast to/from JSON.
 */
#[Fillable(['name', 'filters'])]
class SavedScreener extends Model
{
    /** @use HasFactory<SavedScreenerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /**
     * The account that owns this Screener.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
