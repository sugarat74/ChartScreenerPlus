<?php

namespace App\Enums;

/**
 * Per-instrument outcome inside an ingestion run ledger.
 */
enum IngestionRunItemStatus: string
{
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
}
