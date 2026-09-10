<?php

return [
    'upload' => [
        'max_size_kilobytes' => (int) env(
            'DOCUMENT_UPLOAD_MAX_SIZE_KB',
            10240,
        ),

        'max_original_name_length' => 255,

        'types' => [
            'pdf' => [
                'application/pdf',
            ],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            'txt' => [
                'text/plain',
            ],
        ],

        'docx' => [
            'max_entries' => 1000,
            'max_uncompressed_bytes' => 50 * 1024 * 1024,
        ],
    ],
];
