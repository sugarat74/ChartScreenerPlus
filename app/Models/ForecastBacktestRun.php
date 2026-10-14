<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['requested_by', 'tickers', 'bars_requested', 'model_version', 'question_version', 'status', 'total', 'processed', 'correct', 'skipped'])]
class ForecastBacktestRun extends Model
{
    protected function casts(): array
    {
        return ['tickers' => 'array', 'bars_requested' => 'integer', 'total' => 'integer', 'processed' => 'integer', 'correct' => 'integer', 'skipped' => 'integer'];
    }

    /** @return HasMany<ForecastBacktestCase, $this> */
    public function cases(): HasMany
    {
        return $this->hasMany(ForecastBacktestCase::class, 'run_id');
    }
}
