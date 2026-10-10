<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\Universe;
use App\Notifications\AlertTriggered;
use App\Services\Screener\CandidateQuery;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates one Alert against the current data (docs/specs/alerts-engine.md).
 *
 * - The first evaluation stores a baseline (`last_state`) and never notifies.
 * - Later evaluations notify only additions versus `last_state` (new
 *   Candidates, or Signal types that appeared on a followed Instrument), then
 *   replace `last_state`.
 * - An Alert is evaluated at most once per as-of date (the latest stored
 *   Daily Bar date), so re-running never duplicates a notification.
 */
class AlertEvaluator
{
    public const SKIPPED = 'skipped';

    public const BASELINE = 'baseline';

    public const UNCHANGED = 'unchanged';

    public const NOTIFIED = 'notified';

    public function __construct(private readonly CandidateQuery $candidates) {}

    /**
     * The as-of date of the stored data (`Y-m-d`), or null when there is none.
     */
    public function asOf(): ?string
    {
        $latest = DB::table('daily_bars')->max('date');

        return $latest === null ? null : substr((string) $latest, 0, 10);
    }

    /**
     * Evaluate one Alert for `$asOf`; returns what happened.
     */
    public function evaluate(Alert $alert, string $asOf): string
    {
        if ($alert->last_evaluated_as_of?->toDateString() === $asOf) {
            return self::SKIPPED;
        }

        $current = $alert->kind === Alert::KIND_SCREENER
            ? $this->screenerState($alert)
            : $this->watchlistState($alert);

        $previous = $alert->last_state;
        $items = $previous === null ? [] : $this->additions($alert->kind, $previous, $current);

        DB::transaction(function () use ($alert, $asOf, $current, $previous, $items): void {
            if ($previous !== null && $items !== []) {
                $max = max(1, (int) config('alerts.max_items'));

                $alert->user->notify(new AlertTriggered(
                    alertId: $alert->id,
                    kind: $alert->kind,
                    asOf: $asOf,
                    savedScreenerId: $alert->saved_screener_id,
                    savedScreenerName: $alert->savedScreener?->name,
                    items: array_slice($items, 0, $max),
                    more: max(0, count($items) - $max),
                ));
            }

            $alert->forceFill([
                'last_state' => $current,
                'last_evaluated_as_of' => $asOf,
            ])->save();
        });

        if ($previous === null) {
            return self::BASELINE;
        }

        return $items === [] ? self::UNCHANGED : self::NOTIFIED;
    }

    /**
     * The tickers currently returned by the Alert's Saved Screener.
     *
     * @return array{tickers: list<string>}
     */
    private function screenerState(Alert $alert): array
    {
        $screener = $alert->savedScreener;
        $universe = Universe::query()->where('slug', (string) config('ingestion.universe'))->first();

        if ($screener === null || $universe === null) {
            return ['tickers' => []];
        }

        $filters = CandidateQuery::fromSaved(is_array($screener->filters) ? $screener->filters : []);
        $tickers = array_column($this->candidates->candidates($universe, $filters), 'ticker');
        sort($tickers);

        return ['tickers' => array_values(array_unique($tickers))];
    }

    /**
     * For each followed Instrument, the chosen Signal types active right now.
     *
     * @return array{signals: array<string, list<string>>}
     */
    private function watchlistState(Alert $alert): array
    {
        $types = is_array($alert->signal_types) ? $alert->signal_types : [];
        $instrumentIds = $alert->user->watchlist()->pluck('instruments.id');

        if ($types === [] || $instrumentIds->isEmpty()) {
            return ['signals' => []];
        }

        $tickers = Instrument::query()->whereIn('id', $instrumentIds)->pluck('ticker', 'id');
        $signals = [];

        Signal::query()
            ->whereIn('instrument_id', $instrumentIds)
            ->whereIn('type', $types)
            ->orderBy('type')
            ->get(['instrument_id', 'type'])
            ->each(function (Signal $signal) use ($tickers, &$signals): void {
                $ticker = $tickers->get($signal->instrument_id);

                if ($ticker !== null) {
                    $signals[$ticker][] = $signal->type;
                }
            });

        ksort($signals);

        return ['signals' => array_map(fn (array $list): array => array_values(array_unique($list)), $signals)];
    }

    /**
     * What `$current` adds over `$previous`, as notification items.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<array{ticker: string, name: string|null, reason: string}>
     */
    private function additions(string $kind, array $previous, array $current): array
    {
        $pairs = [];

        if ($kind === Alert::KIND_SCREENER) {
            $before = is_array($previous['tickers'] ?? null) ? $previous['tickers'] : [];

            foreach (array_diff($current['tickers'], $before) as $ticker) {
                $pairs[] = [$ticker, 'new_candidate'];
            }
        } else {
            $before = is_array($previous['signals'] ?? null) ? $previous['signals'] : [];

            foreach ($current['signals'] as $ticker => $types) {
                $had = is_array($before[$ticker] ?? null) ? $before[$ticker] : [];

                foreach (array_diff($types, $had) as $type) {
                    $pairs[] = [(string) $ticker, $type];
                }
            }
        }

        if ($pairs === []) {
            return [];
        }

        $names = Instrument::query()
            ->whereIn('ticker', array_unique(array_column($pairs, 0)))
            ->pluck('company', 'ticker');

        return array_map(fn (array $pair): array => [
            'ticker' => $pair[0],
            'name' => $names->get($pair[0]),
            'reason' => $pair[1],
        ], $pairs);
    }
}
