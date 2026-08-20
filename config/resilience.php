<?php

$backupDirectory = trim((string) env('SRCM_BACKUP_DIRECTORY', ''));

return [
    'backup' => [
        'enabled' => (bool) env('SRCM_BACKUP_ENABLED', true),
        'connection' => env('SRCM_BACKUP_DB_CONNECTION', 'sqlite'),
        'directory' => $backupDirectory !== ''
            ? $backupDirectory
            : storage_path('backups/database'),
        'retention_count' => (int) env('SRCM_BACKUP_RETENTION_COUNT', 168),
        'freshness_minutes' => (int) env('SRCM_BACKUP_FRESHNESS_MINUTES', 90),
    ],

    'objectives' => [
        'rpo_minutes' => (int) env('SRCM_RPO_MINUTES', 60),
        'rto_minutes' => (int) env('SRCM_RTO_MINUTES', 240),
    ],
];
