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

    /*
    | WaveSpeed OpenAI-compatible LLM (AI matching)
    | https://llm.wavespeed.ai/v1
    */
    'wavespeed' => [
        'enabled' => env('WAVESPEED_ENABLED', true),
        'key' => env('WAVESPEED_API_KEY'),
        'base_url' => env('WAVESPEED_BASE_URL', 'https://llm.wavespeed.ai/v1'),
        'model' => env('WAVESPEED_MODEL', 'deepseek/deepseek-v4-flash'),
        'timeout' => (int) env('WAVESPEED_TIMEOUT', 45),
    ],

];

