<?php

namespace App\Http\Controllers;

use App\Notifications\AlertTriggered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The authenticated user's in-app alert notifications (alerts-engine).
 *
 * Always read through `$request->user()->notifications()`, so ids from another
 * account are never matched. Text is rendered by the SPA from the stored data.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $page = $user->notifications()
            ->where('type', AlertTriggered::class)
            ->latest()
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'kind' => $notification->data['kind'] ?? null,
                    'as_of' => $notification->data['as_of'] ?? null,
                    'saved_screener' => isset($notification->data['saved_screener_id'])
                        ? ['id' => $notification->data['saved_screener_id'], 'name' => $notification->data['saved_screener_name'] ?? null]
                        : null,
                    'items' => $notification->data['items'] ?? [],
                    'more' => (int) ($notification->data['more'] ?? 0),
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'unread_count' => $user->unreadNotifications()->where('type', AlertTriggered::class)->count(),
        ]);
    }

    /**
     * Mark some (`ids`) or all (`all: true`) of the user's notifications read.
     */
    public function markRead(Request $request): Response
    {
        $validated = $request->validate([
            'all' => ['sometimes', 'boolean'],
            'ids' => ['required_without:all', 'array', 'max:100'],
            'ids.*' => ['required', 'string', 'uuid'],
        ]);

        $query = $request->user()->unreadNotifications();

        if (! ($validated['all'] ?? false)) {
            $query->whereIn('id', $validated['ids'] ?? []);
        }

        $query->update(['read_at' => now()]);

        return response()->noContent();
    }
}
