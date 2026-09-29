<?php

namespace App\Models;

use App\Enums\IngestionRunItemStatus;
use Database\Factories\IngestionRunItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instrument's outcome inside an ingestion run: success with the number of
 * bars stored, or failure with a message.
 */
#[Fillable(['ingestion_run_id', 'instrument_id', 'status', 'bars_stored', 'message'])]
class IngestionRunItem extends Model
{
    /** @use HasFactory<IngestionRunItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IngestionRunItemStatus::class,
            'bars_stored' => 'integer',
        ];
    }

    /**
     * The run this item belongs to.
     *
     * @return BelongsTo<IngestionRun, $this>
     */
    public function ingestionRun(): BelongsTo
    {
        return $this->belongsTo(IngestionRun::class);
    }

    /**
     * The instrument this item processed.
     *
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
