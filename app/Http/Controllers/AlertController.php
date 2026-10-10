<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A Registered User's Alert rules (docs/specs/alerts-engine.md).
 *
 * Every query starts from `$request->user()->alerts()`, so another user's id
 * is a `404` and no endpoint accepts a `user_id`. Limits and duplicates are a
 * `422` with localized messages; keys and status codes never change with the
 * language.
 */
class AlertController extends Controller
{
    /**
     * The Signal vocabulary a Watchlist alert may watch (signals-detect).
     */
    private const SIGNAL_TYPES = [
        'golden_cross',
        'death_cross',
        'ma_alignment_bullish',
        'ma_alignment_bearish',
        'pivot_breakout_rvol',
        'rsi_overbought',
        'rsi_oversold',
        'macd_bullish_cross',
        'macd_bearish_cross',
    ];

    public function index(Request $request): JsonResponse
    {
        $alerts = $request->user()->alerts()
            ->with('savedScreener')
            ->orderBy('id')
            ->get()
            ->map(fn (Alert $alert): array => $this->payload($alert))
            ->all();

        return response()->json(['alerts' => $alerts]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(Alert::KINDS)],
            'saved_screener_id' => [
                Rule::requiredIf($request->input('kind') === Alert::KIND_SCREENER),
                Rule::prohibitedIf($request->input('kind') === Alert::KIND_WATCHLIST),
                'nullable',
                'integer',
                Rule::exists('saved_screeners', 'id')->where('user_id', $user->id),
            ],
            'signal_types' => [
                Rule::requiredIf($request->input('kind') === Alert::KIND_WATCHLIST),
                Rule::prohibitedIf($request->input('kind') === Alert::KIND_SCREENER),
                'nullable',
                'array',
                'min:1',
            ],
            'signal_types.*' => ['required', 'string', Rule::in(self::SIGNAL_TYPES)],
        ]);

        try {
            // The limit and duplicate checks and the insert run under a lock on
            // the user's row, so parallel requests cannot exceed the limit or
            // create a second Watchlist alert; a race on the unique
            // (user, saved screener) index still ends as the duplicate 422.
            $alert = DB::transaction(function () use ($user, $validated): Alert {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                if ($user->alerts()->count() >= (int) config('alerts.max_per_user')) {
                    throw ValidationException::withMessages([
                        'kind' => [__('messages.alerts.limit', ['max' => (int) config('alerts.max_per_user')])],
                    ]);
                }

                $duplicate = $validated['kind'] === Alert::KIND_SCREENER
                    ? $user->alerts()->where('saved_screener_id', $validated['saved_screener_id'])->exists()
                    : $user->alerts()->where('kind', Alert::KIND_WATCHLIST)->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'kind' => [__('messages.alerts.duplicate')],
                    ]);
                }

                return $user->alerts()->create([
                    'kind' => $validated['kind'],
                    'saved_screener_id' => $validated['saved_screener_id'] ?? null,
                    'signal_types' => isset($validated['signal_types']) ? $this->canonicalTypes($validated['signal_types']) : null,
                    'active' => true,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'kind' => [__('messages.alerts.duplicate')],
            ]);
        }

        return response()->json(['alert' => $this->payload($alert->load('savedScreener'))], 201);
    }

    public function update(Request $request, string $alert): JsonResponse
    {
        $owned = $this->owned($request, $alert);

        if ($owned === null) {
            return response()->json(['message' => __('messages.alerts.not_found')], 404);
        }

        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'signal_types' => [
                'sometimes',
                Rule::prohibitedIf($owned->kind === Alert::KIND_SCREENER),
                'array',
                'min:1',
            ],
            'signal_types.*' => ['required', 'string', Rule::in(self::SIGNAL_TYPES)],
        ]);

        $resetBaseline = false;

        if (array_key_exists('active', $validated)) {
            // Re-activating starts a fresh baseline so the user is not flooded
            // with everything that changed while the alert was paused.
            $resetBaseline = $validated['active'] && ! $owned->active;
            $owned->active = (bool) $validated['active'];
        }

        if (array_key_exists('signal_types', $validated)) {
            $types = $this->canonicalTypes($validated['signal_types']);
            $resetBaseline = $resetBaseline || $types !== $owned->signal_types;
            $owned->signal_types = $types;
        }

        if ($resetBaseline) {
            $owned->forceFill(['last_state' => null, 'last_evaluated_as_of' => null]);
        }

        $owned->save();

        return response()->json(['alert' => $this->payload($owned->load('savedScreener'))]);
    }

    public function destroy(Request $request, string $alert): JsonResponse
    {
        $owned = $this->owned($request, $alert);

        if ($owned === null) {
            return response()->json(['message' => __('messages.alerts.not_found')], 404);
        }

        $owned->delete();

        return response()->json(null, 204);
    }

    private function owned(Request $request, string $id): ?Alert
    {
        return ctype_digit($id) ? $request->user()->alerts()->whereKey((int) $id)->first() : null;
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function canonicalTypes(array $types): array
    {
        return array_values(array_filter(self::SIGNAL_TYPES, fn (string $type): bool => in_array($type, $types, true)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'kind' => $alert->kind,
            'saved_screener' => $alert->savedScreener === null
                ? null
                : ['id' => $alert->savedScreener->id, 'name' => $alert->savedScreener->name],
            'signal_types' => $alert->signal_types,
            'active' => $alert->active,
            'last_evaluated_as_of' => $alert->last_evaluated_as_of?->toDateString(),
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }
}
