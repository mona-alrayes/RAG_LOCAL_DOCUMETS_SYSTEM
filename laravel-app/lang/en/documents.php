<?php

return [
    'availability' => [
        'pending' => 'Pending',
        'scanning' => 'Scanning file',
        'infected' => 'Unsafe file detected',
        'queued' => 'Queued',
        'processing' => 'Processing document',
        'indexing' => 'Indexing document',
        'ready' => 'Ready',
        'failed' => 'Processing failed',
    ],

    'processing_run' => [
        'status' => [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'indexing' => 'Indexing',
            'indexed' => 'Processing completed',
            'failed' => 'Processing failed',
        ],

        'kind' => [
            'initial' => 'Initial processing',
            'reprocessing' => 'Reprocessing',
        ],

        'profile' => [
            'cloud' => 'Cloud',
            'hybrid_local' => 'Hybrid local',
        ],
    ],

    'failure' => [
        'processing_failed' => 'Document processing could not be completed. You can try again.',
    ],

    'validation' => [
        'secure_upload' => [
            'invalid_file' => 'The uploaded file could not be read or the upload is invalid.',
            'unsafe_filename' => 'The filename is unsafe. Please use a valid filename.',
            'unsupported_type' => 'The file type is not supported.',
            'content_mismatch' => 'The file content does not match its extension.',
            'inspection_failed' => 'The uploaded file could not be inspected.',
            'malformed_or_unsafe' => 'The file is malformed or unsafe and cannot be used.',
        ],
    ],

    'commands' => [
        'upload' => [
            'success' => 'The document was uploaded and processing has started.',
            'duplicate' => 'This document has already been uploaded.',
            'profile_unavailable' => 'The requested processing profile is no longer available. Please choose an available processing profile and try again.',
            'service_unavailable' => 'The processing service is currently unavailable. Please try again later.',
        ],

        'reprocess' => [
            'started' => 'Document reprocessing has started.',
            'no_active_run' => 'This document does not have an active processed version to reprocess.',
            'invalid_active_run' => 'The document cannot be reprocessed in its current state.',
            'already_in_progress' => 'Document processing is already in progress.',
            'profile_unavailable' => 'The requested processing profile is currently unavailable.',
            'service_unavailable' => 'The processing service is currently unavailable. Please try again later.',
            'failed' => 'Document reprocessing could not be started.',
        ],

        'delete' => [
            'success' => 'The document was deleted successfully.',
            'processing_in_progress' => 'The document cannot be deleted while processing is in progress.',
            'cleanup_failed' => 'The document data could not be cleaned up. Deletion was not completed.',
            'failed' => 'The document could not be deleted.',
        ],
    ],
];
