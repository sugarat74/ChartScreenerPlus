<?php

namespace App\Services\Fundamentals;

use DateTimeImmutable;

/**
 * Trailing-twelve-month arithmetic over companyfacts facts (spec decisions
 * 2-3). Pure: no I/O, no clock.
 *
 * Period labels always come from a fact's `start`/`end`, never from its
 * `fy`/`fp` (those describe the filing, not the fact). Facts are deduplicated
 * by `(start, end)` keeping the latest `filed`, so restatements and 10-K/A /
 * 10-Q/A amendments win over the original figure.
 *
 * TTM at an anchor date: take `L`, the longest duration (<= 380 days) ending
 * at the anchor. A 350-380-day `L` is the fiscal year itself (this covers Q4:
 * the 10-K figure is used, no Q4 is derived). Otherwise TTM = A + L - C, with
 * `A` the fiscal year ending the day before `L` starts and `C` the same-length
 * period one year earlier; this works both for year-to-date cash-flow items
 * and for quarter-only facts. Fallback: four contiguous quarters. Else null.
 */
class TtmCalculator
{
    public const ANNUAL_MIN_DAYS = 350;

    public const ANNUAL_MAX_DAYS = 380;

    public const QUARTER_MIN_DAYS = 80;

    public const QUARTER_MAX_DAYS = 100;

    /** A TTM metric must end within this many days of the anchor. */
    public const ANCHOR_TOLERANCE_DAYS = 3;

    /** Tolerance for matching the year-ago comparative and adjacent periods. */
    public const MATCH_TOLERANCE_DAYS = 10;

    public const METHOD_ANNUAL = 'annual';

    public const METHOD_YTD = 'ytd';

    public const METHOD_QUARTERS = 'quarters';

    /**
     * Newest end date of the duration facts (the anchor period end).
     *
     * @param  list<array<string, mixed>>  $facts
     */
    public function anchor(array $facts): ?string
    {
        $ends = array_map(fn (array $fact): string => $fact['end'], self::durations($facts));

        return $ends === [] ? null : max($ends);
    }

    /**
     * TTM value ending at `$end` (+/- `$tolerance` days).
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array{value: float, start: string, end: string, form: string, accn: string, filed: string, method: string}|null
     */
    public function ttm(array $facts, string $end, int $tolerance = self::ANCHOR_TOLERANCE_DAYS): ?array
    {
        $durations = self::durations($facts);
        if ($durations === []) {
            return null;
        }

        $atEnd = array_values(array_filter(
            $durations,
            fn (array $fact): bool => abs(self::days($end, $fact['end'])) <= $tolerance
                && self::length($fact) <= self::ANNUAL_MAX_DAYS,
        ));
        usort($atEnd, fn (array $a, array $b): int => [self::length($b), abs(self::days($end, $a['end']))] <=> [self::length($a), abs(self::days($end, $b['end']))]);
        $longest = $atEnd[0] ?? null;

        if ($longest !== null && self::length($longest) >= self::ANNUAL_MIN_DAYS) {
            return self::result($longest['val'], $longest['start'], $longest, self::METHOD_ANNUAL);
        }

        if ($longest !== null) {
            $annual = self::closest($durations, self::shift($longest['start'], '-1 day'), fn (array $fact): bool => self::isAnnual($fact));
            $comparative = self::closest(
                $durations,
                self::shift($longest['end'], '-1 year'),
                fn (array $fact): bool => abs(self::length($fact) - self::length($longest)) <= self::MATCH_TOLERANCE_DAYS,
            );
            if ($annual !== null && $comparative !== null) {
                return self::result(
                    $annual['val'] + $longest['val'] - $comparative['val'],
                    self::shift($comparative['end'], '+1 day'),
                    $longest,
                    self::METHOD_YTD,
                );
            }
        }

        return $this->quarters($durations, $end, $tolerance);
    }

    /**
     * The same TTM one year before the anchor (for growth rates).
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array{value: float, start: string, end: string, form: string, accn: string, filed: string, method: string}|null
     */
    public function priorTtm(array $facts, string $anchor): ?array
    {
        return $this->ttm($facts, self::shift($anchor, '-1 year'), self::MATCH_TOLERANCE_DAYS);
    }

    /**
     * Newest duration fact of quarter length (e.g. weighted shares).
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>|null
     */
    public function latestQuarter(array $facts): ?array
    {
        $quarters = array_values(array_filter(self::durations($facts), fn (array $fact): bool => self::isQuarter($fact)));
        usort($quarters, fn (array $a, array $b): int => strcmp($b['end'], $a['end']));

        return $quarters[0] ?? null;
    }

    /**
     * The instant value at `$date` (+/- tolerance), latest filing winning.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>|null
     */
    public function instantAt(array $facts, string $date, int $tolerance = self::ANCHOR_TOLERANCE_DAYS): ?array
    {
        $match = array_values(array_filter(self::instants($facts), fn (array $fact): bool => abs(self::days($date, $fact['end'])) <= $tolerance));
        usort($match, fn (array $a, array $b): int => abs(self::days($date, $a['end'])) <=> abs(self::days($date, $b['end'])));

        return $match[0] ?? null;
    }

    /**
     * The newest instant (balance-sheet date, cover-page date).
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>|null
     */
    public function latestInstant(array $facts): ?array
    {
        $instants = self::instants($facts);
        usort($instants, fn (array $a, array $b): int => strcmp($b['end'], $a['end']));

        return $instants[0] ?? null;
    }

    /**
     * Duration facts deduplicated by (start, end), the latest `filed` winning.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    public static function durations(array $facts): array
    {
        $kept = [];
        foreach ($facts as $fact) {
            if (($fact['start'] ?? null) === null) {
                continue;
            }
            $key = $fact['start'].'|'.$fact['end'];
            if (! isset($kept[$key]) || strcmp((string) $fact['filed'], (string) $kept[$key]['filed']) >= 0) {
                $kept[$key] = $fact;
            }
        }

        return array_values($kept);
    }

    /**
     * Instant facts deduplicated by end date, the latest `filed` winning.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    public static function instants(array $facts): array
    {
        $kept = [];
        foreach ($facts as $fact) {
            if (($fact['start'] ?? null) !== null) {
                continue;
            }
            $key = $fact['end'];
            if (! isset($kept[$key]) || strcmp((string) $fact['filed'], (string) $kept[$key]['filed']) >= 0) {
                $kept[$key] = $fact;
            }
        }

        return array_values($kept);
    }

    /** Signed number of days from `$from` to `$to`. */
    public static function days(string $from, string $to): int
    {
        $diff = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to));

        return $diff->invert === 1 ? -(int) $diff->days : (int) $diff->days;
    }

    public static function shift(string $date, string $modifier): string
    {
        return (new DateTimeImmutable($date))->modify($modifier)->format('Y-m-d');
    }

    /**
     * @param  list<array<string, mixed>>  $durations
     * @return array{value: float, start: string, end: string, form: string, accn: string, filed: string, method: string}|null
     */
    private function quarters(array $durations, string $end, int $tolerance): ?array
    {
        $chain = [];
        $target = $end;
        $window = $tolerance;
        for ($i = 0; $i < 4; $i++) {
            $quarter = self::closest($durations, $target, fn (array $fact): bool => self::isQuarter($fact), $window);
            if ($quarter === null) {
                return null;
            }
            $chain[] = $quarter;
            $target = self::shift($quarter['start'], '-1 day');
            $window = self::MATCH_TOLERANCE_DAYS;
        }

        $sum = array_sum(array_map(fn (array $fact): float => (float) $fact['val'], $chain));

        return self::result($sum, $chain[3]['start'], $chain[0], self::METHOD_QUARTERS);
    }

    /**
     * Fact ending closest to `$end` (within tolerance) that passes `$filter`.
     *
     * @param  list<array<string, mixed>>  $durations
     * @param  callable(array<string, mixed>): bool  $filter
     * @return array<string, mixed>|null
     */
    private static function closest(array $durations, string $end, callable $filter, int $tolerance = self::MATCH_TOLERANCE_DAYS): ?array
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($durations as $fact) {
            $distance = abs(self::days($end, $fact['end']));
            if ($distance <= $tolerance && $filter($fact) && $distance < $bestDistance) {
                $best = $fact;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $last  The fact that ends the period (gives end, form, accession).
     * @return array{value: float, start: string, end: string, form: string, accn: string, filed: string, method: string}
     */
    private static function result(float $value, string $start, array $last, string $method): array
    {
        return [
            'value' => $value,
            'start' => $start,
            'end' => $last['end'],
            'form' => $last['form'],
            'accn' => $last['accn'],
            'filed' => $last['filed'],
            'method' => $method,
        ];
    }

    /** @param array<string, mixed> $fact */
    private static function length(array $fact): int
    {
        return self::days($fact['start'], $fact['end']);
    }

    /** @param array<string, mixed> $fact */
    private static function isAnnual(array $fact): bool
    {
        $length = self::length($fact);

        return $length >= self::ANNUAL_MIN_DAYS && $length <= self::ANNUAL_MAX_DAYS;
    }

    /** @param array<string, mixed> $fact */
    private static function isQuarter(array $fact): bool
    {
        $length = self::length($fact);

        return $length >= self::QUARTER_MIN_DAYS && $length <= self::QUARTER_MAX_DAYS;
    }
}
