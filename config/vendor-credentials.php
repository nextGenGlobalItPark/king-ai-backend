<?php

/**
 * Vendor API Credentials
 *
 * Maps environment variable names to config keys so they work after config:cache.
 *
 * Auth strategies use: config('services.KLING_ACCESS_KEY') which reads from here.
 *
 * Add new vendor credentials here as you onboard new AI providers.
 */
return [
    // Kling (JWT auth)
    'KLING_ACCESS_KEY' => env('KLING_ACCESS_KEY'),
    'KLING_SECRET_KEY' => env('KLING_SECRET_KEY'),

    // Vidu (static token auth)
    'VIDU_API_KEY' => env('VIDU_API_KEY'),

    // Add more vendor keys as needed:
    // 'RUNWAY_API_KEY' => env('RUNWAY_API_KEY'),
];
