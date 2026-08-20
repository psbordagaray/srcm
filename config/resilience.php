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

    'off_host' => [
        'enabled' => (bool) env('SRCM_BACKUP_OFF_HOST_ENABLED', false),
        'remote_disk' => env('SRCM_BACKUP_REMOTE_DISK'),
        'remote_prefix' => env(
            'SRCM_BACKUP_REMOTE_PREFIX',
            'srcm/backups/database'
        ),
        'allowed_remote_drivers' => ['s3', 'sftp'],
        'encryption' => [
            'key_id' => env('SRCM_BACKUP_ENCRYPTION_KEY_ID'),
            'key_reference' => env('SRCM_BACKUP_ENCRYPTION_KEY_REFERENCE'),
            'chunk_bytes' => (int) env(
                'SRCM_BACKUP_ENCRYPTION_CHUNK_BYTES',
                1048576
            ),
        ],
    ],

    'objectives' => [
        'rpo_minutes' => (int) env('SRCM_RPO_MINUTES', 60),
        'rto_minutes' => (int) env('SRCM_RTO_MINUTES', 240),
    ],
];
