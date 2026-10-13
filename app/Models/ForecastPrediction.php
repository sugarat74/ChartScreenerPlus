<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'instrument_id', 'as_of_date', 'reference_close', 'horizon', 'target_date',
    'model_version', 'question_version', 'input_hash', 'probability_up',
    'probability_down', 'probability_flat', 'source', 'observed_close',
    'observed_class', 'resolved_at',
])]
class ForecastPrediction extends Model
{
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'target_date' => 'date',
            'reference_close' => 'decimal:4',
            'horizon' => 'integer',
            'probability_up' => 'decimal:6',
            'probability_down' => 'decimal:6',
            'probability_flat' => 'decimal:6',
            'observed_close' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
