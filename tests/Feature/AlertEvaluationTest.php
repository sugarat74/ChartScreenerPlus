<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\SavedScreener;
use App\Models\Signal;
use App\Models\Universe;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Screener\CandidateQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * alerts-engine evaluation: baseline without notifying, additions only, once
 * per as-of date, failures isolated, 50-item cap, pruning.
 */
class AlertEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private Universe $universe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->universe = Universe::factory()->create(['slug' => 'sp500']);
        config(['ingestion.universe' => 'sp500']);
    }

    private function member(string $ticker, float $rsi, string $date = '2026-10-01'): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker, 'company' => "{$ticker} Corp"]);
        $this->universe->instruments()->attach($instrument->id);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date]);
        IndicatorSnapshot::factory()->create(['instrument_id' => $instrument->id, 'date' => $date, 'rsi14' => $rsi]);

        return $instrument;
    }

    /** Advance the stored data to a new as-of date (a new bar on any instrument). */
    private function newSession(Instrument $instrument, string $date): void
    {
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date]);
    }

    private function screenerAlert(User $user, array $filters): Alert
    {
        $screener = $user->savedScreeners()->create(['name' => 'RSI alto', 'filters' => array_merge([
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null,
            'price_above_sma200' => false, 'ma_cross' => null, 'pattern' => [], 'pattern_status' => 'any',
            'sort' => 'rvol_desc',
        ], $filters)]);

        return $user->alerts()->create(['kind' => Alert::KIND_SCREENER, 'saved_screener_id' => $screener->id]);
    }

    public function test_screener_alert_baseline_then_notifies_only_new_candidates(): void
    {
        $user = User::factory()->create();
        $a = $this->member('AAA', 70);
        $this->member('BBB', 65);
        $c = $this->member('CCC', 40);
        $alert = $this->screenerAlert($user, ['rsi_min' => 50]);

        $this->artisan('alerts:evaluate')->expectsOutputToContain('0 notified, 1 baseline')->assertExitCode(0);
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(['tickers' => ['AAA', 'BBB']], $alert->fresh()->last_state);

        // Next session: CCC enters, AAA leaves.
        IndicatorSnapshot::query()->where('instrument_id', $c->id)->update(['rsi14' => 60]);
        IndicatorSnapshot::query()->where('instrument_id', $a->id)->update(['rsi14' => 30]);
        $this->newSession($c, '2026-10-02');

        $this->artisan('alerts:evaluate')->expectsOutputToContain('1 notified')->assertExitCode(0);

        $notification = $user->notifications()->sole();
        $this->assertSame('screener_new_candidates', $notification->data['kind']);
        $this->assertSame('2026-10-02', $notification->data['as_of']);
        $this->assertSame('RSI alto', $notification->data['saved_screener_name']);
        $this->assertSame([['ticker' => 'CCC', 'name' => 'CCC Corp', 'reason' => 'new_candidate']], $notification->data['items']);
        $this->assertSame(['tickers' => ['BBB', 'CCC']], $alert->fresh()->last_state);
    }

    public function test_evaluation_runs_once_per_as_of_date(): void
    {
        $user = User::factory()->create();
        $this->member('AAA', 70);
        $c = $this->member('CCC', 40);
        $this->screenerAlert($user, ['rsi_min' => 50]);

        $this->artisan('alerts:evaluate')->assertExitCode(0);
        IndicatorSnapshot::query()->where('instrument_id', $c->id)->update(['rsi14' => 60]);
        $this->newSession($c, '2026-10-02');

        $this->artisan('alerts:evaluate')->assertExitCode(0);
        $this->artisan('alerts:evaluate')->expectsOutputToContain('1 already evaluated')->assertExitCode(0);

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_watchlist_alert_notifies_when_a_chosen_signal_appears_not_while_it_persists(): void
    {
        $user = User::factory()->create();
        $nvda = $this->member('NVDA', 50);
        $msft = $this->member('MSFT', 50);
        $user->watchlist()->attach([$nvda->id, $msft->id]);
        Signal::factory()->create(['instrument_id' => $msft->id, 'date' => '2026-10-01', 'type' => 'golden_cross']);
        $alert = $user->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross', 'rsi_oversold']]);

        $this->artisan('alerts:evaluate')->assertExitCode(0);
        $this->assertSame(0, $user->notifications()->count());

        Signal::factory()->create(['instrument_id' => $nvda->id, 'date' => '2026-10-02', 'type' => 'golden_cross']);
        Signal::factory()->create(['instrument_id' => $nvda->id, 'date' => '2026-10-02', 'type' => 'rsi_overbought']);
        $this->newSession($nvda, '2026-10-02');
        $this->artisan('alerts:evaluate')->assertExitCode(0);

        $this->assertSame(
            [['ticker' => 'NVDA', 'name' => 'NVDA Corp', 'reason' => 'golden_cross']],
            $user->notifications()->sole()->data['items'],
        );

        // The signal persists on the next session: no new notification.
        $this->newSession($nvda, '2026-10-05');
        $this->artisan('alerts:evaluate')->expectsOutputToContain('1 unchanged')->assertExitCode(0);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(['signals' => ['MSFT' => ['golden_cross'], 'NVDA' => ['golden_cross']]], $alert->fresh()->last_state);
    }

    public function test_paused_alerts_are_skipped_and_notifications_belong_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $nvda = $this->member('NVDA', 50);
        $owner->watchlist()->attach($nvda->id);
        $other->watchlist()->attach($nvda->id);
        $owner->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross']]);
        $other->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross'], 'active' => false]);

        $this->artisan('alerts:evaluate')->assertExitCode(0);
        Signal::factory()->create(['instrument_id' => $nvda->id, 'date' => '2026-10-02', 'type' => 'golden_cross']);
        $this->newSession($nvda, '2026-10-02');
        $this->artisan('alerts:evaluate')->assertExitCode(0);

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_items_are_capped_and_the_rest_is_counted(): void
    {
        config(['alerts.max_items' => 2]);
        $user = User::factory()->create();
        $anchor = $this->member('AAA', 10);
        foreach (['BBB', 'CCC', 'DDD', 'EEE'] as $ticker) {
            $this->member($ticker, 10);
        }
        $this->screenerAlert($user, ['rsi_min' => 50]);

        $this->artisan('alerts:evaluate')->assertExitCode(0);
        IndicatorSnapshot::query()->update(['rsi14' => 80]);
        $this->newSession($anchor, '2026-10-02');
        $this->artisan('alerts:evaluate')->assertExitCode(0);

        $data = $user->notifications()->sole()->data;
        $this->assertCount(2, $data['items']);
        $this->assertSame(3, $data['more']);
    }

    public function test_one_failing_alert_does_not_stop_the_others(): void
    {
        $user = User::factory()->create();
        $nvda = $this->member('NVDA', 50);
        $user->watchlist()->attach($nvda->id);
        $failing = $user->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross']]);
        $this->screenerAlert($user, ['rsi_min' => 10]);

        $this->app->instance(AlertEvaluator::class, new class(app(CandidateQuery::class), $failing->id) extends AlertEvaluator
        {
            public function __construct(CandidateQuery $query, private readonly int $failingId)
            {
                parent::__construct($query);
            }

            public function evaluate(Alert $alert, string $asOf): string
            {
                if ($alert->id === $this->failingId) {
                    throw new RuntimeException('boom');
                }

                return parent::evaluate($alert, $asOf);
            }
        });

        $this->artisan('alerts:evaluate')
            ->expectsOutputToContain("Alert {$failing->id} failed: boom")
            ->expectsOutputToContain('1 baseline')
            ->assertExitCode(1);

        $this->assertNull($failing->fresh()->last_state);
    }

    public function test_a_failing_notification_rolls_back_the_state_update(): void
    {
        $user = User::factory()->create();
        $nvda = $this->member('NVDA', 50);
        $user->watchlist()->attach($nvda->id);
        $alert = $user->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross']]);
        $this->artisan('alerts:evaluate')->assertExitCode(0);
        $baseline = $alert->fresh()->last_state;

        Signal::factory()->create(['instrument_id' => $nvda->id, 'date' => '2026-10-02', 'type' => 'golden_cross']);
        $this->newSession($nvda, '2026-10-02');
        // Make the notification insert fail inside the evaluator's transaction.
        DB::statement('DROP TABLE notifications');

        $this->artisan('alerts:evaluate')->expectsOutputToContain('failed')->assertExitCode(1);

        $this->assertSame($baseline, $alert->fresh()->last_state);
        $this->assertSame('2026-10-01', $alert->fresh()->last_evaluated_as_of->toDateString());
    }

    public function test_no_data_means_nothing_to_evaluate(): void
    {
        User::factory()->create()->alerts()->create(['kind' => Alert::KIND_WATCHLIST, 'signal_types' => ['golden_cross']]);

        $this->artisan('alerts:evaluate')->expectsOutputToContain('No stored daily bars')->assertExitCode(0);
    }

    public function test_deleting_a_saved_screener_deletes_its_alert(): void
    {
        $user = User::factory()->create();
        $alert = $this->screenerAlert($user, []);

        SavedScreener::query()->whereKey($alert->saved_screener_id)->delete();

        $this->assertDatabaseMissing('alerts', ['id' => $alert->id]);
    }

    public function test_old_notifications_are_pruned(): void
    {
        $user = User::factory()->create();
        foreach ([['old', 91], ['recent', 10]] as [$id, $days]) {
            DB::table('notifications')->insert([
                'id' => fake()->uuid(),
                'type' => 'App\\Notifications\\AlertTriggered',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode(['kind' => $id]),
                'created_at' => now()->subDays($days),
                'updated_at' => now()->subDays($days),
            ]);
        }

        $this->artisan('notifications:prune')->expectsOutputToContain('Deleted 1 notification(s)')->assertExitCode(0);
        $this->assertSame(['recent'], $user->notifications()->get()->pluck('data.kind')->all());
    }
}
