<?php

return [
    'stg_import_path' => env('STG_IMPORT_PATH', storage_path('app/imports/stg')),
    'stg_laudos_path' => env('STG_LAUDOS_PATH', storage_path('app/imports/stg-laudos')),
    'report_retention_years' => 5,
    'timezone' => env('BUSINESS_TIMEZONE', 'America/Fortaleza'),
    'public_booking_horizon_days' => (int) env('PUBLIC_BOOKING_HORIZON_DAYS', 30),
    'public_booking_lead_minutes' => 30,
    'routine_return_months' => (int) env('ROUTINE_RETURN_MONTHS', 6),
    'identity_pepper' => env('IDENTITY_PEPPER', env('APP_KEY')),
    'management_token_secret' => env('MANAGEMENT_TOKEN_SECRET', env('APP_KEY')),
    'deepseek_api_key' => env('DEEPSEEK_API_KEY'),
    'deepseek_model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    'portal' => [
        'ttl_days' => (int) env('PORTAL_TTL_DAYS', 30),
        'base_url' => env('PORTAL_BASE_URL', 'http://localhost:5176'),
        'path' => '/resultados',
    ],
];
