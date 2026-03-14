<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:102400'],  // 100MB
        'directory' => 'livewire-tmp',
        'middleware' => null,
        'preview_mimes' => [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'video/mp4',
            'video/mpeg',
            'video/quicktime',
            'video/webm',
            'video/ogg',
            'video/x-msvideo',
            'video/x-ms-wmv',
        ],
        'max_upload_time' => 5,  // Minutes
        'max_file_size' => 100,  // MB
        'permissions_for_folders' => 0755,
        'permissions_for_files' => 0644,
        'use_queue' => false,
    ],
];