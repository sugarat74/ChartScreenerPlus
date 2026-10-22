<?php

namespace App\Models;

use Database\Factories\MacroObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published observation of a Macro Series, with its own date and the
 * value exactly as published (no unit conversion; docs/specs/macro-series-fred.md).
 */
#[Fillable([
    'macro_series_id',
    'date',
    'value',
])]
class MacroObservation extends Model
{
    /** @use HasFactory<MacroObservationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'decimal:6',
        ];
    }

    /**
     * The series this observation belongs to.
     *
     * @return BelongsTo<MacroSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(MacroSeries::class, 'macro_series_id');
    }
}
