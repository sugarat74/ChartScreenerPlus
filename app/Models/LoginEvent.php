<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in activity record for the Admin overview. Append-only; rows older
 * than `config('admin.activity_retention_days')` are mass-pruned daily.
 *
 * @property string $event
 */
class LoginEvent extends Model
{
    use MassPrunable;

    public const EVENT_LOGIN = 'login';

    public const EVENT_FAILED = 'failed';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_SESSION_REVOKED = 'session_revoked';

    public const EVENTS = [
        self::EVENT_LOGIN,
        self::EVENT_FAILED,
        self::EVENT_LOGOUT,
        self::EVENT_SESSION_REVOKED,
    ];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'sessions_revoked' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<',
            now()->subDays(max(1, (int) config('admin.activity_retention_days'))),
        );
    }
}
