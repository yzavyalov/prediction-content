<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),

        'base_url' => env(
            'GEMINI_BASE_URL',
            'https://generativelanguage.googleapis.com/v1beta'
        ),

        'models' => [
            'discovery' => env(
                'GEMINI_MODEL_DISCOVERY',
                'gemini-3.8-flash'
            ),

            'extraction' => env(
                'GEMINI_MODEL_EXTRACTION',
                'gemini-3.8-flash'
            ),

            'deduplication' => env(
                'GEMINI_MODEL_DEDUPLICATION',
                'gemini-3.8-flash'
            ),

            'marketability' => env(
                'GEMINI_MODEL_MARKETABILITY',
                'gemini-3.8-flash'
            ),

            'generation' => env(
                'GEMINI_MODEL_GENERATION',
                'gemini-3.8-flash'
            ),

            'validation' => env(
                'GEMINI_MODEL_VALIDATION',
                'gemini-3.8-flash'
            ),

            'translation' => env(
                'GEMINI_MODEL_TRANSLATION',
                'gemini-3.5-flash-lite'
            ),

            'resolution' => env(
                'GEMINI_MODEL_RESOLUTION',
                'gemini-3.8-flash'
            ),
        ],
    ],

];
