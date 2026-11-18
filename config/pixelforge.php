<?php

declare(strict_types=1);

return [

    /*
    | One API key per environment (local and production), each with its own webhook URL and secret.
    */
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    /*
    | Seconds before a request to Pixelforge gives up. Timeouts are retried with the same Idempotency-Key.
    */
    'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),

    /*
    | What users pay on top of the provider cost, in percent.
    */
    'markup_percent' => 25,

    /*
    | Seconds GET /v1/pricing stays cached. Pricing feeds estimates only.
    */
    'pricing_cache_seconds' => 3600,

    /*
    | Webhook signatures older or newer than this many seconds are rejected (replay protection).
    */
    'webhook_tolerance_seconds' => 300,

    /*
    | Seeded accounts. The admin can open /admin/report; is_admin is never settable from a request.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'demo' => [
        'email' => env('DEMO_EMAIL', 'demo@example.com'),
        'password' => env('DEMO_PASSWORD'),
    ],

];
