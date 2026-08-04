<?php

return [
    'disk' => 'local',

    'max_bytes' => 20 * 1024 * 1024,

    'allowed_mime_types' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ],
];
