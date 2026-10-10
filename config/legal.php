<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Owner (data controller) identity
    |--------------------------------------------------------------------------
    |
    | Supplied by the owner, never invented (docs/specs/legal-compliance-eu.md).
    | The privacy policy and legal notice are published only when name, tax_id,
    | address and email are all set; registry is optional and shown only when
    | it applies (registry, authorisation or professional body data).
    |
    */

    'owner' => [
        'name' => env('LEGAL_OWNER_NAME'),
        'tax_id' => env('LEGAL_OWNER_TAX_ID'),
        'address' => env('LEGAL_OWNER_ADDRESS'),
        'email' => env('LEGAL_CONTACT_EMAIL'),
        'registry' => env('LEGAL_OWNER_REGISTRY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Texts
    |--------------------------------------------------------------------------
    |
    | Date of the last substantive change to the legal texts (ISO 8601). Bump
    | it whenever the processing inventory or the texts change.
    |
    */

    'updated_at' => '2026-10-10',

    /*
    |--------------------------------------------------------------------------
    | Retention stated in the privacy policy
    |--------------------------------------------------------------------------
    |
    | Mirrors operations outside Laravel: the daily PostgreSQL dump rotation
    | (CHARTIKO_BACKUP_RETENTION_DAYS in deploy/backup-postgresql.sh) and the
    | nginx logrotate / Laravel `daily` log channel. Change both together.
    |
    */

    'backup_retention_days' => (int) env('CHARTIKO_BACKUP_RETENTION_DAYS', 14),

    'server_log_retention_days' => (int) env('LOG_DAILY_DAYS', 14),

];
