<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\ChartPattern;
use App\Models\User;
use App\Services\Screener\ScreenerFilterParser;
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
    private const SIGNAL_TYPES = ScreenerFilterParser::SIGNAL_TYPES;

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

        $kind = $request->input('kind');
        $isPattern = $kind === Alert::KIND_WATCHLIST_PATTERN;

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(Alert::KINDS)],
            'saved_screener_id' => [
                Rule::requiredIf($kind === Alert::KIND_SCREENER),
                Rule::prohibitedIf(in_array($kind, [Alert::KIND_WATCHLIST, Alert::KIND_WATCHLIST_PATTERN], true)),
                'nullable',
                'integer',
                Rule::exists('saved_screeners', 'id')->where('user_id', $user->id),
            ],
            'signal_types' => [
                Rule::requiredIf($kind === Alert::KIND_WATCHLIST),
                Rule::prohibitedIf(in_array($kind, [Alert::KIND_SCREENER, Alert::KIND_WATCHLIST_PATTERN], true)),
                'nullable',
                'array',
                'min:1',
            ],
            'signal_types.*' => ['required', 'string', Rule::in(self::SIGNAL_TYPES)],
            'pattern_types' => [
                Rule::requiredIf($isPattern),
                Rule::prohibitedIf(in_array($kind, [Alert::KIND_SCREENER, Alert::KIND_WATCHLIST], true)),
                'nullable',
                'array',
                'min:1',
            ],
            'pattern_types.*' => ['required', 'string', Rule::in(ChartPattern::TYPES)],
            'pattern_events' => [
                'sometimes',
                Rule::prohibitedIf(in_array($kind, [Alert::KIND_SCREENER, Alert::KIND_WATCHLIST], true)),
                'array',
                'min:1',
            ],
            'pattern_events.*' => ['required', 'string', Rule::in(Alert::PATTERN_EVENTS)],
        ]);

        try {
            // The limit and duplicate checks and the insert run under a lock on
            // the user's row, so parallel requests cannot exceed the limit or
            // create a second Watchlist alert; a race on the unique
            // (user, saved screener) index still ends as the duplicate 422.
            $alert = DB::transaction(function () use ($user, $validated, $isPattern): Alert {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                if ($user->alerts()->count() >= (int) config('alerts.max_per_user')) {
                    throw ValidationException::withMessages([
                        'kind' => [__('messages.alerts.limit', ['max' => (int) config('alerts.max_per_user')])],
                    ]);
                }

                $duplicate = $validated['kind'] === Alert::KIND_SCREENER
                    ? $user->alerts()->where('saved_screener_id', $validated['saved_screener_id'])->exists()
                    // One alert per user for each Watchlist kind (signals, patterns).
                    : $user->alerts()->where('kind', $validated['kind'])->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'kind' => [__('messages.alerts.duplicate')],
                    ]);
                }

                return $user->alerts()->create([
                    'kind' => $validated['kind'],
                    'saved_screener_id' => $validated['saved_screener_id'] ?? null,
                    'signal_types' => isset($validated['signal_types']) ? $this->canonicalTypes($validated['signal_types']) : null,
                    'pattern_types' => $isPattern ? $this->canonical($validated['pattern_types'], ChartPattern::TYPES) : null,
                    'pattern_events' => $isPattern
                        ? $this->canonical($validated['pattern_events'] ?? ['confirmed'], Alert::PATTERN_EVENTS)
                        : null,
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
                Rule::prohibitedIf($owned->kind !== Alert::KIND_WATCHLIST),
                'array',
                'min:1',
            ],
            'signal_types.*' => ['required', 'string', Rule::in(self::SIGNAL_TYPES)],
            'pattern_types' => [
                'sometimes',
                Rule::prohibitedIf($owned->kind !== Alert::KIND_WATCHLIST_PATTERN),
                'array',
                'min:1',
            ],
            'pattern_types.*' => ['required', 'string', Rule::in(ChartPattern::TYPES)],
            'pattern_events' => [
                'sometimes',
                Rule::prohibitedIf($owned->kind !== Alert::KIND_WATCHLIST_PATTERN),
                'array',
                'min:1',
            ],
            'pattern_events.*' => ['required', 'string', Rule::in(Alert::PATTERN_EVENTS)],
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

        // Changing the pattern types or events to a different canonical set
        // starts a fresh baseline, like the Signal types above.
        foreach (['pattern_types' => ChartPattern::TYPES, 'pattern_events' => Alert::PATTERN_EVENTS] as $field => $vocabulary) {
            if (array_key_exists($field, $validated)) {
                $values = $this->canonical($validated[$field], $vocabulary);
                $resetBaseline = $resetBaseline || $values !== $owned->{$field};
                $owned->{$field} = $values;
            }
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
        return $this->canonical($types, self::SIGNAL_TYPES);
    }

    /**
     * Deduplicate `$values` into the order of `$vocabulary`.
     *
     * @param  list<string>  $values
     * @param  list<string>  $vocabulary
     * @return list<string>
     */
    private function canonical(array $values, array $vocabulary): array
    {
        return array_values(array_filter($vocabulary, fn (string $value): bool => in_array($value, $values, true)));
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
            'pattern_types' => $alert->pattern_types,
            'pattern_events' => $alert->pattern_events,
            'active' => $alert->active,
            'last_evaluated_as_of' => $alert->last_evaluated_as_of?->toDateString(),
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }
}
