<?php

return [
    /*
    |----------------------------------------------------------------------------
    | Google application name
    |----------------------------------------------------------------------------
    */
    'application_name' => env('GOOGLE_APPLICATION_NAME', 'PostLink API'),

    /*
    |----------------------------------------------------------------------------
    | Google OAuth 2.0 access
    |----------------------------------------------------------------------------
    |
    | Keys for OAuth 2.0 access, see the API console at
    | https://developers.google.com/console
    |
    */
    'client_id' => env('GOOGLE_CLIENT_ID', ''),
    'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
    'redirect_uri' => env('GOOGLE_REDIRECT', ''),
    'scopes' => [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive.readonly',
    ],
    'access_type' => 'offline',
    'prompt' => 'consent select_account',

    /*
    |----------------------------------------------------------------------------
    | Google developer key
    |----------------------------------------------------------------------------
    |
    | Simple API access key, also from the API console. Ensure you get
    | a Server key, and not a Browser key.
    |
    */
    'developer_key' => env('GOOGLE_DEVELOPER_KEY', ''),

    /*
    |----------------------------------------------------------------------------
    | Google service account
    |----------------------------------------------------------------------------
    |
    | Set the credentials JSON's location to use assert credentials, otherwise
    | app engine or compute engine will be used.
    |
    */
    'service' => [
        /*
        | Enable service account auth or not.
        */
        'enable' => env('GOOGLE_SERVICE_ENABLED', true),

        /*
         * Path to service account json file. You can also pass the credentials as an array
         * instead of a file path.
         */
        'file' => env('GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION', storage_path('app/google-service-account.json')),
    ],

    /*
    |----------------------------------------------------------------------------
    | Additional config for the Google Client
    |----------------------------------------------------------------------------
    |
    | Set any additional config variables supported by the Google Client
    | Details can be found here:
    | https://github.com/google/google-api-php-client/blob/master/src/Google/Client.php
    |
    | NOTE: If client id is specified here, it will get over written by the one above.
    |
    */
    'config' => [],

    /*
    |--------------------------------------------------------------------------
    | Google Sheets Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Sheets integration
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
