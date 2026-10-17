<?php

namespace App\Models;

use Database\Factories\AiCompanyCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shared, validated AI Company Card for one Instrument, locale and schema
 * version (ai-company-card). Holds no user identity.
 */
#[Fillable([
    'instrument_id', 'locale', 'schema_version', 'prompt_version', 'model',
    'context_mode', 'context_as_of_date', 'content', 'generated_at',
    'fundamentals_accession', 'fundamentals_period_end',
])]
class AiCompanyCard extends Model
{
    /** @use HasFactory<AiCompanyCardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'context_as_of_date' => 'date',
            'fundamentals_period_end' => 'date',
            'content' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
