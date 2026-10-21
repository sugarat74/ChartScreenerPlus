<?php

namespace Tests\Feature;

use App\Console\Commands\PatternReviewSample;
use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `patterns:review-sample` and `patterns:calibration-report`
 * (chart-patterns-calibration): CLI-only export of a labelling sample and the
 * metrics over a labelled CSV. The engine is always faked.
 */
class PatternCalibrationCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = storage_path('framework/testing/pattern-review-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    private function member(Universe $universe, string $ticker, int $bars = 30): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker]);
        $universe->instruments()->attach($instrument->id);

        for ($index = 0; $index < $bars; $index++) {
            $close = 100 + $index;
            DailyBar::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => Carbon::parse('2026-01-01')->addDays($index),
                'open' => $close,
                'high' => $close + 1,
                'low' => $close - 1,
                'close' => $close,
                'volume' => 1000 + $index,
            ]);
        }

        return $instrument;
    }

    private function storedPattern(Instrument $instrument, string $type, string $status): ChartPattern
    {
        return ChartPattern::factory()->create([
            'instrument_id' => $instrument->id,
            'as_of_date' => '2026-01-30',
            'type' => $type,
            'status' => $status,
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-25',
            'breakout_level' => 112.5,
            'points' => [
                ['date' => '2026-01-05', 'price' => 105.0, 'role' => 'first'],
                ['date' => '2026-01-15', 'price' => 112.5, 'role' => 'middle'],
                ['date' => '2026-01-25', 'price' => 125.0, 'role' => 'last'],
            ],
        ]);
    }

    private function fakeEngine(): void
    {
        Http::fake([
            '*/patterns/near-misses' => function (Request $request) {
                $bars = $request->data()['bars'];
                $last = end($bars)['date'];

                return Http::response(['near_misses' => [[
                    'rule' => 'peak_tolerance',
                    'parameter' => 'double_peak_tolerance',
                    'production_value' => 0.03,
                    'relaxed_value' => 0.05,
                    'pattern' => [
                        'type' => 'double_top',
                        'status' => 'forming',
                        'as_of_date' => $last,
                        'start_date' => '2026-01-10',
                        'end_date' => '2026-01-20',
                        'breakout_level' => 111.0,
                        'points' => [
                            ['date' => '2026-01-10', 'price' => 110.0, 'role' => 'left_peak'],
                            ['date' => '2026-01-15', 'price' => 111.0, 'role' => 'trough'],
                            ['date' => '2026-01-20', 'price' => 120.0, 'role' => 'right_peak'],
                        ],
                        'metadata' => [],
                    ],
                ]]]);
            },
            '*/patterns/parameters' => Http::response(['parameters' => ['double_peak_tolerance' => 0.03, 'handle_max_rise' => 0.03]]),
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function csvRows(string $path): array
    {
        $lines = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_shift($lines);

        return array_map(fn (array $values): array => array_combine($header, $values), $lines);
    }

    public function test_review_sample_exports_charts_csv_readme_and_parameters(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $instruments = [];

        foreach (['AAA', 'BBB', 'CCC', 'DDD', 'EEE'] as $ticker) {
            $instruments[$ticker] = $this->member($universe, $ticker);
        }

        $this->storedPattern($instruments['AAA'], 'double_top', 'forming');
        $this->storedPattern($instruments['BBB'], 'double_top', 'forming');
        $this->storedPattern($instruments['CCC'], 'double_top', 'forming');
        $this->storedPattern($instruments['DDD'], 'double_top', 'confirmed');
        $this->storedPattern($instruments['EEE'], 'bull_flag', 'confirmed');
        // Outside the universe: never sampled.
        $this->storedPattern(Instrument::factory()->create(['ticker' => 'OUT']), 'double_top', 'forming');
        $this->fakeEngine();

        $this->artisan('patterns:review-sample', ['--per-type' => 2, '--negatives' => 3, '--seed' => 7, '--out' => $this->out])
            ->expectsOutputToContain('double_top: 1 forming, 1 confirmed, 3 near misses')
            ->expectsOutputToContain('bull_flag: 0 forming, 1 confirmed, 0 near misses')
            ->expectsOutputToContain('Exported 6 case(s)')
            ->assertExitCode(0);

        $rows = $this->csvRows($this->out.'/sample.csv');
        $this->assertSame(['id', 'ticker', 'type', 'status', 'as_of', 'points', 'suggested_label', 'label', 'notes'], array_keys($rows[0]));
        $this->assertCount(6, $rows);
        $this->assertNotContains('OUT', array_column($rows, 'ticker'));

        $detected = array_values(array_filter($rows, fn (array $row): bool => ! str_starts_with($row['id'], 'NM-')));
        $this->assertSame(['confirmed', 'forming'], collect($detected)->where('type', 'double_top')->pluck('status')->sort()->values()->all());
        $this->assertSame(['BF-001'], collect($detected)->where('type', 'bull_flag')->pluck('id')->all());

        $negatives = array_values(array_filter($rows, fn (array $row): bool => str_starts_with($row['id'], 'NM-DT-')));
        $this->assertCount(3, $negatives);
        $this->assertSame('near_miss:peak_tolerance', $negatives[0]['status']);
        $this->assertSame('left_peak 2026-01-10 110.00; trough 2026-01-15 111.00; right_peak 2026-01-20 120.00', $negatives[0]['points']);

        foreach ($rows as $row) {
            $this->assertSame('', $row['label']);
            $this->assertSame('', $row['suggested_label']);
            $svg = File::get($this->out.'/charts/'.$row['id'].'.svg');
            $this->assertStringStartsWith('<?xml', $svg);
            $this->assertStringContainsString('<polyline', $svg);
            $this->assertStringContainsString('breakout ', $svg);
            $this->assertStringContainsString('>2026-01-30</text>', $svg); // the as-of bar is drawn and labelled
        }

        $this->assertStringContainsString('right_peak 120.00', File::get($this->out.'/charts/'.$negatives[0]['id'].'.svg'));

        $readme = File::get($this->out.'/README.txt');
        $this->assertStringContainsString('--seed=7', $readme);
        $this->assertStringContainsString('not_a_pattern', $readme);
        $this->assertStringContainsString('missed', $readme);
        $this->assertSame(['double_peak_tolerance' => 0.03, 'handle_max_rise' => 0.03], json_decode(File::get($this->out.'/parameters.json'), true));

        // Same seed, same data: the same sample. An existing sample needs --force.
        $first = File::get($this->out.'/sample.csv');
        $this->artisan('patterns:review-sample', ['--per-type' => 2, '--negatives' => 3, '--seed' => 7, '--out' => $this->out])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(1);
        $this->artisan('patterns:review-sample', ['--per-type' => 2, '--negatives' => 3, '--seed' => 7, '--out' => $this->out, '--force' => true])
            ->assertExitCode(0);
        $this->assertSame($first, File::get($this->out.'/sample.csv'));
    }

    public function test_review_sample_covers_every_pattern_type(): void
    {
        // chart-patterns-extended: every type in the vocabulary has a case-id prefix.
        $this->assertSame(ChartPattern::TYPES, array_keys(PatternReviewSample::PREFIXES));

        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $this->storedPattern($this->member($universe, 'ICW'), 'inverse_cup_with_handle', 'forming');
        $this->storedPattern($this->member($universe, 'BRP'), 'bear_pennant', 'confirmed');
        Http::fake([
            '*/patterns/near-misses' => Http::response(['near_misses' => []]),
            '*/patterns/parameters' => Http::response(['parameters' => ['pennant_max_width_ratio' => 0.7]]),
        ]);

        $this->artisan('patterns:review-sample', ['--per-type' => 2, '--negatives' => 0, '--seed' => 7, '--out' => $this->out])
            ->expectsOutputToContain('inverse_cup_with_handle: 1 forming, 0 confirmed')
            ->assertExitCode(0);

        $ids = array_column($this->csvRows($this->out.'/sample.csv'), 'id');
        $this->assertEqualsCanonicalizing(['ICWH-001', 'BRP-001'], $ids);
    }

    public function test_review_sample_fails_on_unknown_universe_or_engine_failure(): void
    {
        $this->artisan('patterns:review-sample', ['--universe' => 'nope', '--out' => $this->out])
            ->expectsOutputToContain('No universe found for [nope].')
            ->assertExitCode(1);

        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $this->member($universe, 'AAA');
        Http::fake(['*' => Http::response(['detail' => 'boom'], 500)]);

        $this->artisan('patterns:review-sample', ['--out' => $this->out, '--seed' => 1])
            ->expectsOutputToContain('Engine failed')
            ->assertExitCode(1);
        $this->assertFileDoesNotExist($this->out.'/sample.csv');
    }

    public function test_calibration_report_measures_a_labelled_sample(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $a = $this->member($universe, 'AAA', 2);
        $b = $this->member($universe, 'BBB', 2);
        $this->member($universe, 'CCC', 2);
        $this->member($universe, 'DDD', 2);
        $this->storedPattern($a, 'double_top', 'forming');
        $this->storedPattern($b, 'double_top', 'confirmed');
        $this->storedPattern($b, 'cup_with_handle', 'forming');
        Http::fake();

        $this->artisan('patterns:calibration-report', ['csv' => base_path('tests/Fixtures/pattern-calibration/sample.csv')])
            ->expectsOutputToContain('| double_top | 4 | 3 | 1 | 1 | 1 | 66.7% | no (low confidence) |')
            ->expectsOutputToContain('| double_bottom | 2 | 1 | 1 | 0 | 0 | 100.0% | yes |')
            ->expectsOutputToContain('| cup_with_handle | 1 | 1 | 1 | 0 | 0 | 100.0% | yes |')
            ->expectsOutputToContain('| bull_flag | 0 | 0 | 0 | 0 | 0 | n/a | n/a |')
            ->expectsOutputToContain('| **all** | 7 | 5 | 3 | 1 | 1 | 80.0% | yes |')
            ->expectsOutputToContain('| double_top | (all) | 2 | 2 | 1 | 50.0% |')
            ->expectsOutputToContain('| double_top | peak_tolerance | 1 | 1 | 1 | 100.0% |')
            ->expectsOutputToContain('| bull_flag | lower_volume | 2 | 1 | 0 | 0.0% |')
            ->expectsOutputToContain('Rows with both a suggested and a human label: 2; agreement 50.0%')
            ->expectsOutputToContain('4 instruments with bars; 3 active patterns')
            ->expectsOutputToContain('| double_top | 1 | 1 | 2 | 66.7% | 50.0 |')
            ->expectsOutputToContain('| cup_with_handle | 1 | 0 | 1 | 33.3% | 25.0 |')
            ->expectsOutputToContain('recorded with the sample (`parameters.json`)')
            ->expectsOutputToContain('| double_peak_tolerance | 0.03 |')
            ->expectsOutputToContain('DB-002: `maybe`')
            ->expectsOutputToContain('NM-BF-002: `clear`')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_calibration_report_falls_back_to_engine_parameters_and_rejects_bad_files(): void
    {
        $folder = $this->out;
        File::ensureDirectoryExists($folder);
        File::copy(base_path('tests/Fixtures/pattern-calibration/sample.csv'), $folder.'/sample.csv');
        Http::fake(['*/patterns/parameters' => Http::response(['parameters' => ['handle_max_rise' => 0.03]])]);

        $this->artisan('patterns:calibration-report', ['csv' => $folder.'/sample.csv'])
            ->expectsOutputToContain('current engine values')
            ->expectsOutputToContain('| handle_max_rise | 0.03 |')
            ->assertExitCode(0);

        File::put($folder.'/other.csv', "a,b\n1,2\n");
        $this->artisan('patterns:calibration-report', ['csv' => $folder.'/other.csv'])
            ->expectsOutputToContain('Cannot read a review sample CSV')
            ->assertExitCode(1);
        $this->artisan('patterns:calibration-report', ['csv' => $folder.'/missing.csv'])->assertExitCode(1);
    }
}
