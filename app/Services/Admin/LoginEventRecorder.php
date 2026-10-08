<?php

namespace App\Services\Admin;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records sign-in activity from Laravel's auth events on the `web` guard (the
 * SPA's session guard). Passwords are never stored; a failed attempt keeps the
 * attempted email so the Admin can spot credential stuffing.
 */
class LoginEventRecorder
{
    public function __construct(private readonly Request $request) {}

    public function login(Login $event): void
    {
        if ($event->guard === 'web') {
            $this->record(LoginEvent::EVENT_LOGIN, $event->user);
        }
    }

    public function failed(Failed $event): void
    {
        if ($event->guard === 'web') {
            $email = $event->credentials['email'] ?? null;
            $this->record(LoginEvent::EVENT_FAILED, $event->user, is_string($email) ? $email : null);
        }
    }

    public function logout(Logout $event): void
    {
        if ($event->guard === 'web' && $event->user !== null) {
            $this->record(LoginEvent::EVENT_LOGOUT, $event->user);
        }
    }

    /**
     * A revocation is attributed to the acting Admin; the request's IP/user
     * agent belong to that Admin, so they are not stored under the target.
     */
    public function revoked(User $target, User $actor, int $count): void
    {
        $this->record(LoginEvent::EVENT_SESSION_REVOKED, $target, null, $actor, $count, withClient: false);
    }

    /**
     * Auditing must never break authentication: a failed write is reported
     * (logs/error tracking) and the sign-in or revocation proceeds.
     */
    private function record(
        string $type,
        ?Authenticatable $user,
        ?string $email = null,
        ?User $actor = null,
        ?int $sessionsRevoked = null,
        bool $withClient = true,
    ): void {
        $resolvedEmail = $user instanceof User ? $user->email : $email;

        $attributes = [
            'event' => $type,
            'user_id' => $user?->getAuthIdentifier(),
            'actor_id' => $actor?->id,
            'email' => $resolvedEmail === null ? null : mb_substr(mb_strtolower(trim($resolvedEmail)), 0, 255),
            'ip_address' => $withClient ? $this->request->ip() : null,
            'user_agent' => $withClient ? (mb_substr((string) $this->request->userAgent(), 0, 512) ?: null) : null,
            'sessions_revoked' => $sessionsRevoked,
        ];

        try {
            // Own transaction: inside an outer one (e.g. register) Laravel uses
            // a SAVEPOINT, so a failed insert rolls back only itself. Without
            // it PostgreSQL would abort the caller's whole transaction.
            DB::transaction(fn () => LoginEvent::query()->create($attributes));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
