<?php

namespace App\Console\Commands;

use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\Instrument;
use App\Models\Universe;
use App\Services\Engine\EngineClient;
use App\Services\Patterns\PatternReviewChart;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Export a calibration review sample of chartist patterns (Admin/CLI only).
 *
 * For each pattern type it takes up to `--per-type` detected instances from
 * the current `chart_patterns` set (balanced between `forming` and
 * `confirmed`, shuffled with a recorded seed) plus `--negatives` near misses
 * in total (instruments the engine rejects because of exactly one rule,
 * spread round-robin over the types). Each case gets a standalone SVG chart
 * and one CSV row with an empty `label` for a person to fill in. Nothing is
 * exposed publicly. See docs/specs/chart-patterns-calibration.md.
 */
class PatternReviewSample extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'patterns:review-sample
                            {--per-type=40 : Detected instances per pattern type}
                            {--negatives=40 : Near-miss negatives in total, spread over the types}
                            {--out=storage/app/pattern-review : Output folder (relative to the project root or absolute)}
                            {--seed= : Random seed (recorded in the README; random when omitted)}
                            {--universe=sp500 : Universe slug}
                            {--force : Overwrite an existing sample.csv in the output folder}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export detected and near-miss chartist patterns as SVG charts plus a CSV to label (calibration)';

    /** Bars shown before the first key point of a case. */
    private const CONTEXT_BARS = 40;

    public const CSV_HEADER = ['id', 'ticker', 'type', 'status', 'as_of', 'points', 'suggested_label', 'label', 'notes'];

    private const PREFIXES = [
        'double_top' => 'DT',
        'double_bottom' => 'DB',
        'cup_with_handle' => 'CWH',
        'bull_flag' => 'BF',
    ];

    /**
     * Execute the console command.
     */
    public function handle(EngineClient $client, PatternReviewChart $chart): int
    {
        $perType = max(0, (int) $this->option('per-type'));
        $negatives = max(0, (int) $this->option('negatives'));
        $seedOption = $this->option('seed');
        $seed = is_numeric($seedOption) ? (int) $seedOption : random_int(1, 2_147_483_647);
        $out = $this->outputPath((string) $this->option('out'));
        $slug = (string) ($this->option('universe') ?: 'sp500');

        $universe = Universe::query()->where('slug', $slug)->first();

        if ($universe === null) {
            $this->error("No universe found for [{$slug}].");

            return self::FAILURE;
        }

        if (File::exists($out.'/sample.csv') && ! $this->option('force')) {
            $this->error("{$out}/sample.csv already exists (it may hold labels). Use --force to overwrite.");

            return self::FAILURE;
        }

        $randomizer = new Randomizer(new Mt19937($seed));
        $instrumentIds = $universe->instruments()->pluck('instruments.id')->all();

        $detected = $this->sampleDetected($instrumentIds, $perType, $randomizer);

        try {
            $nearMisses = $negatives > 0 ? $this->sampleNearMisses($client, $universe, $negatives, $randomizer) : [];
            $parameters = $client->patternParameters();
        } catch (RequestException|ConnectionException $exception) {
            $this->error("Engine failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($out.'/charts');
        File::delete(File::glob($out.'/charts/*.svg'));

        $rows = [];
        $counts = [];

        foreach ($detected as $type => $patterns) {
            foreach (array_values($patterns) as $index => $pattern) {
                $id = self::PREFIXES[$type].'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
                $case = [
                    'id' => $id,
                    'ticker' => $pattern->instrument->ticker,
                    'type' => $type,
                    'status' => $pattern->status,
                    'as_of' => $pattern->as_of_date->toDateString(),
                    'points' => $pattern->points,
                    'breakout_level' => (float) $pattern->breakout_level,
                ];
                $this->writeChart($chart, $out, $case, $pattern->instrument);
                $rows[] = $case;
                $counts[$type]['detected'][$pattern->status] = ($counts[$type]['detected'][$pattern->status] ?? 0) + 1;
            }
        }

        $negativeIndex = [];

        foreach ($nearMisses as $miss) {
            $type = $miss['pattern']['type'];
            $negativeIndex[$type] = ($negativeIndex[$type] ?? 0) + 1;
            $case = [
                'id' => 'NM-'.self::PREFIXES[$type].'-'.str_pad((string) $negativeIndex[$type], 3, '0', STR_PAD_LEFT),
                'ticker' => $miss['instrument']->ticker,
                'type' => $type,
                'status' => 'near_miss:'.$miss['rule'],
                'as_of' => (string) $miss['pattern']['as_of_date'],
                'points' => $miss['pattern']['points'],
                'breakout_level' => (float) $miss['pattern']['breakout_level'],
            ];
            $this->writeChart($chart, $out, $case, $miss['instrument']);
            $rows[] = $case;
            $counts[$type]['negatives'] = ($counts[$type]['negatives'] ?? 0) + 1;
        }

        $this->writeCsv($out.'/sample.csv', $rows);
        File::put($out.'/parameters.json', json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        File::put($out.'/README.txt', $this->readme($seed, $slug, $perType, $negatives, $counts));

        foreach (array_keys(self::PREFIXES) as $type) {
            $forming = $counts[$type]['detected']['forming'] ?? 0;
            $confirmed = $counts[$type]['detected']['confirmed'] ?? 0;
            $missCount = $counts[$type]['negatives'] ?? 0;
            $this->line("{$type}: {$forming} forming, {$confirmed} confirmed, {$missCount} near misses");
        }

        $this->info('Exported '.count($rows)." case(s) to {$out} (seed {$seed}).");

        return self::SUCCESS;
    }

    /**
     * Up to `$perType` stored patterns per type, half forming and half
     * confirmed when both exist (the shortfall of one status is filled from
     * the other), in a seeded random order.
     *
     * @param  list<int>  $instrumentIds
     * @return array<string, list<ChartPattern>>
     */
    private function sampleDetected(array $instrumentIds, int $perType, Randomizer $randomizer): array
    {
        $sample = [];

        foreach (ChartPattern::TYPES as $type) {
            $byStatus = [];

            foreach (ChartPattern::STATUSES as $status) {
                $rows = ChartPattern::query()
                    ->with('instrument')
                    ->whereIn('instrument_id', $instrumentIds)
                    ->where('type', $type)
                    ->where('status', $status)
                    ->orderBy('id')
                    ->get()
                    ->all();
                $byStatus[$status] = $rows === [] ? [] : $randomizer->shuffleArray($rows);
            }

            $confirmedQuota = min(count($byStatus['confirmed']), intdiv($perType, 2));
            $formingQuota = min(count($byStatus['forming']), $perType - $confirmedQuota);
            $confirmedQuota = min(count($byStatus['confirmed']), $perType - $formingQuota);

            $picked = array_merge(
                array_slice($byStatus['forming'], 0, $formingQuota),
                array_slice($byStatus['confirmed'], 0, $confirmedQuota),
            );
            $sample[$type] = $picked === [] ? [] : array_values($randomizer->shuffleArray($picked));
        }

        return $sample;
    }

    /**
     * Ask the engine for near misses over the whole universe (seeded order),
     * then take `$total` of them round-robin over the types.
     *
     * @return list<array{instrument: Instrument, rule: string, pattern: array<string, mixed>}>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    private function sampleNearMisses(EngineClient $client, Universe $universe, int $total, Randomizer $randomizer): array
    {
        $instruments = $universe->instruments()->whereHas('dailyBars')->orderBy('ticker')->get()->all();
        $instruments = $instruments === [] ? [] : $randomizer->shuffleArray($instruments);

        $byType = array_fill_keys(ChartPattern::TYPES, []);
        $scanned = 0;

        foreach ($instruments as $instrument) {
            foreach ($client->patternNearMisses($this->barsPayload($instrument)) as $miss) {
                $type = $miss['pattern']['type'] ?? null;

                if (
                    isset($byType[$type])
                    && is_string($miss['rule'] ?? null)
                    && is_array($miss['pattern']['points'] ?? null)
                    && is_numeric($miss['pattern']['breakout_level'] ?? null)
                ) {
                    $byType[$type][] = ['instrument' => $instrument, 'rule' => $miss['rule'], 'pattern' => $miss['pattern']];
                }
            }

            if (++$scanned % 50 === 0) {
                $this->line("Scanned {$scanned} instrument(s) for near misses...");
            }
        }

        $picked = [];

        while (count($picked) < $total && array_filter($byType) !== []) {
            foreach (ChartPattern::TYPES as $type) {
                if (count($picked) < $total && $byType[$type] !== []) {
                    $picked[] = array_shift($byType[$type]);
                }
            }
        }

        return $picked;
    }

    /**
     * @return list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>
     */
    private function barsPayload(Instrument $instrument): array
    {
        return $instrument->dailyBars()->orderBy('date')->get()
            ->map(fn (DailyBar $bar): array => [
                'date' => $bar->date->toDateString(),
                'open' => (float) $bar->open,
                'high' => (float) $bar->high,
                'low' => (float) $bar->low,
                'close' => (float) $bar->close,
                'volume' => (int) $bar->volume,
            ])->all();
    }

    /**
     * @param  array{id: string, ticker: string, type: string, status: string, as_of: string, points: array<int, array<string, mixed>>, breakout_level: float}  $case
     */
    private function writeChart(PatternReviewChart $chart, string $out, array $case, Instrument $instrument): void
    {
        $points = $this->points($case['points']);
        $bars = $instrument->dailyBars()
            ->whereDate('date', '<=', $case['as_of'])
            ->orderBy('date')
            ->get()
            ->map(fn (DailyBar $bar): array => [
                'date' => $bar->date->toDateString(),
                'high' => (float) $bar->high,
                'low' => (float) $bar->low,
                'close' => (float) $bar->close,
                'volume' => (float) $bar->volume,
            ])
            ->values();

        $first = $points === [] ? $case['as_of'] : $points[0]['date'];
        $firstIndex = $bars->search(fn (array $bar): bool => $bar['date'] >= $first);
        $from = max(0, ($firstIndex === false ? 0 : $firstIndex) - self::CONTEXT_BARS);

        $title = "{$case['id']} · {$case['ticker']} · {$case['type']} · {$case['status']} · as of {$case['as_of']}";
        File::put(
            $out.'/charts/'.$case['id'].'.svg',
            $chart->render($title, $bars->slice($from)->values()->all(), $points, $case['breakout_level']),
        );
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return list<array{date: string, price: float, role: string}>
     */
    private function points(array $raw): array
    {
        $points = [];

        foreach ($raw as $point) {
            if (is_array($point) && is_string($point['date'] ?? null) && is_numeric($point['price'] ?? null)) {
                $points[] = ['date' => $point['date'], 'price' => (float) $point['price'], 'role' => (string) ($point['role'] ?? '')];
            }
        }

        usort($points, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $points;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, self::CSV_HEADER, ',', '"', '');

        foreach ($rows as $row) {
            $points = implode('; ', array_map(
                fn (array $point): string => $point['role'].' '.$point['date'].' '.number_format($point['price'], 2, '.', ''),
                $this->points($row['points']),
            ));
            fputcsv($handle, [$row['id'], $row['ticker'], $row['type'], $row['status'], $row['as_of'], $points, '', '', ''], ',', '"', '');
        }

        fclose($handle);
    }

    /**
     * @param  array<string, array<string, mixed>>  $counts
     */
    private function readme(int $seed, string $slug, int $perType, int $negatives, array $counts): string
    {
        $lines = [
            'Chartiko - chartist pattern calibration review sample',
            '======================================================',
            '',
            'Generated: '.now()->toDateTimeString().' by `php artisan patterns:review-sample`',
            "Universe: {$slug}   --per-type={$perType}   --negatives={$negatives}   --seed={$seed}",
            'Re-running with the same seed on the same data and rules gives the same sample.',
            '',
            'Contents',
            '--------',
            '- sample.csv       one row per case: id, ticker, type, status, as_of, points, suggested_label, label, notes',
            '- charts/<id>.svg  one chart per case (open in any browser)',
            '- parameters.json  the production rule parameters used (booleans as 1/0)',
            '',
            'Cases',
            '-----',
        ];

        foreach (array_keys(self::PREFIXES) as $type) {
            $lines[] = sprintf(
                '- %-16s detected: %d forming, %d confirmed; near misses: %d',
                $type,
                $counts[$type]['detected']['forming'] ?? 0,
                $counts[$type]['detected']['confirmed'] ?? 0,
                $counts[$type]['negatives'] ?? 0,
            );
        }

        return implode("\n", array_merge($lines, [
            '',
            'Detected cases (status `forming` or `confirmed`) are what Chartiko currently',
            'shows. Near misses (status `near_miss:<rule>`, ids starting with NM-) are',
            'instruments the rules reject because of exactly ONE rule: with only that',
            'rule slightly relaxed the detector finds the drawn instance. They tell us',
            'whether a rule is too strict. Rules: peak_tolerance 3%->5%, min_depth',
            '10%->7% (cup 12%->8%), max_depth 35%->45%, prior_move 15%->10%, min_gap',
            '15->10, rim_tolerance 5%->8%, low_position 20-80%->10-90%,',
            'handle_max_depth 12%->18%, handle_max_rise 3%->6%, handle_max_length',
            '25->35, breakout_rvol 1.5->1.0, pole_min_rise 15%->10%, pole_max_length',
            '15->20, flag_max_retrace 50%->61.8%, flag_max_length 20->25; extremes,',
            'down_slope and lower_volume switch that structural rule off.',
            '',
            'How to label',
            '------------',
            'Open charts/<id>.svg for each row and write ONE value in the `label` column:',
            '',
            '  Detected cases:',
            '    clear          a textbook instance a technical analyst would name at once',
            '    acceptable     recognisable, with some imperfection (proportions, noise)',
            '    not_a_pattern  not this pattern (wrong geometry, points do not make sense)',
            '',
            '  Near misses (NM-...):',
            '    missed         a real instance of this type that the rules wrongly reject',
            '    not_a_pattern  correctly rejected',
            '',
            'Judge only the geometry of the named type (no "would it have made money").',
            'Use `notes` for anything useful (e.g. "handle too deep", "points misplaced").',
            '`suggested_label` may hold a machine pre-label; it is only a suggestion and',
            'is never counted. Leave `label` empty for cases you did not review.',
            '',
            'Chart legend: blue line = closes; grey bars = high-low range; grey strip =',
            'volume; red dots = key points with their role and price; dashed orange =',
            'breakout level (the only price line a pattern exposes; no targets).',
            '',
            'Then run:  php artisan patterns:calibration-report <path>/sample.csv',
            '',
        ]));
    }

    private function outputPath(string $out): string
    {
        $out = rtrim(str_replace('\\', '/', $out), '/');

        if (preg_match('#^([a-zA-Z]:)?/#', $out) === 1) {
            return $out;
        }

        return str_replace('\\', '/', base_path($out));
    }
}
