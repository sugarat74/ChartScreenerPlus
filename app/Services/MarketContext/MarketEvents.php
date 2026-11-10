<?php

namespace App\Services\MarketContext;

use App\Services\Macro\CentralBankCalendar;
use App\Services\Macro\FredClient;
use App\Services\Macro\FredUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarketEvents
{
    public function __construct(private readonly CentralBankCalendar $calendar, private readonly FredClient $fred) {}

    /** Replace only a fully validated release window; a failure preserves its last good dates. */
    public function refresh(string $code, string $from, string $to): bool
    {
        $release = config("market_events.releases.{$code}");
        DB::table('market_event_sources')->updateOrInsert(['code' => $code], ['last_attempted_at' => now()]);
        try {
            $dates = $this->fred->releaseDates($release['id'], $from, $to);
        } catch (FredUnavailable $exception) {
            DB::table('market_event_sources')->where('code', $code)->update(['last_error' => $exception->getMessage()]);

            return false;
        }
        DB::transaction(function () use ($code, $from, $to, $dates): void {
            DB::table('market_events')->where('code', $code)->whereBetween('date', [$from, $to])->delete();
            DB::table('market_events')->insert(array_map(fn (string $date): array => ['code' => $code, 'date' => $date], $dates));
            DB::table('market_event_sources')->where('code', $code)->update(['last_refreshed_at' => now(), 'last_error' => null]);
        });

        return true;
    }

    /** No provider requests on reads. Date-only events retain their source calendar day. */
    public function build(string $from, string $to, ?string $kind = null, ?string $region = null): array
    {
        $today = MarketContextPayload::today();
        $events = [];
        foreach ($this->calendar->banks() as $bank) {
            foreach ($this->calendar->meetings($bank) as $meeting) {
                $date = $meeting['decision'];
                if ($date < $from || $date > $to) {
                    continue;
                }
                $checked = (string) config("central_banks.{$bank}.checked_at");
                $events[] = [
                    'id' => "{$bank}:{$date}", 'code' => $bank, 'kind' => 'central_bank',
                    'region' => $bank === 'ecb' ? 'EU' : 'US', 'date' => $date,
                    'status' => $date < $today ? 'past' : ($bank === 'fed' ? 'tentative' : 'scheduled'),
                    'source' => (string) config("central_banks.{$bank}.name"),
                    'source_url' => (string) config("central_banks.{$bank}.source_url"),
                    'checked_at' => $checked,
                    'stale' => Carbon::parse($checked)->lt(Carbon::parse($today)->subYear()),
                ];
            }
        }
        $enabled = (bool) config('macro.enabled');
        $sources = [];
        if ($enabled) {
            $stored = DB::table('market_event_sources')->get()->keyBy('code');
            foreach ((array) config('market_events.releases') as $code => $release) {
                $row = $stored->get($code);
                $checked = $row?->last_refreshed_at;
                $sources[$code] = [
                    'checked_at' => $checked === null ? null : Carbon::parse($checked)->utc()->toIso8601ZuluString(),
                    'stale' => $checked === null || $row?->last_error !== null
                        || Carbon::parse($checked)->lt(now()->subDays((int) config('market_events.stale_after_days', 3))),
                ];
            }
            foreach (DB::table('market_events')->whereIn('code', array_keys($sources))->whereBetween('date', [$from, $to])->get() as $row) {
                $release = config("market_events.releases.{$row->code}");
                $date = substr($row->date, 0, 10);
                $events[] = [
                    'id' => "{$row->code}:{$date}", 'code' => $row->code, 'kind' => 'macro_release',
                    'region' => 'US', 'date' => $date, 'status' => $date < $today ? 'past' : 'tentative',
                    'source' => $release['source'], 'source_url' => 'https://fred.stlouisfed.org/release?rid='.$release['id'],
                    'checked_at' => $sources[$row->code]['checked_at'], 'stale' => $sources[$row->code]['stale'],
                ];
            }
        }
        $events = array_values(array_filter($events, fn (array $e): bool => ($kind === null || $e['kind'] === $kind) && ($region === null || $e['region'] === $region)));
        usort($events, fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        return ['as_of' => $today, 'from' => $from, 'to' => $to, 'macro_enabled' => $enabled,
            'macro_configured' => FredClient::isConfigured(), 'sources' => $sources, 'events' => $events];
    }
}
