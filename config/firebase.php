<?php
// config/firebase.php

return [
    'default' => env('FIREBASE_DEFAULT_PROJECT', 'your-project-id'), // Replace with your Firebase project ID

    'projects' => [
        'your-project-id' => [ // Replace with your project ID
            'credentials' => [
                'file' => base_path('firebase-service-account.json'), // Path to your JSON key file
                // OR inline: 'json' => json_decode(file_get_contents(base_path('firebase-service-account.json')), true),
            ],
            'database' => [
                'url' => env('FIREBASE_DATABASE_URL', 'https://your-project-id-default-rtdb.firebaseio.com/'), // Your Realtime DB URL
            ],
            // Other options like storage, auth can be added if needed
        ],
    ],
];