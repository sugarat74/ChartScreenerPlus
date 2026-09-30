<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Role of a regular registered account.
     */
    public const ROLE_USER = 'user';

    /**
     * Role of a privileged operator account. Granted out of band only.
     */
    public const ROLE_ADMIN = 'admin';

    /**
     * Default attribute values. Mirrors the `users.role` column default so a
     * freshly created model serializes `role` consistently (the DB default is
     * applied on insert but is not read back into the in-memory instance).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => self::ROLE_USER,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether the account holds the admin role.
     *
     * `role` is deliberately not fillable, so this value can only be set out
     * of band (the DB default or the app:make-admin command).
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * The instruments this account follows (its personal watchlist).
     *
     * Ownership lives on the `watchlist_items` pivot (`user_id`), which is why
     * the explicit table name is required (Laravel would guess
     * `instrument_user`). Every watchlist query MUST start from this relation
     * so it is always scoped to the authenticated user.
     *
     * @return BelongsToMany<Instrument, $this>
     */
    public function watchlist(): BelongsToMany
    {
        return $this->belongsToMany(Instrument::class, 'watchlist_items')->withTimestamps();
    }
}
