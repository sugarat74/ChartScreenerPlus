<?php

namespace App\Enums;

/**
 * Per-instrument outcome inside an ingestion run ledger.
 */
enum IngestionRunItemStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
