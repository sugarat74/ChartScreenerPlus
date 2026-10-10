<?php

namespace Tests\Feature;

use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\SavedScreener;
use App\Models\Universe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * chart-patterns-ui (API side): screener `pattern` / `pattern_status`
 * filters, patterns in the candidate and instrument-detail payloads, and the
 * Saved Screener pattern keys with defaults for rows saved before them.
 */
class ChartPatternsApiTest extends TestCase
{
    use RefreshDatabase;

    private Universe $universe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->universe = Universe::factory()->create(['slug' => 'sp500']);
        config(['ingestion.universe' => 'sp500']);
    }

    private function member(string $ticker): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker]);
        $this->universe->instruments()->attach($instrument->id);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-02', 'close' => 100]);
        IndicatorSnapshot::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-02']);

        return $instrument;
    }

    private function seedPatterns(): void
    {
        ChartPattern::factory()->create(['instrument_id' => $this->member('AAA')->id, 'type' => 'double_bottom', 'status' => 'confirmed']);
        ChartPattern::factory()->create(['instrument_id' => $this->member('BBB')->id, 'type' => 'double_bottom', 'status' => 'forming']);
        ChartPattern::factory()->create(['instrument_id' => $this->member('CCC')->id, 'type' => 'cup_with_handle', 'status' => 'forming']);
        $this->member('DDD');
    }

    /**
     * @return list<string>
     */
    private function tickers(string $query): array
    {
        return array_column($this->getJson('/api/screener'.$query)->assertOk()->json('candidates'), 'ticker');
    }

    public function test_pattern_filter_matches_any_listed_type(): void
    {
        $this->seedPatterns();

        $this->assertEqualsCanonicalizing(['AAA', 'BBB', 'CCC', 'DDD'], $this->tickers(''));
        $this->assertEqualsCanonicalizing(['AAA', 'BBB'], $this->tickers('?pattern=double_bottom'));
        $this->assertEqualsCanonicalizing(['AAA', 'BBB', 'CCC'], $this->tickers('?pattern=double_bottom,cup_with_handle'));
        $this->assertEqualsCanonicalizing(['AAA', 'BBB', 'CCC'], $this->tickers('?pattern[]=double_bottom&pattern[]=cup_with_handle'));
    }

    public function test_pattern_status_narrows_the_pattern_filter(): void
    {
        $this->seedPatterns();

        $this->assertSame(['AAA'], $this->tickers('?pattern=double_bottom&pattern_status=confirmed'));
        $this->assertEqualsCanonicalizing(['BBB', 'CCC'], $this->tickers('?pattern=double_bottom,cup_with_handle&pattern_status=forming'));
        $this->assertEqualsCanonicalizing(['AAA', 'BBB'], $this->tickers('?pattern=double_bottom&pattern_status=any'));
        // Without `pattern`, the status alone does not filter.
        $this->assertCount(4, $this->tickers('?pattern_status=confirmed'));
    }

    public function test_candidates_carry_their_patterns(): void
    {
        $this->seedPatterns();

        $candidate = collect($this->getJson('/api/screener?pattern=double_bottom&pattern_status=confirmed')->json('candidates'))->sole();

        $this->assertSame([[
            'type' => 'double_bottom',
            'status' => 'confirmed',
            'breakout_level' => 115,
            'end_date' => '2026-02-20',
        ]], $candidate['patterns']);
    }

    public function test_invalid_pattern_params_are_localized_422s(): void
    {
        $this->getJson('/api/screener?pattern=triangle', ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.pattern.0', 'The selected pattern type is invalid.');

        $this->getJson('/api/screener?pattern=double_top&pattern_status=maybe', ['Accept-Language' => 'es'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.pattern_status.0', 'El estado de patrón debe ser any, forming o confirmed.');

        $this->getJson('/api/screener?pattern[]=1&pattern[][]=x')->assertUnprocessable()->assertJsonValidationErrors('pattern');
    }

    public function test_instrument_detail_includes_pattern_points(): void
    {
        $instrument = $this->member('AAA');
        ChartPattern::factory()->create(['instrument_id' => $instrument->id]);

        $this->getJson('/api/instruments/AAA')
            ->assertOk()
            ->assertJsonPath('patterns.0.type', 'double_bottom')
            ->assertJsonPath('patterns.0.status', 'forming')
            ->assertJsonPath('patterns.0.start_date', '2026-01-05')
            ->assertJsonPath('patterns.0.breakout_level', 115)
            ->assertJsonPath('patterns.0.points.1.role', 'peak')
            ->assertJsonMissingPath('patterns.0.target');

        $this->getJson('/api/instruments/'.$this->member('BBB')->ticker)->assertOk()->assertJsonPath('patterns', []);
    }

    public function test_saved_screeners_round_trip_the_pattern_filter(): void
    {
        $user = User::factory()->create();
        $filters = [
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null,
            'price_above_sma200' => false, 'ma_cross' => null,
            'pattern' => ['cup_with_handle', 'double_bottom', 'double_bottom'], 'pattern_status' => 'confirmed',
            'sort' => 'rvol_desc',
        ];

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/screeners', ['name' => 'Patrones', 'filters' => $filters])
            ->assertCreated()
            ->assertJsonPath('screener.filters.pattern', ['double_bottom', 'cup_with_handle'])
            ->assertJsonPath('screener.filters.pattern_status', 'confirmed');

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Malo', 'filters' => [...$filters, 'pattern' => ['triangle']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters.pattern.0');

        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Malo 2', 'filters' => [...$filters, 'pattern_status' => 'maybe']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters.pattern_status');
    }

    public function test_saved_screeners_from_before_patterns_still_load_with_defaults(): void
    {
        $user = User::factory()->create();
        $legacy = $user->savedScreeners()->create(['name' => 'Antiguo', 'filters' => [
            'signal' => ['golden_cross'], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => 2,
            'price_above_sma200' => true, 'ma_cross' => null, 'sort' => 'rsi_desc',
        ]]);

        $this->actingAs($user)
            ->getJson('/api/screeners')
            ->assertOk()
            ->assertJsonPath('screeners.0.id', $legacy->id)
            ->assertJsonPath('screeners.0.filters.signal', ['golden_cross'])
            ->assertJsonPath('screeners.0.filters.pattern', [])
            ->assertJsonPath('screeners.0.filters.pattern_status', 'any')
            ->assertJsonPath('screeners.0.filters.sort', 'rsi_desc');

        // A seven-key payload (older SPA) is still accepted and stored canonically.
        $this->actingAs($user)
            ->postJson('/api/screeners', ['name' => 'Siete claves', 'filters' => [
                'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null,
                'price_above_sma200' => false, 'ma_cross' => null, 'sort' => 'rvol_desc',
            ]])
            ->assertCreated()
            ->assertJsonPath('screener.filters.pattern', []);

        $this->assertSame('any', SavedScreener::query()->where('name', 'Siete claves')->sole()->filters['pattern_status']);
    }
}
