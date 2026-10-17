<?php

namespace App\Console\Commands;

use App\Models\ChartPattern;
use App\Models\Universe;
use App\Services\Engine\EngineClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Measure a labelled calibration sample (Admin/CLI only, markdown output).
 *
 * Reads the CSV written by `patterns:review-sample` once a person has filled
 * the `label` column and prints: precision per type (`clear` + `acceptable`
 * over reviewed detected cases), miss rate on the near-miss negatives (per type
 * and per rule), agreement of `suggested_label` with `label` where both exist,
 * the frequency per type over the universe's current `chart_patterns` set and
 * the parameter values used. See docs/specs/chart-patterns-calibration.md.
 */
class PatternCalibrationReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'patterns:calibration-report
                            {csv : Labelled sample.csv from patterns:review-sample}
                            {--universe=sp500 : Universe slug for the frequency section}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report precision, miss rate, frequency and parameters for a labelled pattern review sample';

    private const DETECTED_LABELS = ['clear', 'acceptable', 'not_a_pattern'];

    private const NEGATIVE_LABELS = ['missed', 'not_a_pattern'];

    private const PRECISION_TARGET = 0.8;

    /**
     * Execute the console command.
     */
    public function handle(EngineClient $client): int
    {
        $path = (string) $this->argument('csv');

        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }

        $rows = is_file($path) ? $this->readCsv($path) : null;

        if ($rows === null) {
            $this->error("Cannot read a review sample CSV at [{$path}] (expected the patterns:review-sample header).");

            return self::FAILURE;
        }

        $out = ['# Pattern calibration report', '', 'Sample: `'.basename(dirname($path)).'/'.basename($path).'` ('.count($rows).' rows)', ''];
        $invalid = [];

        array_push($out, ...$this->precisionSection($rows, $invalid));
        array_push($out, ...$this->missSection($rows, $invalid));
        array_push($out, ...$this->agreementSection($rows));
        array_push($out, ...$this->frequencySection((string) ($this->option('universe') ?: 'sp500')));
        array_push($out, ...$this->parameterSection(dirname($path), $client));

        if ($invalid !== []) {
            $out[] = '## Rows with an unknown label (ignored)';
            $out[] = '';

            foreach ($invalid as $line) {
                $out[] = '- '.$line;
            }

            $out[] = '';
        }

        foreach ($out as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, string>>|null
     */
    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle, null, ',', '"', '');

        if (! is_array($header)) {
            fclose($handle);

            return null;
        }

        $header = array_map(fn ($name): string => trim(preg_replace('/^\x{FEFF}/u', '', (string) $name)), $header);

        if (array_diff(['id', 'type', 'status', 'label'], $header) !== []) {
            fclose($handle);

            return null;
        }

        $rows = [];

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }

            $row = [];

            foreach ($header as $index => $name) {
                $row[$name] = trim((string) ($values[$index] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function isNegative(array $row): bool
    {
        return str_starts_with($row['status'], 'near_miss');
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $invalid
     * @return list<string>
     */
    private function precisionSection(array $rows, array &$invalid): array
    {
        $lines = [
            '## Precision on detected cases',
            '',
            'Precision = (clear + acceptable) / reviewed detected cases. Target: >= 80% per type, otherwise the type is documented as low confidence.',
            '',
            '| Type | Cases | Reviewed | Clear | Acceptable | Not a pattern | Precision | >= 80% |',
            '| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |',
        ];
        $totals = ['cases' => 0, 'reviewed' => 0, 'clear' => 0, 'acceptable' => 0, 'not_a_pattern' => 0];

        foreach (ChartPattern::TYPES as $type) {
            $counts = ['cases' => 0, 'reviewed' => 0, 'clear' => 0, 'acceptable' => 0, 'not_a_pattern' => 0];

            foreach ($rows as $row) {
                if ($row['type'] !== $type || $this->isNegative($row)) {
                    continue;
                }

                $counts['cases']++;
                $label = strtolower($row['label']);

                if ($label === '') {
                    continue;
                }

                if (! in_array($label, self::DETECTED_LABELS, true)) {
                    $invalid[] = "{$row['id']}: `{$row['label']}` (detected cases take clear/acceptable/not_a_pattern)";

                    continue;
                }

                $counts['reviewed']++;
                $counts[$label]++;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $counts[$key];
            }

            $lines[] = $this->precisionRow($type, $counts);
        }

        $lines[] = $this->precisionRow('**all**', $totals);
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array{cases: int, reviewed: int, clear: int, acceptable: int, not_a_pattern: int}  $counts
     */
    private function precisionRow(string $type, array $counts): string
    {
        $precision = $counts['reviewed'] > 0 ? ($counts['clear'] + $counts['acceptable']) / $counts['reviewed'] : null;
        $meets = $precision === null ? 'n/a' : ($precision >= self::PRECISION_TARGET ? 'yes' : 'no (low confidence)');

        return "| {$type} | {$counts['cases']} | {$counts['reviewed']} | {$counts['clear']} | {$counts['acceptable']} | {$counts['not_a_pattern']} | {$this->percent($precision)} | {$meets} |";
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $invalid
     * @return list<string>
     */
    private function missSection(array $rows, array &$invalid): array
    {
        $lines = [
            '## Miss rate on near-miss negatives',
            '',
            'Miss rate = missed / reviewed near misses (a `missed` case is a real pattern that one rule rejects).',
            '',
            '| Type | Rule | Cases | Reviewed | Missed | Miss rate |',
            '| --- | --- | ---: | ---: | ---: | ---: |',
        ];
        $groups = [];

        foreach ($rows as $row) {
            if (! $this->isNegative($row)) {
                continue;
            }

            $rule = substr($row['status'], strlen('near_miss:')) ?: '?';

            foreach ([[$row['type'], $rule], [$row['type'], '(all)']] as [$type, $key]) {
                $groups[$type][$key] ??= ['cases' => 0, 'reviewed' => 0, 'missed' => 0];
                $groups[$type][$key]['cases']++;
            }

            $label = strtolower($row['label']);

            if ($label === '') {
                continue;
            }

            if (! in_array($label, self::NEGATIVE_LABELS, true)) {
                $invalid[] = "{$row['id']}: `{$row['label']}` (near misses take missed/not_a_pattern)";

                continue;
            }

            foreach ([$rule, '(all)'] as $key) {
                $groups[$row['type']][$key]['reviewed']++;
                $groups[$row['type']][$key]['missed'] += $label === 'missed' ? 1 : 0;
            }
        }

        if ($groups === []) {
            $lines[] = '| - | - | 0 | 0 | 0 | n/a |';
        }

        foreach (ChartPattern::TYPES as $type) {
            $byRule = $groups[$type] ?? [];
            ksort($byRule);

            foreach ($byRule as $rule => $counts) {
                $rate = $counts['reviewed'] > 0 ? $counts['missed'] / $counts['reviewed'] : null;
                $lines[] = "| {$type} | {$rule} | {$counts['cases']} | {$counts['reviewed']} | {$counts['missed']} | {$this->percent($rate)} |";
            }
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<string>
     */
    private function agreementSection(array $rows): array
    {
        $both = 0;
        $agree = 0;

        foreach ($rows as $row) {
            $label = strtolower($row['label']);
            $suggested = strtolower($row['suggested_label'] ?? '');

            if ($label !== '' && $suggested !== '') {
                $both++;
                $agree += $label === $suggested ? 1 : 0;
            }
        }

        $rate = $both > 0 ? $agree / $both : null;

        return [
            '## Suggested labels',
            '',
            "Rows with both a suggested and a human label: {$both}; agreement {$this->percent($rate)}. Suggestions are never counted in the metrics above.",
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function frequencySection(string $slug): array
    {
        $universe = Universe::query()->where('slug', $slug)->first();
        $lines = ['## Frequency over the universe (current `chart_patterns` set)', ''];

        if ($universe === null) {
            return [...$lines, "Universe [{$slug}] not found.", ''];
        }

        $instrumentIds = $universe->instruments()->whereHas('dailyBars')->pluck('instruments.id')->all();
        $rows = ChartPattern::query()
            ->whereIn('instrument_id', $instrumentIds)
            ->selectRaw('type, status, count(*) as total')
            ->groupBy('type', 'status')
            ->get();
        $all = (int) $rows->sum('total');
        $asOf = ChartPattern::query()->whereIn('instrument_id', $instrumentIds)->max('as_of_date');
        $instruments = count($instrumentIds);

        $lines[] = "Universe `{$slug}`: {$instruments} instruments with bars; {$all} active patterns".($asOf ? ' (latest as-of '.substr((string) $asOf, 0, 10).')' : '').'.';
        $lines[] = '';
        $lines[] = '| Type | Forming | Confirmed | Total | Share of patterns | Per 100 instruments |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: |';

        foreach (ChartPattern::TYPES as $type) {
            $forming = (int) $rows->where('type', $type)->where('status', 'forming')->sum('total');
            $confirmed = (int) $rows->where('type', $type)->where('status', 'confirmed')->sum('total');
            $total = $forming + $confirmed;
            $share = $all > 0 ? $total / $all : null;
            $per100 = $instruments > 0 ? number_format($total / $instruments * 100, 1, '.', '') : 'n/a';
            $lines[] = "| {$type} | {$forming} | {$confirmed} | {$total} | {$this->percent($share)} | {$per100} |";
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * Parameters recorded next to the sample, else the engine's current ones.
     *
     * @return list<string>
     */
    private function parameterSection(string $folder, EngineClient $client): array
    {
        $lines = ['## Parameters', ''];
        $parameters = null;
        $source = '';
        $file = $folder.'/parameters.json';

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded)) {
                $parameters = $decoded;
                $source = 'recorded with the sample (`parameters.json`)';
            }
        }

        if ($parameters === null) {
            try {
                $parameters = $client->patternParameters();
                $source = 'current engine values (no `parameters.json` next to the CSV)';
            } catch (RequestException|ConnectionException) {
                return [...$lines, 'Not available: no `parameters.json` next to the CSV and the engine is unreachable.', ''];
            }
        }

        $lines[] = "Source: {$source}.";
        $lines[] = '';
        $lines[] = '| Parameter | Value |';
        $lines[] = '| --- | ---: |';

        foreach ($parameters as $name => $value) {
            if (is_string($name) && is_numeric($value)) {
                $lines[] = "| {$name} | ".(float) $value.' |';
            }
        }

        $lines[] = '';

        return $lines;
    }

    private function percent(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value * 100, 1, '.', '').'%';
    }
}
