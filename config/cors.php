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

    'allowed_origins' => [
        'https://*.ngrok-free.app',
        'https://backend.sdutalks.kz',
        'http://localhost:3000',
        'https://localhost:3000',
        'http://127.0.0.1:3000',
        'https://127.0.0.1:3000',
        'http://127.0.0.1:8000',
        'https://127.0.0.1:8000',
        'http://postlink.test',
        'https://postlink.test',
        '*' // Temporarily allow all origins for testing
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Origin',
        'Content-Type',
        'Accept',
        'Authorization',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'X-TELEGRAM-USER-DATA',
        'Cache-Control',
        'Pragma',
        'User-Agent',
        'Accept-Language',
        'Accept-Encoding',
        '*'
    ],

    'exposed_headers' => [
        'Cache-Control',
        'Content-Language',
        'Content-Type',
        'Expires',
        'Last-Modified',
        'Pragma',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];
