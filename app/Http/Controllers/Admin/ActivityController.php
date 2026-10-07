<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\PaginatesAdminLists;
use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Support\UserAgentLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sign-in activity log (sign-ins, failed attempts, sign-outs, revocations),
 * newest first, kept for `config('admin.activity_retention_days')`.
 */
class ActivityController extends Controller
{
    use PaginatesAdminLists;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['nullable', 'string', Rule::in(LoginEvent::EVENTS)],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = LoginEvent::query()
            ->with(['user:id,name,email', 'actor:id,name'])
            ->when($validated['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $data = $paginator->getCollection()
            ->map(fn (LoginEvent $event) => self::presentEvent($event, true))
            ->values()
            ->all();

        return response()->json($this->page($paginator, $data));
    }

    /** @return array<string, mixed> */
    public static function presentEvent(LoginEvent $event, bool $withUser = false): array
    {
        $payload = [
            'id' => $event->id,
            'event' => $event->event,
            'email' => $event->email,
            'ip_address' => $event->ip_address,
            'device' => UserAgentLabel::describe($event->user_agent),
            'sessions_revoked' => $event->sessions_revoked,
            'actor' => $event->actor === null ? null : ['id' => $event->actor->id, 'name' => $event->actor->name],
            'created_at' => $event->created_at?->toIso8601String(),
        ];

        if ($withUser) {
            $payload['user'] = $event->user === null ? null : [
                'id' => $event->user->id,
                'name' => $event->user->name,
                'email' => $event->user->email,
            ];
        }

        return $payload;
    }
}
