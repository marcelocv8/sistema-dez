<?php

return [
    'stg_import_path' => env('STG_IMPORT_PATH', storage_path('app/imports/stg')),
    'stg_laudos_path' => env('STG_LAUDOS_PATH', storage_path('app/imports/stg-laudos')),
    'report_retention_years' => 20,
    'timezone' => env('BUSINESS_TIMEZONE', 'America/Fortaleza'),
    'public_booking_horizon_days' => (int) env('PUBLIC_BOOKING_HORIZON_DAYS', 30),
    'public_booking_lead_minutes' => 30,
    'routine_return_months' => (int) env('ROUTINE_RETURN_MONTHS', 6),
    'mt_due_warning_days' => (int) env('MT_DUE_WARNING_DAYS', 30),
    'identity_pepper' => env('IDENTITY_PEPPER', env('APP_KEY')),
    'management_token_secret' => env('MANAGEMENT_TOKEN_SECRET', env('APP_KEY')),
    'deepseek_api_key' => env('DEEPSEEK_API_KEY'),
    'deepseek_model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    'whatsapp' => [
        // Canal oficial do paciente = fork do Chat App Crédito (§6.12.1). Com a
        // flag desligada, nada é enviado e o comportamento é o de antes (T2/T3 inertes).
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'base_url' => env('APPCHAT_BASE_URL', 'http://127.0.0.1:3333'),
        'api_key' => env('APPCHAT_API_KEY'),
        'reminder_days' => (int) env('WHATSAPP_REMINDER_DAYS', 2),
        'reminder_hour' => env('WHATSAPP_REMINDER_HOUR', '10:00'),
        'booking_base_url' => env('BOOKING_BASE_URL', ''),
    ],
    'portal' => [
        'ttl_days' => (int) env('PORTAL_TTL_DAYS', 30),
        'base_url' => env('PORTAL_BASE_URL', 'http://localhost:5176'),
        'path' => '/resultados',
    ],
];
