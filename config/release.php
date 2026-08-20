<?php

return [
    /*
    |--------------------------------------------------------------------------
    | P11 production release authorization
    |--------------------------------------------------------------------------
    |
    | V1 deliberately remains fail-closed. This cut creates executable CI and
    | preflight gates, but it does not implement or authorize a real production
    | deployment. A later, separately reviewed cut must replace these sentinels
    | only after the external gates have real evidence.
    |
    */
    'production_release_enabled' => false,

    'post_deploy_readiness' => [
        'route_name' => 'api.health.ready',
        'uri' => 'api/health/ready',
        'method' => 'GET',
    ],

    'external_gates' => [
        'off_host_encrypted_backup' => false,
        'operational_restore_drill' => false,
        'production_environment_secrets_and_approvals' => false,
    ],
];
