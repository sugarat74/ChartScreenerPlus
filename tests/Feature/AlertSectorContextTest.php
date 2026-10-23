<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * Saved Screener alerts with sector context filters
 * (docs/specs/market-context-patterns.md, C6; Scenario 6): evaluated with the
 * filters while the owner has Market Context access, skipped (never
 * broadened) once the owner loses it.
 */
class AlertSectorContextTest extends TestCase
{
    use BuildsMarketContextFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setUpMarketContextFixture();
        config(['market_context.public' => true]);
        $this->contextFixture();
        Artisan::call('screener:refresh');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $filters */
    private function alert(User $user, array $filters): Alert
    {
        $screener = $user->savedScreeners()->create(['name' => 'Sectores '.md5(json_encode($filters)), 'filters' => array_merge([
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null,
            'price_above_sma200' => false, 'ma_cross' => null, 'pattern' => [], 'pattern_status' => 'any',
            'sort' => 'rvol_desc', 'sector_trend' => null, 'sector_rs_rank_max' => null,
        ], $filters)]);

        return $user->alerts()->create(['kind' => Alert::KIND_SCREENER, 'saved_screener_id' => $screener->id]);
    }

    public function test_the_sector_filters_change_the_candidate_set(): void
    {
        $user = User::factory()->create();
        $ranked = $this->alert($user, ['sector_rs_rank_max' => 11]);
        $plain = $user->alerts()->create(['kind' => Alert::KIND_SCREENER, 'saved_screener_id' => $user->savedScreeners()->create([
            'name' => 'Todo', 'filters' => ['signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false, 'ma_cross' => null, 'sort' => 'rvol_desc'],
        ])->id]);
        $uptrend = $this->alert($user, ['sector_trend' => 'uptrend']);

        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($ranked, self::LATEST));
        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($plain, self::LATEST));
        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($uptrend, self::LATEST));

        $this->assertSame(['tickers' => ['AAPL', 'XOM']], $ranked->fresh()->last_state);
        $this->assertSame(['tickers' => ['AAPL', 'NEE', 'ODD', 'XOM', 'ZZZ']], $plain->fresh()->last_state);
        $this->assertSame(['tickers' => ['AAPL']], $uptrend->fresh()->last_state);
    }

    public function test_an_owner_without_access_is_skipped_without_notification_or_state_change(): void
    {
        $user = User::factory()->create();
        $alert = $this->alert($user, ['sector_trend' => 'uptrend', 'sector_rs_rank_max' => 3]);
        $evaluator = app(AlertEvaluator::class);
        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($alert, self::LATEST));

        // XOM's sector joins the uptrend top 3 on the next session...
        DB::table('market_sector_snapshots')->where('sector', 'Energy')->update(['trend' => 'uptrend', 'rs_rank' => 2]);
        Artisan::call('screener:refresh');
        // ...but the owner lost access (the flag was turned off again).
        config(['market_context.public' => false]);

        $this->assertSame(AlertEvaluator::SKIPPED, $evaluator->evaluate($alert->fresh(), '2026-10-23'));

        $fresh = $alert->fresh();
        $this->assertSame(['tickers' => ['AAPL']], $fresh->last_state);
        $this->assertSame(self::LATEST, $fresh->last_evaluated_as_of->toDateString());
        $this->assertSame(0, $user->notifications()->count());

        // An alert without sector filters of the same owner is still evaluated.
        $plain = $this->alert($user, []);
        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($plain, '2026-10-23'));
    }

    public function test_an_admin_owner_is_evaluated_while_the_flag_is_off(): void
    {
        $admin = User::factory()->admin()->create();
        $alert = $this->alert($admin, ['sector_trend' => 'uptrend', 'sector_rs_rank_max' => 3]);
        $evaluator = app(AlertEvaluator::class);
        config(['market_context.public' => false]);

        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($alert, self::LATEST));

        DB::table('market_sector_snapshots')->where('sector', 'Energy')->update(['trend' => 'uptrend', 'rs_rank' => 2]);
        Artisan::call('screener:refresh');

        $this->assertSame(AlertEvaluator::NOTIFIED, $evaluator->evaluate($alert->fresh(), '2026-10-23'));
        $this->assertSame([['ticker' => 'XOM', 'name' => 'XOM Inc', 'reason' => 'new_candidate']], $admin->notifications()->sole()->data['items']);
    }
}
