<?php

namespace App\Models;

use App\Enums\IngestionRunStatus;
use Database\Factories\IngestionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ledger of one ingestion run over a universe (or a re-run of the failed
 * instruments of a previous run). Laravel owns this ledger; the engine never
 * sees it.
 */
#[Fillable(['status', 'universe_id', 'started_at', 'finished_at', 'total', 'succeeded', 'failed'])]
class IngestionRun extends Model
{
    /** @use HasFactory<IngestionRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IngestionRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'total' => 'integer',
            'succeeded' => 'integer',
            'failed' => 'integer',
        ];
    }

    /**
     * The universe this run processed, when it was scoped to one.
     *
     * @return BelongsTo<Universe, $this>
     */
    public function universe(): BelongsTo
    {
        return $this->belongsTo(Universe::class);
    }

    /**
     * The per-instrument results of this run.
     *
     * @return HasMany<IngestionRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(IngestionRunItem::class);
    }
}
