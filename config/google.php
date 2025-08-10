<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Sheets Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Sheets integration using revolution/laravel-google-sheets
    |
    */

    'sheets' => [
        /*
        |--------------------------------------------------------------------------
        | Google Sheets Spreadsheet ID
        |--------------------------------------------------------------------------
        |
        | The ID of your Google Sheets spreadsheet. You can find this in the URL
        | of your spreadsheet: https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit
        |
        */
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),

        /*
        |--------------------------------------------------------------------------
        | Service Account Credentials
        |--------------------------------------------------------------------------
        |
        | Path to your Google Service Account JSON credentials file.
        | This file should be stored securely and not committed to version control.
        |
        */
        'service_account_credentials_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION', storage_path('app/google-service-account.json')),

        /*
        |--------------------------------------------------------------------------
        | Default Ranges
        |--------------------------------------------------------------------------
        |
        | Default ranges for reading and clearing data in Google Sheets.
        |
        */
        'post_range' => env('GOOGLE_SHEETS_POST_RANGE', 'A1:Z1000'),
        'post_clear_range' => env('GOOGLE_SHEETS_POST_CLEAR_RANGE', 'A1:Z1000'),

        /*
        |--------------------------------------------------------------------------
        | Worksheet Names
        |--------------------------------------------------------------------------
        |
        | Default worksheet names used by the application.
        |
        */
        'worksheets' => [
            'users' => 'Users',
            'delivery_requests' => 'Deliver requests',
            'send_requests' => 'Send requests',
        ],

        /*
        |--------------------------------------------------------------------------
        | Auto Sync Settings
        |--------------------------------------------------------------------------
        |
        | Configure automatic synchronization behavior.
        |
        */
        'auto_sync' => [
            'enabled' => env('GOOGLE_SHEETS_AUTO_SYNC', true),
            'queue' => env('GOOGLE_SHEETS_QUEUE', 'default'),
            'delay_seconds' => env('GOOGLE_SHEETS_DELAY_SECONDS', 0),
        ],

        /*
        |--------------------------------------------------------------------------
        | Error Handling
        |--------------------------------------------------------------------------
        |
        | Configure how errors should be handled.
        |
        */
        'error_handling' => [
            'log_errors' => true,
            'fail_silently' => true, // Don't throw exceptions, just log errors
            'retry_attempts' => 3,
        ],
    ],
];