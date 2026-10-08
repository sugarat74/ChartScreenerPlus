<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\PaginatesAdminLists;
use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Admin\LoginEventRecorder;
use App\Services\Admin\SessionDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin overview of registered accounts (admin-users-sessions). Read-only
 * except for ending a user's sessions; role changes, suspension and deletion
 * are out of scope (roles are granted out of band). Authorization is the
 * `auth:sanctum` + `admin` route group.
 */
class UserController extends Controller
{
    use PaginatesAdminLists;

    public function __construct(private readonly SessionDirectory $sessions) {}

    public function summary(): JsonResponse
    {
        $now = now();

        return response()->json([
            'summary' => [
                'users_total' => User::query()->count(),
                'admins_total' => User::query()->where('role', User::ROLE_ADMIN)->count(),
                'users_new_7d' => User::query()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
                'users_new_30d' => User::query()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
                'users_active_24h' => $this->sessions->table()
                    ->whereNotNull('user_id')
                    ->where('last_activity', '>=', $now->copy()->subDay()->getTimestamp())
                    ->distinct()
                    ->count('user_id'),
                'sessions_active' => $this->sessions->active()->count(),
                'failed_logins_24h' => LoginEvent::query()
                    ->where('event', LoginEvent::EVENT_FAILED)
                    ->where('created_at', '>=', $now->copy()->subDay())
                    ->count(),
                'activity_retention_days' => (int) config('admin.activity_retention_days'),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $paginator = $this->withOverview(User::query())
            ->when($search !== '', function (Builder $query) use ($search): void {
                // Wildcards in the input are literal; `!` is the escape char on
                // both SQLite and PostgreSQL.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$like]);
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $data = $paginator->getCollection()->map(fn (User $user) => $this->present($user))->values()->all();

        return response()->json($this->page($paginator, $data));
    }

    public function show(Request $request, int $user): JsonResponse
    {
        $account = $this->withOverview(User::query())->find($user);
        if ($account === null) {
            return response()->json(['message' => __('messages.user_not_found')], 404);
        }

        $current = $request->hasSession() ? $request->session()->getId() : null;

        $sessions = $this->sessions->active()
            ->where('user_id', $account->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $row) => $this->sessions->present($row, $current))
            ->values()
            ->all();

        $activity = LoginEvent::query()
            ->with('actor:id,name')
            ->where('user_id', $account->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (LoginEvent $event) => ActivityController::presentEvent($event))
            ->values()
            ->all();

        return response()->json([
            'user' => $this->present($account),
            'sessions' => $sessions,
            'activity' => $activity,
        ]);
    }

    /**
     * End every session of a user. The caller's own current session is kept
     * so an Admin cannot lock themselves out from this screen.
     */
    public function revokeSessions(Request $request, int $user, LoginEventRecorder $recorder): JsonResponse
    {
        $account = User::query()->find($user);
        if ($account === null) {
            return response()->json(['message' => __('messages.user_not_found')], 404);
        }

        $current = $request->hasSession() ? $request->session()->getId() : null;
        $revoked = $this->sessions->deleteForUser($account->id, $current);

        $recorder->revoked($account, $request->user(), $revoked);

        return response()->json(['revoked' => $revoked]);
    }

    /**
     * Last sign-in, last activity and ownership counts as subqueries so the
     * list stays one bounded query per page (no N+1).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function withOverview(Builder $query): Builder
    {
        $sessions = config('session.table', 'sessions');

        return $query
            ->select('users.*')
            ->withCount(['savedScreeners', 'watchlist'])
            ->selectSub(
                LoginEvent::query()
                    ->selectRaw('MAX(created_at)')
                    ->whereColumn('login_events.user_id', 'users.id')
                    ->where('event', LoginEvent::EVENT_LOGIN),
                'last_login_at',
            )
            ->selectSub(
                $this->sessions->table()
                    ->selectRaw('MAX(last_activity)')
                    ->whereColumn("{$sessions}.user_id", 'users.id'),
                'last_activity_ts',
            )
            ->selectSub(
                $this->sessions->table()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn("{$sessions}.user_id", 'users.id')
                    ->where('last_activity', '>=', $this->sessions->activeSince()),
                'active_sessions_count',
            );
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        $lastLogin = $user->getAttribute('last_login_at');
        $lastActivity = $user->getAttribute('last_activity_ts');
        $lastLoginAt = $lastLogin === null ? null : Carbon::parse($lastLogin);
        $lastActivityAt = $lastActivity === null ? null : Carbon::createFromTimestamp((int) $lastActivity);
        // Session rows disappear on sign-out/revocation/expiry; fall back to the
        // latest sign-in so "last activity" never regresses to empty.
        if ($lastLoginAt !== null && ($lastActivityAt === null || $lastLoginAt->greaterThan($lastActivityAt))) {
            $lastActivityAt = $lastLoginAt;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
            'last_login_at' => $lastLoginAt?->toIso8601String(),
            'last_activity_at' => $lastActivityAt?->toIso8601String(),
            'active_sessions_count' => (int) $user->getAttribute('active_sessions_count'),
            'saved_screeners_count' => (int) $user->getAttribute('saved_screeners_count'),
            'watchlist_count' => (int) $user->getAttribute('watchlist_count'),
        ];
    }
}
