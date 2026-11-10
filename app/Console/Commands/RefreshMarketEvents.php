<?php

namespace App\Console\Commands;

use App\Console\Concerns\RecordsMarketContextRun;
use App\Services\Macro\FredClient;
use App\Services\MarketContext\MarketContextPayload;
use App\Services\MarketContext\MarketEvents;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RefreshMarketEvents extends Command
{
    use RecordsMarketContextRun;

    protected $signature = 'events:refresh';

    protected $description = 'Refresh the closed list of free US macro release dates from FRED';

    public function handle(MarketEvents $events): int
    {
        if (! config('macro.enabled')) {
            $this->info('Macro series are disabled; event dates were not requested.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('events:refresh', 900);
        if (! $lock->get()) {
            $this->info('Event refresh already running; skipped.');

            return self::SUCCESS;
        }
        try {
            return $this->recordingRun('events:refresh', function () use ($events): int {
                if (! FredClient::isConfigured()) {
                    $this->error('FRED_API_KEY is missing or malformed; nothing was requested.');

                    return self::FAILURE;
                }
                $today = Carbon::parse(MarketContextPayload::today());
                $from = $today->copy()->subDays((int) config('market_events.past_days', 30))->toDateString();
                $to = $today->copy()->addDays((int) config('market_events.future_days', 90))->toDateString();
                $failed = false;
                foreach (array_keys((array) config('market_events.releases')) as $code) {
                    $ok = $events->refresh($code, $from, $to);
                    $this->line($code.($ok ? ': updated' : ': failed; previous dates retained'));
                    $failed = $failed || ! $ok;
                }

                return $failed ? self::FAILURE : self::SUCCESS;
            });
        } finally {
            $lock->release();
        }
    }
}
