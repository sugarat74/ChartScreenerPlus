<?php

namespace App\Enums;

/**
 * Lifecycle of one ingestion run: `queued -> running -> completed`, with
 * `failed` (nothing succeeded) and `partial` (some instruments failed) as the
 * terminal outcomes of a run that finished. See docs/domain-model.md.
 */
enum IngestionRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Partial = 'partial';
}
