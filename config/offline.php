<?php

declare(strict_types=1);

return [
    'restricted_signed_grant' => [
        'enabled' => env(
            'SRCM_OFFLINE_SIGNED_GRANT_ISSUANCE_ENABLED',
            false
        ),
        'active_kid' => env(
            'SRCM_OFFLINE_SIGNED_GRANT_ACTIVE_KID'
        ),
        'signing_secret_env' =>
            'SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL',
        'trusted_public_keyring_json' => env(
            'SRCM_OFFLINE_SIGNED_GRANT_TRUSTED_PUBLIC_KEYRING_JSON'
        ),
        'policy_version' => env(
            'SRCM_OFFLINE_SIGNED_GRANT_POLICY_VERSION',
            'restricted-read-model-v1'
        ),
    ],
];
