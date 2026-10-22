<?php

namespace App\Services\Macro;

use InvalidArgumentException;

/**
 * Hand-kept Fed and ECB monetary policy meeting dates from
 * config/central_banks.php (docs/specs/macro-series-fred.md, Domain Rule 7).
 * Nothing is inferred: when the config has no matching meeting the answer is
 * null, never a guess.
 */
class CentralBankCalendar
{
    /** @return list<string> */
    public function banks(): array
    {
        return array_keys((array) config('central_banks'));
    }

    /**
     * Meetings of `$bank`, ascending by decision date.
     *
     * @return list<array{start: string, decision: string}>
     */
    public function meetings(string $bank): array
    {
        $config = config("central_banks.{$bank}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown central bank: {$bank}");
        }

        $meetings = array_values(array_map(
            fn (array $meeting): array => ['start' => (string) $meeting['start'], 'decision' => (string) $meeting['decision']],
            (array) ($config['meetings'] ?? []),
        ));
        usort($meetings, fn (array $a, array $b): int => strcmp($a['decision'], $b['decision']));

        return $meetings;
    }

    /**
     * First meeting whose decision is on or after `$asOf` (`Y-m-d`): on a
     * decision day, that day's meeting is still the next one.
     *
     * @return array{start: string, decision: string}|null
     */
    public function next(string $bank, string $asOf): ?array
    {
        foreach ($this->meetings($bank) as $meeting) {
            if ($meeting['decision'] >= $asOf) {
                return $meeting;
            }
        }

        return null;
    }

    /**
     * Latest meeting whose decision is before `$asOf` (`Y-m-d`).
     *
     * @return array{start: string, decision: string}|null
     */
    public function last(string $bank, string $asOf): ?array
    {
        $last = null;
        foreach ($this->meetings($bank) as $meeting) {
            if ($meeting['decision'] < $asOf) {
                $last = $meeting;
            }
        }

        return $last;
    }
}
