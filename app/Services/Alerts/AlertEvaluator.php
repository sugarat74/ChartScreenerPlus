<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\ChartPattern;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\Universe;
use App\Notifications\AlertTriggered;
use App\Services\Screener\CandidateQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates one Alert against the current data (docs/specs/alerts-engine.md).
 *
 * - The first evaluation stores a baseline (`last_state`) and never notifies.
 * - Later evaluations notify only additions versus `last_state` (new
 *   Candidates, Signal types that appeared on a followed Instrument, or
 *   chartist patterns that appeared or were confirmed on one, see
 *   docs/specs/alerts-patterns.md), then replace `last_state`.
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
     * The as-of date of the stored stock data (`Y-m-d`), or null when there is
     * none. Market Context ETF bars never move it.
     */
    public function asOf(): ?string
    {
        $latest = DB::table('daily_bars')
            ->join('instruments', 'instruments.id', '=', 'daily_bars.instrument_id')
            ->where('instruments.kind', Instrument::KIND_STOCK)
            ->max('daily_bars.date');

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

        $current = match ($alert->kind) {
            Alert::KIND_SCREENER => $this->screenerState($alert),
            Alert::KIND_WATCHLIST => $this->watchlistState($alert),
            Alert::KIND_WATCHLIST_PATTERN => $this->patternState($alert),
        };

        $previous = $alert->last_state;
        $items = $previous === null ? [] : match ($alert->kind) {
            Alert::KIND_SCREENER, Alert::KIND_WATCHLIST => $this->additions($alert->kind, $previous, $current),
            Alert::KIND_WATCHLIST_PATTERN => $this->patternAdditions($alert, $previous, $current),
        };

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
        $instrumentIds = $alert->user->watchlist()->where('instruments.kind', Instrument::KIND_STOCK)->pluck('instruments.id');

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
        $pairs = match ($kind) {
            Alert::KIND_SCREENER => $this->candidatePairs($previous, $current),
            Alert::KIND_WATCHLIST => $this->signalPairs($previous, $current),
        };

        if ($pairs === []) {
            return [];
        }

        $names = $this->companies(array_column($pairs, 0));

        return array_map(fn (array $pair): array => [
            'ticker' => $pair[0],
            'name' => $names->get($pair[0]),
            'reason' => $pair[1],
        ], $pairs);
    }

    /**
     * New Candidates of a Screener alert as `[ticker, 'new_candidate']` pairs.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<array{0: string, 1: string}>
     */
    private function candidatePairs(array $previous, array $current): array
    {
        $before = is_array($previous['tickers'] ?? null) ? $previous['tickers'] : [];
        $pairs = [];

        foreach (array_diff($current['tickers'], $before) as $ticker) {
            $pairs[] = [$ticker, 'new_candidate'];
        }

        return $pairs;
    }

    /**
     * Signal types that appeared on a followed Instrument as `[ticker, type]` pairs.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<array{0: string, 1: string}>
     */
    private function signalPairs(array $previous, array $current): array
    {
        $before = is_array($previous['signals'] ?? null) ? $previous['signals'] : [];
        $pairs = [];

        foreach ($current['signals'] as $ticker => $types) {
            $had = is_array($before[$ticker] ?? null) ? $before[$ticker] : [];

            foreach (array_diff($types, $had) as $type) {
                $pairs[] = [(string) $ticker, $type];
            }
        }

        return $pairs;
    }

    /**
     * For each followed Instrument, the chosen pattern types in the current
     * `chart_patterns` set, identified by `start` (the first key point's date)
     * with their status (docs/specs/alerts-patterns.md, Decisions 4-5).
     *
     * @return array{patterns: array<string, array<string, array{start: string, status: string}>>}
     */
    private function patternState(Alert $alert): array
    {
        $chosen = is_array($alert->pattern_types) ? $alert->pattern_types : [];
        $types = array_values(array_filter(ChartPattern::TYPES, fn (string $type): bool => in_array($type, $chosen, true)));
        $instrumentIds = $alert->user->watchlist()->where('instruments.kind', Instrument::KIND_STOCK)->pluck('instruments.id');

        if ($types === [] || $instrumentIds->isEmpty()) {
            return ['patterns' => []];
        }

        $tickers = Instrument::query()->whereIn('id', $instrumentIds)->pluck('ticker', 'id');
        $found = [];

        ChartPattern::query()
            ->whereIn('instrument_id', $instrumentIds)
            ->whereIn('type', $types)
            ->orderBy('as_of_date')
            ->get(['instrument_id', 'type', 'status', 'start_date'])
            ->each(function (ChartPattern $pattern) use ($tickers, &$found): void {
                $ticker = $tickers->get($pattern->instrument_id);

                if ($ticker !== null) {
                    // One current row per (instrument, type); a later as-of wins.
                    $found[$ticker][$pattern->type] = [
                        'start' => $pattern->start_date->toDateString(),
                        'status' => $pattern->status,
                    ];
                }
            });

        ksort($found);
        $patterns = [];

        foreach ($found as $ticker => $byType) {
            foreach ($types as $type) {
                if (isset($byType[$type])) {
                    $patterns[$ticker][$type] = $byType[$type];
                }
            }
        }

        return ['patterns' => $patterns];
    }

    /**
     * Pattern events versus the previous evaluation (Decision 6): `appears`
     * for an instance (`type` + `start`) absent before, `confirmed` when an
     * instance is confirmed now and was not confirmed before. Both at once
     * yield one `confirmed` item; disappearance never notifies.
     *
     * @param  array<string, mixed>  $previous
     * @param  array{patterns: array<string, array<string, array{start: string, status: string}>>}  $current
     * @return list<array{ticker: string, name: string|null, reason: string, status: string, breakout_level: float}>
     */
    private function patternAdditions(Alert $alert, array $previous, array $current): array
    {
        $events = is_array($alert->pattern_events) ? $alert->pattern_events : [];
        $before = is_array($previous['patterns'] ?? null) ? $previous['patterns'] : [];
        $hits = [];

        foreach ($current['patterns'] as $ticker => $byType) {
            $had = is_array($before[$ticker] ?? null) ? $before[$ticker] : [];

            foreach ($byType as $type => $now) {
                $then = is_array($had[$type] ?? null) ? $had[$type] : null;
                $same = $then !== null && ($then['start'] ?? null) === $now['start'];

                $confirmed = in_array('confirmed', $events, true)
                    && $now['status'] === 'confirmed'
                    && ! ($same && ($then['status'] ?? null) === 'confirmed');
                $appears = in_array('appears', $events, true) && ! $same;

                if ($confirmed || $appears) {
                    $hits[] = [(string) $ticker, (string) $type, $confirmed ? 'confirmed' : 'appears', $now['status']];
                }
            }
        }

        if ($hits === []) {
            return [];
        }

        $tickers = array_values(array_unique(array_column($hits, 0)));
        $names = $this->companies($tickers);
        $levels = [];

        ChartPattern::query()
            ->join('instruments', 'instruments.id', '=', 'chart_patterns.instrument_id')
            ->whereIn('instruments.ticker', $tickers)
            ->whereIn('chart_patterns.type', array_values(array_unique(array_column($hits, 1))))
            ->orderBy('chart_patterns.as_of_date')
            ->get(['instruments.ticker', 'chart_patterns.type', 'chart_patterns.breakout_level'])
            ->each(function (ChartPattern $row) use (&$levels): void {
                $levels[$row->getAttribute('ticker')][$row->type] = (float) $row->breakout_level;
            });

        return array_map(fn (array $hit): array => [
            'ticker' => $hit[0],
            'name' => $names->get($hit[0]),
            'reason' => "pattern:{$hit[1]}:{$hit[2]}",
            'status' => $hit[3],
            'breakout_level' => $levels[$hit[0]][$hit[1]] ?? 0.0,
        ], $hits);
    }

    /**
     * @param  list<string>  $tickers
     * @return Collection<string, string|null>
     */
    private function companies(array $tickers): Collection
    {
        return Instrument::query()
            ->whereIn('ticker', array_unique($tickers))
            ->pluck('company', 'ticker');
    }
}
