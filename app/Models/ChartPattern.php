<?php

namespace App\Models;

use Database\Factories\ChartPatternFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chartist pattern active on an instrument's as-of bar.
 *
 * Rows are the *current* set written by `patterns:detect` (replace per
 * instrument); direction may be null while a triangle forms and
 * `breakout_level` may be null when only sloped geometry is honest. No target
 * is ever stored. `points` is a list of `{date, price, role}`.
 */
#[Fillable(['instrument_id', 'as_of_date', 'type', 'status', 'direction', 'start_date', 'end_date', 'breakout_level', 'points', 'metadata'])]
class ChartPattern extends Model
{
    /** @use HasFactory<ChartPatternFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $pattern): void {
            if ($pattern->direction !== null) {
                return;
            }

            if (in_array($pattern->type, ['double_bottom', 'cup_with_handle', 'bull_flag', 'triple_bottom', 'bull_pennant', 'inverse_head_and_shoulders'], true)) {
                $pattern->direction = 'bullish';
            } elseif (in_array($pattern->type, ['double_top', 'triple_top', 'inverse_cup_with_handle', 'bear_flag', 'bear_pennant', 'head_and_shoulders'], true)) {
                $pattern->direction = 'bearish';
            }
        });
    }

    /**
     * The fixed vocabulary owned by the engine (`engine/app/patterns/rules.py`
     * and `extended.py`). The second batch (chart-patterns-extended) is
     * appended so the canonical order of stored lists does not change.
     */
    public const TYPES = [
        'double_top',
        'double_bottom',
        'cup_with_handle',
        'bull_flag',
        'triple_top',
        'triple_bottom',
        'inverse_cup_with_handle',
        'bear_flag',
        'bull_pennant',
        'bear_pennant',
        'head_and_shoulders',
        'inverse_head_and_shoulders',
        'ascending_triangle',
        'descending_triangle',
        'symmetrical_triangle',
    ];

    public const LEGACY_TYPES = ['double_top', 'double_bottom', 'cup_with_handle', 'bull_flag', 'triple_top', 'triple_bottom', 'inverse_cup_with_handle', 'bear_flag', 'bull_pennant', 'bear_pennant'];

    public const STATUSES = ['forming', 'confirmed'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'breakout_level' => 'float',
            'points' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * The instrument this pattern belongs to.
     *
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
