<?php

return [
    'stg_import_path' => env('STG_IMPORT_PATH', storage_path('app/imports/stg')),
    'timezone' => env('BUSINESS_TIMEZONE', 'America/Fortaleza'),
    'public_booking_horizon_days' => (int) env('PUBLIC_BOOKING_HORIZON_DAYS', 30),
    'public_booking_lead_minutes' => 30,
    'identity_pepper' => env('IDENTITY_PEPPER', env('APP_KEY')),
    'management_token_secret' => env('MANAGEMENT_TOKEN_SECRET', env('APP_KEY')),
];
