<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\PaginatesAdminLists;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\LoginEventRecorder;
use App\Services\Admin\SessionDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Currently active authenticated sessions across all users, and revocation of
 * one of them by its opaque reference (session ids are never exposed).
 */
class SessionController extends Controller
{
    use PaginatesAdminLists;

    public function __construct(private readonly SessionDirectory $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;

        $paginator = $this->sessions->active()
            ->orderByDesc('last_activity')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        $rows = collect($paginator->items());
        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->unique()->all())
            ->get(['id', 'name', 'email', 'role'])
            ->keyBy('id');

        $data = $rows->map(function (object $row) use ($current, $users): array {
            $user = $users->get((int) $row->user_id);

            return $this->sessions->present($row, $current) + [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ];
        })->values()->all();

        return response()->json($this->page($paginator, $data));
    }

    public function destroy(Request $request, string $reference, LoginEventRecorder $recorder): JsonResponse
    {
        $row = $this->sessions->find($reference);
        if ($row === null) {
            return response()->json(['message' => __('messages.session_not_found')], 404);
        }

        $current = $request->hasSession() ? $request->session()->getId() : null;
        if ($current !== null && hash_equals($row->id, $current)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.session_is_current')],
            ]);
        }

        $revoked = $this->sessions->delete($row->id);

        $target = User::query()->find((int) $row->user_id);
        if ($target !== null) {
            $recorder->revoked($target, $request->user(), $revoked);
        }

        return response()->json(['revoked' => $revoked]);
    }
}
