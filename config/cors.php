<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Разрешённые источники задаются через FRONTEND_URL (через запятую).
    // По умолчанию '*' — удобно для разработки; на проде укажи домен Vercel.
    'allowed_origins' => array_filter(
        explode(',', (string) env('FRONTEND_URL', '*'))
    ),

    // Любой превью-деплой Vercel вида https://agora-*.vercel.app
    'allowed_origins_patterns' => ['#^https://agora-[\w-]+\.vercel\.app$#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
