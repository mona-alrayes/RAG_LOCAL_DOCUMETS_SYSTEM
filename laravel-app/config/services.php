<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'ai_service' => [
        'base_url' => env(
            'AI_SERVICE_BASE_URL',
            'http://host.docker.internal:8000',
        ),
        'internal_api_key' => env('AI_SERVICE_INTERNAL_API_KEY'),
        'connect_timeout' => (int) env('AI_SERVICE_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 30),
        'process_document_timeout' => (int) env(
            'AI_SERVICE_PROCESS_DOCUMENT_TIMEOUT',
            300,
        ),
    ],

    'processing_callback' => [
        'secret' => env('PROCESSING_CALLBACK_SECRET'),
    ],

];
