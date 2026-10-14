<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['run_id', 'instrument_id', 'as_of_date', 'target_date', 'status', 'predicted_class', 'observed_class', 'probabilities', 'reason'])]
class ForecastBacktestCase extends Model
{
    protected function casts(): array
    {
        return ['as_of_date' => 'date', 'target_date' => 'date', 'probabilities' => 'array'];
    }

    /** @return BelongsTo<ForecastBacktestRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ForecastBacktestRun::class, 'run_id');
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
