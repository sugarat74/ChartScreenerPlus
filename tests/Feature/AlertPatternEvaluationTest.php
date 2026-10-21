<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * alerts-patterns evaluation (docs/specs/alerts-patterns.md): identity
 * `type + start_date`, `appears`/`confirmed` events, merge rule, silent
 * disappearance, baseline, once per as-of and the item cap.
 */
class AlertPatternEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private Universe $universe;

    private string $asOf = '2026-10-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->universe = Universe::factory()->create(['slug' => 'sp500']);
        config(['ingestion.universe' => 'sp500']);
    }

    private function member(string $ticker): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker, 'company' => "{$ticker} Corp"]);
        $this->universe->instruments()->attach($instrument->id);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $this->asOf]);

        return $instrument;
    }

    /** Advance the stored data to a new as-of date (a new bar on any instrument). */
    private function newSession(Instrument $instrument, string $date): void
    {
        $this->asOf = $date;
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date]);
    }

    /** Replace the instrument's current pattern of `$type`, as `patterns:detect` would. */
    private function pattern(Instrument $instrument, string $type, string $status, string $start, float $breakout = 123.45): void
    {
        $this->clearPattern($instrument, $type);
        ChartPattern::factory()->create([
            'instrument_id' => $instrument->id,
            'as_of_date' => $this->asOf,
            'type' => $type,
            'status' => $status,
            'start_date' => $start,
            'end_date' => $this->asOf,
            'breakout_level' => $breakout,
        ]);
    }

    private function clearPattern(Instrument $instrument, string $type): void
    {
        ChartPattern::query()->where('instrument_id', $instrument->id)->where('type', $type)->delete();
    }

    private function evaluate(string $expected = ''): void
    {
        $command = $this->refreshScreenerRows()->artisan('alerts:evaluate');

        if ($expected !== '') {
            $command->expectsOutputToContain($expected);
        }

        $command->assertExitCode(0);
    }

    /**
     * The data of the user's newest notification (by as-of, never tied).
     *
     * @return array<string, mixed>
     */
    private function latest(User $user): array
    {
        return $user->notifications()->get()->sortByDesc('data.as_of')->first()->data;
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $events
     */
    private function patternAlert(User $user, array $types, array $events = ['confirmed']): Alert
    {
        return Alert::factory()->for($user)->watchlistPattern($types, $events)->create();
    }

    public function test_a_second_batch_type_notifies_like_the_first_four(): void
    {
        // chart-patterns-extended: an inverse cup with handle confirming on the Watchlist.
        $user = User::factory()->create();
        $ste = $this->member('STE');
        $user->watchlist()->attach($ste->id);
        $this->pattern($ste, 'inverse_cup_with_handle', 'forming', '2026-05-04');
        $this->patternAlert($user, ['inverse_cup_with_handle']);

        $this->evaluate('0 notified, 1 baseline');

        $this->newSession($ste, '2026-10-02');
        $this->pattern($ste, 'inverse_cup_with_handle', 'confirmed', '2026-05-04', 98.7);
        $this->evaluate('1 notified');

        $this->assertSame([[
            'ticker' => 'STE',
            'name' => 'STE Corp',
            'reason' => 'pattern:inverse_cup_with_handle:confirmed',
            'status' => 'confirmed',
            'breakout_level' => 98.7,
        ]], $this->latest($user)['items']);
    }

    public function test_confirmation_notifies_once_after_a_silent_baseline(): void
    {
        $user = User::factory()->create();
        $nvda = $this->member('NVDA');
        $user->watchlist()->attach($nvda->id);
        $this->pattern($nvda, 'double_bottom', 'forming', '2026-08-03');
        $alert = $this->patternAlert($user, ['double_bottom']);

        $this->evaluate('0 notified, 1 baseline');
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(
            ['patterns' => ['NVDA' => ['double_bottom' => ['start' => '2026-08-03', 'status' => 'forming']]]],
            $alert->fresh()->last_state,
        );

        $this->newSession($nvda, '2026-10-02');
        $this->pattern($nvda, 'double_bottom', 'confirmed', '2026-08-03', 123.45);
        $this->evaluate('1 notified');

        $notification = $user->notifications()->sole();
        $this->assertSame('watchlist_pattern', $notification->data['kind']);
        $this->assertSame('2026-10-02', $notification->data['as_of']);
        $this->assertSame([[
            'ticker' => 'NVDA',
            'name' => 'NVDA Corp',
            'reason' => 'pattern:double_bottom:confirmed',
            'status' => 'confirmed',
            'breakout_level' => 123.45,
        ]], $notification->data['items']);
        $this->assertSame(0, $notification->data['more']);

        // Same as-of again: already evaluated. Next as-of, still confirmed: nothing.
        $this->evaluate('1 already evaluated');
        $this->newSession($nvda, '2026-10-05');
        $this->pattern($nvda, 'double_bottom', 'confirmed', '2026-08-03');
        $this->evaluate('1 unchanged');
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_appears_on_new_rows_and_new_instances_not_on_persistence_and_never_on_disappearance(): void
    {
        $user = User::factory()->create();
        $aapl = $this->member('AAPL');
        $user->watchlist()->attach($aapl->id);
        $this->patternAlert($user, ['bull_flag'], ['appears']);
        $this->evaluate('1 baseline');

        // A new bull flag: appears.
        $this->newSession($aapl, '2026-10-02');
        $this->pattern($aapl, 'bull_flag', 'forming', '2026-09-20');
        $this->evaluate('1 notified');
        $this->assertSame('pattern:bull_flag:appears', $this->latest($user)['items'][0]['reason']);
        $this->assertSame('forming', $this->latest($user)['items'][0]['status']);

        // Same instance one session later (even confirmed, `confirmed` not chosen): nothing.
        $this->newSession($aapl, '2026-10-03');
        $this->pattern($aapl, 'bull_flag', 'confirmed', '2026-09-20');
        $this->evaluate('1 unchanged');

        // A different instance (new start date) replaces it: appears again.
        $this->newSession($aapl, '2026-10-04');
        $this->pattern($aapl, 'bull_flag', 'forming', '2026-09-28');
        $this->evaluate('1 notified');
        $this->assertSame(2, $user->notifications()->count());

        // It disappears: nothing.
        $this->newSession($aapl, '2026-10-05');
        $this->clearPattern($aapl, 'bull_flag');
        $this->evaluate('1 unchanged');

        // It reappears later (same start date as before): notifies again.
        $this->newSession($aapl, '2026-10-06');
        $this->pattern($aapl, 'bull_flag', 'forming', '2026-09-28');
        $this->evaluate('1 notified');
        $this->assertSame(3, $user->notifications()->count());
    }

    public function test_both_events_on_a_new_confirmed_instance_yield_one_confirmed_item(): void
    {
        $user = User::factory()->create();
        $msft = $this->member('MSFT');
        $user->watchlist()->attach($msft->id);
        $this->patternAlert($user, ['double_top', 'cup_with_handle'], ['appears', 'confirmed']);
        $this->evaluate('1 baseline');

        $this->newSession($msft, '2026-10-02');
        $this->pattern($msft, 'cup_with_handle', 'confirmed', '2026-07-01', 310.5);
        $this->pattern($msft, 'double_top', 'forming', '2026-08-10', 400.25);
        $this->evaluate('1 notified');

        $items = $user->notifications()->sole()->data['items'];
        // Canonical type order: double_top before cup_with_handle.
        $this->assertSame(['pattern:double_top:appears', 'pattern:cup_with_handle:confirmed'], array_column($items, 'reason'));
        $this->assertSame(['forming', 'confirmed'], array_column($items, 'status'));
        $this->assertSame([400.25, 310.5], array_column($items, 'breakout_level'));

        // Forming → confirmed of the same instance with both events: one `confirmed`.
        $this->newSession($msft, '2026-10-05');
        $this->pattern($msft, 'double_top', 'confirmed', '2026-08-10', 400.25);
        $this->pattern($msft, 'cup_with_handle', 'confirmed', '2026-07-01', 310.5);
        $this->evaluate('1 notified');
        $this->assertSame(
            ['pattern:double_top:confirmed'],
            array_column($this->latest($user)['items'], 'reason'),
        );
    }

    public function test_unchosen_types_and_unfollowed_instruments_never_notify(): void
    {
        $user = User::factory()->create();
        $nvda = $this->member('NVDA');
        $tsla = $this->member('TSLA');
        $user->watchlist()->attach($nvda->id);
        $alert = $this->patternAlert($user, ['double_bottom'], ['appears', 'confirmed']);
        $this->evaluate('1 baseline');

        $this->newSession($nvda, '2026-10-02');
        $this->pattern($nvda, 'double_top', 'confirmed', '2026-08-01');
        $this->pattern($tsla, 'double_bottom', 'confirmed', '2026-08-01');
        $this->evaluate('1 unchanged');

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(['patterns' => []], $alert->fresh()->last_state);
    }

    public function test_a_newly_followed_ticker_notifies_its_active_patterns_at_the_next_run(): void
    {
        $user = User::factory()->create();
        $nvda = $this->member('NVDA');
        $amd = $this->member('AMD');
        $user->watchlist()->attach($nvda->id);
        $this->pattern($amd, 'double_bottom', 'confirmed', '2026-08-01');
        $this->patternAlert($user, ['double_bottom']);
        $this->evaluate('1 baseline');

        $user->watchlist()->attach($amd->id);
        $this->newSession($nvda, '2026-10-02');
        $this->evaluate('1 notified');

        $this->assertSame('AMD', $user->notifications()->sole()->data['items'][0]['ticker']);
    }

    public function test_items_are_capped_and_ordered_by_ticker(): void
    {
        config(['alerts.max_items' => 2]);
        $user = User::factory()->create();
        $instruments = [];
        foreach (['EEE', 'BBB', 'DDD', 'AAA', 'CCC'] as $ticker) {
            $instruments[$ticker] = $this->member($ticker);
            $user->watchlist()->attach($instruments[$ticker]->id);
        }
        $this->patternAlert($user, ['bull_flag'], ['appears']);
        $this->evaluate('1 baseline');

        $this->newSession($instruments['AAA'], '2026-10-02');
        foreach ($instruments as $instrument) {
            $this->pattern($instrument, 'bull_flag', 'forming', '2026-09-25');
        }
        $this->evaluate('1 notified');

        $data = $user->notifications()->sole()->data;
        $this->assertSame(['AAA', 'BBB'], array_column($data['items'], 'ticker'));
        $this->assertSame(3, $data['more']);
    }

    public function test_pattern_and_signal_alerts_of_one_user_evaluate_independently(): void
    {
        $user = User::factory()->create();
        $nvda = $this->member('NVDA');
        $user->watchlist()->attach($nvda->id);
        $signalAlert = $user->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross']]);
        $patternAlert = $this->patternAlert($user, ['double_bottom']);
        $this->evaluate('2 baseline');

        $this->newSession($nvda, '2026-10-02');
        Signal::factory()->create(['instrument_id' => $nvda->id, 'date' => '2026-10-02', 'type' => 'golden_cross']);
        $this->pattern($nvda, 'double_bottom', 'confirmed', '2026-08-03');
        $this->evaluate('2 notified');

        $byKind = $user->notifications()->get()->keyBy('data.kind');
        $this->assertSame([['ticker' => 'NVDA', 'name' => 'NVDA Corp', 'reason' => 'golden_cross']], $byKind['watchlist_signal']->data['items']);
        $this->assertSame('pattern:double_bottom:confirmed', $byKind['watchlist_pattern']->data['items'][0]['reason']);
        $this->assertSame(['signals' => ['NVDA' => ['golden_cross']]], $signalAlert->fresh()->last_state);
        $this->assertSame(['start' => '2026-08-03', 'status' => 'confirmed'], $patternAlert->fresh()->last_state['patterns']['NVDA']['double_bottom']);
    }
}
