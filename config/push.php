<?php

return [
    'provider' => env('PUSH_PROVIDER', 'fcm'),
    'fcm' => [
        'credentials' => storage_path('app/vidio-king-ai-firebase.json'),
    ],
];