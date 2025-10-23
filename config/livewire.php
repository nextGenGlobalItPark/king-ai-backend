<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:102400'],  // 100MB
        'directory' => 'livewire-tmp',
        'middleware' => null,
        'preview_mimes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'video/mp4',
            'video/webm',
            'video/ogg',
        ],  // Add video MIME for preview
        'max_upload_time' => 5,  // Minutes
        'max_file_size' => 100,  // MB
        'permissions_for_folders' => 0755,
        'permissions_for_files' => 0644,
        'use_queue' => false,
    ],
];