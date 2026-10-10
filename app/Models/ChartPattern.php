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
 * instrument); `breakout_level` is the only price line a pattern exposes and
 * no target is ever stored. `points` is a list of `{date, price, role}`.
 */
#[Fillable(['instrument_id', 'as_of_date', 'type', 'status', 'start_date', 'end_date', 'breakout_level', 'points', 'metadata'])]
class ChartPattern extends Model
{
    /** @use HasFactory<ChartPatternFactory> */
    use HasFactory;

    /**
     * The fixed vocabulary owned by the engine (`engine/app/patterns/rules.py`).
     */
    public const TYPES = ['double_top', 'double_bottom', 'cup_with_handle', 'bull_flag'];

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
