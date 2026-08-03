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

    // FRONTEND_URL + ADMIN_FRONTEND_URL (через запятую в каждой).
    // На проде: https://agora-trade.vercel.app,https://agora-admin.vercel.app
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', implode(',', array_filter([
            env('FRONTEND_URL', '*'),
            env('ADMIN_FRONTEND_URL', ''),
        ])))
    ))),

    // Превью-деплои Vercel: продукт и админка
    'allowed_origins_patterns' => [
        '#^https://agora-[\w-]+\.vercel\.app$#',
        '#^https://agora-admin[\w-]*\.vercel\.app$#',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],


    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
