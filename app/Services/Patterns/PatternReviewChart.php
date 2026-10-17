<?php

namespace App\Services\Patterns;

/**
 * Standalone SVG chart of one pattern case for the calibration review sample.
 *
 * Draws the closes (line), each bar's high-low range (faint), volume bars in a
 * strip below, the pattern's key points with their roles and the breakout
 * level as a dashed line, with labelled price and date axes. Pure: no I/O, no
 * external assets, so the file opens in any browser. Admin/CLI only
 * (chart-patterns-calibration); never served to the public.
 */
class PatternReviewChart
{
    private const WIDTH = 900;

    private const HEIGHT = 460;

    private const LEFT = 70;

    private const RIGHT = 30;

    private const TOP = 44;

    private const PRICE_BOTTOM = 340;

    private const VOLUME_TOP = 356;

    private const VOLUME_BOTTOM = 410;

    /**
     * @param  list<array{date: string, high: float, low: float, close: float, volume: float}>  $bars  ascending by date
     * @param  list<array{date: string, price: float, role: string}>  $points
     */
    public function render(string $title, array $bars, array $points, ?float $breakoutLevel): string
    {
        $count = count($bars);

        if ($count === 0) {
            return $this->document($title, '<text x="'.self::LEFT.'" y="80" font-size="14">No bars</text>');
        }

        $prices = array_merge(array_column($bars, 'high'), array_column($bars, 'low'), array_column($points, 'price'));

        if ($breakoutLevel !== null) {
            $prices[] = $breakoutLevel;
        }

        $min = min($prices);
        $max = max($prices);
        $pad = ($max - $min) * 0.05 ?: max(abs($max) * 0.01, 0.01);
        $min -= $pad;
        $max += $pad;

        $plotWidth = self::WIDTH - self::LEFT - self::RIGHT;
        $step = $plotWidth / max($count, 1);
        $x = fn (int $index): float => self::LEFT + ($index + 0.5) * $step;
        $y = fn (float $price): float => self::TOP + (self::PRICE_BOTTOM - self::TOP) * ($max - $price) / ($max - $min);

        $body = [];

        // Price grid and labels.
        foreach ($this->ticks($min, $max, 6) as $tick) {
            $ty = $this->fmt($y($tick));
            $body[] = '<line x1="'.self::LEFT.'" y1="'.$ty.'" x2="'.(self::WIDTH - self::RIGHT).'" y2="'.$ty.'" stroke="#e5e7eb" stroke-width="1"/>';
            $body[] = '<text x="'.(self::LEFT - 6).'" y="'.$this->fmt($y($tick) + 4).'" font-size="11" text-anchor="end" fill="#374151">'.$this->price($tick).'</text>';
        }

        // Date ticks (about 7 labels, always the first and the last bar).
        $dateStep = max(1, (int) ceil(($count - 1) / 6));
        $dateIndexes = range(0, $count - 1, $dateStep);

        if (end($dateIndexes) !== $count - 1) {
            if ($count - 1 - end($dateIndexes) < $dateStep / 2) {
                array_pop($dateIndexes);
            }
            $dateIndexes[] = $count - 1;
        }

        foreach ($dateIndexes as $index) {
            $tx = $this->fmt($x($index));
            $body[] = '<line x1="'.$tx.'" y1="'.self::TOP.'" x2="'.$tx.'" y2="'.self::VOLUME_BOTTOM.'" stroke="#f3f4f6" stroke-width="1"/>';
            $anchor = $index === 0 ? 'start' : ($index === $count - 1 ? 'end' : 'middle');
            $body[] = '<text x="'.$tx.'" y="'.(self::VOLUME_BOTTOM + 16).'" font-size="11" text-anchor="'.$anchor.'" fill="#374151">'.$this->escape($bars[$index]['date']).'</text>';
        }

        // Axes.
        $body[] = '<line x1="'.self::LEFT.'" y1="'.self::TOP.'" x2="'.self::LEFT.'" y2="'.self::VOLUME_BOTTOM.'" stroke="#9ca3af"/>';
        $body[] = '<line x1="'.self::LEFT.'" y1="'.self::PRICE_BOTTOM.'" x2="'.(self::WIDTH - self::RIGHT).'" y2="'.self::PRICE_BOTTOM.'" stroke="#9ca3af"/>';
        $body[] = '<line x1="'.self::LEFT.'" y1="'.self::VOLUME_BOTTOM.'" x2="'.(self::WIDTH - self::RIGHT).'" y2="'.self::VOLUME_BOTTOM.'" stroke="#9ca3af"/>';
        $body[] = '<text x="'.(self::LEFT - 6).'" y="'.(self::VOLUME_TOP + 10).'" font-size="10" text-anchor="end" fill="#6b7280">vol</text>';

        // High-low ranges and volume.
        $maxVolume = max(array_column($bars, 'volume')) ?: 1.0;
        $barWidth = $this->fmt(max(1.0, $step * 0.6));

        foreach ($bars as $index => $bar) {
            $bx = $this->fmt($x($index));
            $body[] = '<line x1="'.$bx.'" y1="'.$this->fmt($y($bar['high'])).'" x2="'.$bx.'" y2="'.$this->fmt($y($bar['low'])).'" stroke="#cbd5e1" stroke-width="1"/>';
            $height = (self::VOLUME_BOTTOM - self::VOLUME_TOP) * $bar['volume'] / $maxVolume;
            $body[] = '<rect x="'.$this->fmt($x($index) - max(1.0, $step * 0.6) / 2).'" y="'.$this->fmt(self::VOLUME_BOTTOM - $height).'" width="'.$barWidth.'" height="'.$this->fmt($height).'" fill="#cbd5e1"/>';
        }

        // Closes.
        $line = [];

        foreach ($bars as $index => $bar) {
            $line[] = $this->fmt($x($index)).','.$this->fmt($y($bar['close']));
        }

        $body[] = '<polyline points="'.implode(' ', $line).'" fill="none" stroke="#1d4ed8" stroke-width="1.6"/>';

        // Breakout level.
        if ($breakoutLevel !== null) {
            $by = $this->fmt($y($breakoutLevel));
            $body[] = '<line x1="'.self::LEFT.'" y1="'.$by.'" x2="'.(self::WIDTH - self::RIGHT).'" y2="'.$by.'" stroke="#d97706" stroke-width="1.4" stroke-dasharray="6 4"/>';
            $body[] = '<text x="'.(self::WIDTH - self::RIGHT - 4).'" y="'.$this->fmt($y($breakoutLevel) - 5).'" font-size="11" text-anchor="end" fill="#b45309">breakout '.$this->price($breakoutLevel).'</text>';
        }

        // Key points.
        $indexByDate = array_flip(array_column($bars, 'date'));

        foreach ($points as $order => $point) {
            if (! isset($indexByDate[$point['date']])) {
                continue;
            }

            $px = $x($indexByDate[$point['date']]);
            $py = $y($point['price']);
            $above = $py > self::TOP + 30 && ($order % 2 === 0 || $py > self::PRICE_BOTTOM - 30);
            $ly = $above ? $py - 10 : $py + 18;
            $body[] = '<circle cx="'.$this->fmt($px).'" cy="'.$this->fmt($py).'" r="4.5" fill="#dc2626" stroke="#ffffff" stroke-width="1.2"/>';
            $anchor = $px > self::WIDTH - self::RIGHT - 80 ? 'end' : ($px < self::LEFT + 80 ? 'start' : 'middle');
            $body[] = '<text x="'.$this->fmt($px).'" y="'.$this->fmt($ly).'" font-size="11" font-weight="600" text-anchor="'.$anchor.'" fill="#991b1b">'
                .$this->escape($point['role']).' '.$this->price($point['price']).'</text>';
        }

        return $this->document($title, implode("\n", $body));
    }

    private function document(string $title, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" width="'.self::WIDTH.'" height="'.self::HEIGHT.'" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'" font-family="Arial, Helvetica, sans-serif">'."\n"
            .'<rect width="100%" height="100%" fill="#ffffff"/>'."\n"
            .'<text x="'.self::LEFT.'" y="26" font-size="15" font-weight="700" fill="#111827">'.$this->escape($title).'</text>'."\n"
            .$body."\n"
            .'</svg>'."\n";
    }

    /**
     * About `$target` round price ticks inside `[$min, $max]`.
     *
     * @return list<float>
     */
    private function ticks(float $min, float $max, int $target): array
    {
        $raw = ($max - $min) / max($target, 1);
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude;

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($magnitude * $factor >= $raw) {
                $step = $magnitude * $factor;
                break;
            }
        }

        $ticks = [];

        for ($tick = ceil($min / $step) * $step; $tick <= $max; $tick += $step) {
            $ticks[] = round($tick, 6);
        }

        return $ticks;
    }

    private function price(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function fmt(float $value): string
    {
        return number_format($value, 1, '.', '');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
